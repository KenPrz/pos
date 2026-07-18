<?php

// backend/app/Actions/Admin/Catalog/UpdateVariant.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class UpdateVariant
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateVariantInput $in): ProductVariant
    {
        return DB::transaction(function () use ($in): ProductVariant {
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($in->variantId);
            $oldPriceCents = $variant->price_cents;

            $variant->fill($in->changes)->save();

            $payload = ['changed' => array_keys($in->changes)];

            // Repricing is the fraud-adjacent event here, so it gets the richer
            // old/new payload rather than just landing in "changed".
            if (array_key_exists('price_cents', $in->changes) && $variant->price_cents !== $oldPriceCents) {
                $payload['price_cents'] = ['from' => $oldPriceCents, 'to' => $variant->price_cents];
            }

            $this->audit->record('admin.variant.update', $variant, $in->actorId, $payload);

            return $variant;
        });
    }
}
