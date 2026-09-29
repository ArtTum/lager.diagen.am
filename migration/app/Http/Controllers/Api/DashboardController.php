<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $location = (int) request()->user()->currentLocationId();
        $lots = DB::table('stock_lots')->where('location_id', $location);
        $summary = (clone $lots)->selectRaw('COUNT(DISTINCT product_id) as products, COALESCE(SUM(qty),0) as units, COALESCE(SUM(qty * unit_cost),0) as value')->first();
        $lowStock = DB::table('products as p')->leftJoin('stock_lots as l', function ($join) use ($location): void {
            $join->on('l.product_id', '=', 'p.id')->where('l.location_id', '=', $location);
        })->where('p.active', 1)->groupBy('p.id', 'p.name', 'p.code', 'p.min_qty')
            ->havingRaw('COALESCE(SUM(l.qty),0) < p.min_qty')->count();
        $expiring = (clone $lots)->whereNotNull('expires_on')->whereBetween('expires_on', [now()->toDateString(), now()->addDays(90)->toDateString()])->count();
        $pending = DB::table('stock_requests')->whereIn('status', ['sent', 'review', 'approved', 'ready', 'shipped']);
        if ($location !== 0) $pending->where('branch_id', $location);

        return response()->json(['data' => [
            'products' => (int) ($summary->products ?? 0),
            'units' => (float) ($summary->units ?? 0),
            'stock_value' => (float) ($summary->value ?? 0),
            'low_stock_products' => $lowStock,
            'expiring_lots' => $expiring,
            'open_requests' => $pending->count(),
        ]]);
    }
}
