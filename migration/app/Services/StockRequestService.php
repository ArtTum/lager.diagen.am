<?php

namespace App\Services;

use App\Repositories\StockRequestRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockRequestService
{
    public function __construct(private readonly StockRequestRepository $requests) {}

    public function details(int $id, object $actor): object
    {
        $row = $this->requests->findWithItems($id);
        abort_unless($row, 404, 'Պահանջագիրը չի գտնվել։');
        $this->assertVisible($actor, (int) $row->branch_id);
        return $row;
    }

    public function create(object $actor, string $ip, array $data): array
    {
        $branchId = $this->branchForActor($actor, (int) $data['branch_id']);
        abort_unless(DB::table('branches')->where('id', $branchId)->where('active', 1)->exists(), 422, 'Ընտրված մասնաճյուղը ակտիվ չէ։');
        $this->assertActiveProducts($data['items']);
        $status = $data['submit_mode'] === 'draft' ? 'draft' : 'sent';
        return DB::transaction(function () use ($actor, $ip, $data, $branchId, $status): array {
            $number = 'ՊՀ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            $id = DB::table('stock_requests')->insertGetId([
                'request_no' => $number, 'branch_id' => $branchId, 'requested_by' => $actor->id,
                'status' => $status, 'urgency' => $data['urgency'], 'reason' => trim($data['reason'] ?? ''), 'created_at' => now(),
            ]);
            $this->requests->replaceItems($id, $data['items']);
            $this->requests->audit((int) $actor->id, $status === 'draft' ? 'Պահանջագիրը պահվեց որպես սևագիր' : 'Պահանջագիրն ուղարկվեց', $id, null, ['request_no' => $number, 'status' => $status], $ip);
            return ['id' => $id, 'status' => $status];
        });
    }

    public function updateDraft(int $id, object $actor, string $ip, array $data): void
    {
        $this->assertActiveProducts($data['items']);
        DB::transaction(function () use ($id, $actor, $ip, $data): void {
            $row = $this->requests->lock($id);
            abort_unless($row && $row->status === 'draft', 409, 'Միայն սևագիր պահանջագիրը կարող եք փոփոխել։');
            $this->assertOwner($actor, $row);
            $status = $data['submit_mode'] === 'draft' ? 'draft' : 'sent';
            DB::table('stock_requests')->where('id', $id)->update(['urgency' => $data['urgency'], 'reason' => trim($data['reason'] ?? ''), 'status' => $status]);
            $this->requests->replaceItems($id, $data['items']);
            $this->requests->audit((int) $actor->id, $status === 'sent' ? 'Սևագիր պահանջագիրն ուղարկվեց' : 'Սևագիրը փոփոխվեց', $id, ['status' => 'draft'], ['status' => $status], $ip);
        });
    }

    public function review(int $id, object $actor, string $ip, array $data): void
    {
        DB::transaction(function () use ($id, $actor, $ip, $data): void {
            $row = $this->requests->lock($id);
            abort_unless($row && in_array($row->status, ['sent', 'review'], true), 409, 'Պահանջագիրը հաստատման ենթակա չէ։');
            $items = $this->requests->items($id, true);
            if ($data['decision'] === 'start_review') {
                abort_unless($row->status === 'sent', 409, 'Ստուգման փուլ կարող է տեղափոխվել միայն ուղարկված պահանջագիրը։');
                DB::table('stock_requests')->where('id', $id)->update(['status' => 'review', 'reviewed_by' => $actor->id]);
                $this->audit($actor, $ip, 'Պահանջագիրը վերցվեց ստուգման', $id, ['status' => 'sent'], ['status' => 'review']);
                return;
            }
            if ($data['decision'] === 'reject') {
                DB::table('stock_requests')->where('id', $id)->update(['status' => 'rejected', 'rejection_reason' => trim($data['rejection_reason']), 'reviewed_by' => $actor->id]);
                $this->audit($actor, $ip, 'Պահանջագիրը մերժվեց', $id, ['status' => $row->status], ['status' => 'rejected', 'reason' => trim($data['rejection_reason'])]);
                return;
            }
            $approvedTotal = 0.0;
            $partial = false;
            foreach ($items as $item) {
                $qty = (float) ($data['approved'][$item->id] ?? 0);
                if ($qty > (float) $item->requested_qty + 0.00001) throw ValidationException::withMessages(['approved' => ['Հաստատվող քանակը գերազանցում է պահանջված քանակը։']]);
                if ($qty > $this->requests->centralFreeStock((int) $item->product_id, $id) + 0.00001) throw ValidationException::withMessages(['approved' => ['Հաստատվող քանակը գերազանցում է ազատ կենտրոնական մնացորդը։']]);
                if ($qty + 0.00001 < (float) $item->requested_qty) $partial = true;
                $approvedTotal += $qty;
                DB::table('request_items')->where('id', $item->id)->update(['approved_qty' => $qty]);
            }
            if ($approvedTotal <= 0) throw ValidationException::withMessages(['approved' => ['Հաստատեք դրական քանակ կամ մերժեք պահանջագիրը՝ նշելով պատճառը։']]);
            $status = $partial ? 'partially_approved' : 'approved';
            DB::table('stock_requests')->where('id', $id)->update(['status' => $status, 'reviewed_by' => $actor->id]);
            $this->audit($actor, $ip, 'Պահանջագիրը հաստատվեց', $id, ['status' => $row->status], ['status' => $status]);
        });
    }

    public function transition(int $id, string $action, object $actor, string $ip): void
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
            if ($action === 'cancel') $this->assertOwner($actor, $row);
            if (in_array($action, ['collect', 'ready', 'ship'], true)) abort_unless($central || $actor->role?->name === 'admin', 403, 'Այս գործողությունը կատարվում է կենտրոնական պահեստում։');
            if (in_array($action, ['receive', 'close'], true)) abort_unless($actor->role?->name === 'admin' || (int) $actor->branch_id === (int) $row->branch_id, 403, 'Կարող եք հաստատել միայն ձեր մասնաճյուղի պահանջագիրը։');
            if ($action === 'ship') $this->ship($row, $actor);
            if ($action === 'receive') $this->receive($row, $actor);
            $status = match ($action) { 'collect' => 'collecting', 'ready' => 'ready_to_ship', 'cancel' => 'cancelled', 'ship' => 'shipped', 'receive' => 'received', 'close' => 'closed' };
            $updates = ['status' => $status];
            if ($action === 'ship') $updates += ['sent_by' => $actor->id, 'sent_at' => now()];
            if ($action === 'receive') $updates += ['received_by' => $actor->id, 'received_at' => now()];
            DB::table('stock_requests')->where('id', $id)->update($updates);
            $this->audit($actor, $ip, 'Պահանջագրի կարգավիճակը փոփոխվեց', $id, ['status' => $row->status], ['status' => $status]);
        });
    }

    private function ship(object $row, object $actor): void
    {
        foreach ($this->requests->items((int) $row->id, true) as $item) {
            $remaining = (float) $item->approved_qty;
            if ($remaining <= 0) continue;
            foreach ($this->requests->lockCentralLots((int) $item->product_id) as $lot) {
                if ($remaining <= 0.00001) break;
                $take = min($remaining, (float) $lot->qty);
                DB::table('stock_lots')->where('id', $lot->id)->update(['qty' => DB::raw('qty - '.(float) $take)]);
                $this->requests->movement((int) $actor->id, 'branch_out', (int) $item->product_id, (int) $lot->id, 0, (int) $row->branch_id, $take, (float) $lot->unit_cost, $row->request_no, 'Մասնաճյուղի պահանջագրի բաշխում');
                $remaining -= $take;
            }
            if ($remaining > 0.00001) throw ValidationException::withMessages(['items' => ['Պահեստային քանակը փոխվել է․ ուղարկումը չկատարվեց։']]);
        }
    }

    private function receive(object $row, object $actor): void
    {
        $movements = DB::table('movements')->where('reference', $row->request_no)->where('type', 'branch_out')->where('to_location', $row->branch_id)
            ->select('lot_id', 'product_id', DB::raw('SUM(qty) as qty'), DB::raw('MAX(unit_cost) as unit_cost'))->groupBy('lot_id', 'product_id')->get();
        if ($movements->isEmpty()) abort(409, 'Առաքման շարժերը չեն գտնվել։');
        foreach ($movements as $move) {
            $source = DB::table('stock_lots')->where('id', $move->lot_id)->first();
            abort_unless($source, 409, 'Սկզբնական LOT-ը չի գտնվել։');
            $destination = $this->requests->matchingLot((int) $move->product_id, (int) $row->branch_id, $source->lot_no, $source->expires_on);
            if ($destination) DB::table('stock_lots')->where('id', $destination->id)->update(['qty' => DB::raw('qty + '.(float) $move->qty)]);
            else $destinationId = DB::table('stock_lots')->insertGetId(['product_id' => $move->product_id, 'location_id' => $row->branch_id, 'lot_no' => $source->lot_no,
                'expires_on' => $source->expires_on, 'received_on' => now()->toDateString(), 'supplier_id' => $source->supplier_id, 'unit_cost' => $move->unit_cost,
                'bin_location' => $source->bin_location, 'qty' => $move->qty]);
            $this->requests->movement((int) $actor->id, 'branch_in', (int) $move->product_id, (int) ($destination->id ?? $destinationId), 0, (int) $row->branch_id,
                (float) $move->qty, (float) $move->unit_cost, $row->request_no, 'Մասնաճյուղը հաստատեց ստացումը');
        }
    }

    private function branchForActor(object $actor, int $requested): int
    { return $actor->currentLocationId() > 0 ? (int) $actor->branch_id : $requested; }

    private function assertVisible(object $actor, int $branchId): void
    { abort_unless($actor->role?->name === 'admin' || $actor->currentLocationId() === 0 || (int) $actor->branch_id === $branchId, 403); }

    private function assertOwner(object $actor, object $row): void
    { abort_unless($actor->role?->name === 'admin' || ((int) $actor->branch_id === (int) $row->branch_id && (int) $actor->id === (int) $row->requested_by), 403, 'Կարող եք փոփոխել միայն ձեր մասնաճյուղի ձեր պահանջագիրը։'); }

    private function assertActiveProducts(array $items): void
    { foreach ($items as $item) abort_unless(DB::table('products')->where('id', $item['product_id'])->where('active', 1)->exists(), 422, 'Ընտրված ապրանքներից մեկն ապաակտիվացված է։'); }

    private function audit(object $actor, string $ip, string $action, int $id, ?array $before, ?array $after): void
    { $this->requests->audit((int) $actor->id, $action, $id, $before, $after, $ip); }
}
