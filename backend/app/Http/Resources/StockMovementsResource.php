<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** { movements, level } — docs/03-api.md. Wraps the object `GetStockMovements` returns. */
final class StockMovementsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'movements' => collect($this->movements)->map(fn (object $m): array => [
                'qty_delta' => $m->qty_delta,
                'reason' => $m->reason,
                'ref_type' => $m->ref_type,
                'ref_id' => $m->ref_id,
                'user_id' => $m->user_id,
                'note' => $m->note,
                'created_at' => $m->created_at,
            ])->all(),
            'level' => [
                'variant_id' => $this->level->variant_id,
                'qty' => $this->level->qty,
            ],
        ];
    }
}
