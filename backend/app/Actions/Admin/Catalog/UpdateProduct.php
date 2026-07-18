<?php

// backend/app/Actions/Admin/Catalog/UpdateProduct.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class UpdateProduct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateProductInput $in): Product
    {
        return DB::transaction(function () use ($in): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($in->productId);

            $product->fill($in->changes)->save();

            $this->audit->record('admin.product.update', $product, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $product;
        });
    }
}
