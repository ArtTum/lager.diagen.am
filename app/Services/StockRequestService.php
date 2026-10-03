<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\StockRequestRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockRequestService
{
    public function __construct(private readonly StockRequestRepository $requests) {}

    public function details(int $id, User $actor): object
    {
        $row = $this->requests->findWithItems($id);
        abort_unless($row, 404, 'Պահանջագիրը չի գտնվել։');
        $this->assertVisible($actor, (int) $row->branch_id);
        if ($actor->hasPermissionCode('requests.approve')) {
            $productStats = $this->requests->suggestionsForBranch(
                (int) $row->branch_id,
                $row->items->pluck('product_id')->map(static fn ($id): int => (int) $id)->all(),
            );
            $row->items->each(function ($item): void {
                $item->setAttribute('central_free_qty', $this->requests->centralFreeStock((int) $item->product_id, (int) $item->request_id));
            });
            $row->items->each(static function ($item) use ($productStats): void {
                $stats = $productStats[(int) $item->product_id] ?? ['current' => 0.0, 'average' => 0.0];
                $item->setAttribute('branch_current_qty', $stats['current']);
                $item->setAttribute('branch_monthly_average', $stats['average']);
            });
        }

        return $row;
    }

    /** @return array{branch: int, items: array<int, array{current: float, suggested: float, average: float}>} */
    public function suggestions(User $actor, int $requestedBranch): array
    {
        $branchId = $this->branchForActor($actor, $requestedBranch);
        abort_unless($this->requests->branchIsActive($branchId), 422, 'Պահանջագրի ստացողը պետք է լինի ակտիվ մասնաճյուղ՝ կենտրոնական պահեստից բացի։');

        return ['branch' => $branchId, 'items' => $this->requests->suggestionsForBranch($branchId)];
    }

    public function dispatchDocument(int $id, User $actor): array
    {
        $request = $this->details($id, $actor);
        abort_unless(in_array($request->status, ['shipped', 'received', 'closed'], true), 404, 'Բաշխման փաստաթուղթը հասանելի է միայն ուղարկված պահանջագրերի համար։');

        return [
            'request_no' => $request->request_no,
            'status' => $request->status,
            'urgency' => $request->urgency,
            'reason' => $request->reason,
            'created_at' => $request->created_at,
            'sent_at' => $request->sent_at,
            'received_at' => $request->received_at,
            'branch_name' => $request->branch_name,
            'requester_name' => $request->requester?->name,
            'sender_name' => $request->sender?->name,
            'receiver_name' => $request->receiver?->name,
            'items' => $request->items->map(static fn ($item): array => [
                'code' => $item->code,
                'name' => $item->name,
                'requested_qty' => $item->requested_qty,
                'approved_qty' => $item->approved_qty,
                'unit' => $item->unit,
            ])->all(),
        ];
    }

    public function create(User $actor, string $ip, array $data): array
    {
        $branchId = $this->branchForActor($actor, (int) $data['branch_id']);
        abort_unless($this->requests->branchIsActive($branchId), 422, 'Պահանջագրի ստացողը պետք է լինի ակտիվ մասնաճյուղ՝ կենտրոնական պահեստից բացի։');
        $this->assertActiveProducts($data['items']);
        $status = $data['submit_mode'] === 'draft' ? 'draft' : 'sent';

        return DB::transaction(function () use ($actor, $ip, $data, $branchId, $status): array {
            $number = 'ՊՀ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            $request = $this->requests->create([
                'request_no' => $number, 'branch_id' => $branchId, 'requested_by' => $actor->id,
                'status' => $status, 'urgency' => $data['urgency'], 'reason' => trim($data['reason'] ?? ''), 'created_at' => now(),
            ]);
            $id = (int) $request->id;
            $this->requests->replaceItems($id, $data['items']);
            $this->requests->audit((int) $actor->id, $status === 'draft' ? 'Պահանջագիրը պահվեց որպես սևագիր' : 'Պահանջագիրն ուղարկվեց', $id, null, ['request_no' => $number, 'status' => $status], $ip);

            return ['id' => $id, 'status' => $status];
        });
    }

    public function updateDraft(int $id, User $actor, string $ip, array $data): void
    {
        $this->assertActiveProducts($data['items']);
        DB::transaction(function () use ($id, $actor, $ip, $data): void {
            $row = $this->requests->lock($id);
            abort_unless($row && $row->status === 'draft', 409, 'Միայն սևագիր պահանջագիրը կարող եք փոփոխել։');
            $this->assertOwner($actor, $row);
            $status = $data['submit_mode'] === 'draft' ? 'draft' : 'sent';
            $this->requests->update($id, ['urgency' => $data['urgency'], 'reason' => trim($data['reason'] ?? ''), 'status' => $status]);
            $this->requests->replaceItems($id, $data['items']);
            $this->requests->audit((int) $actor->id, $status === 'sent' ? 'Սևագիր պահանջագիրն ուղարկվեց' : 'Սևագիրը փոփոխվեց', $id, ['status' => 'draft'], ['status' => $status], $ip);
        });
    }

    public function review(int $id, User $actor, string $ip, array $data): void
    {
        abort_unless($actor->currentLocationId() === 0, 403, 'Պահանջագրի որոշումները կատարվում են կենտրոնական պահեստում։');
        DB::transaction(function () use ($id, $actor, $ip, $data): void {
            $row = $this->requests->lock($id);
            abort_unless($row && in_array($row->status, ['sent', 'review'], true), 409, 'Պահանջագիրը հաստատման ենթակա չէ։');
            $items = $this->requests->items($id, true);
            if ($data['decision'] === 'start_review') {
                abort_unless($row->status === 'sent', 409, 'Ստուգման փուլ կարող է տեղափոխվել միայն ուղարկված պահանջագիրը։');
                $this->requests->update($id, ['status' => 'review', 'reviewed_by' => $actor->id]);
                $this->audit($actor, $ip, 'Պահանջագիրը վերցվեց ստուգման', $id, ['status' => 'sent'], ['status' => 'review']);

                return;
            }
            if ($data['decision'] === 'reject') {
                $this->requests->update($id, ['status' => 'rejected', 'rejection_reason' => trim($data['rejection_reason']), 'reviewed_by' => $actor->id]);
                $this->audit($actor, $ip, 'Պահանջագիրը մերժվեց', $id, ['status' => $row->status], ['status' => 'rejected', 'reason' => trim($data['rejection_reason'])]);

                return;
            }
            $approvedTotal = 0.0;
            $partial = false;
            foreach ($items as $item) {
                $qty = (float) ($data['approved'][$item->id] ?? 0);
                if ($qty > (float) $item->requested_qty + 0.00001) {
                    throw ValidationException::withMessages(['approved' => ['Հաստատվող քանակը գերազանցում է պահանջված քանակը։']]);
                }
                if ($qty > $this->requests->centralFreeStock((int) $item->product_id, $id) + 0.00001) {
                    throw ValidationException::withMessages(['approved' => ['Հաստատվող քանակը գերազանցում է ազատ կենտրոնական մնացորդը։']]);
                }
                if ($qty + 0.00001 < (float) $item->requested_qty) {
                    $partial = true;
                }
                $approvedTotal += $qty;
                $this->requests->updateItem((int) $item->id, ['approved_qty' => $qty]);
            }
            if ($approvedTotal <= 0) {
                throw ValidationException::withMessages(['approved' => ['Հաստատեք դրական քանակ կամ մերժեք պահանջագիրը՝ նշելով պատճառը։']]);
            }
            $status = $partial ? 'partially_approved' : 'approved';
            $this->requests->update($id, ['status' => $status, 'reviewed_by' => $actor->id]);
            $this->audit($actor, $ip, 'Պահանջագիրը հաստատվեց', $id, ['status' => $row->status], ['status' => $status]);
        });
    }

    public function transition(int $id, string $action, User $actor, string $ip): void
    {
        abort_unless(in_array($action, ['collect', 'ready', 'cancel', 'ship', 'close', 'receive'], true), 404);
        DB::transaction(function () use ($id, $action, $actor, $ip): void {
            $row = $this->requests->lock($id);
            abort_unless($row, 404, 'Պահանջագիրը չի գտնվել։');
            $central = $actor->currentLocationId() === 0;
            $allowed = match ($action) {
                'collect' => ['approved', 'partially_approved'], 'ready' => ['collecting'], 'cancel' => ['draft', 'sent', 'review'],
                'ship' => ['ready_to_ship'], 'receive' => ['shipped'], 'close' => ['received'],
            };
            abort_unless(in_array($row->status, $allowed, true), 409, 'Գործողությունը հասանելի չէ ընթացիկ կարգավիճակում։');
            if ($action === 'cancel') {
                $this->assertCanCancel($actor, $row);
            }
            if (in_array($action, ['collect', 'ready', 'ship'], true)) {
                abort_unless($central, 403, 'Այս գործողությունը կատարվում է կենտրոնական պահեստում։');
            }
            if (in_array($action, ['receive', 'close'], true)) {
                abort_unless(
                    $this->isGlobalAdmin($actor) || (int) $actor->branch_id === (int) $row->branch_id,
                    403,
                    'Կարող եք հաստատել միայն ձեր մասնաճյուղի պահանջագիրը։',
                );
            }
            if ($action === 'ship') {
                $this->ship($row, $actor);
            }
            if ($action === 'receive') {
                $this->receive($row, $actor);
            }
            $status = match ($action) {
                'collect' => 'collecting', 'ready' => 'ready_to_ship', 'cancel' => 'cancelled', 'ship' => 'shipped', 'receive' => 'received', 'close' => 'closed'
            };
            $updates = ['status' => $status];
            if ($action === 'ship') {
                $updates += ['sent_by' => $actor->id, 'sent_at' => now()];
            }
            if ($action === 'receive') {
                $updates += ['received_by' => $actor->id, 'received_at' => now()];
            }
            $this->requests->update($id, $updates);
            $this->audit($actor, $ip, 'Պահանջագրի կարգավիճակը փոփոխվեց', $id, ['status' => $row->status], ['status' => $status]);
        });
    }

    private function ship(object $row, User $actor): void
    {
        abort_unless($this->requests->branchIsActive((int) $row->branch_id), 422, 'Պահանջագրի ստացողը պետք է լինի ակտիվ մասնաճյուղ՝ կենտրոնական պահեստից բացի։');
        foreach ($this->requests->items((int) $row->id, true) as $item) {
            $remaining = (float) $item->approved_qty;
            if ($remaining <= 0) {
                continue;
            }
            if ($remaining > $this->requests->centralFreeStock((int) $item->product_id, (int) $row->id) + 0.00001) {
                throw ValidationException::withMessages(['items' => ['Ազատ պահեստային քանակը փոխվել է․ ուղարկումը չկատարվեց։']]);
            }
            foreach ($this->requests->lockCentralLots((int) $item->product_id) as $lot) {
                if ($remaining <= 0.00001) {
                    break;
                }
                $take = min($remaining, (float) $lot->qty);
                abort_unless($this->requests->decreaseLot((int) $lot->id, $take) === 1, 409, 'LOT-ի ազատ քանակը փոխվել է։');
                $this->requests->movement((int) $actor->id, 'branch_out', (int) $item->product_id, (int) $lot->id, 0, (int) $row->branch_id, $take, (float) $lot->unit_cost, $row->request_no, 'Մասնաճյուղի պահանջագրի բաշխում');
                $remaining -= $take;
            }
            if ($remaining > 0.00001) {
                throw ValidationException::withMessages(['items' => ['Պահեստային քանակը փոխվել է․ ուղարկումը չկատարվեց։']]);
            }
        }
    }

    private function receive(object $row, User $actor): void
    {
        abort_unless($this->requests->branchIsActive((int) $row->branch_id), 422, 'Պահանջագրի ստացողը պետք է լինի ակտիվ մասնաճյուղ՝ կենտրոնական պահեստից բացի։');
        $movements = $this->requests->movementsForReceipt((string) $row->request_no, (int) $row->branch_id);
        if ($movements->isEmpty()) {
            abort(409, 'Առաքման շարժերը չեն գտնվել։');
        }
        foreach ($movements as $move) {
            $source = $this->requests->lotById((int) $move->lot_id);
            abort_unless($source, 409, 'Սկզբնական LOT-ը չի գտնվել։');
            $expiresOn = $source->expires_on?->format('Y-m-d');
            $destination = $this->requests->matchingLot((int) $move->product_id, (int) $row->branch_id, $source->lot_no, $expiresOn,
                $source->supplier_id === null ? null : (int) $source->supplier_id, (float) $move->unit_cost);
            if ($destination) {
                $this->requests->increaseLot((int) $destination->id, (float) $move->qty);
            } else {
                $destinationId = $this->requests->addLot(['product_id' => $move->product_id, 'location_id' => $row->branch_id, 'lot_no' => $source->lot_no,
                    'expires_on' => $source->expires_on, 'received_on' => now()->toDateString(), 'supplier_id' => $source->supplier_id, 'unit_cost' => $move->unit_cost,
                    'bin_location' => $source->bin_location, 'qty' => $move->qty])->id;
            }
            $this->requests->movement((int) $actor->id, 'branch_in', (int) $move->product_id, (int) ($destination->id ?? $destinationId), 0, (int) $row->branch_id,
                (float) $move->qty, (float) $move->unit_cost, $row->request_no, 'Մասնաճյուղը հաստատեց ստացումը');
        }
    }

    private function branchForActor(User $actor, int $requested): int
    {
        return $actor->currentLocationId() > 0 ? (int) $actor->branch_id : $requested;
    }

    private function assertVisible(User $actor, int $branchId): void
    {
        abort_unless($actor->currentLocationId() === 0 || (int) $actor->branch_id === $branchId, 403);
    }

    private function assertOwner(User $actor, object $row): void
    {
        abort_unless(
            $this->isGlobalAdmin($actor)
                || ((int) $actor->branch_id === (int) $row->branch_id
                    && ($actor->role?->name === 'admin' || (int) $actor->id === (int) $row->requested_by)),
            403,
            'Կարող եք փոփոխել միայն ձեր մասնաճյուղի ձեր պահանջագիրը.',
        );
    }

    private function assertCanCancel(User $actor, object $row): void
    {
        abort_unless(
            $this->isGlobalAdmin($actor) || (int) $actor->branch_id === (int) $row->branch_id,
            403,
            'Կարող եք չեղարկել միայն ձեր մասնաճյուղի պահանջագիրը։',
        );
    }

    private function isGlobalAdmin(User $actor): bool
    {
        return $actor->role?->name === 'admin' && $actor->currentLocationId() === 0;
    }

    private function assertActiveProducts(array $items): void
    {
        foreach ($items as $item) {
            abort_unless($this->requests->productIsActive((int) $item['product_id']), 422, 'Ընտրված ապրանքներից մեկն ապաակտիվացված է։');
        }
    }

    private function audit(User $actor, string $ip, string $action, int $id, ?array $before, ?array $after): void
    {
        $this->requests->audit((int) $actor->id, $action, $id, $before, $after, $ip);
    }
}
