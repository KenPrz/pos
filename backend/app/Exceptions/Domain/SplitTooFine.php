<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class SplitTooFine extends DomainException
{
    public function __construct(
        private readonly string $lineId,
        private readonly string $qty,
        private readonly int $ways,
    ) {
        parent::__construct('A line cannot be split into more parts than its quantity has thousandths.');
    }

    public function errorCode(): string
    {
        return 'split_too_fine';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['line_id' => $this->lineId, 'qty' => $this->qty, 'ways' => $this->ways];
    }
}
