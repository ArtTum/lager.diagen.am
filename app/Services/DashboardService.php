<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Repositories\DashboardRepository;
use Illuminate\Validation\ValidationException;

class DashboardService
{
    public function __construct(private readonly DashboardRepository $dashboard) {}

    public function summary(User $actor, ?int $branchId = null): array
    {
        $permissions = $actor->permissionMap();
        $can = static fn (string $permission): bool => ! empty($permissions[$permission]);
        $actorLocation = $actor->currentLocationId();
        $canSelectLocation = $actorLocation === 0 && $can('branches.view');

        if (! $canSelectLocation && $branchId !== null && $branchId !== $actorLocation) {
            abort(403, 'Այլ պահեստի վահանակը դիտելու իրավունք չունեք։');
        }

        $location = $branchId ?? $actorLocation;
        $branch = $location > 0 ? Branch::query()->find($location, ['id', 'name', 'code', 'active']) : null;
        if ($location < 0 || ($branchId !== null && $location > 0 && (! $branch || ! $branch->active || $branch->code === 'CENTRAL'))) {
            throw ValidationException::withMessages(['branch_id' => ['Ընտրեք ակտիվ մասնաճյուղ կամ կենտրոնական պահեստը։']]);
        }

        $summary = $this->dashboard->summary($location, $can('purchases.view'));

        foreach ([
            'stock.view' => ['products', 'units', 'low_stock_products', 'zero_stock_products'],
            'expiry.view' => ['expired_lots', 'expiring_lots'],
            'requests.view' => ['open_requests', 'unapproved_requests', 'awaiting_receipt_requests'],
        ] as $permission => $fields) {
            if (! $can($permission)) {
                foreach ($fields as $field) {
                    unset($summary[$field]);
                }
            }
        }

        if (! $can('branches.view')) {
            unset($summary['branches']);
        } elseif (isset($summary['branches'])) {
            $summary['branches'] = array_map(static function (array $row) use ($can): array {
                foreach ([
                    'stock.view' => ['stock_units'],
                    'requests.view' => ['open_requests', 'unapproved_requests', 'awaiting_receipt_requests'],
                    'transfers.view' => ['awaiting_transfer_receipts'],
                ] as $permission => $fields) {
                    if (! $can($permission)) {
                        foreach ($fields as $field) {
                            unset($row[$field]);
                        }
                    }
                }

                return $row;
            }, $summary['branches']);
        }
        if (! $can('purchases.view')) {
            unset($summary['stock_value']);
        }

        $activityPermissions = [
            'receipts' => 'receipts.view',
            'issues' => 'movements.view',
            'returns' => 'returns.view',
            'transfers' => 'transfers.view',
        ];
        foreach ($activityPermissions as $activity => $permission) {
            if (! $can($permission)) {
                unset($summary['today'][$activity]);
                unset($summary['charts']['activity_daily']['series'][$activity]);
            }
        }
        if (empty($summary['today'])) {
            unset($summary['today']);
        }
        if (empty($summary['charts']['activity_daily']['series'])) {
            unset($summary['charts']['activity_daily']);
        }
        if (! $can('stock.view')) {
            unset($summary['charts']['stock_status']);
        }
        if (! $can('expiry.view')) {
            unset($summary['charts']['expiry_status']);
        }
        if (empty($summary['charts'])) {
            unset($summary['charts']);
        }

        $locationOptions = [];
        if ($canSelectLocation) {
            $locationOptions = [
                ['id' => 0, 'name' => 'Կենտրոնական պահեստ'],
                ...Branch::query()->where('active', true)->where('code', '<>', 'CENTRAL')->orderBy('name')
                    ->get(['id', 'name'])->map(static fn (Branch $option): array => ['id' => (int) $option->id, 'name' => $option->name])->all(),
            ];
        }

        return [
            ...$summary,
            'selected_location' => ['id' => $location, 'name' => $location === 0 ? 'Կենտրոնական պահեստ' : ($branch?->name ?? 'Չնշված պահեստ')],
            'location_options' => $locationOptions,
            'can_select_location' => $canSelectLocation,
        ];
    }
}
