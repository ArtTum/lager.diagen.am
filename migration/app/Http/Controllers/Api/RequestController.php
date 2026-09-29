<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function show(Request $request, int $stockRequest): JsonResponse
    {
        $row = DB::table('stock_requests as r')->join('branches as b', 'b.id', '=', 'r.branch_id')
            ->where('r.id', $stockRequest)->select('r.*', 'b.name as branch_name')->first();
        abort_unless($row, 404, 'Պահանջագիրը չի գտնվել։');
        $this->assertVisible($request, (int) $row->branch_id);
        $row->items = DB::table('request_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.request_id', $stockRequest)->select('i.id','i.product_id','p.code','p.name','p.unit','i.requested_qty','i.approved_qty','i.note')->get();
        return response()->json(['data' => $row]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'], 'urgency' => ['required', 'in:normal,high,urgent'],
            'reason' => ['nullable', 'string', 'max:2000'], 'submit_mode' => ['required', 'in:draft,send'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'], 'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        $branchId = (int) $data['branch_id'];
        if ($actor->currentLocationId() > 0) $branchId = (int) $actor->branch_id;
        abort_unless(DB::table('branches')->where('id', $branchId)->where('active', 1)->exists(), 422, 'Ընտրված մասնաճյուղը ակտիվ չէ։');
        $this->assertActiveProducts($data['items']);
        $status = $data['submit_mode'] === 'draft' ? 'draft' : 'sent';
        $id = DB::transaction(function () use ($request, $actor, $data, $branchId, $status): int {
            $number = 'ՊՀ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            $id = DB::table('stock_requests')->insertGetId([
                'request_no' => $number, 'branch_id' => $branchId, 'requested_by' => $actor->id,
                'status' => $status, 'urgency' => $data['urgency'], 'reason' => trim($data['reason'] ?? ''), 'created_at' => now(),
            ]);
            $this->replaceItems($id, $data['items']);
            $this->audit($request, $status === 'draft' ? 'Պահանջագիրը պահվեց որպես սևագիր' : 'Պահանջագիրն ուղարկվեց', $id, null, ['request_no' => $number, 'status' => $status]);
            return $id;
        });
        return response()->json(['data' => ['id' => $id, 'status' => $status]], 201);
    }

    public function updateDraft(Request $request, int $stockRequest): JsonResponse
    {
        $data = $request->validate([
            'urgency' => ['required', 'in:normal,high,urgent'], 'reason' => ['nullable', 'string', 'max:2000'],
            'submit_mode' => ['required', 'in:draft,send'], 'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'], 'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->assertActiveProducts($data['items']);
        DB::transaction(function () use ($request, $stockRequest, $data): void {
            $row = DB::table('stock_requests')->where('id', $stockRequest)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'draft', 409, 'Միայն սևագիր պահանջագիրը կարող եք փոփոխել։');
            $this->assertOwner($request, $row);
            $before = (array) $row;
            $status = $data['submit_mode'] === 'draft' ? 'draft' : 'sent';
            DB::table('stock_requests')->where('id', $stockRequest)->update(['urgency' => $data['urgency'], 'reason' => trim($data['reason'] ?? ''), 'status' => $status]);
            $this->replaceItems($stockRequest, $data['items']);
            $this->audit($request, $status === 'sent' ? 'Սևագիր պահանջագիրն ուղարկվեց' : 'Սևագիրը փոփոխվեց', $stockRequest, $before, ['status' => $status]);
        });
        return response()->json(['message' => 'Պահանջագիրը պահպանվեց։']);
    }

    public function review(Request $request, int $stockRequest): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:start_review,approve,reject'], 'rejection_reason' => ['required_if:decision,reject','nullable','string','min:3','max:2000'],
            'approved' => ['required_if:decision,approve','array'], 'approved.*' => ['required_if:decision,approve','numeric','min:0'],
        ]);
        DB::transaction(function () use ($request, $stockRequest, $data): void {
            $row = DB::table('stock_requests')->where('id', $stockRequest)->lockForUpdate()->first();
            abort_unless($row && in_array($row->status, ['sent','review'], true), 409, 'Պահանջագիրը հաստատման ենթակա չէ։');
            $items = DB::table('request_items')->where('request_id', $stockRequest)->orderBy('product_id')->lockForUpdate()->get();
            if ($data['decision'] === 'start_review') {
                abort_unless($row->status === 'sent', 409, 'Ստուգման փուլ կարող է տեղափոխվել միայն ուղարկված պահանջագիրը։');
                DB::table('stock_requests')->where('id', $stockRequest)->update(['status' => 'review', 'reviewed_by' => $request->user()->id]);
                $this->audit($request, 'Պահանջագիրը վերցվեց ստուգման', $stockRequest, ['status' => 'sent'], ['status' => 'review']);
                return;
            }
            if ($data['decision'] === 'reject') {
                DB::table('stock_requests')->where('id', $stockRequest)->update(['status' => 'rejected', 'rejection_reason' => trim($data['rejection_reason']), 'reviewed_by' => $request->user()->id]);
                $this->audit($request, 'Պահանջագիրը մերժվեց', $stockRequest, ['status' => $row->status], ['status' => 'rejected', 'reason' => trim($data['rejection_reason'])]);
                return;
            }
            $approvedTotal = 0.0; $partial = false;
            foreach ($items as $item) {
                $qty = (float) ($data['approved'][$item->id] ?? 0);
                if ($qty > (float) $item->requested_qty + 0.00001) throw ValidationException::withMessages(['approved' => ['Հաստատվող քանակը գերազանցում է պահանջված քանակը։']]);
                $available = $this->centralFreeStock((int) $item->product_id, $stockRequest);
                if ($qty > $available + 0.00001) throw ValidationException::withMessages(['approved' => ['Հաստատվող քանակը գերազանցում է ազատ կենտրոնական մնացորդը։']]);
                if ($qty + 0.00001 < (float) $item->requested_qty) $partial = true;
                $approvedTotal += $qty;
                DB::table('request_items')->where('id', $item->id)->update(['approved_qty' => $qty]);
            }
            if ($approvedTotal <= 0) throw ValidationException::withMessages(['approved' => ['Հաստատեք դրական քանակ կամ մերժեք պահանջագիրը՝ նշելով պատճառը։']]);
            $status = $partial ? 'partially_approved' : 'approved';
            DB::table('stock_requests')->where('id', $stockRequest)->update(['status' => $status, 'reviewed_by' => $request->user()->id]);
            $this->audit($request, 'Պահանջագիրը հաստատվեց', $stockRequest, ['status' => $row->status], ['status' => $status]);
        });
        return response()->json(['message' => 'Պահանջագրի որոշումը պահպանվեց։']);
    }

    public function transition(Request $request, int $stockRequest, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['collect','ready','cancel','ship','close','receive'], true), 404);
        DB::transaction(function () use ($request, $stockRequest, $action): void {
            $row = DB::table('stock_requests')->where('id', $stockRequest)->lockForUpdate()->first();
            abort_unless($row, 404, 'Պահանջագիրը չի գտնվել։');
            $actor = $request->user();
            $central = $actor->currentLocationId() === 0;
            $next = match ($action) {
                'collect' => ['approved','partially_approved'], 'ready' => ['collecting'], 'cancel' => ['draft','sent','review'],
                'ship' => ['ready_to_ship'], 'receive' => ['shipped'], 'close' => ['received'],
            };
            abort_unless(in_array($row->status, $next, true), 409, 'Գործողությունը հասանելի չէ ընթացիկ կարգավիճակում։');
            if ($action === 'cancel') $this->assertOwner($request, $row);
            if (in_array($action, ['collect','ready','ship'], true)) abort_unless($central || $actor->role?->name === 'admin', 403, 'Այս գործողությունը կատարվում է կենտրոնական պահեստում։');
            if (in_array($action, ['receive','close'], true)) abort_unless($actor->role?->name === 'admin' || (int) $actor->branch_id === (int) $row->branch_id, 403, 'Կարող եք հաստատել միայն ձեր մասնաճյուղի պահանջագիրը։');

            if ($action === 'ship') $this->shipRequest($request, $row);
            if ($action === 'receive') $this->receiveRequest($request, $row);
            $status = match ($action) { 'collect' => 'collecting', 'ready' => 'ready_to_ship', 'cancel' => 'cancelled', 'ship' => 'shipped', 'receive' => 'received', 'close' => 'closed' };
            $updates = ['status' => $status];
            if ($action === 'ship') $updates += ['sent_by' => $actor->id, 'sent_at' => now()];
            if ($action === 'receive') $updates += ['received_by' => $actor->id, 'received_at' => now()];
            DB::table('stock_requests')->where('id', $stockRequest)->update($updates);
            $this->audit($request, 'Պահանջագրի կարգավիճակը փոփոխվեց', $stockRequest, ['status' => $row->status], ['status' => $status]);
        });
        return response()->json(['message' => 'Պահանջագրի կարգավիճակը թարմացվեց։']);
    }

    private function shipRequest(Request $request, object $row): void
    {
        $items = DB::table('request_items')->where('request_id', $row->id)->orderBy('product_id')->get();
        foreach ($items as $item) {
            $remaining = (float) $item->approved_qty;
            if ($remaining <= 0) continue;
            $lots = DB::table('stock_lots')->where('product_id', $item->product_id)->where('location_id', 0)->where('qty', '>', 0)
                ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))
                ->orderByRaw('(expires_on IS NULL), expires_on, received_on, id')->lockForUpdate()->get();
            foreach ($lots as $lot) {
                if ($remaining <= 0.00001) break;
                $take = min($remaining, (float) $lot->qty);
                DB::table('stock_lots')->where('id', $lot->id)->update(['qty' => DB::raw('qty - '.(float) $take)]);
                $this->movement($request, 'branch_out', (int) $item->product_id, (int) $lot->id, 0, (int) $row->branch_id, $take, (float) $lot->unit_cost, $row->request_no, 'Մասնաճյուղի պահանջագրի բաշխում');
                $remaining -= $take;
            }
            if ($remaining > 0.00001) throw ValidationException::withMessages(['items' => ['Պահեստային քանակը փոխվել է․ ուղարկումը չկատարվեց։']]);
        }
    }

    private function receiveRequest(Request $request, object $row): void
    {
        $movements = DB::table('movements')->where('reference', $row->request_no)->where('type', 'branch_out')->where('to_location', $row->branch_id)
            ->select('lot_id','product_id',DB::raw('SUM(qty) as qty'),DB::raw('MAX(unit_cost) as unit_cost'))->groupBy('lot_id','product_id')->get();
        if ($movements->isEmpty()) abort(409, 'Առաքման շարժերը չեն գտնվել։');
        foreach ($movements as $move) {
            $source = DB::table('stock_lots')->where('id', $move->lot_id)->first();
            abort_unless($source, 409, 'Սկզբնական LOT-ը չի գտնվել։');
            $destination = DB::table('stock_lots')->where('product_id', $move->product_id)->where('location_id', $row->branch_id)
                ->where('lot_no', $source->lot_no)->whereRaw('IFNULL(expires_on, \'1000-01-01\')=IFNULL(?, \'1000-01-01\')', [$source->expires_on])->lockForUpdate()->first();
            if ($destination) DB::table('stock_lots')->where('id', $destination->id)->update(['qty' => DB::raw('qty + '.(float) $move->qty)]);
            else $destinationId = DB::table('stock_lots')->insertGetId(['product_id' => $move->product_id, 'location_id' => $row->branch_id,
                'lot_no' => $source->lot_no, 'expires_on' => $source->expires_on, 'received_on' => now()->toDateString(),
                'supplier_id' => $source->supplier_id, 'unit_cost' => $move->unit_cost, 'bin_location' => $source->bin_location, 'qty' => $move->qty]);
            $this->movement($request, 'branch_in', (int) $move->product_id, (int) ($destination->id ?? $destinationId), 0, (int) $row->branch_id, (float) $move->qty, (float) $move->unit_cost, $row->request_no, 'Մասնաճյուղը հաստատեց ստացումը');
        }
    }

    private function centralFreeStock(int $productId, int $currentRequest): float
    {
        $lots = DB::table('stock_lots')->where('product_id', $productId)->where('location_id', 0)->where('qty', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))->lockForUpdate()->get(['qty']);
        $physical = (float) $lots->sum(static fn ($lot): float => (float) $lot->qty);
        $requests = (float) DB::table('request_items as i')->join('stock_requests as r','r.id','=','i.request_id')->where('i.product_id',$productId)
            ->where('r.id','<>',$currentRequest)->whereIn('r.status',['approved','partially_approved','collecting','ready_to_ship'])->sum('i.approved_qty');
        $centralId = (int) DB::table('branches')->where('code','CENTRAL')->value('id');
        $transfers = (float) DB::table('transfer_items as i')->join('transfers as t','t.id','=','i.transfer_id')->where('i.product_id',$productId)
            ->where('t.from_branch',$centralId)->where('t.status','approved')->sum('i.qty');
        return max(0, $physical - $requests - $transfers);
    }

    private function assertVisible(Request $request, int $branchId): void
    {
        $actor = $request->user();
        abort_unless($actor->role?->name === 'admin' || $actor->currentLocationId() === 0 || (int) $actor->branch_id === $branchId, 403);
    }

    private function assertOwner(Request $request, object $row): void
    {
        $actor = $request->user();
        abort_unless($actor->role?->name === 'admin' || ((int) $actor->branch_id === (int) $row->branch_id && (int) $actor->id === (int) $row->requested_by), 403, 'Կարող եք փոփոխել միայն ձեր մասնաճյուղի ձեր պահանջագիրը։');
    }

    private function assertActiveProducts(array $items): void
    {
        foreach ($items as $item) abort_unless(DB::table('products')->where('id',$item['product_id'])->where('active',1)->exists(), 422, 'Ընտրված ապրանքներից մեկն ապաակտիվացված է։');
    }

    private function replaceItems(int $id, array $items): void
    {
        DB::table('request_items')->where('request_id',$id)->delete();
        foreach ($items as $item) DB::table('request_items')->insert(['request_id'=>$id,'product_id'=>$item['product_id'],'requested_qty'=>$item['qty'],'note'=>trim($item['note']??'')]);
    }

    private function movement(Request $request, string $type, int $product, int $lot, ?int $from, ?int $to, float $qty, float $cost, string $reference, string $reason): void
    {
        DB::table('movements')->insert(['movement_no'=>'ՇԱՐԺ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))), 'type'=>$type,
            'product_id'=>$product,'lot_id'=>$lot,'from_location'=>$from,'to_location'=>$to,'qty'=>$qty,'unit_cost'=>$cost,
            'reference'=>$reference,'reason'=>$reason,'actor_id'=>$request->user()->id,'happened_at'=>now(),'created_at'=>now()]);
    }

    private function audit(Request $request, string $action, int $id, ?array $before, ?array $after): void
    {
        AuditLog::create(['actor_id'=>$request->user()->id,'action'=>$action,'entity'=>'stock_requests','entity_id'=>$id,
            'before_data'=>$before,'after_data'=>$after,'ip_address'=>$request->ip(),'created_at'=>now()]);
    }
}
