<?php

namespace App\Services;

use App\Models\StockLot;
use App\Models\User;
use App\Repositories\MovementRepository;
use App\Repositories\StockRepository;
use App\Repositories\TransferRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MovementService
{
    private const REVERSIBLE_TYPES = ['consumption', 'inventory_adjustment', 'receipt', 'return_supplier'];

    public function __construct(private readonly MovementRepository $movements, private readonly StockRepository $stock, private readonly TransferRepository $transfers) {}

    public function index(User $actor, array $filters): array
    {
        $showSuppliers = $actor->hasPermissionCode('suppliers.view');
        $query = $this->filteredQuery($actor, $filters, $showSuppliers);
        $rows = $query->paginate(min(max((int) ($filters['per_page'] ?? 15), 5), 100));
        if (! $actor->hasPermissionCode('purchases.view')) {
            foreach ($rows->items() as $row) {
                $row->makeHidden('unit_cost');
            }
        }

        return ['data' => $rows->items(), 'filters' => $this->filterOptions($actor, $showSuppliers), 'pagination' => [
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]];
    }

    /** @return array{headers: list<string>, query: Builder, show_cost: bool} */
    public function export(User $actor, array $filters): array
    {
        $showCost = $actor->hasPermissionCode('purchases.view');
        $showSuppliers = $actor->hasPermissionCode('suppliers.view');
        $headers = ['Ամսաթիվ', 'Փաստաթուղթ', 'Գործողություն', 'Կոդ', 'Ապրանք', 'Խումբ', 'LOT'];
        if ($showSuppliers) {
            $headers[] = 'Մատակարար';
        }
        array_push($headers, 'Պահեստից', 'Պահեստ', 'Քանակ');
        if ($showCost) {
            $headers[] = 'Միավորի գին';
        }
        array_push($headers, 'Կատարող', 'Պատճառ');

        return ['headers' => $headers, 'query' => $this->filteredQuery($actor, $filters, $showSuppliers),
            'show_cost' => $showCost, 'show_supplier' => $showSuppliers];
    }

    private function filteredQuery(User $actor, array $filters, bool $showSuppliers): Builder
    {
        if (! $showSuppliers) {
            unset($filters['supplier_id']);
        }
        $query = $this->movements->query($showSuppliers)->orderByDesc('movements.happened_at')->orderByDesc('movements.id');
        $location = (int) $actor->currentLocationId();
        if ($location > 0) {
            $query->where(fn ($where) => $where->where('movements.from_location', $location)->orWhere('movements.to_location', $location));
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->whereDate('movements.happened_at', $operator, $filters[$key]);
            }
        }
        if (! empty($filters['product_id'])) {
            $query->where('movements.product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('p.category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('l.supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['actor_id'])) {
            $query->where('movements.actor_id', (int) $filters['actor_id']);
        }
        if (! empty($filters['type'])) {
            $query->where('movements.type', $filters['type']);
        }
        if (! empty($filters['lot'])) {
            $query->where('l.lot_no', 'like', '%'.trim($filters['lot']).'%');
        }
        if (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $branchId = (int) $filters['branch_id'];
            $query->where(fn ($where) => $where->where('movements.from_location', $branchId)->orWhere('movements.to_location', $branchId));
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($where) => $where->where('movements.movement_no', 'like', "%{$search}%")
                ->orWhere('movements.reference', 'like', "%{$search}%")->orWhere('movements.reason', 'like', "%{$search}%")
                ->orWhere('p.code', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%"));
        }

        return $query;
    }

    public function reverse(User $actor, string $ip, int $movementId, array $data): array
    {
        return DB::transaction(function () use ($actor, $ip, $movementId, $data): array {
            $original = $this->movements->locked($movementId);
            abort_unless($original, 404, 'Պահեստային շարժը չի գտնվել։');
            abort_if($this->movements->correctionExists($movementId), 409, 'Այս շարժն արդեն հակադարձվել է։');
            $from = $original->from_location === null ? null : (int) $original->from_location;
            $to = $original->to_location === null ? null : (int) $original->to_location;
            $oneSided = ($from !== null && $to === null) || ($from === null && $to !== null);
            if (! in_array($original->type, self::REVERSIBLE_TYPES, true) || ! $oneSided) {
                throw ValidationException::withMessages(['movement' => ['Այս շարժի տեսակը ավտոմատ հակադարձում չի աջակցում։ Ուղղումը կատարեք գույքագրման միջոցով։']]);
            }
            $location = $from ?? $to;
            $actorLocation = (int) $actor->currentLocationId();
            if ($actorLocation > 0 && $actorLocation !== $location) {
                abort(403, 'Կարող եք հակադարձել միայն ձեր մասնաճյուղի շարժը։');
            }
            if (! $original->lot_id) {
                throw ValidationException::withMessages(['movement' => ['Շարժի LOT-ը չի գտնվել։ Ստուգեք սկզբնական փաստաթուղթը։']]);
            }
            /** @var StockLot|null $lot */
            $lot = $this->movements->lockedLot((int) $original->lot_id, (int) $original->product_id, (int) $location);
            abort_unless($lot, 409, 'Սկզբնական LOT-ը տվյալ պահեստում չի գտնվել։');
            $quantity = (float) $original->qty;
            $reverseFrom = null;
            $reverseTo = null;
            if ($from !== null) {
                $this->movements->increaseLot((int) $lot->id, $quantity);
                $reverseTo = $from;
            } else {
                $current = (float) $lot->qty;
                if ($current + 0.00001 < $quantity) {
                    throw ValidationException::withMessages(['movement' => ['Այս LOT-ի մնացորդը պակասել է սկզբնական շարժի քանակից։ Սկսեք գույքագրում՝ ուղղումը գրանցելու համար։']]);
                }
                if (! $lot->expires_on || $lot->expires_on->gte(now()->startOfDay())) {
                    $free = $this->transfers->freeStock($this->stock->branchIdForLocation((int) $location), (int) $original->product_id, 0);
                    if ($quantity > $free + 0.00001) {
                        throw ValidationException::withMessages(['movement' => ['Հակադարձումը կխախտի արդեն պահուստավորված մնացորդը։']]);
                    }
                }
                if ($this->movements->decreaseLot((int) $lot->id, $quantity) !== 1) {
                    abort(409, 'LOT-ի մնացորդը փոխվել է։ Հակադարձումը չգրանցվեց։');
                }
                $reverseFrom = $to;
                if ($original->type === 'receipt') {
                    $orderItem = $this->movements->receiptOrderItem((int) $lot->id, (string) $original->reference, (int) $original->product_id);
                    if ($orderItem && $this->movements->reduceReceivedQuantity($orderItem, $quantity) !== 1) {
                        abort(409, 'Գնման պատվերի մուտքագրված քանակը փոխվել է։');
                    }
                }
            }

            $correctionNo = 'ՀԱԿ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
            $this->stock->movement((int) $actor->id, 'movement_reversal', (int) $original->product_id, (int) $lot->id,
                $reverseFrom, $reverseTo, $quantity, (float) $original->unit_cost, $correctionNo, trim($data['reason']));
            $correction = $this->movements->createCorrection(['movement_id' => $original->id, 'correction_no' => $correctionNo,
                'reason' => trim($data['reason']), 'actor_id' => $actor->id, 'created_at' => now()]);
            $this->stock->audit((int) $actor->id, 'Պահեստային շարժը հակադարձվեց', 'movements', (int) $original->id,
                ['movement_no' => $original->movement_no, 'qty' => $quantity], ['correction_no' => $correctionNo, 'reason' => trim($data['reason'])], $ip);

            return ['correction' => $correction, 'movement_no' => $original->movement_no, 'lot_qty' => $this->movements->lotQuantity((int) $lot->id)];
        });
    }

    private function filterOptions(User $actor, bool $showSuppliers): array
    {
        $location = (int) $actor->currentLocationId();
        $options = $this->movements->filters($location, $showSuppliers);
        if ($location > 0) {
            $options['branches'] = $options['branches']->where('id', $location)->values();
        }

        return $options;
    }
}
