<?php

// backend/app/Actions/Admin/Catalog/CreateVariant.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class CreateVariant
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateVariantInput $in): ProductVariant
    {
        return DB::transaction(function () use ($in): ProductVariant {
            $variant = ProductVariant::create([
                'product_id' => $in->productId,
                'name' => $in->name,
                'sku' => $in->sku,
                'barcode' => $in->barcode,
                'price_cents' => $in->priceCents,
                'cost_cents' => $in->costCents,
                'tax_rate_id' => $in->taxRateId,
                'track_inventory' => $in->trackInventory,
                'is_active' => true,
            ]);

            $this->audit->record('admin.variant.create', $variant, $in->actorId, [
                'name' => $in->name, 'sku' => $in->sku, 'price_cents' => $in->priceCents,
            ]);

            return $variant;
        });
    }
}
