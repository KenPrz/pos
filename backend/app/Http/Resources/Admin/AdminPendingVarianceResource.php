<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plain serializer over ListPendingVariances's query row — not an Eloquent model, so
 * this mirrors the row's own column names rather than a `@mixin`. pgsql returns
 * aggregates/bigints as strings, so every money column is cast to `int` here.
 */
final class AdminPendingVarianceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'shift_id' => $this->shift_id,
            'register_id' => $this->register_id,
            'register_name' => $this->register_name,
            'location_id' => $this->location_id,
            'location_name' => $this->location_name,
            'opened_by_name' => $this->opened_by_name,
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'expected_cash_cents' => (int) $this->expected_cash_cents,
            'counted_cash_cents' => (int) $this->counted_cash_cents,
            'variance_cents' => (int) $this->variance_cents,
            // Returned per row so the client can show WHY a row qualifies without
            // re-deriving a rule the server owns.
            'threshold_cents' => (int) $this->threshold_cents,
        ];
    }
}
