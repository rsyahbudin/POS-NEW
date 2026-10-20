<?php

namespace App\Domain\Ordering\Enums;

enum CompType: string
{
    case Complimentary = 'complimentary';
    case StaffMeal = 'staff_meal';
}
