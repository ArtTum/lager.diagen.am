<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogUserUpdateApiTest extends TestCase
{
    private User $actor;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title');
        });
        Schema::create('permissions', static function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('title');
            $table->string('module');
        });
        Schema::create('role_permissions', static function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
        });
        Schema::create('branches', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
        });
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('personal_access_tokens', static function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id');
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at');
        });

        $role = Role::query()->create(['name' => 'user_editor', 'title' => 'User editor']);
        foreach (['users.create', 'users.edit', 'users.delete'] as $code) {
            $role->permissions()->attach(Permission::query()->create(['code' => $code, 'title' => $code, 'module' => 'users']));
        }
        $this->actor = User::query()->create([
            'name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'editor-password',
            'role_id' => $role->id, 'active' => true,
        ]);
        $this->target = User::query()->create([
            'name' => 'Target', 'email' => 'target@example.test', 'password' => 'original-password',
            'role_id' => $role->id, 'active' => true,
        ]);
        $this->actingAs($this->actor, 'sanctum');
    }

    protected function tearDown(): void
    {
        foreach (['audit_logs', 'personal_access_tokens', 'users', 'branches', 'role_permissions', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public static function unchangedPasswords(): array
    {
        return ['empty form value' => [['password' => '']], 'null value' => [['password' => null]], 'omitted' => [[]]];
    }

    #[DataProvider('unchangedPasswords')]
    public function test_optional_empty_password_preserves_password_and_existing_sessions(array $password): void
    {
        $hash = $this->target->password;
        $this->target->createToken('first-browser');
        $this->target->createToken('second-browser');

        $this->putJson('/api/catalog/users/'.$this->target->id, $this->payload($password))
            ->assertOk()->assertJsonPath('data.name', 'Updated target');

        self::assertSame($hash, $this->target->fresh()->password);
        self::assertSame(2, $this->target->tokens()->count());
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_password_reset_revokes_all_previous_sessions(): void
    {
        $this->target->createToken('first-browser');
        $this->target->createToken('second-browser');
        $this->actor->createToken('editor-browser');

        $this->putJson('/api/catalog/users/'.$this->target->id, $this->payload(['password' => 'replacement-password']))
            ->assertOk();

        self::assertTrue(Hash::check('replacement-password', $this->target->fresh()->password));
        self::assertSame(0, $this->target->tokens()->count());
        self::assertSame(1, $this->actor->tokens()->count());
    }

    #[DataProvider('deactivationMethods')]
    public function test_deactivation_revokes_sessions_before_the_account_can_be_reactivated(string $method): void
    {
        $this->target->createToken('first-browser');
        $this->target->createToken('second-browser');

        if ($method === 'update') {
            $this->putJson('/api/catalog/users/'.$this->target->id, $this->payload(['active' => false]))->assertOk();
        } else {
            $this->deleteJson('/api/catalog/users/'.$this->target->id)->assertOk();
        }
        self::assertFalse($this->target->fresh()->active);
        self::assertSame(0, $this->target->tokens()->count());

        $this->putJson('/api/catalog/users/'.$this->target->id, $this->payload(['active' => true]))->assertOk();
        self::assertTrue($this->target->fresh()->active);
        self::assertSame(0, $this->target->tokens()->count());
    }

    public static function deactivationMethods(): array
    {
        return [['update'], ['delete']];
    }

    public function test_hash_looking_password_reset_is_treated_as_the_literal_password(): void
    {
        $literalPassword = password_hash('hidden-secret', PASSWORD_BCRYPT, ['cost' => 10]);
        $this->target->createToken('old-browser');

        $this->putJson('/api/catalog/users/'.$this->target->id, $this->payload(['password' => $literalPassword]))
            ->assertOk();

        self::assertTrue(Hash::check($literalPassword, $this->target->fresh()->password));
        self::assertFalse(Hash::check('hidden-secret', $this->target->fresh()->password));
        self::assertSame(0, $this->target->tokens()->count());
    }

    public function test_new_user_hash_looking_password_is_treated_as_the_literal_password(): void
    {
        $literalPassword = password_hash('hidden-secret', PASSWORD_BCRYPT, ['cost' => 10]);
        $this->postJson('/api/catalog/users', $this->payload([
            'email' => 'new@example.test', 'password' => $literalPassword,
        ]))->assertCreated();

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        self::assertTrue(Hash::check($literalPassword, $user->password));
        self::assertFalse(Hash::check('hidden-secret', $user->password));
    }

    public function test_account_edit_keeps_an_assignable_imported_role_with_obsolete_grants(): void
    {
        $legacy = Permission::query()->create([
            'code' => 'legacy.retired_action', 'title' => 'Retired permission', 'module' => 'legacy',
        ]);
        $this->target->role->permissions()->attach($legacy);

        $this->putJson('/api/catalog/users/'.$this->target->id, $this->payload([]))->assertOk();

        self::assertTrue($this->target->role->permissions()->where('code', 'legacy.retired_action')->exists());
    }

    public static function maximumPasswordValues(): array
    {
        return ['ascii' => [str_repeat('x', 72)], 'multibyte' => [str_repeat('ա', 36)]];
    }

    #[DataProvider('maximumPasswordValues')]
    public function test_password_reset_accepts_the_full_bcrypt_byte_limit(string $password): void
    {
        $this->putJson('/api/catalog/users/'.$this->target->id, $this->payload(['password' => $password]))
            ->assertOk();

        self::assertTrue(Hash::check($password, $this->target->fresh()->password));
    }

    public static function invalidPasswordValues(): array
    {
        return [
            'bcrypt truncation' => [str_repeat('x', 73)],
            'multibyte truncation' => [str_repeat('ա', 37)],
            'null byte' => ["valid-password\0suffix"],
        ];
    }

    #[DataProvider('invalidPasswordValues')]
    public function test_password_reset_rejects_values_bcrypt_cannot_preserve(string $password): void
    {
        $hash = $this->target->password;
        $this->target->createToken('existing-browser');
        $this->putJson('/api/catalog/users/'.$this->target->id, $this->payload(['password' => $password]))
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        self::assertSame($hash, $this->target->fresh()->password);
        self::assertSame(1, $this->target->tokens()->count());
    }

    #[DataProvider('invalidPasswordValues')]
    public function test_user_creation_rejects_values_bcrypt_cannot_preserve(string $password): void
    {
        $this->postJson('/api/catalog/users', $this->payload([
            'email' => 'new@example.test', 'password' => $password,
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    private function payload(array $changes): array
    {
        return array_replace([
            'name' => 'Updated target', 'email' => $this->target->email,
            'role_id' => $this->target->role_id, 'branch_id' => null, 'active' => true,
        ], $changes);
    }
}
