<?php

declare(strict_types=1);

namespace App\Models;

enum OrderStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Voided = 'voided';
}
