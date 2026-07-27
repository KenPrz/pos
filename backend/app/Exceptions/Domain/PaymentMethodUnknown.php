<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * No payment method with this code exists at this location.
 *
 * Distinct from PaymentMethodInactive on purpose: "no such tender here" and "you turned
 * this off yesterday" are different problems for whoever reads the error. Another
 * location's code lands here too — it is unknown *here*, which is the honest answer.
 */
final class PaymentMethodUnknown extends DomainException
{
    private readonly string $methodCode;

    public function __construct(
        private readonly string $locationId,
        string $code,
    ) {
        $this->methodCode = $code;
        parent::__construct("No payment method '{$code}' is offered at this location.");
    }

    public function errorCode(): string
    {
        return 'payment_method_unknown';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['location_id' => $this->locationId, 'payment_method_code' => $this->methodCode];
    }
}
