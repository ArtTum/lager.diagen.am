<?php

namespace Tests\Unit;

use App\Models\User;
use App\Repositories\ReportRepository;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
use Tests\TestCase;

class ReportExportProjectionTest extends TestCase
{
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
