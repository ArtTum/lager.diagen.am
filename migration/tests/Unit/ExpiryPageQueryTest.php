<?php

namespace Tests\Unit;

use App\Repositories\PageDataRepository;
use Carbon\Carbon;
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
}
