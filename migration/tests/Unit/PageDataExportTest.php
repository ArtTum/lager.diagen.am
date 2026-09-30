<?php

namespace Tests\Unit;

use App\Models\User;
use App\Repositories\PageDataRepository;
use App\Services\PageDataService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PageDataExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('page_export_fixtures', function ($table): void {
            $table->id();
            $table->string('name');
            $table->text('before_data')->nullable();
            $table->text('after_data')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('page_export_fixtures');
        parent::tearDown();
    }

    public function test_export_applies_the_same_search_filter_and_column_order(): void
    {
        ExportFixtureRow::query()->create(['name' => 'Alpha']);
        ExportFixtureRow::query()->create(['name' => 'Beta']);
        $service = new PageDataService($this->repository(['name' => 'Անուն'], ['name']));

        $export = $service->export('products', 0, ['search' => 'alp'], $this->actor());

        self::assertSame(['Անուն'], array_values($export['columns']));
        self::assertSame([['Alpha']], iterator_to_array($export['rows']));
    }

    public function test_audit_export_redacts_sensitive_values_without_cost_permission(): void
    {
        ExportFixtureRow::query()->create([
            'name' => 'Audit entry',
            'before_data' => json_encode(['purchase_price' => 10, 'nested' => ['unit_cost' => 5, 'status' => 'old']]),
            'after_data' => json_encode(['status' => 'new']),
        ]);
        $columns = ['name' => 'Անուն', 'before_data' => 'Մինչև', 'after_data' => 'Հետո'];
        $service = new PageDataService($this->repository($columns, array_keys($columns)));

        $export = $service->export('audit', 0, [], $this->actor());
        $row = iterator_to_array($export['rows'])[0];

        self::assertSame('Audit entry', $row[0]);
        self::assertSame(['nested' => ['status' => 'old']], json_decode($row[1], true));
        self::assertSame(['status' => 'new'], json_decode($row[2], true));
    }

    private function repository(array $columns, array $searchFields): PageDataRepository
    {
        $repository = $this->getMockBuilder(PageDataRepository::class)->onlyMethods(['definition'])->getMock();
        $repository->method('definition')->willReturn([$columns, ExportFixtureRow::query(), $searchFields]);

        return $repository;
    }

    private function actor(): User
    {
        $actor = $this->createMock(User::class);
        $actor->method('hasPermissionCode')->willReturn(false);

        return $actor;
    }
}

class ExportFixtureRow extends Model
{
    protected $table = 'page_export_fixtures';

    public $timestamps = false;

    protected $guarded = [];
}
