<?php

namespace App\Domain\Payment\Enums;

enum BillStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Void = 'void';
}
