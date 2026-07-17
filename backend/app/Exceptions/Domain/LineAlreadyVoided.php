<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The line was already voided — a second void would restock the same units twice
 * (docs/02-data-model.md). The caller likely double-submitted; refetch the order.
 */
final class LineAlreadyVoided extends DomainException
{
    public function __construct(private readonly string $lineId)
    {
        parent::__construct('This line was already voided.');
    }

    public function errorCode(): string
    {
        return 'line_already_voided';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['order_line_id' => $this->lineId];
    }
}
