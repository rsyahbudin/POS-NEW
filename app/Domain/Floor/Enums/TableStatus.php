<?php

namespace App\Domain\Floor\Enums;

enum TableStatus: string
{
    case Empty = 'empty';
    case Occupied = 'occupied';
    case AwaitingPayment = 'awaiting_payment';
}
