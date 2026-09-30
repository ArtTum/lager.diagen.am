<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
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
        foreach (['user_notification_reads', 'stock_lots', 'products', 'role_permissions', 'permissions', 'users', 'roles', 'branches'] as $table) {
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

    private function product(string $code, string $name): Product
    {
        return Product::query()->create([
            'code' => $code, 'name' => $name, 'unit' => 'հատ', 'active' => true,
            'expiry_control' => false, 'min_qty' => 5, 'max_qty' => 10,
        ]);
    }

    private function lot(Product $product, int $locationId, float $qty): StockLot
    {
        return StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => $locationId,
            'lot_no' => $product->code.'-LOT', 'received_on' => now()->toDateString(), 'qty' => $qty,
        ]);
    }

    private function user(Branch $branch, string $email): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'branch'], ['title' => 'Մասնաճյուղի պատասխանատու']);
        foreach (['notifications.view', 'stock.view'] as $code) {
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
            $table->decimal('qty', 12, 3);
        });
        Schema::create('user_notification_reads', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->char('notice_key', 40);
            $table->timestamp('read_at');
            $table->primary(['user_id', 'notice_key']);
        });
    }
}
