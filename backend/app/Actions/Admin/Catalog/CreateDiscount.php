<?php

// backend/app/Actions/Admin/Catalog/CreateDiscount.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Discount;
use Illuminate\Support\Facades\DB;

final class CreateDiscount
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateDiscountInput $in): Discount
    {
        return DB::transaction(function () use ($in): Discount {
            $discount = Discount::create([
                'name' => $in->name,
                'kind' => $in->kind,
                'percent_micros' => $in->percentMicros,
                'amount_cents' => $in->amountCents,
                'scope' => $in->scope,
                'requires_supervisor' => $in->requiresSupervisor,
                'is_active' => $in->isActive,
            ]);

            $this->audit->record('admin.discount.create', $discount, $in->actorId, [
                'name' => $in->name, 'kind' => $in->kind, 'scope' => $in->scope,
            ]);

            return $discount;
        });
    }
}
