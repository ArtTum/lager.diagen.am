<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Repositories\TransferRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogTransferOptionsApiTest extends TestCase
{
    private Branch $source;

    private Branch $destination;

    private Branch $central;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('manager')->nullable();
            $table->boolean('active');
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->decimal('purchase_price', 14, 2);
            $table->boolean('expiry_control');
            $table->boolean('active');
        });

        $this->central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $this->source = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $this->destination = Branch::query()->create([
            'name' => 'Gyumri', 'code' => 'GYUM', 'active' => true,
            'address' => 'Private address', 'phone' => 'Private phone', 'manager' => 'Private contact',
        ]);
        Branch::query()->create(['name' => 'Inactive', 'code' => 'OFF', 'active' => false]);
        $this->product = Product::query()->create([
            'code' => 'TRANSFER-OPTIONS', 'name' => 'Transfer item', 'unit' => 'հատ',
            'purchase_price' => 88.25, 'expiry_control' => false, 'active' => true,
        ]);
        $role = new Role(['name' => 'branch']);
        $role->setRelation('permissions', collect([
            new Permission(['code' => 'transfers.view']), new Permission(['code' => 'transfers.create']),
            new Permission(['code' => 'requests.view']), new Permission(['code' => 'stock.view']),
        ]));
        $actor = new User(['active' => true, 'branch_id' => $this->source->id]);
        $actor->setAttribute('id', 10);
        $actor->setRelation('branch', $this->source);
        $actor->setRelation('role', $role);
        $this->actingAs($actor, 'sanctum');
    }

    protected function tearDown(): void
    {
        foreach (['products', 'roles', 'categories', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_branch_transfer_options_include_active_destinations_without_contact_or_cost_fields(): void
    {
        $response = $this->getJson('/api/catalog/transfers/options')->assertOk();

        self::assertSame([$this->central->id, $this->source->id, $this->destination->id], array_column($response->json('data.branches'), 'id'));
        foreach ($response->json('data.branches') as $branch) {
            self::assertSame(['id', 'name', 'code'], array_keys($branch));
        }
        $response->assertJsonMissingPath('data.products.0.purchase_price')
            ->assertJsonPath('data.suppliers', []);
    }

    public function test_request_and_stock_options_remain_limited_to_the_current_branch(): void
    {
        foreach (['requests', 'stock'] as $kind) {
            $this->getJson('/api/catalog/'.$kind.'/options')->assertOk()
                ->assertJsonCount(1, 'data.branches')
                ->assertJsonPath('data.branches.0.id', $this->source->id);
        }
    }

    public function test_central_request_options_exclude_central_without_removing_other_workflow_destinations(): void
    {
        $role = new Role(['name' => 'central_viewer']);
        $role->setRelation('permissions', collect([
            new Permission(['code' => 'requests.view']), new Permission(['code' => 'transfers.view']),
            new Permission(['code' => 'stock.view']),
        ]));
        $actor = new User(['active' => true, 'branch_id' => $this->central->id]);
        $actor->setAttribute('id', 11);
        $actor->setRelation('branch', $this->central);
        $actor->setRelation('role', $role);
        $this->actingAs($actor, 'sanctum');

        $requests = $this->getJson('/api/catalog/requests/options')->assertOk();
        self::assertSame([$this->source->id, $this->destination->id], array_column($requests->json('data.branches'), 'id'));
        foreach (['transfers', 'stock'] as $kind) {
            $response = $this->getJson('/api/catalog/'.$kind.'/options')->assertOk();
            self::assertSame([$this->central->id, $this->source->id, $this->destination->id], array_column($response->json('data.branches'), 'id'));
        }
    }

    public function test_destination_metadata_does_not_authorize_sending_from_another_branch(): void
    {
        $repository = $this->createMock(TransferRepository::class);
        $repository->expects(self::never())->method('activeBranches');
        $repository->expects(self::never())->method('create');
        $this->app->instance(TransferRepository::class, $repository);

        $this->postJson('/api/transfers', [
            'from_branch' => $this->destination->id, 'to_branch' => $this->source->id,
            'reason' => 'Unauthorized source attempt',
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertForbidden();
    }
}
