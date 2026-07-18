<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the object `ListAuditLog` returns — `{rows, page, has_more}`. */
final class AdminAuditResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'rows' => $this->rows,
            'page' => $this->page,
            'has_more' => $this->has_more,
        ];
    }
}
