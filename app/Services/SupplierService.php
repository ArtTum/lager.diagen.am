<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\User;
use App\Repositories\SupplierRepository;
use Illuminate\Support\Facades\DB;

class SupplierService
{
    public function __construct(private readonly SupplierRepository $suppliers) {}

    public function index(array $filters, bool $showFinancial = false)
    {
        $rows = $this->suppliers->paginate($filters);
        if (! $showFinancial) {
            foreach ($rows->items() as $supplier) {
                $supplier->makeHidden('bank_details');
            }
        }

        return $rows;
    }

    public function show(int $id, bool $showFinancial = false): Supplier
    {
        $supplier = $this->suppliers->find($id);
        if (! $showFinancial) {
            $supplier->makeHidden('bank_details');
        }

        return $supplier;
    }

    public function history(int $id, bool $showPrices, int $location, array $filters = []): array
    {
        $history = $this->suppliers->history($id, $location, $showPrices, $filters);
        if (! $showPrices) {
            $history['lots']->getCollection()->each(static fn ($lot) => $lot->makeHidden('unit_cost'));
            $history['receipts']->getCollection()->each(static function ($receipt): void {
                $receipt->setRelation('lines', $receipt->lines->map(static function (array $line): array {
                    unset($line['unit_cost']);

                    return $line;
                }));
            });
        }

        $history['show_prices'] = $showPrices;

        return $history;
    }

    public function create(User $actor, string $ip, array $data): Supplier
    {
        if (! $actor->hasPermissionCode('purchases.view')) {
            unset($data['bank_details']);
            $data['bank_details'] = '';
        }

        return DB::transaction(function () use ($actor, $ip, $data): Supplier {
            $supplier = $this->suppliers->create($data);
            $this->audit($actor, $ip, 'Ստեղծում', $supplier, null, $this->auditData($supplier->getAttributes()));
            if (! $actor->hasPermissionCode('purchases.view')) {
                $supplier->makeHidden('bank_details');
            }

            return $supplier;
        });
    }

    public function update(User $actor, string $ip, int $id, array $data): Supplier
    {
        if (! $actor->hasPermissionCode('purchases.view')) {
            unset($data['bank_details']);
        }

        return DB::transaction(function () use ($actor, $ip, $id, $data): Supplier {
            $supplier = $this->suppliers->find($id);
            $before = $this->auditData($supplier->getAttributes());
            $supplier = $this->suppliers->update($supplier, $data);
            $this->audit($actor, $ip, 'Փոփոխություն', $supplier, $before, $this->auditData($supplier->getAttributes()));
            if (! $actor->hasPermissionCode('purchases.view')) {
                $supplier->makeHidden('bank_details');
            }

            return $supplier;
        });
    }

    public function deactivate(User $actor, string $ip, int $id): void
    {
        DB::transaction(function () use ($actor, $ip, $id): void {
            $supplier = $this->suppliers->find($id);
            $before = ['active' => (bool) $supplier->active];
            $this->suppliers->deactivate($supplier);
            $this->audit($actor, $ip, 'Ապաակտիվացում', $supplier, $before, ['active' => false]);
        });
    }

    private function audit(User $actor, string $ip, string $action, Supplier $supplier, ?array $before, ?array $after): void
    {
        $this->suppliers->createAuditEntry(['actor_id' => $actor->id, 'action' => $action, 'entity' => 'suppliers', 'entity_id' => $supplier->id,
            'before_data' => $before, 'after_data' => $after, 'ip_address' => $ip, 'created_at' => now()]);
    }

    /** Keep supplier audit history useful while excluding bank account details. */
    private function auditData(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'tax_id', 'address', 'contact_name', 'phone', 'email', 'contract_no', 'contract_start',
            'contract_end', 'payment_terms', 'delivery_days', 'active',
        ]));
    }
}
