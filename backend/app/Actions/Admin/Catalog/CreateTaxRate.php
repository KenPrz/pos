<?php

// backend/app/Actions/Admin/Catalog/CreateTaxRate.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;

final class CreateTaxRate
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateTaxRateInput $in): TaxRate
    {
        return DB::transaction(function () use ($in): TaxRate {
            $taxRate = TaxRate::create([
                'name' => $in->name,
                'rate_micros' => $in->rateMicros,
                'is_active' => $in->isActive,
            ]);

            $this->audit->record('admin.tax_rate.create', $taxRate, $in->actorId, [
                'name' => $in->name, 'rate_micros' => $in->rateMicros,
            ]);

            return $taxRate;
        });
    }
}
