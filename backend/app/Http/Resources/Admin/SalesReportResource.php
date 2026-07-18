<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the object `SalesReport` returns — `{rows, totals, basis}`.
 *
 * `basis` names which kind of number the requested `group_by` produced: `"ledger"` for
 * `day`/`user` (captured payments and refunds — money that actually moved), `"lines"`
 * for `category` (the sales mix read off non-voided order lines of closed orders,
 * joined to the LIVE catalog for category names — a report may do that join; a receipt
 * never may, since it must reprint identically to what it said on the day). The two
 * bases are not guaranteed to reconcile with each other, which is exactly why the field
 * exists: so the back office can label what it's showing instead of implying one number.
 */
final class SalesReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'rows' => $this->rows,
            'totals' => $this->totals,
            'basis' => $this->basis,
        ];
    }
}
