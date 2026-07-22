<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The server decides WHAT a receipt says; the client (and one day the desktop shell)
 * decides HOW to put it on paper. Snapshot columns only — a reprint next year must
 * produce identical bytes.
 */
final class ReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = app(Settings::class);

        return [
            'business' => [
                'name' => $settings->get('business.name'),
                'address' => $settings->get('business.address'),
                'tax_id' => $settings->get('business.tax_id'),
            ],
            'location' => [
                'name' => $this->location->name,
                'header' => $this->location->receipt_header,
                'footer' => $this->location->receipt_footer,
            ],
            'order' => [
                'number' => $this->number,
                'business_date' => $this->business_date,
                'opened_at' => $this->opened_at?->toIso8601String(),
                'closed_at' => $this->closed_at?->toIso8601String(),
                'table_ref' => $this->table_ref,
                'cashier' => $this->opener->name,
                'prices_include_tax' => $this->prices_include_tax,
            ],
            'lines' => $this->lines->map(fn ($line): array => [
                'name' => $line->name_snapshot,
                'sku' => $line->sku_snapshot,
                'qty' => $line->qty,
                'unit_price_cents' => $line->unit_price_cents,
                'line_total_cents' => $line->line_total_cents,
                'tax_cents' => $line->tax_cents,
                'modifiers' => $line->modifiers->map(fn ($m): array => [
                    'name' => $m->name_snapshot,
                    'price_delta_cents' => $m->price_delta_cents,
                ])->values()->all(),
            ])->values()->all(),
            'totals' => [
                'subtotal_cents' => $this->subtotal_cents,
                'discount_cents' => $this->discount_cents,
                'tax_cents' => $this->tax_cents,
                'total_cents' => $this->total_cents,
                'paid_cents' => $this->paid_cents,
            ],
            'payments' => $this->payments->map(fn ($p): array => [
                'driver' => $p->driver,
                'amount_cents' => $p->amount_cents,
                'tendered_cents' => $p->tendered_cents,
                'change_cents' => $p->change_cents,
            ])->values()->all(),
            'currency' => config('pos.currency'),
        ];
    }
}
