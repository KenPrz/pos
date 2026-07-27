<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class ListPaymentMethodGroupsInput
{
    public function __construct(public string $locationId) {}
}
