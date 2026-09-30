<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\StockRequest;
use App\Models\User;
use App\Repositories\StockRequestRepository;
use App\Services\StockRequestService;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockRequestDispatchDocumentTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_dispatch_document_returns_only_print_fields_for_a_visible_shipped_request(): void
    {
        $row = new StockRequest([
            'id' => 27,
            'request_no' => 'ՊՀ-2609-0007',
            'status' => 'shipped',
            'urgency' => 'high',
            'reason' => 'Շտապ համալրում',
            'created_at' => '2026-09-28 10:00:00',
            'sent_at' => '2026-09-29 11:00:00',
            'received_at' => null,
        ]);
        $row->setAttribute('branch_name', 'Դիագեն Պլյուս — Էրեբունի');
        $row->setRelation('requester', new User(['name' => 'Անի Սարգսյան']));
        $row->setRelation('sender', new User(['name' => 'Արման Մկրտչյան']));
        $row->setRelation('receiver', null);
        $row->setRelation('items', new Collection([(object) [
            'code' => 'DIS-055', 'name' => 'Ախտահանիչ լուծույթ 1 լ',
            'requested_qty' => '12.000', 'approved_qty' => '10.000', 'unit' => 'լիտր',
            'product' => (object) ['purchase_price' => '999999.00'],
        ]]));

        $repository = Mockery::mock(StockRequestRepository::class);
        $repository->shouldReceive('findWithItems')->once()->with(27)->andReturn($row);
        $service = new StockRequestService($repository);
        $actor = new User;
        $actor->setRelation('role', new Role(['name' => 'admin']));

        $result = $service->dispatchDocument(27, $actor);

        self::assertSame('ՊՀ-2609-0007', $result['request_no']);
        self::assertSame('Դիագեն Պլյուս — Էրեբունի', $result['branch_name']);
        self::assertSame('Արման Մկրտչյան', $result['sender_name']);
        self::assertSame([[
            'code' => 'DIS-055', 'name' => 'Ախտահանիչ լուծույթ 1 լ',
            'requested_qty' => '12.000', 'approved_qty' => '10.000', 'unit' => 'լիտր',
        ]], $result['items']);
        self::assertArrayNotHasKey('product', $result['items'][0]);
        self::assertArrayNotHasKey('purchase_price', $result['items'][0]);
    }

    public function test_dispatch_document_is_not_available_before_shipping(): void
    {
        $row = new StockRequest(['id' => 27, 'status' => 'approved']);
        $row->setAttribute('branch_name', 'Կենտրոն');
        $row->setRelation('requester', null);
        $row->setRelation('sender', null);
        $row->setRelation('receiver', null);
        $row->setRelation('items', new Collection);

        $repository = Mockery::mock(StockRequestRepository::class);
        $repository->shouldReceive('findWithItems')->once()->with(27)->andReturn($row);
        $service = new StockRequestService($repository);
        $actor = new User;
        $actor->setRelation('role', new Role(['name' => 'admin']));

        $this->expectException(HttpException::class);
        $service->dispatchDocument(27, $actor);
    }
}
