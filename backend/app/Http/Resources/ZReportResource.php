<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps ZReport (Actions/Reports). */
final class ZReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'shift' => new ShiftResource($this->shift),
            'sales_by_driver' => $this->salesByDriver,
            'refunds_by_driver' => $this->refundsByDriver,
            'movements' => $this->movements,
            'orders_closed' => $this->ordersClosed,
            'orders_voided' => $this->ordersVoided,
            'expected_cash_cents' => $this->expectedCashCents,
        ];
    }
}
