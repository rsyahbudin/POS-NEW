<?php

namespace App\Domain\Ordering\Enums;

enum RoundSource: string
{
    case Pos = 'pos';
    case Qr = 'qr';
}
