<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Human-facing order numbers: per location, per business day, gapless under
 * concurrency. A Postgres sequence won't do — sequences don't reset per day per
 * location and leak gaps on rollback, and an auditor reading a receipt book with
 * holes asks questions. See docs/02-data-model.md (order numbers).
 */
final class OrderNumbers
{
    public function next(Location $location, string $businessDate): string
    {
        $seq = DB::selectOne(
            'insert into order_counters (location_id, business_date, next_val)
             values (?, ?, 2)
             on conflict (location_id, business_date)
               do update set next_val = order_counters.next_val + 1
             returning next_val - 1 as seq',
            [$location->id, $businessDate],
        )->seq;

        return str_replace(
            ['{location}', '{date}', '{seq}'],
            [$location->code, str_replace('-', '', $businessDate), str_pad((string) $seq, 4, '0', STR_PAD_LEFT)],
            config('pos.orders.number_format'),
        );
    }
}
