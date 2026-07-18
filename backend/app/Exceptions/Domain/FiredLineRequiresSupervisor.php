<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class FiredLineRequiresSupervisor extends DomainException
{
    public function __construct(private readonly string $lineId)
    {
        parent::__construct('Reducing a line already fired to the kitchen needs a supervisor.');
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['line_id' => $this->lineId];
    }
}
