<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\User;
use App\Repositories\SupplierRepository;
use App\Services\SupplierService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierAuditHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('suppliers', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('bank_details')->nullable();
            $table->string('contract_no')->nullable();
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->string('payment_terms')->nullable();
            $table->unsignedInteger('delivery_days')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('audit_logs', function ($table): void {
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('suppliers');
        parent::tearDown();
    }

    public function test_supplier_changes_record_before_and_after_values_without_bank_details(): void
    {
        $service = new SupplierService(new SupplierRepository);
        $actor = $this->createMock(User::class);
        $actor->id = 5;
        $actor->method('hasPermissionCode')->willReturn(false);

        $supplier = $service->create($actor, '127.0.0.1', ['name' => 'Մատակարար A', 'bank_details' => 'not permitted']);
        self::assertSame('', $supplier->getRawOriginal('bank_details'));
        self::assertArrayNotHasKey('bank_details', $supplier->toArray());
        $created = AuditLog::query()->firstOrFail();
        self::assertSame('Մատակարար A', $created->after_data['name']);
        self::assertArrayNotHasKey('bank_details', $created->after_data);

        $updatedSupplier = $service->update($actor, '127.0.0.1', (int) $supplier->id, [
            'name' => 'Մատակարար B',
            'bank_details' => 'still not permitted',
        ]);
        self::assertSame('', $updatedSupplier->getRawOriginal('bank_details'));
        self::assertArrayNotHasKey('bank_details', $updatedSupplier->toArray());
        $updated = AuditLog::query()->where('action', 'Փոփոխություն')->firstOrFail();
        self::assertSame('Մատակարար A', $updated->before_data['name']);
        self::assertSame('Մատակարար B', $updated->after_data['name']);
        self::assertArrayNotHasKey('bank_details', $updated->before_data);
        self::assertArrayNotHasKey('bank_details', $updated->after_data);

        $service->deactivate($actor, '127.0.0.1', (int) $supplier->id);
        $deactivated = AuditLog::query()->where('action', 'Ապաակտիվացում')->firstOrFail();
        self::assertTrue($deactivated->before_data['active']);
        self::assertFalse($deactivated->after_data['active']);
    }
}
