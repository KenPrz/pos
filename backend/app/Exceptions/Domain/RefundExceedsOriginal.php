<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * This request, added to every refund already issued against this original order line,
 * would return more of it than was ever sold. The cumulative check runs inside the same
 * locked transaction as the write, so two concurrent refunds of the same line can't both
 * slip under the limit.
 */
final class RefundExceedsOriginal extends DomainException
{
    public function __construct(
        private readonly string $originalOrderLineId,
        private readonly string $originalQty,
        private readonly string $alreadyRefundedQty,
        private readonly string $requestedQty,
    ) {
        parent::__construct('This refund would return more than was sold on this line.');
    }

    public function errorCode(): string
    {
        return 'refund_exceeds_original';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'original_order_line_id' => $this->originalOrderLineId,
            'original_qty' => $this->originalQty,
            'already_refunded_qty' => $this->alreadyRefundedQty,
            'requested_qty' => $this->requestedQty,
        ];
    }
}
