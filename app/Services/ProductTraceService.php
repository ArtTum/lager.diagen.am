<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\ProductTraceRepository;

class ProductTraceService
{
    public function __construct(private readonly ProductTraceRepository $trace) {}

    public function show(User $actor, int $productId, array $filters = []): array
    {
        $product = $this->trace->product($productId);
        abort_unless($product->active, 404, 'Ապրանքը ակտիվ ցանկում չի գտնվել։');

        $location = (int) $actor->currentLocationId();
        $showCosts = $actor->hasPermissionCode('purchases.view');
        $showSuppliers = $actor->hasPermissionCode('suppliers.view');
        if (! $showCosts) {
            $product->makeHidden('purchase_price');
        }
        if (! $showSuppliers) {
            $product->makeHidden('supplier');
        }

        return [
            'product' => $product,
            'show_costs' => $showCosts,
            'lots' => $this->trace->lots((int) $product->id, $location, $showCosts, $showSuppliers, max(1, (int) ($filters['lots_page'] ?? 1))),
            'movements' => $this->trace->movements((int) $product->id, $location, max(1, (int) ($filters['movements_page'] ?? 1))),
            'requests' => $this->trace->requests((int) $product->id, $location, max(1, (int) ($filters['requests_page'] ?? 1))),
        ];
    }
}
