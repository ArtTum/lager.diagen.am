<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Repositories\StockRequestRepository;
use App\Services\StockRequestService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockRequestWorkflowTest extends TestCase
{
    public function test_request_can_be_fulfilled_from_draft_through_branch_receipt(): void
    {
        $this->createTables();

        try {
            $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
            $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
            $otherBranch = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
            $product = Product::query()->create([
                'code' => 'QA-001', 'name' => 'Test item', 'unit' => 'հատ', 'purchase_price' => 100,
                'lot_control' => true, 'expiry_control' => true, 'active' => true,
            ]);
            $centralLot = StockLot::query()->create([
                'product_id' => $product->id, 'location_id' => 0, 'lot_no' => 'LOT-001',
                'expires_on' => now()->addMonths(6)->toDateString(), 'received_on' => now()->subDays(2)->toDateString(),
                'unit_cost' => 100, 'qty' => 5,
            ]);
            $service = new StockRequestService(new StockRequestRepository);
            $requester = $this->user(10, (int) $branch->id, $branch, 'branch');
            $otherRequester = $this->user(11, (int) $otherBranch->id, $otherBranch, 'branch');
            $centralActor = $this->user(20, (int) $central->id, $central, 'admin');

            $created = $service->create($requester, '127.0.0.1', [
                'branch_id' => $branch->id,
                'urgency' => 'normal', 'reason' => 'Սպառման համալրում', 'submit_mode' => 'draft',
                'items' => [['product_id' => $product->id, 'qty' => 2, 'note' => '']],
            ]);
            $requestId = $created['id'];
            $request = StockRequest::query()->findOrFail($requestId);

            self::assertSame('draft', $request->status);

            $service->updateDraft($requestId, $requester, '127.0.0.1', [
                'urgency' => 'high', 'reason' => 'Սպառման համալրում', 'submit_mode' => 'send',
                'items' => [['product_id' => $product->id, 'qty' => 2, 'note' => '']],
            ]);
            $service->review($requestId, $centralActor, '127.0.0.1', ['decision' => 'start_review']);
            $itemId = (int) StockRequestItem::query()->where('request_id', $requestId)->value('id');

            try {
                $service->review($requestId, $centralActor, '127.0.0.1', ['decision' => 'approve', 'approved' => [$itemId => 3]]);
                self::fail('The system must reject approval above the requested quantity.');
            } catch (ValidationException) {
                self::assertSame('review', StockRequest::query()->findOrFail($requestId)->status);
                self::assertEquals(0.0, (float) StockRequestItem::query()->findOrFail($itemId)->approved_qty);
            }

            $service->review($requestId, $centralActor, '127.0.0.1', ['decision' => 'approve', 'approved' => [$itemId => 2]]);
            $service->transition($requestId, 'collect', $centralActor, '127.0.0.1');
            $service->transition($requestId, 'ready', $centralActor, '127.0.0.1');
            $service->transition($requestId, 'ship', $centralActor, '127.0.0.1');

            self::assertSame('shipped', StockRequest::query()->findOrFail($requestId)->status);
            self::assertSame('3.000', $centralLot->fresh()->qty);

            try {
                $service->transition($requestId, 'receive', $otherRequester, '127.0.0.1');
                self::fail('A different branch must not receive this request.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
                self::assertSame('shipped', StockRequest::query()->findOrFail($requestId)->status);
            }

            $service->transition($requestId, 'receive', $requester, '127.0.0.1');
            $service->transition($requestId, 'close', $requester, '127.0.0.1');

            self::assertSame('closed', StockRequest::query()->findOrFail($requestId)->status);
            self::assertEquals(2.0, (float) StockLot::query()->where('location_id', $branch->id)->sum('qty'));
            self::assertSame(2, Movement::query()->count());
            self::assertSame(['branch_out', 'branch_in'], Movement::query()->orderBy('id')->pluck('type')->all());
        } finally {
            foreach (['audit_logs', 'movements', 'transfer_items', 'transfers', 'request_items', 'stock_requests', 'stock_lots', 'products', 'branches'] as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    private function user(int $id, int $branchId, Branch $branch, string $roleName): User
    {
        $user = new User(['active' => true, 'branch_id' => $branchId]);
        $user->setAttribute('id', $id);
        $user->setRelation('branch', $branch);
        $user->setRelation('role', new Role(['name' => $roleName]));

        return $user;
    }

    private function createTables(): void
    {
        Schema::create('branches', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active');
        });
        Schema::create('products', function ($table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->decimal('purchase_price', 14, 2);
            $table->boolean('lot_control');
            $table->boolean('expiry_control');
            $table->boolean('active');
        });
        Schema::create('stock_lots', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('qty', 12, 3);
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('bin_location')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
        });
        Schema::create('stock_requests', function ($table): void {
            $table->id();
            $table->string('request_no');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('status');
            $table->string('urgency');
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('request_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('requested_qty', 12, 3);
            $table->decimal('approved_qty', 12, 3);
            $table->string('note')->nullable();
        });
        Schema::create('transfers', function ($table): void {
            $table->id();
            $table->string('status');
            $table->unsignedBigInteger('from_branch');
        });
        Schema::create('transfer_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty', 12, 3);
        });
        Schema::create('movements', function ($table): void {
            $table->id();
            $table->string('movement_no');
            $table->string('type');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id');
            $table->unsignedBigInteger('from_location')->nullable();
            $table->unsignedBigInteger('to_location')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->string('reference');
            $table->string('reason');
            $table->unsignedBigInteger('actor_id');
            $table->dateTime('happened_at');
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('audit_logs', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id');
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }
}
