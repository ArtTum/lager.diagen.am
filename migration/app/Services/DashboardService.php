<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\DashboardRepository;

class DashboardService
{
    public function __construct(private readonly DashboardRepository $dashboard) {}

    public function summary(User $actor): array
    {
        return $this->dashboard->summary((int) $actor->currentLocationId(), $actor->hasPermissionCode('purchases.view'));
    }
}
