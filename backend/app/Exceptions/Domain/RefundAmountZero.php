<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The derived refund amount is zero — a fully discounted line, or a qty fraction that
 * rounds to nothing. There is no money to hand back, so there is nothing to record:
 * refunds.amount_cents > 0 is a schema check, and silently writing a zero row would
 * fail it as a 500 instead of telling the cashier what happened.
 */
final class RefundAmountZero extends DomainException
{
    public function __construct(private readonly string $originalOrderLineId)
    {
        parent::__construct('Nothing to refund — the selected quantity carries no money. For a free item, restock it with a stock adjustment instead.');
    }

    public function errorCode(): string
    {
        return 'refund_amount_zero';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['original_order_line_id' => $this->originalOrderLineId];
    }
}
