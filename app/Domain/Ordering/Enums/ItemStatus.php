<?php

namespace App\Domain\Ordering\Enums;

use App\Domain\Ordering\Exceptions\InvalidTransition;

enum ItemStatus: string
{
    case Pending = 'pending';
    case New = 'new';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Voided = 'voided';
    case Rejected = 'rejected';

    public function next(): self
    {
        return match ($this) {
            self::New => self::Preparing,
            self::Preparing => self::Ready,
            default => throw new InvalidTransition("Item berstatus {$this->value} tidak bisa dimajukan."),
        };
    }

    public function isLive(): bool
    {
        return in_array($this, [self::New, self::Preparing, self::Ready], true);
    }

    public function countsTowardTotal(): bool
    {
        return $this->isLive();
    }
}
