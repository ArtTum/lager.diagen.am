<?php

namespace Tests\Unit;

use App\Models\User;
use App\Repositories\ReportRepository;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportExportProjectionTest extends TestCase
{
    #[DataProvider('reportWorkflowStatuses')]
    public function test_status_exports_use_workflow_metadata_but_report_data_keeps_raw_codes(string $type, string $workflow, array $statuses): void
    {
        $records = array_map(static fn ($status) => (object) ['document_no' => 'DOC-'.$status, 'status' => $status], $statuses);
        $query = $this->createMock(Builder::class);
        $query->method('cursor')->willReturn(new LazyCollection($records));
        $query->method('paginate')->willReturn(new LengthAwarePaginator($records, count($records), 15));
        $repository = $this->createMock(ReportRepository::class);
        $repository->method('query')->willReturn($query);
        $repository->method('stockSummary')->willReturn(['value' => 0]);
        $repository->method('filterOptions')->willReturn([]);
        $actor = $this->createMock(User::class);
        $actor->method('currentLocationId')->willReturn(0);
        $actor->method('hasPermissionCode')->willReturn(true);
        $service = new ReportService($repository);
        $catalog = json_decode(file_get_contents(resource_path('js/workflowStatuses.json')), true, 512, JSON_THROW_ON_ERROR);

        $screen = $service->data($actor, ['report_type' => $type]);
        $export = $service->export($actor, ['report_type' => $type]);
        $exported = array_map(static fn ($row) => array_combine($export['column_keys'], $row), iterator_to_array($export['rows']));

        self::assertSame($statuses, array_column($screen['data'], 'status'));
        self::assertSame(array_map(static fn ($status) => $catalog[$workflow][$status]['label'] ?? $catalog['generic'][$status]['label'] ?? $status, $statuses), array_column($exported, 'status'));
        self::assertSame(array_column($screen['data'], 'document_no'), array_column($exported, 'document_no'));
        self::assertSame($statuses, array_column($records, 'status'));
    }

    public static function reportWorkflowStatuses(): array
    {
        return [
            'supplier purchases' => ['supplier_purchases', 'purchases', ['pending', 'approved', 'completed', 'future_phase']],
            'purchases by period' => ['purchases_by_period', 'purchases', ['approved', 'cancelled']],
            'inventory differences' => ['inventory_differences', 'inventory', ['counted', 'closed', 'future_phase']],
            'branch requests' => ['branch_requests', 'requests', ['sent', 'shipped', 'closed', 'future_phase']],
            'rejected requests' => ['rejected_requests', 'requests', ['rejected']],
        ];
    }

    public function test_stock_export_matches_screen_reserved_and_free_quantities(): void
    {
        $records = [
            (object) ['code' => 'CENTRAL', 'location_id' => 0, 'quantity' => 10, 'reserved_requests' => 4, 'reserved_transfers' => 3, 'value' => 100],
            (object) ['code' => 'BRANCH', 'location_id' => 7, 'quantity' => 8, 'reserved_requests' => 6, 'reserved_transfers' => 3, 'value' => 80],
            (object) ['code' => 'SHORT', 'location_id' => 7, 'quantity' => 1, 'reserved_requests' => 0, 'reserved_transfers' => 3, 'value' => 10],
        ];
        $query = $this->createMock(Builder::class);
        $query->method('cursor')->willReturn(new LazyCollection($records));
        $query->method('paginate')->willReturn(new LengthAwarePaginator($records, 3, 15));
        $repository = $this->createMock(ReportRepository::class);
        $repository->method('query')->willReturn($query);
        $repository->method('stockSummary')->willReturn(['value' => 190]);
        $repository->method('filterOptions')->willReturn([]);
        $actor = $this->createMock(User::class);
        $actor->method('currentLocationId')->willReturn(0);
        $actor->method('hasPermissionCode')->willReturn(false);
        $service = new ReportService($repository);

        $screen = $service->data($actor, ['report_type' => 'stock_by_location']);
        $export = $service->export($actor, ['report_type' => 'stock_by_location']);
        $rows = iterator_to_array($export['rows']);

        foreach ($screen['data'] as $index => $row) {
            $exported = array_combine($export['column_keys'], $rows[$index]);
            self::assertSame($row['reserved_quantity'], $exported['reserved_quantity']);
            self::assertSame($row['free_quantity'], $exported['free_quantity']);
            self::assertArrayNotHasKey('value', $exported);
            self::assertArrayNotHasKey('location_id', $exported);
        }
        self::assertSame([7.0, 3.0, 3.0], array_column($screen['data'], 'reserved_quantity'));
        self::assertSame([3.0, 5.0, 0], array_column($screen['data'], 'free_quantity'));
    }
}
