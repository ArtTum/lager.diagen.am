<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Movement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Repositories\MovementRepository;
use App\Repositories\StockRepository;
use App\Repositories\TransferRepository;
use App\Services\MovementService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MovementExportTest extends TestCase
{
    public function test_movement_export_applies_the_combined_filters_without_leaking_other_locations(): void
    {
        $this->createTables();

        try {
            $central = Branch::query()->create(['id' => 1, 'name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
            $branch = Branch::query()->create(['id' => 2, 'name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
            $otherBranch = Branch::query()->create(['id' => 3, 'name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
            Category::query()->create(['id' => 1, 'name' => 'Medical']);
            Category::query()->create(['id' => 2, 'name' => 'Office']);
            Product::query()->create(['id' => 1, 'code' => 'MOV-001', 'name' => 'Target product', 'category_id' => 1, 'unit' => 'հատ']);
            Product::query()->create(['id' => 2, 'code' => 'MOV-002', 'name' => 'Other product', 'category_id' => 1, 'unit' => 'հատ']);
            Product::query()->create(['id' => 3, 'code' => 'MOV-003', 'name' => 'Other category product', 'category_id' => 2, 'unit' => 'հատ']);
            Product::query()->create(['id' => 4, 'code' => 'MOV-004', 'name' => 'Foreign product', 'category_id' => 2, 'unit' => 'հատ']);
            Supplier::query()->create(['id' => 1, 'name' => 'Target supplier']);
            Supplier::query()->create(['id' => 2, 'name' => 'Other supplier']);
            Supplier::query()->create(['id' => 3, 'name' => 'Foreign supplier']);
            User::query()->forceCreate(['id' => 10, 'name' => 'Target actor', 'active' => true]);
            User::query()->forceCreate(['id' => 20, 'name' => 'Other actor', 'active' => true]);
            User::query()->forceCreate(['id' => 30, 'name' => 'Foreign actor', 'active' => true]);
            StockLot::query()->create(['id' => 1, 'product_id' => 1, 'location_id' => 2, 'lot_no' => 'TARGET-LOT', 'supplier_id' => 1]);
            StockLot::query()->create(['id' => 2, 'product_id' => 1, 'location_id' => 2, 'lot_no' => 'OTHER-LOT', 'supplier_id' => 1]);
            StockLot::query()->create(['id' => 3, 'product_id' => 1, 'location_id' => 2, 'lot_no' => 'TARGET-LOT-SUPPLIER', 'supplier_id' => 2]);
            StockLot::query()->create(['id' => 4, 'product_id' => 2, 'location_id' => 2, 'lot_no' => 'TARGET-LOT-PRODUCT', 'supplier_id' => 1]);
            StockLot::query()->create(['id' => 5, 'product_id' => 3, 'location_id' => 2, 'lot_no' => 'TARGET-LOT-CATEGORY', 'supplier_id' => 1]);
            StockLot::query()->create(['id' => 6, 'product_id' => 4, 'location_id' => 3, 'lot_no' => 'FOREIGN-LOT', 'supplier_id' => 3]);

            $this->movement(1, 'receipt', null, 2, 1, 'TARGET-REF-NEEDLE', ['happened_at' => '2026-09-15 10:00:00', 'reason' => 'needle match']);
            $this->movement(2, 'receipt', null, 2, 1, 'TOO-EARLY', ['happened_at' => '2026-08-31 10:00:00', 'reason' => 'needle match']);
            $this->movement(3, 'receipt', null, 2, 1, 'TOO-LATE', ['happened_at' => '2026-10-01 10:00:00', 'reason' => 'needle match']);
            $this->movement(4, 'receipt', null, 2, 4, 'OTHER-PRODUCT', ['product_id' => 2, 'reason' => 'needle match']);
            $this->movement(5, 'receipt', null, 2, 5, 'OTHER-CATEGORY', ['product_id' => 3, 'reason' => 'needle match']);
            $this->movement(6, 'receipt', null, 2, 3, 'OTHER-SUPPLIER', ['reason' => 'needle match']);
            $this->movement(7, 'receipt', null, 2, 1, 'OTHER-ACTOR', ['actor_id' => 20, 'reason' => 'needle match']);
            $this->movement(8, 'consumption', 2, null, 1, 'OTHER-TYPE', ['reason' => 'needle match']);
            $this->movement(9, 'receipt', null, 2, 2, 'OTHER-LOT', ['reason' => 'needle match']);
            $this->movement(10, 'receipt', null, 3, 1, 'OTHER-BRANCH', ['reason' => 'needle match']);
            $this->movement(11, 'receipt', null, 2, 1, 'NO-SEARCH-MATCH', ['reason' => 'different phrase']);
            $this->movement(12, 'receipt', null, 3, 6, 'FOREIGN-ONLY', ['product_id' => 4, 'actor_id' => 30]);

            $actor = new User(['id' => 10, 'branch_id' => 2, 'active' => true]);
            $actor->setRelation('branch', $branch);
            $branchRole = new Role(['name' => 'branch']);
            $branchRole->setRelation('permissions', collect([new Permission(['code' => 'movements.view'])]));
            $actor->setRelation('role', $branchRole);
            $index = $this->service()->index($actor, ['per_page' => 100]);
            self::assertSame([1, 2, 3], $index['filters']['products']->pluck('id')->sort()->values()->all());
            self::assertSame([1, 2], $index['filters']['categories']->pluck('id')->sort()->values()->all());
            self::assertSame([], $index['filters']['suppliers']->all());
            self::assertSame([10, 20], $index['filters']['users']->pluck('id')->sort()->values()->all());
            self::assertSame([2], $index['filters']['branches']->pluck('id')->all());
            self::assertTrue(collect($index['data'])->every(static fn (Movement $row): bool => $row->supplier === null && $row->supplier_id === null));
            $export = $this->service()->export($actor, [
                'from' => '2026-09-01', 'to' => '2026-09-30', 'product_id' => 1, 'category_id' => 1,
                'supplier_id' => 1, 'actor_id' => 10, 'type' => 'receipt', 'lot' => 'TARGET-LOT',
                'branch_id' => 2, 'search' => 'NEEDLE',
            ]);

            self::assertSame(['OTHER-SUPPLIER', 'TARGET-REF-NEEDLE'], $export['query']->pluck('reference')->all());
            self::assertFalse($export['show_cost']);
            self::assertFalse($export['show_supplier']);
            self::assertNotContains('Միավորի գին', $export['headers']);
            self::assertNotContains('Մատակարար', $export['headers']);
            self::assertCount(10, $this->service()->export($actor, ['supplier_id' => 2])['query']->get());

            $centralActor = new User(['id' => 11, 'branch_id' => 1, 'active' => true]);
            $centralActor->setRelation('branch', $central);
            $centralRole = new Role(['name' => 'finance']);
            $centralRole->setRelation('permissions', collect([
                new Permission(['code' => 'movements.view']),
                new Permission(['code' => 'purchases.view']),
                new Permission(['code' => 'suppliers.view']),
            ]));
            $centralActor->setRelation('role', $centralRole);
            $centralExport = $this->service()->export($centralActor, [
                'from' => '2026-09-01', 'to' => '2026-09-30', 'product_id' => 1, 'category_id' => 1,
                'supplier_id' => 1, 'actor_id' => 10, 'type' => 'receipt', 'lot' => 'TARGET-LOT',
                'branch_id' => 2, 'search' => 'NEEDLE',
            ]);
            self::assertSame(['TARGET-REF-NEEDLE'], $centralExport['query']->pluck('reference')->all());
            self::assertTrue($centralExport['show_supplier']);
            self::assertSame(['OTHER-SUPPLIER'], $this->service()->export($centralActor, ['supplier_id' => 2])['query']->pluck('reference')->all());
        } finally {
            foreach (['movement_corrections', 'movements', 'stock_lots', 'products', 'categories', 'suppliers', 'users', 'branches'] as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    public function test_movement_export_reuses_branch_scope_and_hides_costs_without_permission(): void
    {
        $this->createTables();

        try {
            $central = Branch::query()->create(['id' => 1, 'name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
            $branch = Branch::query()->create(['id' => 2, 'name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
            Branch::query()->create(['id' => 3, 'name' => 'Shengavit', 'code' => 'SHENG', 'active' => true]);
            Category::query()->create(['id' => 1, 'name' => 'Medical']);
            Product::query()->create(['id' => 1, 'code' => 'EXP-001', 'name' => 'Test product', 'category_id' => 1, 'unit' => 'հատ']);
            Supplier::query()->create(['id' => 1, 'name' => 'Test supplier']);
            StockLot::query()->create(['id' => 1, 'product_id' => 1, 'location_id' => 2, 'lot_no' => 'LOT-2', 'supplier_id' => 1]);
            User::query()->create(['id' => 10, 'name' => 'Erebuni user']);
            $this->movement(1, 'receipt', null, 2, 1, 'IN-1');
            $this->movement(2, 'consumption', 2, null, 1, 'OUT-1');
            $this->movement(3, 'receipt', null, 3, 1, 'OTHER-BRANCH');
            $this->movement(4, 'receipt', null, 0, 1, 'CENTRAL');

            $role = new Role(['name' => 'branch']);
            $role->setRelation('permissions', collect([new Permission(['code' => 'movements.view'])]));
            $actor = new User(['id' => 10, 'branch_id' => 2, 'active' => true]);
            $actor->setRelation('branch', $branch);
            $actor->setRelation('role', $role);

            $export = $this->service()->export($actor, ['from' => '2026-09-01', 'to' => '2026-09-30']);
            $rows = $export['query']->get();

            self::assertFalse($export['show_cost']);
            self::assertNotContains('Միավորի գին', $export['headers']);
            self::assertSame(['IN-1', 'OUT-1'], $rows->pluck('reference')->sort()->values()->all());
            self::assertSame(2, $rows->count());

            $centralRole = new Role(['name' => 'finance']);
            $centralRole->setRelation('permissions', collect([new Permission(['code' => 'movements.view']), new Permission(['code' => 'purchases.view'])]));
            $centralActor = new User(['id' => 11, 'branch_id' => 1, 'active' => true]);
            $centralActor->setRelation('branch', $central);
            $centralActor->setRelation('role', $centralRole);
            $centralExport = $this->service()->export($centralActor, ['type' => 'receipt']);

            self::assertTrue($centralExport['show_cost']);
            self::assertContains('Միավորի գին', $centralExport['headers']);
            self::assertSame(3, $centralExport['query']->count());
            $centralReceipt = $centralExport['query']->get()->firstWhere('reference', 'CENTRAL');
            self::assertSame('Դրսից', $centralReceipt->from_branch);
            self::assertSame('Կենտրոնական պահեստ', $centralReceipt->to_branch);
        } finally {
            foreach (['movement_corrections', 'movements', 'stock_lots', 'products', 'categories', 'suppliers', 'users', 'branches'] as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    private function service(): MovementService
    {
        return new MovementService(new MovementRepository, new StockRepository, new TransferRepository);
    }

    private function movement(int $id, string $type, ?int $from, ?int $to, int $lot, string $reference, array $overrides = []): void
    {
        Movement::query()->create([
            'id' => $id, 'movement_no' => 'MOVE-'.$id, 'type' => $type, 'product_id' => 1, 'lot_id' => $lot,
            'from_location' => $from, 'to_location' => $to, 'qty' => 1, 'unit_cost' => 25,
            'reference' => $reference, 'reason' => 'QA test', 'actor_id' => 10,
            'happened_at' => '2026-09-15 10:00:00', 'created_at' => '2026-09-15 10:00:00', ...$overrides,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('branches', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active');
        });
        Schema::create('categories', function ($table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', function ($table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('unit');
            $table->boolean('active')->default(true);
        });
        Schema::create('suppliers', function ($table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('stock_lots', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->unsignedBigInteger('supplier_id')->nullable();
        });
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
        });
        Schema::create('movements', function ($table): void {
            $table->id();
            $table->string('movement_no');
            $table->string('type');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('from_location')->nullable();
            $table->unsignedBigInteger('to_location')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->string('reference')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->dateTime('happened_at');
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('movement_corrections', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('movement_id');
            $table->string('correction_no');
        });
    }
}
