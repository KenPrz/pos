<?php

// backend/app/Actions/Admin/Catalog/UpdateTaxRate.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;

final class UpdateTaxRate
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateTaxRateInput $in): TaxRate
    {
        return DB::transaction(function () use ($in): TaxRate {
            $taxRate = TaxRate::query()->lockForUpdate()->findOrFail($in->taxRateId);

            $taxRate->fill($in->changes)->save();

            $this->audit->record('admin.tax_rate.update', $taxRate, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $taxRate;
        });
    }
}
