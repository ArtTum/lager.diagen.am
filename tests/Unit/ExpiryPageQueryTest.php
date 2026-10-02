<?php

namespace Tests\Unit;

use App\Repositories\PageDataRepository;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpiryPageQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expiry_page_only_queries_products_with_expiry_control_enabled(): void
    {
        [, $query] = (new PageDataRepository)->definition('expiry', 0);

        self::assertStringContainsString('"p"."expiry_control"', $query->toSql());
        self::assertContains(true, $query->getBindings());
    }

    public function test_expiry_thresholds_use_inclusive_date_boundaries(): void
    {
        foreach ([7, 30, 60, 90, 180] as $days) {
            [, $query] = (new PageDataRepository)->definition('expiry', 0, ['threshold' => (string) $days]);
            $sql = $query->toSql();
            $bindings = $query->getBindings();

            self::assertStringContainsString(' >= ', $sql);
            self::assertStringContainsString(' <= ', $sql);
            self::assertContains('2026-09-30', $bindings);
            self::assertContains(Carbon::parse('2026-09-30')->addDays($days)->toDateString(), $bindings);
        }
    }

    public function test_expired_filter_stops_before_today_and_does_not_include_today(): void
    {
        [, $query] = (new PageDataRepository)->definition('expiry', 0, ['threshold' => 'expired']);

        self::assertStringContainsString(' < ', $query->toSql());
        self::assertContains('2026-09-30', $query->getBindings());
    }

    public function test_days_left_uses_the_same_application_date_as_expiry_filters(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('DATEDIFF', static fn (string $end, string $start): int => (int) ((strtotime($end) - strtotime($start)) / 86400));
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->boolean('expiry_control');
        });
        foreach (['branches', 'suppliers'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('lot_no');
            $table->date('expires_on');
            $table->decimal('qty', 12, 3);
        });
        try {
            DB::table('products')->insert(['id' => 1, 'code' => 'EXP', 'name' => 'Expiry', 'unit' => 'հատ', 'expiry_control' => true]);
            DB::table('stock_lots')->insert([
                'product_id' => 1, 'location_id' => 0, 'lot_no' => 'TODAY',
                'expires_on' => now()->toDateString(), 'qty' => 1,
            ]);
            [, $query] = (new PageDataRepository)->definition('expiry', 0, ['threshold' => '7']);

            self::assertSame(0, (int) $query->first()->days_left);
        } finally {
            foreach (['stock_lots', 'suppliers', 'branches', 'products'] as $name) {
                Schema::dropIfExists($name);
            }
        }
    }
}
