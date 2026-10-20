<?php

namespace App\Domain\Pricing\Enums;

enum ChargeKind: string
{
    case Tax = 'tax';
    case Service = 'service';
}
