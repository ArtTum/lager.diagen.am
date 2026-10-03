<?php

namespace App\Repositories;

use App\Models\Branch;
use App\Models\Movement;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Receipt;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

class DashboardRepository
{
    private const OPEN_REQUEST_STATUSES = ['draft', 'sent', 'review', 'approved', 'partially_approved', 'collecting', 'ready_to_ship', 'shipped', 'received'];

    public function summary(int $location, bool $showCosts = false): array
    {
        $totals = StockLot::query()->where('location_id', $location)
            ->selectRaw('COUNT(DISTINCT product_id) as products, COALESCE(SUM(qty),0) as units')
            ->when($showCosts, fn ($query) => $query->selectRaw('COALESCE(SUM(qty * unit_cost),0) as value'))->first();
        $lowStock = Product::query()->leftJoin('stock_lots as l', function ($join) use ($location): void {
            $join->on('l.product_id', '=', 'products.id')->where('l.location_id', '=', $location);
        })->where('products.active', true)->groupBy('products.id', 'products.min_qty')
            ->havingRaw('COALESCE(SUM(l.qty),0) < products.min_qty')->select('products.id')->get()->count();
        $zeroStock = Product::query()->where('active', true)->whereDoesntHave('lots', fn ($query) => $query
            ->where('location_id', $location)->where('qty', '>', 0))->count();
        $expiredLots = StockLot::query()->where('location_id', $location)->where('qty', '>', 0)
            ->whereNotNull('expires_on')->whereDate('expires_on', '<', now()->toDateString())->count();
        $expiringLots = StockLot::query()->where('location_id', $location)->where('qty', '>', 0)->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', now()->toDateString())->whereDate('expires_on', '<=', now()->addDays(90)->toDateString())->count();
        $requests = StockRequest::query()->when($location !== 0, fn ($query) => $query->where('branch_id', $location));
        $openRequests = (clone $requests)->whereIn('status', self::OPEN_REQUEST_STATUSES)->count();
        $unapprovedRequests = (clone $requests)->whereIn('status', ['sent', 'review'])->count();
        $awaitingReceiptRequests = (clone $requests)->where('status', 'shipped')->count();

        $summary = [
            'products' => (int) ($totals->products ?? 0),
            'units' => (float) ($totals->units ?? 0),
            ...($showCosts ? ['stock_value' => (float) ($totals->value ?? 0)] : []),
            'low_stock_products' => $lowStock,
            'zero_stock_products' => $zeroStock,
            'expired_lots' => $expiredLots,
            'expiring_lots' => $expiringLots,
            'open_requests' => $openRequests,
            'unapproved_requests' => $unapprovedRequests,
            'awaiting_receipt_requests' => $awaitingReceiptRequests,
            'today' => $this->todayActivity($location),
            'charts' => [
                'activity_daily' => $this->dailyActivity($location),
                'stock_status' => $this->stockStatus($location),
                'expiry_status' => $this->expiryStatus($location),
            ],
        ];

        if ($location === 0) {
            $summary['branches'] = $this->branchSummary();
        }

        return $summary;
    }

