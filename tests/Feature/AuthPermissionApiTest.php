<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\AuthRepository;
use Database\Seeders\LagerAccessSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class AuthPermissionApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['audit_logs', 'personal_access_tokens', 'role_permissions', 'permissions', 'users', 'roles', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_login_returns_user_permissions_and_token_that_can_be_revoked(): void
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $branch = Branch::query()->create(['name' => 'Էրեբունի', 'code' => 'EREB', 'active' => true]);
        $permission = Permission::query()->create([
            'code' => 'dashboard.view',
            'title' => 'Գլխավոր վահանակ — Դիտել',
            'module' => 'dashboard',
        ]);
        $role->permissions()->attach($permission);
        User::query()->create([
            'name' => 'Branch user',
            'email' => 'branch@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'active' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'branch@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.role.name', 'branch')
            ->assertJsonPath('data.user.branch.code', 'EREB')
            ->assertJsonPath('data.user.location_id', $branch->id)
            ->assertJsonPath('data.user.permissions', ['dashboard.view' => true]);

        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        app('auth')->forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_all_six_standard_roles_login_with_their_expected_permission_map(): void
    {
        (new LagerAccessSeeder)->run();
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $expectations = [
            'admin' => [
                'branch' => 'CENTRAL',
                'allowed' => ['dashboard.view', 'branches.delete', 'inventory.approve', 'roles.edit', 'users.create'],
                'denied' => [],
            ],
            'manager' => [
                'branch' => 'CENTRAL',
                'allowed' => ['dashboard.view', 'purchases.approve', 'reports.export', 'roles.view'],
                'denied' => ['branches.delete', 'roles.edit', 'users.create'],
            ],
            'storekeeper' => [
                'branch' => 'CENTRAL',
                'allowed' => ['stock.create', 'requests.edit', 'inventory.edit'],
                'denied' => ['purchases.approve', 'users.view'],
            ],
            'branch' => [
                'branch' => 'EREB',
                'allowed' => ['stock.create', 'requests.create', 'inventory.view', 'reports.export'],
                'denied' => ['products.view', 'stock.edit', 'requests.approve', 'inventory.approve'],
            ],
            'finance' => [
                'branch' => 'CENTRAL',
                'allowed' => ['purchases.approve', 'reports.export', 'suppliers.view'],
                'denied' => ['receipts.create', 'stock.create', 'users.view'],
            ],
            'viewer' => [
                'branch' => 'CENTRAL',
                'allowed' => ['dashboard.view', 'stock.view', 'reports.view'],
                'denied' => ['stock.create', 'reports.export', 'users.create'],
            ],
        ];

        foreach ($expectations as $roleName => $expectation) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            $branchId = $expectation['branch'] === 'EREB' ? $branch->id : $central->id;
            User::query()->create([
                'name' => 'QA '.ucfirst($roleName),
                'email' => 'qa-'.$roleName.'@example.test',
                'password' => 'correct-password',
                'role_id' => $role->id,
                'branch_id' => $branchId,
                'active' => true,
            ]);

            $response = $this->postJson('/api/auth/login', [
                'email' => 'qa-'.$roleName.'@example.test',
                'password' => 'correct-password',
            ])->assertOk()
                ->assertJsonPath('data.user.role.name', $roleName)
                ->assertJsonPath('data.user.branch.code', $expectation['branch']);

            $permissions = $response->json('data.user.permissions');
            foreach ($expectation['allowed'] as $code) {
                self::assertArrayHasKey($code, $permissions, "{$roleName} should receive {$code} at login.");
            }
            foreach ($expectation['denied'] as $code) {
                self::assertArrayNotHasKey($code, $permissions, "{$roleName} must not receive {$code} at login.");
            }

            app('auth')->forgetGuards();
            $meResponse = $this->withToken($response->json('data.token'))->getJson('/api/auth/me')->assertOk();
            $mePermissions = $meResponse->json('data.permissions');
            ksort($permissions);
            ksort($mePermissions);
            self::assertSame($permissions, $mePermissions, "{$roleName} should keep the same permission map after login.");
        }
    }

    public function test_login_trims_email_like_the_legacy_login_form(): void
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        User::query()->create([
            'name' => 'Branch user',
            'email' => 'branch@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => '  branch@example.test  ',
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('data.user.email', 'branch@example.test');
    }

    public function test_login_accepts_and_upgrades_a_legacy_php_password_default_hash(): void
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $legacyHash = password_hash('legacy-compatible-password', PASSWORD_DEFAULT);
        User::query()->create([
            'name' => 'Migrated branch user',
            'email' => 'migrated-branch@example.test',
            'password' => $legacyHash,
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'migrated-branch@example.test',
            'password' => 'legacy-compatible-password',
        ])->assertOk()->assertJsonPath('data.user.email', 'migrated-branch@example.test');

        $upgradedHash = User::query()->where('email', 'migrated-branch@example.test')->value('password');
        self::assertNotSame($legacyHash, $upgradedHash);
        self::assertTrue(password_verify('legacy-compatible-password', $upgradedHash));
        $this->postJson('/api/auth/login', [
            'email' => 'migrated-branch@example.test',
            'password' => 'incorrect-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_password_assignment_only_preserves_the_observed_legacy_hash_format(): void
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $hashLookingPassword = password_hash('literal-password-value', PASSWORD_BCRYPT, ['cost' => 11]);
        User::query()->create([
            'name' => 'New branch user',
            'email' => 'new-branch@example.test',
            'password' => $hashLookingPassword,
            'role_id' => $role->id,
            'active' => true,
        ]);

        $storedHash = User::query()->where('email', 'new-branch@example.test')->value('password');
        self::assertNotSame($hashLookingPassword, $storedHash);
        self::assertTrue(password_verify($hashLookingPassword, $storedHash));
    }

    public function test_login_cannot_overwrite_a_password_reset_completed_after_the_initial_read(): void
    {
        $user = User::query()->create([
            'name' => 'Concurrent account', 'email' => 'concurrent@example.test',
            'password' => password_hash('original-password', PASSWORD_BCRYPT, ['cost' => 10]),
            'active' => true,
        ]);
        $replacementHash = Hash::make('replacement-password');
        $this->changeAccountAfterAuthRead(static function (User $snapshot) use ($replacementHash): void {
            DB::table('users')->where('id', $snapshot->id)->update(['password' => $replacementHash]);
        });

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'original-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        self::assertSame($replacementHash, $user->fresh()->password);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_cannot_issue_a_token_after_concurrent_account_deactivation(): void
    {
        $user = User::query()->create([
            'name' => 'Concurrent account', 'email' => 'concurrent@example.test',
            'password' => 'original-password', 'active' => true,
        ]);
        $this->changeAccountAfterAuthRead(static function (User $snapshot): void {
            DB::table('users')->where('id', $snapshot->id)->update(['active' => false]);
        });

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'original-password'])
            ->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rechecks_a_concurrent_hash_upgrade_without_rejecting_valid_credentials(): void
    {
        $user = User::query()->create([
            'name' => 'Concurrent account', 'email' => 'concurrent@example.test',
            'password' => password_hash('original-password', PASSWORD_BCRYPT, ['cost' => 10]),
            'active' => true,
        ]);
        $upgradedHash = Hash::make('original-password');
        $this->changeAccountAfterAuthRead(static function (User $snapshot) use ($upgradedHash): void {
            DB::table('users')->where('id', $snapshot->id)->update(['password' => $upgradedHash]);
        });

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'original-password'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);

        self::assertSame($upgradedHash, $user->fresh()->password);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    private function changeAccountAfterAuthRead(\Closure $change): void
    {
        $this->app->instance(AuthRepository::class, new class($change) extends AuthRepository
        {
            public function __construct(private readonly \Closure $change) {}

            public function findByEmail(string $email): ?User
            {
                $snapshot = parent::findByEmail($email);
                ($this->change)($snapshot);

                return $snapshot;
            }

            public function findByEmailForUpdate(string $email): ?User
            {
                Assert::assertGreaterThan(0, DB::transactionLevel());

                return parent::findByEmailForUpdate($email);
            }
        });
    }

    public function test_role_permission_changes_take_effect_for_an_existing_token_on_the_next_request(): void
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $permission = Permission::query()->create([
            'code' => 'dashboard.view',
            'title' => 'Գլխավոր վահանակ — Դիտել',
            'module' => 'dashboard',
        ]);
        $role->permissions()->attach($permission);
        User::query()->create([
            'name' => 'Branch user',
            'email' => 'branch@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'branch@example.test',
            'password' => 'correct-password',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.permissions', ['dashboard.view' => true]);

        $role->permissions()->detach($permission);
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.permissions', []);
    }

    public function test_login_rejects_inactive_accounts_and_invalid_credentials(): void
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        User::query()->create([
            'name' => 'Inactive user',
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
        ])->assertForbidden();

        $this->postJson('/api/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_deactivating_a_user_revokes_their_existing_api_token(): void
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $user = User::query()->create([
            'name' => 'Branch user',
            'email' => 'branch@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);
        $token = $user->createToken('browser-session')->plainTextToken;
        $user->update(['active' => false]);

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unauthenticated_api_request_does_not_redirect_to_missing_login_route(): void
    {
        $this->get('/api/auth/me', ['Accept' => 'text/html'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_branch_role_cannot_approve_central_inventory_even_if_granted_by_mistake(): void
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $branch = Branch::query()->create(['name' => 'Էրեբունի', 'code' => 'EREB', 'active' => true]);
        $permission = Permission::query()->create([
            'code' => 'inventory.approve',
            'title' => 'Գույքագրում — Հաստատել',
            'module' => 'inventory',
        ]);
        $role->permissions()->attach($permission);
        $user = User::query()->create([
            'name' => 'Branch user',
            'email' => 'branch@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/inventory/1/approve')
            ->assertForbidden();
    }

    public function test_view_permission_does_not_implicitly_allow_data_export(): void
    {
        $role = Role::query()->create(['name' => 'readonly', 'title' => 'Դիտորդ']);
        $view = Permission::query()->create([
            'code' => 'products.view',
            'title' => 'Ապրանքներ — Դիտել',
            'module' => 'products',
        ]);
        $role->permissions()->attach($view);
        $user = User::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/pages/products/export')
            ->assertForbidden();
    }

    public function test_inventory_list_rejects_an_invalid_page_size_before_running_the_query(): void
    {
        $role = Role::query()->create(['name' => 'inventory_reader', 'title' => 'Գույքագրում դիտող']);
        $permission = Permission::query()->create([
            'code' => 'inventory.view',
            'title' => 'Գույքագրում — Դիտել',
            'module' => 'inventory',
        ]);
        $role->permissions()->attach($permission);
        $user = User::query()->create([
            'name' => 'Inventory reader',
            'email' => 'inventory-reader@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/inventory?per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_supplier_list_rejects_a_non_boolean_active_filter(): void
    {
        $role = Role::query()->create(['name' => 'supplier_reader', 'title' => 'Մատակարար դիտող']);
        $permission = Permission::query()->create([
            'code' => 'suppliers.view',
            'title' => 'Մատակարարներ — Դիտել',
            'module' => 'suppliers',
        ]);
        $role->permissions()->attach($permission);
        $user = User::query()->create([
            'name' => 'Supplier reader',
            'email' => 'supplier-reader@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/suppliers?active_only=perhaps')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('active_only');
    }

    public function test_supplier_history_rejects_invalid_page_numbers(): void
    {
        $role = Role::query()->create(['name' => 'manager', 'title' => 'Տնտեսական ղեկավար']);
        $permission = Permission::query()->create([
            'code' => 'suppliers.view',
            'title' => 'Մատակարարներ — Դիտել',
            'module' => 'suppliers',
        ]);
        $role->permissions()->attach($permission);
        $user = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/suppliers/1/history?receipts_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipts_page');
    }

    public function test_product_trace_rejects_invalid_page_numbers(): void
    {
        $role = Role::query()->create(['name' => 'manager', 'title' => 'Տնտեսական ղեկավար']);
        $permission = Permission::query()->create([
            'code' => 'products.view',
            'title' => 'Ապրանքներ — Դիտել',
            'module' => 'products',
        ]);
        $role->permissions()->attach($permission);
        $user = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products/1/history?movements_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('movements_page');
    }

    public function test_role_creation_requires_create_and_permission_edit_grants(): void
    {
        $role = Role::query()->create(['name' => 'manager', 'title' => 'Տնտեսական պատասխանատու']);
        $create = Permission::query()->create([
            'code' => 'roles.create',
            'title' => 'Դերեր — Ստեղծել',
            'module' => 'roles',
        ]);
        $edit = Permission::query()->create([
            'code' => 'roles.edit',
            'title' => 'Դերեր — Փոփոխել իրավունքները',
            'module' => 'roles',
        ]);
        $stockView = Permission::query()->create([
            'code' => 'stock.view',
            'title' => 'Մնացորդներ — Դիտել',
            'module' => 'stock',
        ]);
        Permission::query()->create([
            'code' => 'purchases.approve',
            'title' => 'Գնումներ — Հաստատել',
            'module' => 'purchases',
        ]);
        $role->permissions()->attach([$create->id, $stockView->id]);
        $user = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/roles', ['title' => 'Stock observer', 'permissions' => ['stock.view']])
            ->assertForbidden();
        $this->assertDatabaseMissing('roles', ['title' => 'Stock observer']);

        $role->permissions()->attach($edit);
        $user->unsetRelation('role');
        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/roles', ['title' => 'Stock observer', 'permissions' => ['stock.view']])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Stock observer')
            ->assertJsonPath('data.permissions', ['stock.view']);

        $roleId = $created->json('data.id');
        $this->putJson("/api/roles/{$roleId}/permissions", ['permissions' => ['stock.view']])->assertOk();
        $this->postJson('/api/roles', ['title' => 'Escalated role', 'permissions' => ['purchases.approve']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions');

        self::assertDatabaseHas('audit_logs', ['entity' => 'roles', 'action' => 'Նոր դեր ստեղծվեց']);
    }

    public function test_role_detail_route_accepts_the_string_id_supplied_by_the_router(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'title' => 'Համակարգի ադմինիստրատոր']);
        $permission = Permission::query()->create([
            'code' => 'roles.view',
            'title' => 'Դերեր — Դիտել',
            'module' => 'roles',
        ]);
        $role->permissions()->attach($permission);
        $user = User::query()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => 'correct-password',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/catalog/roles/'.$role->id)
            ->assertOk()
            ->assertJsonPath('data.id', $role->id)
            ->assertJsonPath('data.permissions', ['roles.view']);
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
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('active')->default(true);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }
}
