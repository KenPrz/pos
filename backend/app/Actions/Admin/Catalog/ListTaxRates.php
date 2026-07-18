<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Collection;

final class ListTaxRates
{
    /** @return Collection<int, TaxRate> */
    public function execute(): Collection
    {
        return TaxRate::query()->orderBy('name')->get();
    }
}
