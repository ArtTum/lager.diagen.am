<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Services\CatalogService;
use App\Services\PermissionService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogDeactivationTest extends TestCase
{
    public static function guardedActions(): array
    {
        return [['products', 'update'], ['products', 'deactivate'], ['branches', 'update'], ['branches', 'deactivate']];
    }

    #[DataProvider('guardedActions')]
    public function test_stock_blocks_deactivation_through_both_edit_and_delete(string $kind, string $action): void
    {
        $record = ($kind === 'products' ? new Product : new Branch)->forceFill(['id' => 3, 'active' => true, 'code' => 'NORMAL']);
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('find')->with($kind, 3)->willReturn($record);
        $catalog->expects(self::once())->method($kind === 'products' ? 'stockExistsForProduct' : 'stockExistsForLocation')
            ->with(3)->willReturn(true);
        $catalog->expects(self::never())->method('update');
        $catalog->expects(self::never())->method('deactivate');
        $catalog->expects(self::never())->method('createAuditEntry');
        $service = new CatalogService($catalog, app(PermissionService::class));

        $this->expectException(ValidationException::class);
        if ($action === 'update') {
            $service->update($this->actor(), '127.0.0.1', $kind, 3, ['active' => false]);
        } else {
            $service->deactivate($this->actor(), '127.0.0.1', $kind, 3);
        }
    }

    public static function kinds(): array
    {
        return [['products'], ['branches']];
    }

    #[DataProvider('kinds')]
    public function test_edit_can_deactivate_a_record_without_stock(string $kind): void
    {
        $record = ($kind === 'products' ? new Product : new Branch)->forceFill(['id' => 3, 'active' => true, 'code' => 'NORMAL']);
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('find')->with($kind, 3)->willReturn($record);
        $catalog->expects(self::once())->method($kind === 'products' ? 'stockExistsForProduct' : 'stockExistsForLocation')
            ->with(3)->willReturn(false);
        $catalog->expects(self::once())->method('update')->with($record, ['active' => false])
            ->willReturnCallback(static fn ($record, $data) => $record->forceFill($data));
        $catalog->expects(self::once())->method('createAuditEntry')->willReturn(new AuditLog);

        $result = (new CatalogService($catalog, app(PermissionService::class)))
            ->update($this->actor(), '127.0.0.1', $kind, 3, ['active' => false]);

        self::assertFalse($result->active);
    }

    private function actor(): User
    {
        $actor = $this->createMock(User::class);
        $actor->method('currentLocationId')->willReturn(0);
        $actor->method('hasPermissionCode')->willReturn(true);

        return $actor;
    }
}
