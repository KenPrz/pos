<?php

// backend/app/Actions/Admin/Catalog/SetProductModifierGroups.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** Full-set replace; position = array index. Empty array detaches everything. */
final class SetProductModifierGroups
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(SetProductModifierGroupsInput $in): Product
    {
        return DB::transaction(function () use ($in): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($in->productId);

            $sync = [];
            foreach (array_values($in->groupIds) as $ix => $groupId) {
                $sync[$groupId] = ['position' => $ix];
            }
            $product->modifierGroups()->sync($sync);

            $this->audit->record('admin.product.modifier_groups', $product, $in->actorId, [
                'group_ids' => $in->groupIds,
            ]);

            return $product->load('modifierGroups');
        });
    }
}
