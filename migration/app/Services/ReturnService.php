<?php

namespace App\Services;

use App\Models\ProductReturn;
use App\Models\User;
use App\Repositories\ReturnRepository;
use App\Repositories\StockRepository;
use App\Repositories\TransferRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    public function __construct(private readonly ReturnRepository $returns, private readonly StockRepository $stock, private readonly TransferRepository $transfers) {}

    public function index(User $actor, array $filters): array
    {
        $query = $this->filteredQuery($actor, $filters);
        $rows = $query->paginate(min(max((int) ($filters['per_page'] ?? 15), 5), 100));

        return ['data' => $rows->items(), 'pagination' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]];
    }

    /** @return array{headers: list<string>, rows: iterable<list<mixed>>} */
    public function export(User $actor, array $filters): array
    {
        $headers = ['Վերադարձ', 'Ուղղություն', 'Պահեստ / մատակարար', 'Կոդ', 'Ապրանք', 'LOT', 'Քանակ', 'Պատճառ', 'Ամսաթիվ'];
        $query = $this->filteredQuery($actor, $filters);

        return ['headers' => $headers, 'rows' => (function () use ($query): \Generator {
            foreach ($query->lazy(500) as $row) {
                yield [$row->return_no, $row->direction === 'central_to_supplier' ? 'Կենտրոն → Մատակարար' : 'Մասնաճյուղ → Կենտրոն', $row->direction === 'central_to_supplier' ? $row->supplier?->name : ($row->branch?->name ?? 'Կենտրոնական պահեստ'),
                    $row->product?->code, $row->product?->name, $row->lot?->lot_no, $row->qty, $row->reason, $row->created_at?->toDateTimeString()];
            }
        })()];
    }

    private function filteredQuery(User $actor, array $filters): Builder
    {
        $query = $this->returns->query()->orderByDesc('id');
        $location = (int) $actor->currentLocationId();
        if ($location > 0) {
            $query->where('from_location', $location);
        }
        if (filled($filters['direction'] ?? null)) {
            $query->where('direction', $filters['direction']);
        }
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($where) => $where->where('return_no', 'like', "%{$search}%")
                ->orWhere('direction', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                ->orWhereHas('branch', fn ($branch) => $branch->where('name', 'like', "%{$search}%"))
                ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%")));
        }

        return $query;
    }

    public function options(User $actor): array
    {
        $location = (int) $actor->currentLocationId();
        $branches = $this->returns->branches();
        if ($location > 0) {
            $branches = $branches->where('id', $location)->values();
        }

        return ['branches' => $branches, 'products' => $this->returns->products(), 'suppliers' => $location === 0 ? $this->returns->suppliers() : []];
    }

    public function create(User $actor, string $ip, array $data): ProductReturn
    {
        $direction = (string) $data['direction'];
        $actorLocation = (int) $actor->currentLocationId();
        if ($direction === 'central_to_supplier' && $actorLocation !== 0) {
            abort(403, 'Մատակարարին վերադարձ կարող է գրանցել միայն կենտրոնական պահեստը։');
        }
        $location = $direction === 'central_to_supplier' ? 0 : (int) ($data['from_location'] ?? $actorLocation);
        if ($actorLocation > 0) {
            $location = $actorLocation;
        }
        if ($direction === 'branch_to_central' && $location === 0) {
            throw ValidationException::withMessages(['from_location' => ['Մասնաճյուղից վերադարձի համար ընտրեք մասնաճյուղ։']]);
        }
        if ($direction === 'branch_to_central' && ! $this->returns->activeBranch($location)) {
            throw ValidationException::withMessages(['from_location' => ['Ընտրված մասնաճյուղը ակտիվ չէ։']]);
        }

        $supplierId = $direction === 'central_to_supplier' ? (int) ($data['supplier_id'] ?? 0) : null;
        if ($supplierId && ! $this->returns->activeSupplier($supplierId)) {
            throw ValidationException::withMessages(['supplier_id' => ['Ընտրված մատակարարը ակտիվ չէ։']]);
        }
        foreach ($data['items'] as $item) {
            if (! $this->returns->activeProduct((int) $item['product_id'])) {
                throw ValidationException::withMessages(['items' => ['Ընտրված ապրանքներից մեկը ակտիվ չէ։']]);
            }
        }
        $reason = trim((string) $data['reason']);

        return DB::transaction(function () use ($actor, $ip, $data, $direction, $location, $supplierId, $reason): ProductReturn {
            $number = 'ՎԵՐ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
            $totalQuantity = 0.0;
            $created = null;
            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $requested = (float) $item['qty'];
                $lots = $this->returns->eligibleLots($productId, $location, $supplierId);
                $eligible = (float) $lots->sum(static fn ($lot): float => (float) $lot->qty);
                $expired = (float) $lots->filter(fn ($lot) => $lot->expires_on && $lot->expires_on->lt(now()->startOfDay()))->sum(static fn ($lot): float => (float) $lot->qty);
                $eligibleUnexpired = $eligible - $expired;
                $freeUnexpired = $this->transfers->freeStock($this->stock->branchIdForLocation($location), $productId, 0);
                // Reservations are product/location totals, not tied to the
                // selected supplier. Cap the selected supplier's return by the
                // total unreserved quantity instead of subtracting every
                // reservation from that supplier's own LOT quantity.
                $allowed = $expired + min($eligibleUnexpired, $freeUnexpired);
                if ($requested > $allowed + 0.00001) {
                    $format = static fn (float $quantity): string => rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
                    $label = $this->returns->productLabel($productId);
                    throw ValidationException::withMessages(['items' => [
                        '«'.$label.'» ապրանքի վերադարձի համար մնացորդը բավարար չէ։ Պահանջված՝ '.$format($requested).'։ Այժմ վերադարձի առավելագույն հասանելի քանակը՝ '.$format($allowed).'.',
                    ]]);
                }

                $remaining = $requested;
                foreach ($lots as $lot) {
                    if ($remaining <= 0.00001) {
                        break;
                    }
                    $take = min($remaining, (float) $lot->qty);
                    if ($this->stock->decreaseLot((int) $lot->id, $take) !== 1) {
                        abort(409, 'LOT-ի մնացորդը փոխվել է․ վերադարձը չգրանցվեց։');
                    }
                    $destinationLot = null;
                    if ($direction === 'branch_to_central') {
                        $destinationLot = $this->returns->findCentralLot($productId, (string) $lot->lot_no, $lot->expires_on?->toDateString());
                        if ($destinationLot) {
                            $this->returns->addToLot((int) $destinationLot->id, $take);
                        } else {
                            $destinationLot = $this->stock->createLot([
                                'product_id' => $productId, 'location_id' => 0, 'lot_no' => $lot->lot_no,
                                'expires_on' => $lot->expires_on?->toDateString(), 'received_on' => now()->toDateString(),
                                'supplier_id' => $lot->supplier_id, 'unit_cost' => $lot->unit_cost,
                                'bin_location' => $lot->bin_location, 'qty' => $take,
                            ]);
                        }
                    }
                    $this->stock->movement((int) $actor->id, $direction === 'branch_to_central' ? 'return_in' : 'return_supplier',
                        $productId, $direction === 'branch_to_central' ? (int) $destinationLot->id : (int) $lot->id,
                        $location, $direction === 'branch_to_central' ? 0 : null, $take, (float) $lot->unit_cost, $number, $reason);
                    $created = $this->returns->createReturn([
                        'return_no' => $number, 'direction' => $direction, 'from_location' => $location,
                        'supplier_id' => $supplierId, 'product_id' => $productId, 'lot_id' => $lot->id,
                        'qty' => $take, 'reason' => $reason, 'status' => 'posted', 'actor_id' => $actor->id, 'created_at' => now(),
                    ]);
                    $remaining -= $take;
                    $totalQuantity += $take;
                }
                if ($remaining > 0.00001) {
                    throw ValidationException::withMessages(['items' => ['Վերադարձի քանակը հնարավոր չէր ամբողջությամբ հատկացնել LOT-երին։']]);
                }
            }
            $this->stock->audit((int) $actor->id, 'Ապրանքի վերադարձ գրանցվեց', 'returns', (int) $created?->id, null,
                ['return_no' => $number, 'direction' => $direction, 'from_location' => $location, 'supplier_id' => $supplierId,
                    'total_qty' => $totalQuantity, 'lines' => count($data['items'])], $ip);

            return $created ? $this->returns->loadCreatedRelations($created) : throw new \LogicException('Վերադարձի փաստաթուղթը չի ստեղծվել։');
        });
    }
}
