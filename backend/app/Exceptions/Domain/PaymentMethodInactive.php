<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The method exists at this location but is archived — either itself, or via its group.
 * Archiving a group hides every method under it without touching their rows.
 */
final class PaymentMethodInactive extends DomainException
{
    private readonly string $methodCode;

    public function __construct(
        private readonly string $locationId,
        string $code,
    ) {
        $this->methodCode = $code;
        parent::__construct("Payment method '{$code}' is archived at this location.");
    }

    public function errorCode(): string
    {
        return 'payment_method_inactive';
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
