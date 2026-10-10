<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Models\InventorySession;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\Transfer;
use App\Models\UserNotificationRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NotificationRepository
{
    public function currentStock(int $location): Collection
    {
        return Product::query()->leftJoin('stock_lots as l', function ($join) use ($location): void {
            $join->on('l.product_id', '=', 'products.id')->where('l.location_id', '=', $location);
        })->where('products.active', true)->groupBy('products.id', 'products.code', 'products.name', 'products.min_qty', 'products.max_qty')
            ->select('products.id', 'products.code', 'products.name', 'products.min_qty', 'products.max_qty')
            ->selectRaw('COALESCE(SUM(l.qty),0) as qty')
            ->havingRaw('COALESCE(SUM(l.qty),0) <= products.min_qty OR (products.max_qty > 0 AND COALESCE(SUM(l.qty),0) > products.max_qty)')
            ->orderBy('products.name')->limit(100)->get();
    }

    public function expiringLots(int $location): Collection
    {
        return StockLot::query()->join('products as p', 'p.id', '=', 'stock_lots.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'stock_lots.location_id')
            ->where('p.expiry_control', true)->where('stock_lots.qty', '>', 0)->whereNotNull('stock_lots.expires_on')
            ->whereDate('stock_lots.expires_on', '<=', now()->addDays(180)->toDateString())
            ->when($location > 0, fn ($query) => $query->where('stock_lots.location_id', $location))
            ->select('stock_lots.id', 'stock_lots.lot_no', 'stock_lots.qty', 'stock_lots.expires_on', 'p.code', 'p.name', 'p.unit')
            ->selectRaw("CASE WHEN stock_lots.location_id = 0 THEN 'Կենտրոնական պահեստ' ELSE COALESCE(b.name, 'Անհայտ պահեստ') END as branch_name")
            ->orderBy('stock_lots.expires_on')->limit(150)->get();
    }

    public function pendingRequests(int $location): Collection
    {
        return StockRequest::query()->join('branches as b', 'b.id', '=', 'stock_requests.branch_id')
            ->whereIn('stock_requests.status', ['sent', 'review'])
            ->when($location > 0, fn ($query) => $query->where('stock_requests.branch_id', $location))
            ->select('stock_requests.id', 'stock_requests.request_no', 'stock_requests.status', 'stock_requests.created_at', 'b.name as branch_name')
            ->addSelect(['notice_updated_at' => $this->lastChange('stock_requests')])
            ->orderByDesc('stock_requests.id')->limit(50)->get();
    }

    public function shippedRequests(int $location): Collection
    {
        if ($location < 1) {
            return collect();
        }

        return StockRequest::query()->where('branch_id', $location)->where('status', 'shipped')
            ->orderBy('sent_at')->limit(50)->get(['id', 'request_no', 'sent_at']);
    }

    public function activeInventories(int $location, bool $canApprove): Collection
    {
        $query = InventorySession::query()->leftJoin('branches as b', 'b.id', '=', 'inventory_sessions.location_id');
        if ($location > 0) {
            $query->where('inventory_sessions.location_id', $location)->whereIn('inventory_sessions.status', ['open', 'counted']);
        } elseif ($canApprove) {
            // Approval-capable central users need to see counted sessions that
            // require their review, regardless of the originating branch.
            $query->where('inventory_sessions.status', 'counted');
        } else {
            // Match the legacy visibility rule: without approval rights, a
            // central viewer only sees an in-progress central inventory.
            $query->where('inventory_sessions.location_id', 0)->where('inventory_sessions.status', 'open');
        }

        return $query->select('inventory_sessions.inventory_no', 'inventory_sessions.status', 'inventory_sessions.started_at')
            ->addSelect(['notice_updated_at' => $this->lastChange('inventory_sessions')])
            ->selectRaw("CASE WHEN inventory_sessions.location_id = 0 THEN 'Կենտրոնական պահեստ' ELSE COALESCE(b.name, 'Անհայտ պահեստ') END as branch_name")
            ->orderBy('inventory_sessions.started_at')->limit(50)->get();
    }

    public function requestEvents(int $location, int $actorId): Collection
    {
        // Read transitions, rather than the request's current status: a quick
        // collect/ship/receive must not erase an unseen approval notification.
        return AuditLog::query()->join('stock_requests as r', 'r.id', '=', 'audit_logs.entity_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')
            ->where('audit_logs.entity', 'stock_requests')
            ->where('audit_logs.created_at', '>=', now()->subDays(7))
            ->where(fn ($query) => $query->whereNull('audit_logs.actor_id')->orWhere('audit_logs.actor_id', '<>', $actorId))
            ->when($location > 0, fn ($query) => $query->where('r.branch_id', $location))
            ->select('audit_logs.*', 'r.request_no', 'r.rejection_reason', 'b.name as branch_name')
            ->orderByDesc('audit_logs.created_at')->orderByDesc('audit_logs.id')->limit(1000)->get()
            ->filter(static fn (AuditLog $event): bool => in_array($event->after_data['status'] ?? null,
                ['sent', 'review', 'approved', 'partially_approved', 'rejected', 'collecting', 'ready_to_ship', 'shipped', 'received', 'closed', 'cancelled'], true)
                && ($event->after_data['status'] ?? null) !== ($event->before_data['status'] ?? null)
                && !(($event->after_data['status'] ?? null) === 'cancelled' && ($event->before_data['status'] ?? null) === 'draft'))
            ->take(200)->values();
    }

    public function pendingTransfers(): Collection
    {
        return Transfer::query()->join('branches as f', 'f.id', '=', 'transfers.from_branch')
            ->join('branches as t', 't.id', '=', 'transfers.to_branch')->where('transfers.status', 'pending')
            ->select('transfers.transfer_no', 'transfers.created_at', 'f.name as from_name', 't.name as to_name')
            ->addSelect(['notice_updated_at' => $this->lastChange('transfers')])
            ->orderByDesc('transfers.id')->limit(50)->get();
    }

    public function incomingTransfers(int $location): Collection
    {
        if ($location < 0) {
            return collect();
        }

        return Transfer::query()->join('branches as b', 'b.id', '=', 'transfers.from_branch')
            ->join('branches as destination', 'destination.id', '=', 'transfers.to_branch')
            ->where('transfers.status', 'shipped')
            ->when($location === 0,
                fn ($query) => $query->where('destination.code', 'CENTRAL'),
                fn ($query) => $query->where('transfers.to_branch', $location),
            )
            ->select('transfers.transfer_no', 'transfers.created_at', 'b.name as from_name')
            ->addSelect(['notice_updated_at' => $this->lastChange('transfers')])->orderByDesc('transfers.id')->limit(50)->get();
    }

    private function lastChange(string $table): Builder
    {
        return AuditLog::query()->selectRaw('MAX(audit_logs.created_at)')->where('audit_logs.entity', $table)
            ->whereColumn('audit_logs.entity_id', $table.'.id');
    }

    public function readKeys(int $userId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return UserNotificationRead::query()->where('user_id', $userId)->whereIn('notice_key', $keys)->pluck('notice_key')->all();
    }

    public function markRead(int $userId, string $key): void
    {
        UserNotificationRead::query()->updateOrCreate(['user_id' => $userId, 'notice_key' => $key], ['read_at' => now()]);
    }
}
