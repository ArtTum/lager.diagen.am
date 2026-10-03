<?php

namespace Tests\Unit;

use App\Models\User;
use App\Repositories\PageDataRepository;
use App\Services\PageDataService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PageDataExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('page_export_fixtures', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->boolean('active')->nullable();
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

    #[DataProvider('workflowStatuses')]
    public function test_export_uses_shared_workflow_labels_without_changing_stored_codes(string $workflow, ?string $status, ?string $expected): void
    {
        $fixture = ExportFixtureRow::query()->create(['name' => 'Document', 'status' => $status]);
        $service = new PageDataService($this->repository(['status' => 'Կարգավիճակ'], ['name']));

        $export = $service->export($workflow, 0, [], $this->actor());

        self::assertSame([[$expected]], iterator_to_array($export['rows']));
        self::assertSame($status, $fixture->fresh()->status);
        if ($status !== null && $status !== 'future_phase') {
            $catalog = json_decode(file_get_contents(resource_path('js/workflowStatuses.json')), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($catalog[$workflow][$status]['label'] ?? $catalog['generic'][$status]['label'], $expected);
        }
    }

    public static function workflowStatuses(): array
    {
        return [
            'request submitted' => ['requests', 'sent', 'Սպասում է ստուգման'],
            'goods dispatched' => ['requests', 'shipped', 'Ապրանքն ուղարկված է'],
            'transfer received' => ['transfers', 'completed', 'Տեղափոխումն ավարտված է'],
            'purchase approved' => ['purchases', 'approved', 'Գնումը հաստատված է'],
            'legacy purchase completed' => ['purchases', 'completed', 'Ավարտված է'],
            'inventory counted' => ['inventory', 'counted', 'Սպասում է այլ աշխատակցի հաստատմանը'],
            'inventory closed' => ['inventory', 'closed', 'Գույքագրումն ավարտված է'],
            'posted return generic fallback' => ['returns', 'posted', 'Գրանցված է'],
            'unknown workflow generic fallback' => ['legacy_workflow', 'sent', 'Սպասում է ստուգման'],
            'unknown status preserved' => ['requests', 'future_phase', 'future_phase'],
            'missing status preserved' => ['requests', null, null],
        ];
    }

    public function test_activity_export_labels_match_the_shared_catalog_and_page_data_remains_raw(): void
    {
        ExportFixtureRow::query()->create(['name' => 'Enabled', 'status' => 'sent', 'active' => true]);
        ExportFixtureRow::query()->create(['name' => 'Disabled', 'status' => 'shipped', 'active' => false]);
        $columns = ['name' => 'Անուն', 'status' => 'Կարգավիճակ', 'active' => 'Ակտիվ'];
        $service = new PageDataService($this->repository($columns, ['name']));
        $actor = $this->actor();
        $screen = $service->page('products', 0, [], $actor);
        $export = $service->export('products', 0, [], $actor);
        $catalog = json_decode(file_get_contents(resource_path('js/workflowStatuses.json')), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['sent', 'shipped'], array_map(static fn ($row) => $row->status, $screen['data']));
        self::assertSame([1, 0], array_map(static fn ($row) => $row->active, $screen['data']));
        self::assertSame([
            ['Enabled', $catalog['generic']['sent']['label'], $catalog['activity']['active']['label']],
            ['Disabled', $catalog['generic']['shipped']['label'], $catalog['activity']['inactive']['label']],
        ], iterator_to_array($export['rows']));
        self::assertNotSame($catalog['generic']['sent']['label'], $catalog['generic']['shipped']['label']);
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
