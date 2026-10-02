<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\StockRepository;
use App\Repositories\TransferRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    public function __construct(private readonly StockRepository $stock, private readonly TransferRepository $transfers) {}

    public function lots(User $actor, array $filters): array
    {
        $location = $this->resolveLocation($actor, isset($filters['location_id']) ? (int) $filters['location_id'] : $actor->currentLocationId());

        return $this->stock->lotsForProduct((int) $filters['product_id'], $location, $actor->hasPermissionCode('purchases.view'))->all();
    }

    public function matrix(User $actor, array $filters): array
    {
        $location = (int) $actor->currentLocationId();
        $locations = $this->stock->matrixLocations($location);
        $products = $this->stock->matrixProductsQuery(trim((string) ($filters['search'] ?? '')))
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 5), 100))->withQueryString();
        $quantities = $this->stock->matrixQuantities($products->getCollection()->modelKeys(), $locations->pluck('id')->map(fn ($id) => (int) $id)->all());
        $rows = $products->getCollection()->map(function ($product) use ($locations, $quantities): array {
            $byLocation = [];
            $total = 0.0;
            foreach ($locations as $branch) {
                $quantity = $quantities[$product->id.':'.(int) $branch->id] ?? 0.0;
                $byLocation[(int) $branch->id] = $quantity;
                $total += $quantity;
            }

            return ['id' => (int) $product->id, 'code' => $product->code, 'name' => $product->name, 'unit' => $product->unit,
                'quantities' => $byLocation, 'total' => $total];
        })->values();

        return ['locations' => $locations->values(), 'data' => $rows, 'pagination' => [
            'current_page' => $products->currentPage(), 'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(), 'total' => $products->total(),
        ]];
    }

    public function matrixLocationNames(User $actor): array
    {
        return $this->stock->matrixLocations((int) $actor->currentLocationId())->pluck('name')->all();
    }

    public function eachMatrixExportRow(User $actor, string $search, callable $writer): void
    {
        $locations = $this->stock->matrixLocations((int) $actor->currentLocationId());
        $locationIds = $locations->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->stock->matrixProductsQuery(trim($search))->reorder()->chunkById(500, function ($products) use ($locations, $locationIds, $writer): void {
            $quantities = $this->stock->matrixQuantities($products->modelKeys(), $locationIds);
            foreach ($products as $product) {
                $values = [];
                $total = 0.0;
                foreach ($locations as $branch) {
                    $quantity = $quantities[$product->id.':'.(int) $branch->id] ?? 0.0;
                    $values[] = $quantity;
                    $total += $quantity;
                }
                $writer([$product->code, $product->name, $product->unit, ...$values, $total]);
            }
        });
    }

    public function consume(User $actor, string $ip, array $data): void
    {
        $location = $this->resolveLocation($actor, isset($data['location_id']) ? (int) $data['location_id'] : $actor->currentLocationId());
        DB::transaction(function () use ($actor, $ip, $data, $location): void {
            $product = $this->stock->activeProduct((int) $data['product_id']);
            abort_unless($product, 422, 'Ընտրված ապրանքը ակտիվ չէ։');
            $lots = $data['issue_type'] === 'expired'
                ? $this->stock->expiredLots((int) $product->id, $location)
                : $this->stock->availableLots((int) $product->id, $location);
            $remaining = (float) $data['qty'];
            if ($data['issue_type'] !== 'expired') {
                $free = $this->transfers->freeStock($this->stock->branchIdForLocation($location), (int) $product->id, 0);
                if ($remaining > $free + 0.00001) {
                    throw ValidationException::withMessages(['qty' => ['Քանակը գերազանցում է չպահուստավորված մնացորդը։']]);
                }
            }
            foreach ($lots as $lot) {
                if ($remaining <= 0.00001) {
                    break;
                }
                $take = min($remaining, (float) $lot->qty);
                abort_unless($this->stock->decreaseLot((int) $lot->id, $take) === 1, 409, 'LOT-ի մնացորդը փոխվել է․ ելքը չգրանցվեց։');
                $reason = $data['issue_type'] === 'other' ? trim($data['reason_note'] ?? '') : $data['issue_type'];
                $this->stock->movement((int) $actor->id, 'consumption', (int) $product->id, (int) $lot->id, $location, null,
                    $take, (float) $lot->unit_cost, '', $reason);
                $remaining -= $take;
            }
            if ($remaining > 0.00001) {
                throw ValidationException::withMessages(['qty' => ['Ընտրված տեսակի դուրսգրման համար մնացորդը բավարար չէ։']]);
            }
            $this->stock->audit((int) $actor->id, 'Ապրանքի ելք', 'movements', null, null,
                ['product_id' => (int) $product->id, 'qty' => (float) $data['qty'], 'location_id' => $location, 'issue_type' => $data['issue_type']], $ip);
        });
    }

    public function adjust(User $actor, string $ip, array $data): array
    {
        if (! $actor->hasPermissionCode('purchases.view')) {
            unset($data['unit_cost']);
        }
        $location = $this->resolveLocation($actor, isset($data['location_id']) ? (int) $data['location_id'] : $actor->currentLocationId());

        return DB::transaction(function () use ($actor, $ip, $data, $location): array {
            $product = $this->stock->activeProduct((int) $data['product_id']);
            abort_unless($product, 422, 'Ընտրված ապրանքը ակտիվ չէ։');
            $delta = (float) $data['delta_qty'];
            $lotId = isset($data['lot_id']) ? (int) $data['lot_id'] : 0;
            $before = 0.0;
            $cost = (float) $product->purchase_price;
            if ($lotId) {
                $lot = $this->stock->lockedLot($lotId, (int) $product->id, $location);
                abort_unless($lot, 404, 'LOT-ը տվյալ պահեստում չի գտնվել։');
                $before = (float) $lot->qty;
                abort_if($delta > 0 && $lot->expires_on && $lot->expires_on->lt(now()->startOfDay()), 422, 'Ժամկետանց LOT-ի դրական մնացորդ ստեղծել չի կարելի։');
                if ($delta < 0) {
                    if (abs($delta) > $before + 0.00001) {
                        throw ValidationException::withMessages(['delta_qty' => ['Ճշգրտման նվազումը գերազանցում է LOT-ի մնացորդը։']]);
                    }
                    $expiredLot = $lot->expires_on && $lot->expires_on->lt(now()->startOfDay());
                    if (! $expiredLot) {
                        $free = $this->transfers->freeStock($this->stock->branchIdForLocation($location), (int) $product->id, 0);
                        if (abs($delta) > $free + 0.00001) {
                            throw ValidationException::withMessages(['delta_qty' => ['Նվազեցումը կխախտի արդեն պահուստավորված քանակը։']]);
                        }
                    }
                    abort_unless($this->stock->decreaseLot($lotId, abs($delta)) === 1, 409, 'LOT-ի մնացորդը փոխվել է։');
                } else {
                    $this->stock->increaseLot($lotId, $delta);
                }
                $cost = (float) $lot->unit_cost;
            } else {
                abort_if($delta < 0, 422, 'Մնացորդը նվազեցնելու համար ընտրեք առկա LOT-ը։');
                $lotNo = trim($data['lot_no'] ?? '');
                abort_if($lotNo === '', 422, 'Նոր մնացորդի համար LOT-ի համարը պարտադիր է։');
                if ($product->expiry_control && empty($data['expires_on'])) {
                    throw ValidationException::withMessages(['expires_on' => ['Այս ապրանքի համար պիտանելիության ժամկետը պարտադիր է։']]);
                }
                if (! empty($data['expires_on']) && $data['expires_on'] < now()->toDateString()) {
                    throw ValidationException::withMessages(['expires_on' => ['Նոր LOT-ի պիտանելիության ժամկետն անցած է։']]);
                }
                $cost = (float) ($data['unit_cost'] ?? $product->purchase_price);
                abort_if($this->stock->lotExists((int) $product->id, $location, $lotNo, $data['expires_on'] ?? null), 422, 'Այս LOT-ը տվյալ պահեստում արդեն գոյություն ունի։');
                $lotId = (int) $this->stock->createLot(['product_id' => $product->id, 'location_id' => $location, 'lot_no' => $lotNo,
                    'expires_on' => $data['expires_on'] ?? null, 'received_on' => now()->toDateString(), 'unit_cost' => $cost,
                    'bin_location' => trim($data['bin_location'] ?? ''), 'qty' => $delta])->id;
            }

            $this->stock->movement((int) $actor->id, 'inventory_adjustment', (int) $product->id, $lotId,
                $delta < 0 ? $location : null, $delta > 0 ? $location : null, abs($delta), $cost, '', trim($data['reason']));
            $this->stock->audit((int) $actor->id, 'Պաշարի ճշգրտում', 'stock_lots', $lotId, ['qty' => $before],
                ['qty' => $before + $delta, 'reason' => trim($data['reason'])], $ip);

            return ['lot_id' => $lotId, 'qty' => $before + $delta];
        });
    }

    private function resolveLocation(User $actor, int $location): int
    {
        if ($actor->currentLocationId() > 0) {
            abort_unless($location === $actor->currentLocationId(), 403, 'Կարող եք աշխատել միայն ձեր մասնաճյուղի մնացորդով։');
        }
        abort_unless($location === 0 || $this->stock->branchIsActive($location), 422, 'Ընտրված պահեստը ակտիվ չէ։');

        return $location;
    }
}
