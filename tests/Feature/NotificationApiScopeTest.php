<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationApiScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['audit_logs', 'stock_requests', 'user_notification_reads', 'transfers', 'stock_lots', 'products', 'role_permissions', 'permissions', 'users', 'roles', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_branch_notification_feed_is_location_scoped_and_read_state_is_per_user(): void
    {
        $erebuni = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $gyumri = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        $erebuniProduct = $this->product('EREB-LOW', 'Erebuni low stock');
        $gyumriProduct = $this->product('GYUM-LOW', 'Gyumri low stock');
        $this->lot($erebuniProduct, (int) $erebuni->id, 2);
        $this->lot($erebuniProduct, (int) $gyumri->id, 7);
        $this->lot($gyumriProduct, (int) $erebuni->id, 7);
        $this->lot($gyumriProduct, (int) $gyumri->id, 3);
        $erebuniReader = $this->user($erebuni, 'erebuni-reader@example.test');
        $erebuniColleague = $this->user($erebuni, 'erebuni-colleague@example.test');
        $gyumriReader = $this->user($gyumri, 'gyumri-reader@example.test');

        $erebuniFeed = $this->actingAs($erebuniReader, 'sanctum')->getJson('/api/notifications')->assertOk();
        $erebuniFeed->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.detail', 'EREB-LOW · Erebuni low stock · մնացորդ՝ 2 / MIN 5.000')
            ->assertJsonPath('unread_count', 1);
        $noticeKey = (string) $erebuniFeed->json('data.0.key');

        $this->postJson('/api/notifications/read', ['key' => $noticeKey])->assertOk();
        $this->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.0.read', true)
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($erebuniColleague, 'sanctum')->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.0.key', $noticeKey)
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('unread_count', 1);

        $this->actingAs($gyumriReader, 'sanctum')->getJson('/api/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.detail', 'GYUM-LOW · Gyumri low stock · մնացորդ՝ 3 / MIN 5.000')
            ->assertJsonPath('unread_count', 1);

        self::assertDatabaseHas('user_notification_reads', ['user_id' => $erebuniReader->id, 'notice_key' => $noticeKey]);
        self::assertDatabaseMissing('user_notification_reads', ['user_id' => $erebuniColleague->id, 'notice_key' => $noticeKey]);
        self::assertDatabaseMissing('user_notification_reads', ['user_id' => $gyumriReader->id, 'notice_key' => $noticeKey]);
    }

    public function test_expiry_notification_uses_the_products_measurement_unit(): void
    {
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'REA-310', 'name' => 'Liquid reagent', 'unit' => 'լիտր', 'active' => true,
            'expiry_control' => true, 'min_qty' => 0, 'max_qty' => 0,
        ]);
        StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => $branch->id,
            'lot_no' => 'LIQUID-LOT', 'received_on' => now()->toDateString(),
            'expires_on' => now()->addDays(30)->toDateString(), 'qty' => 15,
        ]);
        $actor = $this->user($branch, 'expiry-reader@example.test');
        $permission = Permission::query()->create(['code' => 'expiry.view', 'title' => 'View expiry', 'module' => 'expiry']);
        $actor->role->permissions()->attach($permission);

        $this->actingAs($actor, 'sanctum')->getJson('/api/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.detail', 'REA-310 · Liquid reagent · LOT LIQUID-LOT · Erebuni · 15.000 լիտր')
            ->assertJsonPath('data.0.link', '/expiry');
    }

    public function test_central_incoming_transfer_notifications_use_the_central_branch_id_and_keep_receiver_scope(): void
    {
        $source = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $other = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        foreach ([
            ['CENTRAL-INCOMING', $central->id, 'shipped'],
            ['BRANCH-INCOMING', $other->id, 'shipped'],
            ['CENTRAL-PENDING', $central->id, 'pending'],
            ['CENTRAL-COMPLETED', $central->id, 'completed'],
        ] as [$number, $destination, $status]) {
            Transfer::query()->create([
                'transfer_no' => $number, 'from_branch' => $source->id, 'to_branch' => $destination, 'status' => $status,
            ]);
        }
        $centralActor = $this->user($central, 'central-receiver@example.test', ['notifications.view', 'transfers.view'], 'receiver');
        self::assertSame(0, $centralActor->currentLocationId());
        self::assertNotSame(0, (int) $centralActor->branch_id);

        $this->actingAs($centralActor, 'sanctum')->getJson('/api/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.detail', 'CENTRAL-INCOMING · ուղարկել է Erebuni')
            ->assertJsonPath('data.0.link', '/transfers')
            ->assertJsonPath('unread_count', 1);

        $branchActor = $this->user($other, 'branch-receiver@example.test', ['notifications.view', 'transfers.view'], 'receiver');
        $branchFeed = $this->actingAs($branchActor, 'sanctum')->getJson('/api/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.detail', 'BRANCH-INCOMING · ուղարկել է Erebuni');
        $this->actingAs($centralActor, 'sanctum')->postJson('/api/notifications/read', ['key' => $branchFeed->json('data.0.key')])
            ->assertUnprocessable()->assertJsonValidationErrors('key');

        $centralViewer = $this->user($central, 'central-viewer@example.test', ['notifications.view'], 'viewer');
        $this->actingAs($centralViewer, 'sanctum')->getJson('/api/notifications')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
    }

    private function product(string $code, string $name): Product
    {
        return Product::query()->create([
            'code' => $code, 'name' => $name, 'unit' => 'հատ', 'active' => true,
            'expiry_control' => false, 'min_qty' => 5, 'max_qty' => 10,
        ]);
    }

    public function test_request_notifications_flow_both_ways_keep_every_transition_and_scope_read_state(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $erebuni = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $other = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        $permissions = ['notifications.view', 'requests.view'];
        $centralActor = $this->user($central, 'central-actor@example.test', $permissions, 'central-actor');
        $storekeeper = $this->user($central, 'storekeeper@example.test', $permissions, 'storekeeper');
        $branchActor = $this->user($erebuni, 'branch-actor@example.test', $permissions, 'branch');
        $branchColleague = $this->user($erebuni, 'colleague@example.test', $permissions, 'branch');
        $otherActor = $this->user($other, 'other@example.test', $permissions, 'branch');
        $request = StockRequest::query()->create(['request_no' => 'EREB-CLOSED', 'branch_id' => $erebuni->id, 'requested_by' => $branchActor->id, 'status' => 'closed', 'created_at' => now()]);
        $previous = 'draft';
        $statuses = ['sent', 'review', 'approved', 'collecting', 'ready_to_ship', 'shipped', 'received', 'closed'];
        foreach ($statuses as $i => $status) {
            AuditLog::query()->create(['actor_id' => in_array($status, ['sent', 'received', 'closed'], true) ? $branchActor->id : $centralActor->id,
                'entity' => 'stock_requests', 'entity_id' => $request->id, 'before_data' => ['status' => $previous],
                'after_data' => ['status' => $status], 'created_at' => now()->subMinutes(10 - $i)]);
            $previous = $status;
        }
        $centralFeed = $this->actingAs($storekeeper, 'sanctum')->getJson('/api/notifications')->assertOk();
        $centralFeed->assertJsonCount(8, 'data')->assertJsonPath('data.0.title', 'Պահանջագիրը փակվել է');
        self::assertContains('Պահանջագրի ստացումը հաստատվել է', array_column($centralFeed->json('data'), 'title'));
        $branchFeed = $this->actingAs($branchActor, 'sanctum')->getJson('/api/notifications')->assertOk();
        $branchFeed->assertJsonCount(5, 'data')->assertJsonPath('data.0.title', 'Պահանջագիրն ուղարկվել է');
        $approval = collect($branchFeed->json('data'))->firstWhere('title', 'Պահանջագիրը հաստատվել է');
        self::assertNotNull($approval, 'A later closed state must not erase approval history.');
        $this->postJson('/api/notifications/read', ['key' => $approval['key']])->assertOk();
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 4);
        $this->actingAs($branchColleague, 'sanctum')->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 8);
        $this->actingAs($otherActor, 'sanctum')->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/notifications/read', ['key' => $approval['key']])->assertUnprocessable();

        // A central-created request must notify its destination branch.
        $fromCentral = StockRequest::query()->create(['request_no' => 'CENTRAL-TO-EREB', 'branch_id' => $erebuni->id, 'requested_by' => $centralActor->id, 'status' => 'sent', 'created_at' => now()]);
        AuditLog::query()->create(['actor_id' => $centralActor->id, 'entity' => 'stock_requests', 'entity_id' => $fromCentral->id,
            'after_data' => ['status' => 'sent'], 'created_at' => now()]);
        $this->actingAs($branchActor, 'sanctum')->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.0.title', 'Նոր պահանջագիր Ձեր մասնաճյուղի համար')
            ->assertJsonPath('data.0.detail', 'CENTRAL-TO-EREB · Erebuni · Սպասում է ստուգման');
        $this->actingAs($storekeeper, 'sanctum')->getJson('/api/notifications')->assertOk()->assertJsonCount(9, 'data');

        // Draft saves/edits, cancelled drafts and expired event history are silent.
        $draft = StockRequest::query()->create(['request_no' => 'PRIVATE-DRAFT', 'branch_id' => $erebuni->id, 'status' => 'cancelled', 'created_at' => now()]);
        foreach ([[null, 'draft', now()], ['draft', 'draft', now()], ['draft', 'cancelled', now()], ['review', 'rejected', now()->subDays(8)]] as [$before, $after, $when]) {
            AuditLog::query()->create(['actor_id' => $centralActor->id, 'entity' => 'stock_requests', 'entity_id' => $draft->id,
                'before_data' => $before ? ['status' => $before] : null, 'after_data' => ['status' => $after], 'created_at' => $when]);
        }
        $this->actingAs($branchActor, 'sanctum')->getJson('/api/notifications')->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_read_sent_event_stays_read_when_it_ages_into_an_active_reminder(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $viewer = $this->user($central, 'aging-viewer@example.test', ['notifications.view', 'requests.view'], 'viewer');
        $request = StockRequest::query()->create(['request_no' => 'AGING-SENT', 'branch_id' => $branch->id, 'status' => 'sent', 'created_at' => now()->subDays(6)]);
        $event = AuditLog::query()->create(['actor_id' => null, 'entity' => 'stock_requests', 'entity_id' => $request->id,
            'after_data' => ['status' => 'sent'], 'created_at' => now()->subDays(6)]);
        $feed = $this->actingAs($viewer, 'sanctum')->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/notifications/read', ['key' => $feed->json('data.0.key')])->assertOk();
        $event->update(['created_at' => now()->subDays(8)]);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 0);
    }

    public function test_a_recent_transfer_reminder_precedes_older_request_history(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $viewer = $this->user($central, 'mixed-viewer@example.test', ['notifications.view', 'requests.view', 'transfers.view'], 'viewer');
        $request = StockRequest::query()->create(['request_no' => 'OLD-APPROVAL', 'branch_id' => $branch->id, 'status' => 'closed', 'created_at' => now()->subDays(2)]);
        AuditLog::query()->create(['entity' => 'stock_requests', 'entity_id' => $request->id, 'after_data' => ['status' => 'approved'], 'created_at' => now()->subDay()]);
        $transfer = Transfer::query()->create(['transfer_no' => 'NEW-INCOMING', 'from_branch' => $branch->id, 'to_branch' => $central->id, 'status' => 'shipped', 'created_at' => now()->subDays(3)]);
        AuditLog::query()->create(['entity' => 'transfers', 'entity_id' => $transfer->id, 'after_data' => ['status' => 'shipped'], 'created_at' => now()]);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/notifications')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.link', '/transfers')->assertJsonPath('data.1.link', '/requests');
    }

    private function lot(Product $product, int $locationId, float $qty): StockLot
    {
        return StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => $locationId,
            'lot_no' => $product->code.'-LOT', 'received_on' => now()->toDateString(), 'qty' => $qty,
        ]);
    }

    private function user(Branch $branch, string $email, array $permissionCodes = ['notifications.view', 'stock.view'], string $roleName = 'branch'): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName], ['title' => 'Մասնաճյուղի պատասխանատու']);
        foreach ($permissionCodes as $code) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $code],
                ['title' => $code, 'module' => explode('.', $code)[0]],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'test-password',
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'active' => true,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('title');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('module');
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id');
            $table->boolean('active')->default(true);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->boolean('active')->default(true);
            $table->boolean('expiry_control')->default(false);
            $table->decimal('min_qty', 12, 3)->default(0);
            $table->decimal('max_qty', 12, 3)->default(0);
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->date('received_on');
            $table->date('expires_on')->nullable();
            $table->decimal('qty', 12, 3);
        });
        Schema::create('user_notification_reads', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->char('notice_key', 40);
            $table->timestamp('read_at');
            $table->primary(['user_id', 'notice_key']);
        });
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no');
            $table->unsignedBigInteger('from_branch');
            $table->unsignedBigInteger('to_branch');
            $table->string('status');
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('stock_requests', function (Blueprint $table): void {
            $table->id(); $table->string('request_no'); $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('requested_by')->nullable(); $table->string('status');
            $table->text('rejection_reason')->nullable(); $table->dateTime('created_at'); $table->dateTime('sent_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('actor_id')->nullable(); $table->string('action')->nullable();
            $table->string('entity'); $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before_data')->nullable(); $table->json('after_data')->nullable();
            $table->string('ip_address')->nullable(); $table->dateTime('created_at');
        });
    }
}
