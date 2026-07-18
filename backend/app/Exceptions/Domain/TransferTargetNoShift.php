<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class TransferTargetNoShift extends DomainException
{
    public function __construct(private readonly string $registerId)
    {
        parent::__construct('The target register has no open shift to receive the tab.');
    }

    public function errorCode(): string
    {
        return 'transfer_target_no_shift';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['register_id' => $this->registerId];
    }
}
