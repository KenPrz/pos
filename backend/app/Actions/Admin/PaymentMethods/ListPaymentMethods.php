<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;

/** Unpaginated, like every admin list in v1. */
final class ListPaymentMethods
{
    /** @return Collection<int, PaymentMethod> */
    public function execute(ListPaymentMethodsInput $in): Collection
    {
        return PaymentMethod::query()
            ->where('location_id', $in->locationId)
            ->orderBy('sort_order')->orderBy('code')
            ->get();
    }
}
