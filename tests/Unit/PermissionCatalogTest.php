<?php

namespace Tests\Unit;

use App\Support\PermissionCatalog;
use PHPUnit\Framework\TestCase;

class PermissionCatalogTest extends TestCase
{
    public function test_catalog_lists_only_actions_the_application_actually_supports(): void
    {
        $definitions = PermissionCatalog::definitions();
        $expectedCount = array_sum(array_map('count', PermissionCatalog::moduleActions()));

        $this->assertCount($expectedCount, $definitions);
        $this->assertCount($expectedCount, array_unique(array_column($definitions, 'code')));
        $this->assertSame(array_column($definitions, 'code'), PermissionCatalog::codes());

        foreach ($definitions as $permission) {
            [$module, $action] = explode('.', $permission['code'], 2);
            $this->assertArrayHasKey($module, PermissionCatalog::modules());
            $this->assertArrayHasKey($action, PermissionCatalog::actions());
            $this->assertContains($action, PermissionCatalog::moduleActions()[$module]);
            $this->assertSame($module, $permission['module']);
        }

        $this->assertFalse(PermissionCatalog::contains('dashboard.approve'));
        $this->assertFalse(PermissionCatalog::contains('reports.create'));
        $this->assertFalse(PermissionCatalog::contains('returns.edit'));
        $this->assertFalse(PermissionCatalog::contains('notifications.delete'));
    }

    public function test_catalog_lookup_rejects_unregistered_codes(): void
    {
        $this->assertTrue(PermissionCatalog::contains('stock.view'));
        $this->assertFalse(PermissionCatalog::contains('stock.adjust'));
        $this->assertFalse(PermissionCatalog::contains('made.up.permission'));
    }

    public function test_branch_and_central_restrictions_only_reference_catalogued_permissions(): void
    {
        $codes = array_column(PermissionCatalog::definitions(), 'code');

        $this->assertSame([], array_diff(PermissionCatalog::branchAllowed(), $codes));
        $this->assertSame([], array_diff(PermissionCatalog::centralOnly(), $codes));
        $this->assertSame([], array_intersect(PermissionCatalog::branchAllowed(), PermissionCatalog::centralOnly()));
        $this->assertSame([], array_diff(PermissionCatalog::branchAllowed(), [
            'dashboard.view', 'stock.view', 'stock.create', 'stock.export',
            'requests.view', 'requests.create', 'requests.edit', 'requests.export',
            'movements.view', 'movements.edit', 'movements.export',
            'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.export',
            'expiry.view', 'expiry.export', 'returns.view', 'returns.create', 'returns.export',
            'transfers.view', 'transfers.create', 'transfers.edit', 'transfers.export',
            'notifications.view', 'reports.view', 'reports.export',
        ]));
    }
}
