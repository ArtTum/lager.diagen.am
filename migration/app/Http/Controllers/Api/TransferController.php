<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'from_branch' => ['required', 'integer', 'exists:branches,id'],
            'to_branch' => ['required', 'integer', 'different:from_branch', 'exists:branches,id'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ]);

        $from = (int) $data['from_branch'];
        $to = (int) $data['to_branch'];
        abort_unless($actor->currentLocationId() === 0 || $actor->branch_id === $from, 403, 'Կարող եք ուղարկել միայն ձեր պահեստից։');
        $activeBranches = DB::table('branches')->whereIn('id', [$from, $to])->where('active', 1)->count();
        if ($activeBranches !== 2) throw ValidationException::withMessages(['to_branch' => ['Երկու պահեստներն էլ պետք է ակտիվ լինեն։']]);

        $transfer = DB::transaction(function () use ($data, $actor, $from, $to): array {
            foreach ($data['items'] as $line) {
                $active = DB::table('products')->where('id', $line['product_id'])->where('active', 1)->exists();
                if (! $active) throw ValidationException::withMessages(['items' => ['Ընտրված ապրանքներից մեկն ապաակտիվացված է։']]);
            }
            $number = 'ՏՂ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            $first = $data['items'][0];
            $id = DB::table('transfers')->insertGetId([
                'transfer_no' => $number, 'from_branch' => $from, 'to_branch' => $to,
                'product_id' => $first['product_id'], 'qty' => $first['qty'], 'status' => 'pending',
                'requested_by' => $actor->id, 'reason' => trim($data['reason']), 'created_at' => now(),
            ]);
            foreach ($data['items'] as $line) DB::table('transfer_items')->insert([
                'transfer_id' => $id, 'product_id' => $line['product_id'], 'qty' => $line['qty'],
            ]);
            $this->audit(request(), 'Տեղափոխման հարցում', $id, null, ['transfer_no' => $number, 'from' => $from, 'to' => $to, 'items' => $data['items']]);
            return ['id' => $id, 'transfer_no' => $number, 'status' => 'pending'];
        });
        return response()->json(['data' => $transfer], 201);
    }

    public function approve(Request $request, int $transfer): JsonResponse
    {
        $actor = $request->user();
        DB::transaction(function () use ($request, $actor, $transfer): void {
            $record = DB::table('transfers')->where('id', $transfer)->lockForUpdate()->first();
            abort_unless($record && $record->status === 'pending', 409, 'Տեղափոխումը հաստատման սպասման կարգավիճակում չէ։');
            $items = DB::table('transfer_items')->where('transfer_id', $transfer)->orderBy('product_id')->lockForUpdate()->get();
            abort_if($items->isEmpty(), 409, 'Տեղափոխման ապրանքային տողերը բացակայում են։');
            foreach ($items as $item) {
                $available = $this->freeStock((int) $record->from_branch, (int) $item->product_id, $transfer);
                if ((float) $item->qty > $available + 0.00001) throw ValidationException::withMessages(['items' => ['Ազատ պաշարը բավարար չէ ապրանք '.$item->product_id.'-ի համար։']]);
            }
            DB::table('transfers')->where('id', $transfer)->update(['status' => 'approved', 'approved_by' => $actor->id]);
            $this->audit($request, 'Տեղափոխումը հաստատվեց', $transfer, ['status' => 'pending'], ['status' => 'approved']);
        });
        return response()->json(['message' => 'Տեղափոխումը հաստատվեց։ Այն դեռ պահեստից դուրս չի գրվել։']);
    }

    public function ship(Request $request, int $transfer): JsonResponse
    {
        $actor = $request->user();
        DB::transaction(function () use ($request, $actor, $transfer): void {
            $record = DB::table('transfers')->where('id', $transfer)->lockForUpdate()->first();
            abort_unless($record && $record->status === 'approved', 409, 'Ուղարկել կարելի է միայն հաստատված տեղափոխումը։');
            abort_unless($actor->currentLocationId() === 0 || $actor->branch_id === $record->from_branch, 403, 'Կարող եք ուղարկել միայն ձեր աղբյուր պահեստից։');
            $items = DB::table('transfer_items')->where('transfer_id', $transfer)->orderBy('product_id')->get();
            foreach ($items as $item) {
                $fromLocation = $this->stockLocation((int) $record->from_branch);
                $lots = DB::table('stock_lots')->where('product_id', $item->product_id)->where('location_id', $fromLocation)
                    ->where('qty', '>', 0)->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))
                    ->orderByRaw('(expires_on IS NULL), expires_on, received_on, id')->lockForUpdate()->get();
                $free = $this->freeStock((int) $record->from_branch, (int) $item->product_id, $transfer);
                if ((float) $item->qty > $free + 0.00001) throw ValidationException::withMessages(['items' => ['Ազատ պաշարը փոխվել է․ տեղափոխումը չի ուղարկվել։']]);
                $remaining = (float) $item->qty;
                foreach ($lots as $lot) {
                    if ($remaining <= 0.00001) break;
                    $take = min($remaining, (float) $lot->qty);
                    DB::table('stock_lots')->where('id', $lot->id)->update(['qty' => DB::raw('qty - '.(float) $take)]);
                    $this->movement($request, $actor->id, 'transfer_sent', (int) $item->product_id, (int) $lot->id, $fromLocation, $this->stockLocation((int) $record->to_branch), $take, (float) $lot->unit_cost, $record->transfer_no, (string) $record->reason);
                    $remaining -= $take;
                }
                if ($remaining > 0.00001) throw ValidationException::withMessages(['items' => ['Աղբյուր պահեստում ապրանքի քանակը բավարար չէ։']]);
            }
            DB::table('transfers')->where('id', $transfer)->update(['status' => 'shipped', 'shipped_by' => $actor->id]);
            $this->audit($request, 'Տեղափոխումն ուղարկվեց', $transfer, ['status' => 'approved'], ['status' => 'shipped']);
        });
        return response()->json(['message' => 'Տեղափոխումը դուրս գրվեց աղբյուր պահեստից և ուղարկվեց։']);
    }

    public function receive(Request $request, int $transfer): JsonResponse
    {
        $actor = $request->user();
        DB::transaction(function () use ($request, $actor, $transfer): void {
            $record = DB::table('transfers')->where('id', $transfer)->lockForUpdate()->first();
            abort_unless($record && $record->status === 'shipped', 409, 'Ստացման սպասող տեղափոխումը չի գտնվել։');
            abort_unless($actor->role?->name === 'admin' || (int) $actor->branch_id === (int) $record->to_branch, 403, 'Կարող եք ընդունել միայն ձեր պահեստ ուղարկված ապրանքը։');
            $toLocation = $this->stockLocation((int) $record->to_branch);
            $fromLocation = $this->stockLocation((int) $record->from_branch);
            $sent = DB::table('movements')->where('reference', $record->transfer_no)->where('type', 'transfer_sent')->where('to_location', $toLocation)
                ->select('lot_id', 'product_id', DB::raw('SUM(qty) as qty'), DB::raw('MAX(unit_cost) as unit_cost'))->groupBy('lot_id', 'product_id')->get();
            if ($sent->isEmpty()) abort(409, 'Ուղարկման շարժերի գրառումները բացակայում են։');
            foreach ($sent as $line) {
                $source = DB::table('stock_lots')->where('id', $line->lot_id)->first();
                if (! $source) abort(409, 'Սկզբնական LOT-ը չի գտնվել։');
                $dest = DB::table('stock_lots')->where('product_id', $line->product_id)->where('location_id', $toLocation)
                    ->where('lot_no', $source->lot_no)->whereRaw('IFNULL(expires_on, \'1000-01-01\') = IFNULL(?, \'1000-01-01\')', [$source->expires_on])->lockForUpdate()->first();
                if ($dest) DB::table('stock_lots')->where('id', $dest->id)->update(['qty' => DB::raw('qty + '.(float) $line->qty)]);
                else $destId = DB::table('stock_lots')->insertGetId([
                    'product_id' => $line->product_id, 'location_id' => $toLocation, 'lot_no' => $source->lot_no,
                    'expires_on' => $source->expires_on, 'received_on' => now()->toDateString(), 'supplier_id' => $source->supplier_id,
                    'unit_cost' => $line->unit_cost, 'bin_location' => $source->bin_location, 'qty' => $line->qty,
                ]);
                $destinationLot = $dest->id ?? $destId;
                $this->movement($request, $actor->id, 'branch_transfer', (int) $line->product_id, (int) $destinationLot, $fromLocation, $toLocation, (float) $line->qty, (float) $line->unit_cost, $record->transfer_no, 'Ստացող պահեստը հաստատեց ընդունումը');
            }
            DB::table('transfers')->where('id', $transfer)->update(['status' => 'completed', 'received_by' => $actor->id, 'accepted_at' => now()]);
            $this->audit($request, 'Տեղափոխման ստացումը հաստատվեց', $transfer, ['status' => 'shipped'], ['status' => 'completed']);
        });
        return response()->json(['message' => 'Ապրանքն ընդունվեց ստացող պահեստի մնացորդ։']);
    }

    private function freeStock(int $location, int $product, int $excludeTransfer): float
    {
        $stockLocation = $this->stockLocation($location);
        $lots = DB::table('stock_lots')->where('product_id', $product)->where('location_id', $stockLocation)->where('qty', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))->lockForUpdate()->get(['qty']);
        $physical = (float) $lots->sum(static fn ($lot): float => (float) $lot->qty);
        $reservedTransfers = (float) DB::table('transfer_items as i')->join('transfers as t', 't.id', '=', 'i.transfer_id')
            ->where('t.status', 'approved')->where('t.from_branch', $location)->where('i.product_id', $product)->where('t.id', '<>', $excludeTransfer)->sum('i.qty');
        $reservedRequests = $stockLocation === 0 ? (float) DB::table('request_items as i')->join('stock_requests as r', 'r.id', '=', 'i.request_id')
            ->whereIn('r.status', ['approved', 'partially_approved', 'collecting', 'ready_to_ship'])->where('i.product_id', $product)->sum('i.approved_qty') : 0.0;
        return max(0, $physical - $reservedTransfers - $reservedRequests);
    }

    private function stockLocation(int $branchId): int
    {
        $branch = DB::table('branches')->where('id', $branchId)->first(['code']);
        abort_unless($branch, 422, 'Պահեստը չի գտնվել։');
        return $branch->code === 'CENTRAL' ? 0 : $branchId;
    }

    private function movement(Request $request, int $actor, string $type, int $product, int $lot, ?int $from, ?int $to, float $qty, float $cost, string $reference, string $reason): void
    {
        DB::table('movements')->insert([
            'movement_no' => 'ՇԱՐԺ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),
            'type' => $type, 'product_id' => $product, 'lot_id' => $lot, 'from_location' => $from, 'to_location' => $to,
            'qty' => $qty, 'unit_cost' => $cost, 'reference' => $reference, 'reason' => $reason,
            'actor_id' => $actor, 'happened_at' => now(), 'created_at' => now(),
        ]);
    }

    private function audit(Request $request, string $action, int $id, ?array $before, ?array $after): void
    {
        AuditLog::create(['actor_id' => $request->user()->id, 'action' => $action, 'entity' => 'transfers', 'entity_id' => $id,
            'before_data' => $before, 'after_data' => $after, 'ip_address' => $request->ip(), 'created_at' => now()]);
    }
}
