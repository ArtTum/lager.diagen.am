<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\InventoryLine;
use App\Models\InventorySession;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\StockRepository;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryActTest extends TestCase
{
    public function test_printable_act_requires_closed_session_and_respects_branch_scope(): void
    {
        $this->createTables();

        try {
            $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
            $otherBranch = Branch::query()->create(['name' => 'Shengavit', 'code' => 'SHENG', 'active' => true]);
            User::query()->create(['name' => 'Erebuni user']);
            User::query()->create(['name' => 'Shengavit user']);
            Product::query()->create(['code' => 'QA-INV-1', 'name' => 'Act product', 'unit' => 'հատ']);
            StockLot::query()->create(['product_id' => 1, 'location_id' => $branch->id, 'lot_no' => 'LOT-A', 'qty' => 3]);
            $session = InventorySession::query()->create([
                'inventory_no' => 'INV-QA-001', 'location_id' => $branch->id, 'status' => 'closed',
                'started_by' => 1, 'approved_by' => 2, 'started_at' => '2026-09-20 09:00:00', 'closed_at' => '2026-09-20 12:00:00',
            ]);
            InventoryLine::query()->create([
                'session_id' => $session->id, 'product_id' => 1, 'lot_id' => 1,
                'expected_qty' => 3, 'counted_qty' => 2, 'difference_reason' => 'Մեկ միավոր բացակայում է',
            ]);

            $service = new InventoryService(new InventoryRepository, new StockRepository);
            $branchUser = $this->user(20, $branch);
            $act = $service->act($branchUser, (int) $session->id);

            self::assertSame('INV-QA-001', $act['inventory_no']);
            self::assertSame('Erebuni', $act['location']);
            self::assertSame('Erebuni user', $act['starter']);
            self::assertSame('Shengavit user', $act['approver']);
            self::assertSame('LOT-A', $act['lines'][0]['lot_no']);
            self::assertSame(3.0, $act['lines'][0]['expected_qty']);
            self::assertSame(2.0, $act['lines'][0]['counted_qty']);
            self::assertSame(-1.0, $act['lines'][0]['difference']);

            try {
                $service->act($this->user(21, $otherBranch), (int) $session->id);
                self::fail('A user from another branch must not access this inventory act.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }

            $open = InventorySession::query()->create([
                'inventory_no' => 'INV-QA-002', 'location_id' => $branch->id, 'status' => 'counted',
                'started_by' => 1, 'started_at' => '2026-09-21 09:00:00',
            ]);
            try {
                $service->act($branchUser, (int) $open->id);
                self::fail('An unclosed session must not produce a printable act.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
            }
        } finally {
            foreach (['inventory_lines', 'inventory_sessions', 'stock_lots', 'products', 'branches', 'users'] as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    private function user(int $id, Branch $branch): User
    {
        $user = new User(['active' => true, 'branch_id' => $branch->id]);
        $user->setAttribute('id', $id);
        $user->setRelation('branch', $branch);
        $user->setRelation('role', new Role(['name' => 'branch']));

        return $user;
    }

    private function createTables(): void
    {
        Schema::create('branches', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active');
        });
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', function ($table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
        });
        Schema::create('stock_lots', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->decimal('qty', 12, 3);
        });
        Schema::create('inventory_sessions', function ($table): void {
            $table->id();
            $table->string('inventory_no');
            $table->unsignedBigInteger('location_id');
            $table->string('status');
            $table->unsignedBigInteger('started_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('closed_at')->nullable();
            $table->text('note')->nullable();
        });
        Schema::create('inventory_lines', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->decimal('expected_qty', 12, 3);
            $table->decimal('counted_qty', 12, 3)->nullable();
            $table->string('difference_reason')->nullable();
        });
    }
}
