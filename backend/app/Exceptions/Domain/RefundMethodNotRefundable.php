<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * This method's driver cannot send money back through us.
 *
 * external_card is the case that exists today: a standalone terminal captured the card,
 * the money never passed through this system, and pretending we can return it would
 * corrupt both the drawer count and the card reconciliation. Sourced from
 * Capabilities::refundable, so any future driver inherits the rule without an edit.
 */
final class RefundMethodNotRefundable extends DomainException
{
    // NOT a promoted `$code` property: `Exception` already declares a non-readonly
    // `$code`, and redeclaring it readonly in a subclass is a fatal error. The sibling
    // PaymentMethodUnknown/PaymentMethodInactive exceptions use this same shape.
    private readonly string $methodCode;

    public function __construct(
        string $code,
        private readonly string $driver,
    ) {
        $this->methodCode = $code;
        parent::__construct("Payments taken on '{$code}' cannot be refunded through this system.");
    }

    public function errorCode(): string
    {
        return 'refund_method_not_refundable';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['payment_method_code' => $this->methodCode, 'driver' => $this->driver];
    }
}
