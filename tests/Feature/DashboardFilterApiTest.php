<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardFilterApiTest extends TestCase
{
    private Branch $central;

    private Branch $erebuni;

    private Branch $gyumri;

    private Branch $inactive;

    private const PERMISSIONS = [
        'dashboard.view', 'branches.view', 'stock.view', 'purchases.view',
        'expiry.view', 'requests.view', 'receipts.view', 'movements.view',
        'returns.view', 'transfers.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 12:00:00');
        $this->createSchema();
        $this->central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $this->erebuni = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $this->gyumri = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        $this->inactive = Branch::query()->create(['name' => 'Disabled', 'code' => 'OFF', 'active' => false]);
        $this->seedActivity();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['transfers', 'returns', 'movement_corrections', 'movements', 'receipts', 'stock_requests', 'stock_lots', 'products', 'role_permissions', 'permissions', 'users', 'roles', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_admin_can_switch_all_dashboard_metrics_to_a_branch_and_back_to_central(): void
    {
        $this->actingAs($this->actor($this->central, self::PERMISSIONS, 'admin'), 'sanctum');

        $this->getJson('/api/dashboard?branch_id='.$this->erebuni->id)
            ->assertOk()
            ->assertJsonPath('data.can_select_location', true)
            ->assertJsonPath('data.selected_location.id', $this->erebuni->id)
            ->assertJsonPath('data.selected_location.name', 'Erebuni')
            ->assertJsonPath('data.products', 2)
            ->assertJsonPath('data.units', 5)
            ->assertJsonPath('data.stock_value', 30)
            ->assertJsonPath('data.low_stock_products', 1)
            ->assertJsonPath('data.zero_stock_products', 0)
            ->assertJsonPath('data.expired_lots', 1)
            ->assertJsonPath('data.expiring_lots', 1)
            ->assertJsonPath('data.open_requests', 2)
            ->assertJsonPath('data.unapproved_requests', 1)
            ->assertJsonPath('data.awaiting_receipt_requests', 1)
            ->assertJsonPath('data.today', ['receipts' => 1, 'issues' => 1, 'returns' => 1, 'transfers' => 2])
            ->assertJsonPath('data.charts.stock_status', ['healthy' => 1, 'low' => 1, 'zero' => 0])
            ->assertJsonPath('data.charts.expiry_status', ['safe' => 0, 'expiring' => 1, 'expired' => 1, 'undated' => 0])
            ->assertJsonPath('data.charts.activity_daily.from', '2026-09-20')
            ->assertJsonPath('data.charts.activity_daily.to', '2026-10-03')
            ->assertJsonPath('data.charts.activity_daily.timezone', 'Asia/Yerevan')
            ->assertJsonCount(14, 'data.charts.activity_daily.dates')
            ->assertJsonPath('data.charts.activity_daily.series.receipts.12', 1)
            ->assertJsonPath('data.charts.activity_daily.series.receipts.13', 1)
            ->assertJsonPath('data.charts.activity_daily.series.issues.13', 1)
            ->assertJsonPath('data.charts.activity_daily.series.returns.13', 0)
            ->assertJsonPath('data.charts.activity_daily.series.transfers.13', 0)
            ->assertJsonMissingPath('data.branches')
            ->assertJsonCount(3, 'data.location_options')
            ->assertJsonPath('data.location_options.0.id', 0)
            ->assertJsonMissing(['name' => 'Disabled']);

        foreach (['/api/dashboard', '/api/dashboard?branch_id=0'] as $url) {
            $this->getJson($url)->assertOk()
                ->assertJsonPath('data.selected_location.id', 0)
                ->assertJsonPath('data.units', 99)
                ->assertJsonPath('data.stock_value', 9900)
                ->assertJsonPath('data.charts.stock_status', ['healthy' => 1, 'low' => 0, 'zero' => 1])
                ->assertJsonPath('data.charts.expiry_status', ['safe' => 1, 'expiring' => 0, 'expired' => 0, 'undated' => 0])
                ->assertJsonPath('data.charts.activity_daily.series.receipts.13', 0)
                ->assertJsonCount(2, 'data.branches');
        }
    }

    public function test_branch_actor_only_receives_its_dashboard_even_with_an_overbroad_role(): void
    {
        $this->actingAs($this->actor($this->erebuni, self::PERMISSIONS), 'sanctum');

        foreach (['/api/dashboard', '/api/dashboard?branch_id='.$this->erebuni->id] as $url) {
            $this->getJson($url)->assertOk()
                ->assertJsonPath('data.selected_location.id', $this->erebuni->id)
                ->assertJsonPath('data.can_select_location', false)
                ->assertJsonPath('data.location_options', [])
                ->assertJsonPath('data.units', 5)
                ->assertJsonMissingPath('data.stock_value')
                ->assertJsonMissingPath('data.branches');
        }

        foreach ([0, $this->gyumri->id, $this->inactive->id, 9999] as $id) {
            $this->getJson('/api/dashboard?branch_id='.$id)->assertForbidden();
        }
    }

    public function test_central_actor_without_branch_permission_cannot_select_another_location(): void
    {
        $this->actingAs($this->actor($this->central, ['dashboard.view', 'stock.view']), 'sanctum');
        $this->getJson('/api/dashboard?branch_id=0')->assertOk()
            ->assertJsonPath('data.can_select_location', false)
            ->assertJsonPath('data.location_options', [])
            ->assertJsonPath('data.units', 99);
        $this->getJson('/api/dashboard?branch_id='.$this->erebuni->id)->assertForbidden();
    }

    public function test_selection_does_not_grant_cost_or_activity_permissions(): void
    {
        $this->actingAs($this->actor($this->central, ['dashboard.view', 'branches.view', 'stock.view', 'requests.view', 'movements.view']), 'sanctum');
        $this->getJson('/api/dashboard?branch_id='.$this->erebuni->id)->assertOk()
            ->assertJsonPath('data.can_select_location', true)
            ->assertJsonPath('data.units', 5)
            ->assertJsonPath('data.open_requests', 2)
            ->assertJsonPath('data.today', ['issues' => 1])
            ->assertJsonMissingPath('data.stock_value')
            ->assertJsonMissingPath('data.expired_lots')
            ->assertJsonMissingPath('data.expiring_lots')
            ->assertJsonPath('data.charts.stock_status', ['healthy' => 1, 'low' => 1, 'zero' => 0])
            ->assertJsonPath('data.charts.activity_daily.series.issues.13', 1)
            ->assertJsonMissingPath('data.charts.activity_daily.series.receipts')
            ->assertJsonMissingPath('data.charts.activity_daily.series.returns')
            ->assertJsonMissingPath('data.charts.activity_daily.series.transfers')
            ->assertJsonMissingPath('data.charts.expiry_status');
    }

    public function test_admin_selection_rejects_unknown_inactive_and_database_central_ids(): void
    {
        $this->actingAs($this->actor($this->central, self::PERMISSIONS, 'admin'), 'sanctum');
        foreach ([$this->central->id, $this->inactive->id, 9999] as $id) {
            $this->getJson('/api/dashboard?branch_id='.$id)->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        }
    }

    public function test_dashboard_query_rejects_malformed_scope(): void
    {
        $this->actingAs($this->actor($this->central, self::PERMISSIONS, 'admin'), 'sanctum');
        foreach (['-1', '1.5', 'abc', '%5B2%5D'] as $id) {
            $this->getJson('/api/dashboard?branch_id='.$id)->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        }
        $this->getJson('/api/dashboard?branch_id%5B%5D=2')->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    private function actor(Branch $branch, array $codes, string $roleName = 'dashboard_test'): User
    {
        $role = Role::query()->create(['name' => $roleName, 'title' => 'Dashboard access']);
        foreach ($codes as $code) {
            $permission = Permission::query()->firstOrCreate(['code' => $code], ['title' => $code, 'module' => explode('.', $code)[0]]);
            $role->permissions()->attach($permission);
        }

        return User::query()->create([
            'name' => 'Dashboard user', 'email' => 'dashboard@example.test', 'password' => 'test-password',
            'role_id' => $role->id, 'branch_id' => $branch->id, 'active' => true,
        ]);
    }

    private function seedActivity(): void
    {
        DB::table('products')->insert([['id' => 1, 'active' => true, 'min_qty' => 5], ['id' => 2, 'active' => true, 'min_qty' => 1]]);
        DB::table('stock_lots')->insert([
            ['product_id' => 1, 'location_id' => 0, 'qty' => 99, 'unit_cost' => 100, 'expires_on' => '2027-10-01'],
            ['product_id' => 1, 'location_id' => $this->erebuni->id, 'qty' => 4, 'unit_cost' => 5, 'expires_on' => '2026-10-20'],
            ['product_id' => 2, 'location_id' => $this->erebuni->id, 'qty' => 1, 'unit_cost' => 10, 'expires_on' => '2026-10-02'],
            ['product_id' => 1, 'location_id' => $this->gyumri->id, 'qty' => 40, 'unit_cost' => 100, 'expires_on' => '2027-10-01'],
        ]);
        DB::table('stock_requests')->insert([
            ['branch_id' => $this->erebuni->id, 'status' => 'sent'],
            ['branch_id' => $this->erebuni->id, 'status' => 'shipped'],
            ['branch_id' => $this->erebuni->id, 'status' => 'closed'],
            ['branch_id' => $this->gyumri->id, 'status' => 'sent'],
            ['branch_id' => $this->gyumri->id, 'status' => 'review'],
        ]);
        DB::table('receipts')->insert(['received_on' => '2026-10-03']);
        DB::table('movements')->insert([
            ['type' => 'branch_in', 'from_location' => 0, 'to_location' => $this->erebuni->id, 'happened_at' => '2026-10-03 09:00:00'],
            ['type' => 'branch_in', 'from_location' => 0, 'to_location' => $this->erebuni->id, 'happened_at' => '2026-10-02 09:00:00'],
            ['type' => 'consumption', 'from_location' => $this->erebuni->id, 'to_location' => null, 'happened_at' => '2026-10-03 10:00:00'],
            ['type' => 'consumption', 'from_location' => $this->gyumri->id, 'to_location' => null, 'happened_at' => '2026-10-03 10:00:00'],
        ]);
        DB::table('returns')->insert([
            ['from_location' => $this->erebuni->id, 'created_at' => '2026-10-03 10:00:00'],
            ['from_location' => $this->gyumri->id, 'created_at' => '2026-10-03 10:00:00'],
        ]);
        DB::table('transfers')->insert([
            ['from_branch' => $this->erebuni->id, 'to_branch' => $this->gyumri->id, 'status' => 'shipped', 'created_at' => '2026-10-03 11:00:00'],
            ['from_branch' => $this->gyumri->id, 'to_branch' => $this->erebuni->id, 'status' => 'completed', 'created_at' => '2026-10-03 11:00:00'],
            ['from_branch' => $this->central->id, 'to_branch' => $this->gyumri->id, 'status' => 'shipped', 'created_at' => '2026-10-03 11:00:00'],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active')->default(true);
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('module');
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id');
            $table->boolean('active')->default(true);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active');
            $table->decimal('min_qty', 12, 3)->default(0);
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 2);
            $table->date('expires_on')->nullable();
        });
        Schema::create('stock_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('status');
        });
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->date('received_on');
        });
        Schema::create('movements', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('from_location')->nullable();
            $table->unsignedBigInteger('to_location')->nullable();
            $table->decimal('qty', 12, 3)->default(1);
            $table->dateTime('happened_at');
        });
        Schema::create('movement_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('movement_id');
        });
        Schema::create('returns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_location');
            $table->dateTime('created_at');
        });
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_branch');
            $table->unsignedBigInteger('to_branch');
            $table->string('status');
            $table->dateTime('created_at');
        });
    }
}
