<?php
// backend/app/Http/Resources/Admin/BusinessDayResource.php
declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\BusinessDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The persisted close record — the immutable, self-contained day snapshot. */
final class BusinessDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BusinessDay $d */
        $d = $this->resource;

        return [
            'id' => $d->id,
            'location_id' => $d->location_id,
            'business_date' => $d->business_date,
            'closed_by' => $d->closed_by,
            'closed_at' => $d->closed_at?->toIso8601String(),
            'gross_sales_cents' => (int) $d->gross_sales_cents,
            'refunds_cents' => (int) $d->refunds_cents,
            'net_sales_cents' => (int) $d->net_sales_cents,
            'tax_cents' => (int) $d->tax_cents,
            'expected_cash_cents' => (int) $d->expected_cash_cents,
            'counted_cash_cents' => (int) $d->counted_cash_cents,
            'variance_cents' => (int) $d->variance_cents,
            'shift_count' => (int) $d->shift_count,
            'deposit_cents' => (int) $d->deposit_cents,
            'checklist' => $d->checklist,
            'note' => $d->note,
            'reopened_at' => $d->reopened_at?->toIso8601String(),
            'reopened_by' => $d->reopened_by,
        ];
    }
}
