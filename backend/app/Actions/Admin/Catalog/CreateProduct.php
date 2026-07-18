<?php

// backend/app/Actions/Admin/Catalog/CreateProduct.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class CreateProduct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateProductInput $in): Product
    {
        return DB::transaction(function () use ($in): Product {
            $product = Product::create([
                'name' => $in->name,
                'description' => $in->description,
                'category_id' => $in->categoryId,
                'kind' => $in->kind,
                'is_active' => true,
            ]);

            $this->audit->record('admin.product.create', $product, $in->actorId, [
                'name' => $in->name, 'kind' => $in->kind,
            ]);

            return $product;
        });
    }
}
