<?php

namespace App\Domain\Ordering\Enums;

use App\Domain\Ordering\Exceptions\InvalidTransition;

enum OrderStatus: string
{
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::PartiallyPaid, self::Paid, self::Cancelled],
            self::PartiallyPaid => [self::Paid, self::Open],
            self::Paid => [self::Refunded],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function transitionTo(self $next): self
    {
        if (! $this->canTransitionTo($next)) {
            throw new InvalidTransition("Order tidak bisa berubah dari {$this->value} ke {$next->value}.");
        }

        return $next;
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Open, self::PartiallyPaid], true);
    }

    public function isActive(): bool
    {
        return $this->isEditable();
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::PartiallyPaid => 'Sebagian Dibayar',
            self::Paid => 'Lunas',
            self::Cancelled => 'Dibatalkan',
            self::Refunded => 'Refund',
        };
    }
}