    /** @return array{from: string, to: string, timezone: string, dates: list<string>, series: array<string, list<int>>} */
    private function dailyActivity(int $location): array
    {
        $today = now()->startOfDay();
        $start = $today->copy()->subDays(13);
        // Request/transfer dispatch changes the source; acceptance changes only the destination.
        // Count effective LOT movement rows, excluding reversed originals and reversal entries.
        $daily = Movement::query()->where('happened_at', '>=', $start)->where('happened_at', '<', $today->copy()->addDay())
            ->where('qty', '>', 0)
            ->where(fn ($query) => $query->where('from_location', $location)->orWhere('to_location', $location))
            ->whereIn('type', ['receipt', 'branch_in', 'branch_out', 'consumption', 'inventory_adjustment', 'return_in', 'return_supplier', 'transfer_sent', 'branch_transfer'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movement_corrections as mc')->whereColumn('mc.movement_id', 'movements.id'))
            ->selectRaw('DATE(happened_at) as activity_date')
            ->selectRaw("SUM(CASE WHEN type IN ('receipt', 'branch_in', 'inventory_adjustment') AND to_location = ? THEN 1 ELSE 0 END) as receipts", [$location])
            ->selectRaw("SUM(CASE WHEN type IN ('consumption', 'branch_out', 'inventory_adjustment') AND from_location = ? THEN 1 ELSE 0 END) as issues", [$location])
            ->selectRaw("SUM(CASE WHEN (type = 'return_in' AND (from_location = ? OR to_location = ?)) OR (type = 'return_supplier' AND from_location = ?) THEN 1 ELSE 0 END) as returns", [$location, $location, $location])
            ->selectRaw("SUM(CASE WHEN (type = 'transfer_sent' AND from_location = ?) OR (type = 'branch_transfer' AND to_location = ?) THEN 1 ELSE 0 END) as transfers", [$location, $location])
            ->groupByRaw('DATE(happened_at)')->get()->keyBy('activity_date');

        $dates = [];
        $series = ['receipts' => [], 'issues' => [], 'returns' => [], 'transfers' => []];
        for ($day = $start->copy(); $day->lte($today); $day->addDay()) {
            $date = $day->toDateString();
            $dates[] = $date;
            $row = $daily->get($date);
            foreach ($series as $key => $_) {
                $series[$key][] = (int) ($row?->{$key} ?? 0);
            }
        }

        return ['from' => $start->toDateString(), 'to' => $today->toDateString(), 'timezone' => (string) config('app.timezone'), 'dates' => $dates, 'series' => $series];
    }

    /** @return array{healthy: int, low: int, zero: int} */
    private function stockStatus(int $location): array
    {
        $products = Product::query()->leftJoin('stock_lots as l', function ($join) use ($location): void {
            $join->on('l.product_id', '=', 'products.id')->where('l.location_id', '=', $location);
        })->where('products.active', true)->groupBy('products.id', 'products.min_qty')
            ->select('products.id', 'products.min_qty')->selectRaw('COALESCE(SUM(l.qty), 0) as quantity')
            ->selectRaw('SUM(CASE WHEN l.qty > 0 THEN 1 ELSE 0 END) as positive_lots');
        $counts = DB::query()->fromSub($products->toBase(), 'scoped_products')
            ->selectRaw('SUM(CASE WHEN positive_lots > 0 AND quantity >= min_qty THEN 1 ELSE 0 END) as healthy')
            ->selectRaw('SUM(CASE WHEN positive_lots > 0 AND quantity < min_qty THEN 1 ELSE 0 END) as low')
            ->selectRaw('SUM(CASE WHEN positive_lots = 0 THEN 1 ELSE 0 END) as zero')->first();

        return ['healthy' => (int) $counts->healthy, 'low' => (int) $counts->low, 'zero' => (int) $counts->zero];
    }

    /** @return array{safe: int, expiring: int, expired: int, undated: int} */
    private function expiryStatus(int $location): array
    {
        $today = now()->toDateString();
        $cutoff = now()->addDays(90)->toDateString();
        $counts = StockLot::query()->where('location_id', $location)->where('qty', '>', 0)
            ->selectRaw('SUM(CASE WHEN DATE(expires_on) > ? THEN 1 ELSE 0 END) as safe', [$cutoff])
            ->selectRaw('SUM(CASE WHEN DATE(expires_on) >= ? AND DATE(expires_on) <= ? THEN 1 ELSE 0 END) as expiring', [$today, $cutoff])
            ->selectRaw('SUM(CASE WHEN DATE(expires_on) < ? THEN 1 ELSE 0 END) as expired', [$today])
            ->selectRaw('SUM(CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END) as undated')->first();

        return ['safe' => (int) $counts->safe, 'expiring' => (int) $counts->expiring, 'expired' => (int) $counts->expired, 'undated' => (int) $counts->undated];
    }

    /** @return list<array{branch_id: int, branch: string, stock_units: float, open_requests: int, unapproved_requests: int, awaiting_receipt_requests: int, awaiting_transfer_receipts: int}> */
    private function branchSummary(): array
    {
        $branches = Branch::query()->where('active', true)->where('code', '<>', 'CENTRAL')->orderBy('name')->get(['id', 'name']);
        $branchIds = $branches->modelKeys();
        if ($branchIds === []) {
            return [];
        }

        $stockByBranch = StockLot::query()->whereIn('location_id', $branchIds)
            ->selectRaw('location_id, COALESCE(SUM(qty), 0) as stock_units')->groupBy('location_id')
            ->get()->keyBy('location_id');
        $requestsByBranch = StockRequest::query()->whereIn('branch_id', $branchIds)
            ->whereIn('status', self::OPEN_REQUEST_STATUSES)
            ->selectRaw('branch_id, status, COUNT(*) as total')->groupBy('branch_id', 'status')
            ->get()->groupBy('branch_id');
        $transfersByBranch = Transfer::query()->whereIn('to_branch', $branchIds)->where('status', 'shipped')
            ->selectRaw('to_branch, COUNT(*) as total')->groupBy('to_branch')->get()->keyBy('to_branch');

        return $branches->map(function (Branch $branch) use ($stockByBranch, $requestsByBranch, $transfersByBranch): array {
            $requests = $requestsByBranch->get($branch->id, collect())->keyBy('status');
            $count = static fn (string $status): int => (int) ($requests->get($status)?->total ?? 0);

            return [
                'branch_id' => (int) $branch->id,
                'branch' => $branch->name,
                'stock_units' => (float) ($stockByBranch->get($branch->id)?->stock_units ?? 0),
                'open_requests' => (int) $requests->sum('total'),
                'unapproved_requests' => $count('sent') + $count('review'),
                'awaiting_receipt_requests' => $count('shipped'),
                'awaiting_transfer_receipts' => (int) ($transfersByBranch->get($branch->id)?->total ?? 0),
            ];
        })->all();
    }

    /** @return array{receipts: int, issues: int, returns: int, transfers: int} */
    private function todayActivity(int $location): array
    {
        $today = now()->toDateString();

        return [
            'receipts' => $location === 0
                ? Receipt::query()->whereDate('received_on', $today)->count()
                : Movement::query()->where('type', 'branch_in')->where('to_location', $location)->whereDate('happened_at', $today)->count(),
            'issues' => Movement::query()->where('from_location', $location)->whereDate('happened_at', $today)
                ->whereIn('type', ['branch_out', 'consumption', 'return_supplier', 'inventory_adjustment'])->count(),
            'returns' => ProductReturn::query()->where('from_location', $location)->whereDate('created_at', $today)->count(),
            'transfers' => Transfer::query()->whereDate('created_at', $today)
                ->when($location > 0, fn ($query) => $query->where(fn ($where) => $where->where('from_branch', $location)->orWhere('to_branch', $location)))->count(),
        ];
    }
}
