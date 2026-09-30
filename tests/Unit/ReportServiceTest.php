<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\ReportRepository;
use App\Services\ReportService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    public function test_every_documented_report_type_builds_a_query(): void
    {
        $repository = new ReportRepository;
        $types = ReportService::types();

        self::assertCount(19, $types);

        foreach (array_keys($types) as $type) {
            $query = $repository->query($type, '2026-09-01', '2026-09-30', null);

            self::assertNotSame('', $query->toSql(), "{$type} must produce an SQL query.");
        }
    }

    public function test_branch_actor_cannot_open_central_stock_report(): void
    {
        $actor = $this->actor(7, 2, 'EREB', ['reports.view']);

        try {
            (new ReportService(new ReportRepository))->export($actor, ['report_type' => 'central_stock']);
            self::fail('Branch staff must not access the central stock report.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_cost_reports_require_purchase_view_permission(): void
    {
        $actor = $this->actor(1, 0, 'CENTRAL', ['reports.view']);

        try {
            (new ReportService(new ReportRepository))->export($actor, ['report_type' => 'supplier_purchases']);
            self::fail('Supplier purchase reports must require purchase-view access.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_purchase_view_actor_can_export_supplier_purchase_report(): void
    {
        $actor = $this->actor(1, 0, 'CENTRAL', ['reports.view', 'purchases.view']);

        $report = (new ReportService(new ReportRepository))->export($actor, [
            'report_type' => 'supplier_purchases',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]);

        self::assertSame('supplier_purchases', $report['type']);
        self::assertContains('value', $report['column_keys']);
        self::assertNotSame('', $report['query']->toSql());
    }

    public function test_supplier_report_columns_and_filters_follow_supplier_permissions(): void
    {
        $reports = new ReportService(new ReportRepository);
        $limited = $reports->export($this->actor(1, 0, 'CENTRAL', ['reports.view']), [
            'report_type' => 'movements', 'supplier_id' => 9876,
        ]);
        self::assertNotContains('supplier', $limited['column_keys']);
        self::assertNotContains(9876, $limited['query']->getBindings());

        $supplierViewer = $reports->export($this->actor(2, 0, 'CENTRAL', ['reports.view', 'suppliers.view']), [
            'report_type' => 'movements', 'supplier_id' => 9876,
        ]);
        self::assertContains('supplier', $supplierViewer['column_keys']);
        self::assertContains(9876, $supplierViewer['query']->getBindings());
        self::assertNotContains('value', $supplierViewer['column_keys']);

        $purchaseViewer = $reports->export($this->actor(3, 0, 'CENTRAL', ['reports.view', 'purchases.view']), [
            'report_type' => 'movements',
        ]);
        self::assertContains('supplier', $purchaseViewer['column_keys']);
        self::assertContains('value', $purchaseViewer['column_keys']);
    }

    public function test_branch_role_cannot_use_a_misconfigured_supplier_grant_to_view_supplier_columns(): void
    {
        $report = (new ReportService(new ReportRepository))->export(
            $this->actor(4, 7, 'EREB', ['reports.view', 'suppliers.view']),
            ['report_type' => 'movements', 'supplier_id' => 9876],
        );

        self::assertNotContains('supplier', $report['column_keys']);
        self::assertNotContains(9876, $report['query']->getBindings());
    }

    /** @param list<string> $permissionCodes */
    private function actor(int $id, int $branchId, string $branchCode, array $permissionCodes): User
    {
        $role = new Role(['name' => 'report_test']);
        $role->setRelation('permissions', collect(array_map(
            static fn (string $code): Permission => new Permission(['code' => $code]),
            $permissionCodes,
        )));

        $actor = new User(['active' => true, 'branch_id' => $branchId]);
        $actor->setAttribute('id', $id);
        $actor->setRelation('role', $role);
        $actor->setRelation('branch', new Branch(['code' => $branchCode]));

        return $actor;
    }
}
