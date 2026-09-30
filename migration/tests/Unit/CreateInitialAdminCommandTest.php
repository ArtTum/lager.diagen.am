<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateInitialAdminCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('active')->default(true);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_first_admin_command_refuses_to_run_after_any_user_exists(): void
    {
        User::query()->create([
            'name' => 'Existing user', 'email' => 'existing@example.test', 'password' => 'existing-password', 'role_id' => 1,
        ]);

        self::assertSame(1, Artisan::call('lager:admin:create'));
        self::assertStringContainsString('արդեն կան օգտատերեր', Artisan::output());
    }
}
