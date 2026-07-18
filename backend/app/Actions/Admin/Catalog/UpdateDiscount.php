<?php

// backend/app/Actions/Admin/Catalog/UpdateDiscount.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Discount;
use Illuminate\Support\Facades\DB;

final class UpdateDiscount
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateDiscountInput $in): Discount
    {
        return DB::transaction(function () use ($in): Discount {
            $discount = Discount::query()->lockForUpdate()->findOrFail($in->discountId);

            $discount->fill($in->changes)->save();

            $this->audit->record('admin.discount.update', $discount, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $discount;
        });
    }
}
