<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class TransferSameShift extends DomainException
{
    public function __construct(private readonly string $shiftId)
    {
        parent::__construct('The order is already on the target shift.');
    }

    public function errorCode(): string
    {
        return 'transfer_same_shift';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['shift_id' => $this->shiftId];
    }
}
