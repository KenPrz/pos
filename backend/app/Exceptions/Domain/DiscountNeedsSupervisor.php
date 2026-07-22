<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class DiscountNeedsSupervisor extends DomainException
{
    public function __construct(private readonly string $discountId)
    {
        parent::__construct('This discount needs a supervisor.');
    }

    public function errorCode(): string
    {
        return 'discount_needs_supervisor';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['discount_id' => $this->discountId];
    }
}
