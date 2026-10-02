<?php

namespace Tests\Feature;

use App\Events\DataChanged;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DataChangeBroadcastTest extends TestCase
{
    private RecordingUpdateBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('realtime_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });

        $this->broadcaster = new RecordingUpdateBroadcaster;
        config(['broadcasting.default' => 'testing', 'broadcasting.connections.testing' => ['driver' => 'testing']]);
        Broadcast::extend('testing', fn () => $this->broadcaster);
        require base_path('routes/channels.php');

        Route::match(['POST', 'PUT', 'PATCH', 'DELETE'], '/api/realtime-test', function () {
            DB::transaction(fn () => DB::table('realtime_records')->insert(['value' => 'committed']));

            return response()->json(['saved' => true], 201);
        })->middleware(['api', 'auth:sanctum', 'active.user']);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Schema::dropIfExists('realtime_records');
        parent::tearDown();
    }

    #[DataProvider('mutationMethods')]
    public function test_successful_api_mutations_broadcast_once_after_the_transaction_commits(string $method): void
    {
        $this->actingAs($this->actor(), 'sanctum')->json($method, '/api/realtime-test')->assertCreated()->assertJson(['saved' => true]);

        self::assertCount(1, $this->broadcaster->messages);
        self::assertSame([
            'channels' => ['private-lager.updates'],
            'event' => 'data.changed',
            'payload' => ['socket' => null],
            'transaction_level' => 0,
            'committed_records' => 1,
        ], $this->broadcaster->messages[0]);
        self::assertInstanceOf(ShouldBroadcastNow::class, new DataChanged);
        self::assertSame([], (new DataChanged)->broadcastWith());
    }

    public static function mutationMethods(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    public function test_reads_failed_responses_and_auth_lifecycle_do_not_broadcast(): void
    {
        $this->actingAs($this->actor(), 'sanctum');
        Route::get('/api/realtime-read', fn () => response()->json(['ok' => true]))->middleware('api');
        $this->getJson('/api/realtime-read')->assertOk();

        foreach ([403, 409, 422, 500] as $status) {
            Route::post('/api/realtime-failure-'.$status, fn () => response()->json(['error' => true], $status))->middleware('api');
            $this->postJson('/api/realtime-failure-'.$status)->assertStatus($status);
        }
        foreach (['login', 'logout'] as $action) {
            Route::post('/api/auth/realtime-'.$action, fn () => response()->json(['ok' => true]))->middleware('api');
            $this->postJson('/api/auth/realtime-'.$action)->assertOk();
        }

        self::assertSame([], $this->broadcaster->messages);
    }

    public function test_failed_transaction_rolls_back_without_broadcasting(): void
    {
        Route::post('/api/realtime-rollback', function () {
            DB::transaction(function (): void {
                DB::table('realtime_records')->insert(['value' => 'rolled back']);
                throw ValidationException::withMessages(['value' => 'Rejected mutation.']);
            });
        })->middleware(['api', 'auth:sanctum', 'active.user']);

        $this->actingAs($this->actor(), 'sanctum')->postJson('/api/realtime-rollback')->assertUnprocessable();

        self::assertSame(0, DB::table('realtime_records')->count());
        self::assertSame([], $this->broadcaster->messages);
    }

    public function test_response_with_an_open_transaction_does_not_broadcast_uncommitted_data(): void
    {
        Route::post('/api/realtime-uncommitted', function () {
            DB::beginTransaction();
            DB::table('realtime_records')->insert(['value' => 'uncommitted']);

            return response()->json(['saved' => true]);
        })->middleware(['api', 'auth:sanctum', 'active.user']);

        $this->actingAs($this->actor(), 'sanctum')->postJson('/api/realtime-uncommitted')->assertOk();

        self::assertSame(1, DB::transactionLevel());
        self::assertSame([], $this->broadcaster->messages);
    }

    public function test_broadcast_failure_is_logged_without_changing_the_committed_mutation_response(): void
    {
        $this->broadcaster->fail = true;
        Log::spy();

        $this->actingAs($this->actor(), 'sanctum')->postJson('/api/realtime-test')->assertCreated()->assertJson(['saved' => true]);

        self::assertSame(1, DB::table('realtime_records')->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Realtime data update could not be broadcast.' && $context['exception'] instanceof RuntimeException);
    }

    public function test_broadcast_and_logging_failures_do_not_change_the_successful_response(): void
    {
        $this->broadcaster->fail = true;
        Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('Logging unavailable.'));

        $this->actingAs($this->actor(), 'sanctum')->postJson('/api/realtime-test')->assertCreated();

        self::assertSame(1, DB::table('realtime_records')->count());
    }

    public function test_private_update_channel_requires_an_active_authenticated_user_and_auth_does_not_broadcast(): void
    {
        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-lager.updates'];
        $this->postJson('/api/broadcasting/auth', $payload)->assertUnauthorized();
        $this->actingAs($this->actor(false), 'sanctum')->postJson('/api/broadcasting/auth', $payload)->assertUnauthorized();
        $this->actingAs($this->actor(), 'sanctum')->postJson('/api/broadcasting/auth', $payload)->assertOk()->assertJson(['auth' => 'authorized']);
        $this->postJson('/api/broadcasting/auth', [...$payload, 'channel_name' => 'private-foreign-channel'])->assertForbidden();

        $authorize = Broadcast::getChannels()->get('lager.updates');
        self::assertTrue($authorize($this->actor()));
        self::assertFalse($authorize($this->actor(false)));
        self::assertSame([], $this->broadcaster->messages);
    }

    public function test_marking_notifications_read_also_broadcasts_a_generic_update(): void
    {
        $actor = $this->actor();
        $role = new Role(['name' => 'viewer']);
        $role->setRelation('permissions', collect([new Permission(['code' => 'notifications.view'])]));
        $actor->setRelation('role', $role);
        $service = $this->createMock(NotificationService::class);
        $service->expects(self::once())->method('markRead')->with($actor, str_repeat('a', 40));
        $this->app->instance(NotificationService::class, $service);

        $this->actingAs($actor, 'sanctum')->postJson('/api/notifications/read', ['key' => str_repeat('a', 40)])->assertOk();

        self::assertCount(1, $this->broadcaster->messages);
        self::assertSame('data.changed', $this->broadcaster->messages[0]['event']);
        self::assertSame(['socket' => null], $this->broadcaster->messages[0]['payload']);
    }

    private function actor(bool $active = true): User
    {
        $actor = new User(['active' => $active]);
        $actor->setAttribute('id', 1);

        return $actor;
    }
}

class RecordingUpdateBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public array $messages = [];

    public bool $fail = false;

    public function auth($request)
    {
        return $this->verifyUserCanAccessChannel($request, $this->normalizeChannelName($request->channel_name));
    }

    public function validAuthenticationResponse($request, $result)
    {
        return ['auth' => 'authorized'];
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if ($this->fail) {
            throw new RuntimeException('Broadcast unavailable.');
        }
        $this->messages[] = [
            'channels' => array_map(strval(...), $channels), 'event' => $event, 'payload' => $payload,
            'transaction_level' => DB::transactionLevel(), 'committed_records' => DB::table('realtime_records')->count(),
        ];
    }
}
