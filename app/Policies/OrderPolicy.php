<?php

namespace App\Policies;

use App\Domain\Ordering\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->can('create-order') || $user->can('receive-payment') || $user->can('view-sales-reports');
    }
}
