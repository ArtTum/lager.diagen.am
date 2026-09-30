<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\ReportRepository;
use App\Services\ReportService;
use App\Services\TabularExportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportRepositoryCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active')->default(true);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->string('lot_no')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('movements', function (Blueprint $table): void {
            $table->id();
            $table->string('movement_no')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('type');
            $table->text('reason')->nullable();
            $table->string('reference')->nullable();
            $table->dateTime('happened_at');
            $table->unsignedBigInteger('from_location')->nullable();
            $table->unsignedBigInteger('to_location')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 2);
        });

        DB::table('branches')->insert([
            ['id' => 7, 'name' => 'Էրեբունի', 'code' => 'EREB'],
            ['id' => 8, 'name' => 'Գյումրի', 'code' => 'GYUM'],
        ]);
        DB::table('categories')->insert(['id' => 4, 'name' => 'Ռեագենտներ']);
        DB::table('products')->insert(['id' => 11, 'code' => 'DIS-055', 'name' => 'Ապրանք', 'unit' => 'հատ', 'category_id' => 4]);
        DB::table('users')->insert([
            ['id' => 20, 'name' => 'Անի', 'branch_id' => 7],
            ['id' => 21, 'name' => 'Արամ', 'branch_id' => 8],
        ]);
        DB::table('suppliers')->insert([
            ['id' => 30, 'name' => 'Թիրախ մատակարար'],
            ['id' => 31, 'name' => 'Այլ մատակարար'],
        ]);
        $targetLot = DB::table('stock_lots')->insertGetId(['lot_no' => 'TARGET-LOT', 'supplier_id' => 30]);
        DB::table('stock_lots')->insertGetId(['lot_no' => 'OTHER-LOT', 'supplier_id' => 31]);
        DB::table('movements')->insert([
            ['movement_no' => 'MOV-001', 'product_id' => 11, 'lot_id' => $targetLot, 'actor_id' => 20, 'type' => 'consumption', 'reason' => 'Ներքին օգտագործում', 'happened_at' => now()->subDays(2), 'from_location' => 7, 'qty' => 4, 'unit_cost' => 25],
            ['movement_no' => 'MOV-002', 'product_id' => 11, 'lot_id' => $targetLot, 'actor_id' => 21, 'type' => 'consumption', 'reason' => 'Ներքին օգտագործում', 'happened_at' => now()->subDays(2), 'from_location' => 0, 'qty' => 100, 'unit_cost' => 25],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('movements');
        Schema::dropIfExists('users');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('branches');

        parent::tearDown();
    }

    public function test_branch_consumption_reports_match_legacy_branch_only_scope(): void
    {
        $reports = new ReportRepository;

        $expense = $reports->query('branch_expense', now()->subDays(3)->toDateString(), now()->toDateString(), null)->get();
        $average = $reports->query('average_usage', '', '', null)->get();

        self::assertCount(1, $expense);
        self::assertSame('Էրեբունի', $expense[0]->branch_name);
        self::assertEquals(4.0, (float) $expense[0]->used_qty);
        self::assertCount(1, $average);
        self::assertSame('Էրեբունի', $average[0]->branch_name);
        self::assertEquals(4.0, (float) $average[0]->consumed);
    }

    public function test_movement_report_applies_employee_and_category_filters(): void
    {
        $reports = new ReportRepository;
        $rows = $reports->query('movements', now()->subDays(3)->toDateString(), now()->toDateString(), null, [
            'category_id' => 4,
            'actor_id' => 20,
        ])->get();

        self::assertCount(1, $rows);
        self::assertSame('MOV-001', $rows[0]->document_no);
        self::assertSame('Անի', $rows[0]->actor);
    }

    public function test_branch_report_filter_options_only_expose_that_branch_employees(): void
    {
        $options = (new ReportRepository)->filterOptions(7, false, false);

        self::assertSame([20], $options['actors']->pluck('id')->all());
        self::assertSame([7], $options['branches']->pluck('id')->all());
    }

    public function test_report_export_applies_combined_filters_and_csv_contains_only_matching_rows(): void
    {
        $otherLotId = (int) DB::table('stock_lots')->where('lot_no', 'OTHER-LOT')->value('id');
        DB::table('movements')->insert([
            'movement_no' => 'MOV-003', 'product_id' => 11, 'lot_id' => $otherLotId, 'actor_id' => 20,
            'type' => 'consumption', 'reason' => 'Ներքին օգտագործում', 'happened_at' => now()->subDays(2),
            'from_location' => 7, 'qty' => 3, 'unit_cost' => 25,
        ]);
        $role = new Role(['name' => 'report_exporter']);
        $role->setRelation('permissions', collect([
            new Permission(['code' => 'reports.view']),
            new Permission(['code' => 'purchases.view']),
        ]));
        $actor = new User(['active' => true]);
        $actor->setRelation('role', $role);
        $report = (new ReportService(new ReportRepository))->export($actor, [
            'report_type' => 'movements',
            'from' => now()->subDays(3)->toDateString(),
            'to' => now()->toDateString(),
            'branch_id' => 7,
            'product_id' => 11,
            'category_id' => 4,
            'supplier_id' => 30,
            'actor_id' => 20,
            'lot_no' => 'TARGET-LOT',
            'movement_type' => 'consumption',
        ]);
        $records = $report['query']->get();

        self::assertSame(['MOV-001'], $records->pluck('document_no')->all());
        $rows = $records->map(function (object $record) use ($report): array {
            $attributes = $record->getAttributes();

            return array_map(static fn (string $key): mixed => $attributes[$key] ?? null, $report['column_keys']);
        });
        $response = app(TabularExportService::class)->download($report['headers'], $rows, 'csv', 'filtered-report');
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        self::assertStringContainsString('MOV-001', $csv);
        self::assertStringContainsString('Թիրախ մատակարար', $csv);
        self::assertStringNotContainsString('MOV-003', $csv);
        self::assertStringNotContainsString('MOV-002', $csv);
    }
}
