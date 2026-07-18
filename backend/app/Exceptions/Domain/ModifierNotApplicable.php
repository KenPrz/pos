<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class ModifierNotApplicable extends DomainException
{
    public function __construct(private readonly string $modifierId)
    {
        parent::__construct('This modifier does not apply to that product (or is inactive).');
    }

    public function errorCode(): string
    {
        return 'modifier_not_applicable';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['modifier_id' => $this->modifierId];
    }
}
