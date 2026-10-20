<?php

namespace App\Domain\Ordering\Enums;

enum OrderType: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Online = 'online';
}
