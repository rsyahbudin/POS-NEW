<?php

namespace App\Domain\Staffing;

use App\Models\User;

/** The stations a person can be scheduled on. One person may cover several in the same shift (the MOD fills an empty station). */
final class Positions
{
    public const ALL = ['cashier' => 'Kasir', 'waiter' => 'Waiter', 'kitchen' => 'Dapur / Bar', 'mod' => 'MOD'];

    /** What the person normally does, from their role: used when no position is picked. */
    public static function defaultFor(User $user): string
    {
        $role = $user->getRoleNames()->first();

        return is_string($role) && isset(self::ALL[$role]) ? $role : 'none';
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public static function clean(array $values): array
    {
        return array_values(array_unique(array_filter($values, fn ($v) => is_string($v) && isset(self::ALL[$v]))));
    }
}
