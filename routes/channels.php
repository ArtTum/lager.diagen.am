<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('lager.updates', static fn (User $user): bool => $user->active, ['guards' => ['sanctum']]);
