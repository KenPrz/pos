<?php

declare(strict_types=1);

namespace App\Domain\Money;

enum DiscountKind: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
