<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Movement;
use App\Models\MovementCorrection;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MovementReversalApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'audit_logs', 'movement_corrections', 'movements', 'stock_lots', 'products',
            'role_permissions', 'permissions', 'users', 'roles', 'branches',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_branch_can_reverse_its_own_consumption_once_and_cannot_reverse_another_branch_movement(): void
    {
        Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'MOV-001', 'name' => 'Movement correction product', 'unit' => 'հատ',
            'purchase_price' => 100, 'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        $lot = $this->lot($product, (int) $branch->id, 'EREB-LOT', 3);
        $otherLot = $this->lot($product, (int) $otherBranch->id, 'GYUM-LOT', 7);
        $original = $this->consumption($product, $lot, (int) $branch->id, 2, 'EREB-OUT-001');
        $foreign = $this->consumption($product, $otherLot, (int) $otherBranch->id, 4, 'GYUM-OUT-001');
        $actor = $this->branchUser($branch, 10);
        $this->actingAs($actor, 'sanctum');

        $this->postJson("/api/movements/{$original->id}/reverse", ['reason' => 'Սխալ ելքի փաստաթղթի ուղղում'])
            ->assertOk()
            ->assertJsonPath('data.movement_no', $original->movement_no)
            ->assertJsonPath('data.lot_qty', 5);

        self::assertEquals(5.0, (float) $lot->fresh()->qty);
        self::assertSame('consumption', $original->fresh()->type);
        self::assertSame('movement_reversal', Movement::query()->where('type', 'movement_reversal')->sole()->type);
        self::assertSame((int) $branch->id, (int) Movement::query()->where('type', 'movement_reversal')->sole()->to_location);
        self::assertSame(1, MovementCorrection::query()->where('movement_id', $original->id)->count());

        $this->postJson("/api/movements/{$original->id}/reverse", ['reason' => 'Երկրորդ ուղղման փորձ'])
            ->assertStatus(409);
        $this->postJson("/api/movements/{$foreign->id}/reverse", ['reason' => 'Այլ մասնաճյուղի ուղղման փորձ'])
            ->assertForbidden();

        self::assertEquals(7.0, (float) $otherLot->fresh()->qty);
        self::assertSame(3, Movement::query()->count());
        self::assertSame(1, MovementCorrection::query()->count());
    }

    private function lot(Product $product, int $location, string $lotNo, float $quantity): StockLot
    {
        return StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => $location, 'lot_no' => $lotNo,
            'expires_on' => now()->addMonths(6)->toDateString(), 'received_on' => now()->subDays(2)->toDateString(),
            'unit_cost' => 100, 'qty' => $quantity,
        ]);
    }

    private function consumption(Product $product, StockLot $lot, int $location, float $quantity, string $number): Movement
    {
        return Movement::query()->create([
            'movement_no' => $number, 'type' => 'consumption', 'product_id' => $product->id, 'lot_id' => $lot->id,
            'from_location' => $location, 'to_location' => null, 'qty' => $quantity, 'unit_cost' => 100,
            'reference' => $number, 'reason' => 'Test consumption', 'actor_id' => 10, 'happened_at' => now(),
        ]);
    }

    private function branchUser(Branch $branch, int $id): User
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $permission = Permission::query()->create([
            'code' => 'movements.edit', 'title' => 'Շարժերը խմբագրել', 'module' => 'movements',
        ]);
        $role->setRelation('permissions', collect([$permission]));

        $user = new User(['name' => 'Branch user', 'email' => 'branch@example.test', 'active' => true, 'branch_id' => $branch->id]);
        $user->setAttribute('id', $id);
        $user->setRelation('branch', $branch);
        $user->setRelation('role', $role);

        return $user;
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
            $table->string('name');
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
            $table->string('password')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->boolean('lot_control')->default(false);
            $table->boolean('expiry_control')->default(false);
            $table->boolean('active')->default(true);
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('qty', 12, 3)->default(0);
        });
        Schema::create('movements', function (Blueprint $table): void {
            $table->id();
            $table->string('movement_no');
            $table->string('type');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('from_location')->nullable();
            $table->unsignedBigInteger('to_location')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->string('reference');
            $table->text('reason');
            $table->unsignedBigInteger('actor_id');
            $table->timestamp('happened_at');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('movement_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('movement_id')->unique();
            $table->string('correction_no')->unique();
            $table->text('reason');
            $table->unsignedBigInteger('actor_id');
            $table->timestamp('created_at');
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
