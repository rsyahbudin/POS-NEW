<?php

namespace App\Domain\Payment\Enums;

enum PaymentMethodType: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Transfer = 'transfer';
}
