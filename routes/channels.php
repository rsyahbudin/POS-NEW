<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('outlet.{outletId}.orders', fn (User $user, int $outletId) => $user->is_active && $user->can('create-order') || $user->can('receive-payment'));

Broadcast::channel('outlet.{outletId}.print', fn (User $user, int $outletId) => $user->is_active && ($user->can('receive-payment') || $user->can('create-order') || $user->can('update-kitchen-status')));

Broadcast::channel('outlet.{outletId}.kitchen.{stationId}', fn (User $user, int $outletId, int $stationId) => $user->is_active && $user->can('update-kitchen-status'));
