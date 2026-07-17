<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class LineTotalNegative extends DomainException
{
    public function __construct(private readonly int $resolvedCents)
    {
        parent::__construct('Modifiers would make this line total negative.');
    }

    public function errorCode(): string
    {
        return 'line_total_negative';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['resolved_cents' => $this->resolvedCents];
    }
}
