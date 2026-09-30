<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\DashboardRepository;

class DashboardService
{
    public function __construct(private readonly DashboardRepository $dashboard) {}

    public function summary(User $actor): array
    {
        $permissions = $actor->permissionMap();
        $can = static fn (string $permission): bool => ! empty($permissions[$permission]);
        $summary = $this->dashboard->summary((int) $actor->currentLocationId(), $can('purchases.view'));

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
            }
        }
        if (empty($summary['today'])) {
            unset($summary['today']);
        }

        return $summary;
    }
}
