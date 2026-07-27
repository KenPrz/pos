<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Models\PaymentMethodGroup;
use Illuminate\Database\Eloquent\Collection;

/** Unpaginated, like every admin list in v1. Ordered as the till renders them. */
final class ListPaymentMethodGroups
{
    /** @return Collection<int, PaymentMethodGroup> */
    public function execute(ListPaymentMethodGroupsInput $in): Collection
    {
        return PaymentMethodGroup::query()
            ->where('location_id', $in->locationId)
            ->with(['methods' => fn ($query) => $query->orderBy('sort_order')->orderBy('code')])
            ->orderBy('sort_order')->orderBy('code')
            ->get();
    }
}
