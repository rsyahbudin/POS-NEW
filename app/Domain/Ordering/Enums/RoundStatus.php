<?php

namespace App\Domain\Ordering\Enums;

enum RoundStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
