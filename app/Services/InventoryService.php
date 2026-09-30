<?php

namespace App\Services;

use App\Models\InventoryLine;
use App\Models\InventorySession;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\StockRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private readonly InventoryRepository $inventory, private readonly StockRepository $stock) {}

    public function index(User $actor, array $filters): array
    {
        $location = (int) $actor->currentLocationId();
        $query = $this->inventory->listQuery($location, trim((string) ($filters['search'] ?? '')));
        $this->applyListFilters($query, $filters);
        $rows = $query->paginate(min(max((int) ($filters['per_page'] ?? 15), 5), 100));

        return ['data' => $rows->items(), 'locations' => $this->inventory->activeLocations($location), 'pagination' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]];
    }

    /** @return array{headers: list<string>, rows: iterable<list<mixed>>} */
    public function export(User $actor, array $filters): array
    {
        $query = $this->inventory->listQuery((int) $actor->currentLocationId(), trim((string) ($filters['search'] ?? '')));
        $this->applyListFilters($query, $filters);

        return ['headers' => ['Գույքագրում', 'Պահեստ', 'Կարգավիճակ', 'Տողերի քանակ', 'Հաշվված տողեր', 'Սկսվել է', 'Սկսել է', 'Փակվել է', 'Հաստատել է'],
            'rows' => (function () use ($query): \Generator {
                foreach ($query->lazy(500) as $session) {
                    yield [$session->inventory_no, $session->location?->name ?? 'Կենտրոնական պահեստ', ['open' => 'Հաշվարկման փուլում', 'counted' => 'Սպասում է հաստատման', 'closed' => 'Փակված'][$session->status] ?? $session->status,
                        $session->lines_count, $session->counted_lines_count, $session->started_at?->toDateTimeString(),
                        $session->starter?->name, $session->closed_at?->toDateTimeString(), $session->approver?->name];
                }
            })()];
    }

    private function applyListFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters): void
    {
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('started_at', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->whereDate('started_at', '<=', $filters['to']);
        }
    }

    public function show(User $actor, int $sessionId): array
    {
        $session = $this->inventory->findWithLines($sessionId);
        $this->assertLocation($actor, (int) $session->location_id);
        if (! $actor->hasPermissionCode('purchases.view')) {
            foreach ($session->lines as $line) {
                $line->makeHidden('counted_unit_cost');
                $line->product?->makeHidden('purchase_price');
            }
        }

        return ['session' => $session, 'suppliers' => $this->inventory->activeSuppliers()];
    }

    public function act(User $actor, int $sessionId): array
    {
        $session = $this->inventory->findForAct($sessionId);
        abort_unless($session->status === 'closed', 409, 'Տպվող ակտը հասանելի է միայն փակված գույքագրման համար։');
        $this->assertLocation($actor, (int) $session->location_id);

        return [
            'inventory_no' => $session->inventory_no,
            'location' => $session->location?->name ?? 'Կենտրոնական պահեստ',
            'started_at' => $session->started_at,
            'closed_at' => $session->closed_at,
            'starter' => $session->starter?->name,
            'approver' => $session->approver?->name,
            'note' => $session->note,
            'lines' => $session->lines->map(static fn (InventoryLine $line): array => [
                'code' => $line->product?->code,
                'product' => $line->product?->name,
                'unit' => $line->product?->unit,
                'lot_no' => $line->lot?->lot_no,
                'expected_qty' => (float) $line->expected_qty,
                'counted_qty' => (float) $line->counted_qty,
                'difference' => (float) $line->counted_qty - (float) $line->expected_qty,
                'reason' => $line->difference_reason,
            ])->values()->all(),
        ];
    }

    public function start(User $actor, string $ip, array $data): InventorySession
    {
        $location = (int) $data['location_id'];
        $this->assertLocation($actor, $location);
        abort_unless($this->inventory->activeLocation($location), 422, 'Ընտրված պահեստը ակտիվ չէ։');

        return DB::transaction(function () use ($actor, $ip, $data, $location): InventorySession {
            $session = $this->inventory->createSession([
                'inventory_no' => 'ԳՈՒՅ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),
                'location_id' => $location, 'status' => 'open', 'started_by' => $actor->id,
                'started_at' => now(), 'note' => trim((string) ($data['note'] ?? '')) ?: null,
            ]);
            $lines = [];
            foreach ($this->inventory->snapshotLots($location) as $lot) {
                $lines[] = ['session_id' => $session->id, 'product_id' => $lot->product_id, 'lot_id' => $lot->id, 'expected_qty' => $lot->qty];
            }
            foreach ($this->inventory->activeProductsWithoutStock($location) as $product) {
                $lines[] = ['session_id' => $session->id, 'product_id' => $product->id, 'lot_id' => null, 'expected_qty' => 0];
            }
            if (! $lines) {
                throw ValidationException::withMessages(['location_id' => ['Գույքագրման համար ակտիվ ապրանքներ չկան։']]);
            }
            $this->inventory->createLines($lines);
            $this->stock->audit((int) $actor->id, 'Գույքագրում սկսվեց', 'inventory_sessions', (int) $session->id, null,
                ['inventory_no' => $session->inventory_no, 'location_id' => $location, 'line_count' => count($lines)], $ip);

            return $this->inventory->sessionWithLineCount($session);
        });
    }

    public function count(User $actor, string $ip, int $sessionId, array $data): InventorySession
    {
        return DB::transaction(function () use ($actor, $ip, $sessionId, $data): InventorySession {
            $session = $this->inventory->lockSession($sessionId, ['open', 'counted']);
            abort_unless($session, 409, 'Գույքագրումը բաց չէ կամ արդեն հաստատվել է։');
            $this->assertLocation($actor, (int) $session->location_id);
            $lines = $this->inventory->lockLines($sessionId);
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['counts' => ['Գույքագրման տողերը չեն գտնվել։']]);
            }
            $counts = $data['counts'];
            $expectedIds = $lines->map(fn (InventoryLine $line) => (string) $line->id)->sort()->values()->all();
            $providedIds = collect(array_keys($counts))->map(fn ($id) => (string) $id)->sort()->values()->all();
            if ($expectedIds !== $providedIds) {
                throw ValidationException::withMessages(['counts' => ['Պետք է լրացնել գույքագրման բոլոր տողերը՝ առանց ավելորդ կամ բացակայող ապրանքի։']]);
            }

            $previousStatus = (string) $session->status;
            foreach ($lines as $line) {
                $input = $counts[$line->id];
                if (! $actor->hasPermissionCode('purchases.view')) {
                    unset($input['unit_cost']);
                }
                $quantity = (float) $input['counted_qty'];
                $reason = trim((string) ($input['reason'] ?? ''));
                if (abs($quantity - (float) $line->expected_qty) > 0.00001 && $reason === '') {
                    throw ValidationException::withMessages(["counts.{$line->id}.reason" => ['Տարբերության յուրաքանչյուր տողի համար գրեք պատճառը։']]);
                }
                $newLot = ! $line->lot_id && $quantity > 0;
                $lotNo = trim((string) ($input['lot_no'] ?? ''));
                $expiry = $input['expires_on'] ?? null;
                if ($newLot && $line->product->lot_control && $lotNo === '') {
                    throw ValidationException::withMessages(["counts.{$line->id}.lot_no" => ['LOT-ով վերահսկվող ապրանքի համար LOT-ի համարը պարտադիր է։']]);
                }
                if ($newLot && $line->product->expiry_control && ! $expiry) {
                    throw ValidationException::withMessages(["counts.{$line->id}.expires_on" => ['Ժամկետով վերահսկվող ապրանքի համար պիտանելիության ժամկետը պարտադիր է։']]);
                }
                if ($newLot && $expiry && $expiry < now()->toDateString()) {
                    throw ValidationException::withMessages(["counts.{$line->id}.expires_on" => ['Նոր LOT-ի պիտանելիության ժամկետն անցած է։']]);
                }
                $supplierId = $input['supplier_id'] ?? null;
                if ($newLot && $supplierId && ! $this->inventory->activeSupplier((int) $supplierId)) {
                    throw ValidationException::withMessages(["counts.{$line->id}.supplier_id" => ['Ընտրված մատակարարը ակտիվ չէ։']]);
                }
                $this->inventory->saveCount($line, [
                    'counted_qty' => $quantity, 'difference_reason' => $reason ?: null,
                    'counted_lot_no' => $newLot ? ($lotNo ?: null) : null, 'counted_expires_on' => $newLot ? $expiry : null,
                    'counted_supplier_id' => $newLot ? $supplierId : null,
                    'counted_bin_location' => $newLot ? (trim((string) ($input['bin_location'] ?? '')) ?: null) : null,
                    'counted_unit_cost' => $newLot && isset($input['unit_cost']) ? (float) $input['unit_cost'] : null,
                ]);
            }
            $this->inventory->updateSession($session, ['status' => 'counted']);
            $this->stock->audit((int) $actor->id, 'Գույքագրման քանակները ներկայացվեցին հաստատման', 'inventory_sessions', $sessionId,
                ['status' => $previousStatus], ['status' => 'counted', 'line_count' => $lines->count()], $ip);

            return $this->inventory->sessionWithLineCount($session);
        });
    }

    public function approve(User $actor, string $ip, int $sessionId): InventorySession
    {
        return DB::transaction(function () use ($actor, $ip, $sessionId): InventorySession {
            $session = $this->inventory->lockSession($sessionId, ['counted']);
            abort_unless($session, 409, 'Հաստատման սպասող գույքագրումը չի գտնվել։');
            abort_if((int) $session->started_by === (int) $actor->id, 422, 'Գույքագրումը հաստատողը պետք է տարբերվի այն սկսած աշխատակցից։');
            $this->assertLocation($actor, (int) $session->location_id);
            $lines = $this->inventory->lockLines($sessionId);
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['session' => ['Գույքագրման տողերը չեն գտնվել։']]);
            }

            foreach ($lines as $line) {
                if ($line->counted_qty === null || (float) $line->counted_qty < 0) {
                    throw ValidationException::withMessages(['session' => ['Բոլոր տողերը պետք է հաշվարկված լինեն։']]);
                }
                $count = (float) $line->counted_qty;
                $expected = (float) $line->expected_qty;
                $reason = trim((string) $line->difference_reason);
                $lot = $line->lot_id ? $this->inventory->lockedLot((int) $line->lot_id) : null;
                $current = $lot ? (float) $lot->qty : 0.0;
                if (! $lot && ! $line->lot_id) {
                    $current = $this->inventory->quantityForProductAtLocation((int) $line->product_id, (int) $session->location_id);
                }
                if (abs($current - $expected) > 0.00001) {
                    throw ValidationException::withMessages(['session' => ["{$line->product->name} ապրանքի մնացորդը գույքագրումից հետո փոխվել է։ Ստուգեք շարժերը և սկսեք նոր գույքագրում։"]]);
                }
                if (abs($count - $expected) > 0.00001 && $reason === '') {
                    throw ValidationException::withMessages(['session' => ['Տարբերության պատճառը պարտադիր է։']]);
                }
                if (abs($count - $expected) < 0.00001) {
                    continue;
                }

                if (! $lot) {
                    if ($count <= 0) {
                        continue;
                    }
                    $lotNo = trim((string) $line->counted_lot_no);
                    if ($line->product->lot_control && $lotNo === '') {
                        throw ValidationException::withMessages(['session' => ['Նոր LOT-ի համարը պարտադիր է։']]);
                    }
                    $expiry = $line->counted_expires_on?->toDateString();
                    if ($line->product->expiry_control && ! $expiry) {
                        throw ValidationException::withMessages(['session' => ['Նոր LOT-ի պիտանելիության ժամկետը պարտադիր է։']]);
                    }
                    $lot = $this->inventory->createLot([
                        'product_id' => $line->product_id, 'location_id' => $session->location_id,
                        'lot_no' => $lotNo ?: 'INV-'.$session->inventory_no, 'expires_on' => $expiry,
                        'received_on' => now()->toDateString(), 'supplier_id' => $line->counted_supplier_id,
                        'unit_cost' => $line->counted_unit_cost ?? $line->product->purchase_price,
                        'bin_location' => $line->counted_bin_location, 'qty' => $count,
                    ]);
                    $this->inventory->linkLineToLot($line, (int) $lot->id);
                    $this->stock->movement((int) $actor->id, 'inventory_adjustment', (int) $line->product_id, (int) $lot->id, null,
                        (int) $session->location_id, $count, (float) $lot->unit_cost, $session->inventory_no, $reason);

                    continue;
                }

                abort_if($count > 0 && $lot->expires_on && $lot->expires_on->lt(now()->startOfDay()), 422, 'Ժամկետանց LOT-ի դրական մնացորդ ստեղծել չի կարելի։');
                $delta = $count - $current;
                $lot = $this->inventory->setLotQuantity($lot, $count);
                $this->stock->movement((int) $actor->id, 'inventory_adjustment', (int) $line->product_id, (int) $lot->id,
                    $delta < 0 ? (int) $session->location_id : null, $delta > 0 ? (int) $session->location_id : null,
                    abs($delta), (float) $lot->unit_cost, $session->inventory_no, $reason);
            }

            $this->inventory->updateSession($session, ['status' => 'closed', 'approved_by' => $actor->id, 'closed_at' => now()]);
            $this->stock->audit((int) $actor->id, 'Գույքագրումը հաստատվեց և փակվեց', 'inventory_sessions', $sessionId,
                ['status' => 'counted'], ['status' => 'closed', 'started_by' => (int) $session->started_by, 'approved_by' => (int) $actor->id], $ip);

            return $this->inventory->sessionWithLineCount($session);
        });
    }

    private function assertLocation(User $actor, int $location): void
    {
        $actorLocation = (int) $actor->currentLocationId();
        if ($actorLocation > 0) {
            abort_unless($location === $actorLocation, 403, 'Կարող եք աշխատել միայն ձեր մասնաճյուղի գույքագրմամբ։');
        }
    }
}
