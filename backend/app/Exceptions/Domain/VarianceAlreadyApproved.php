<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** A second supervisor trying to sign off a variance that's already been approved. */
final class VarianceAlreadyApproved extends DomainException
{
    public function __construct(
        private readonly string $shiftId,
        private readonly ?string $approvedBy,
        private readonly ?string $approvedAt,
    ) {
        parent::__construct('This shift\'s variance has already been approved.');
    }

    public function errorCode(): string
    {
        return 'variance_already_approved';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['shift_id' => $this->shiftId, 'approved_by' => $this->approvedBy, 'approved_at' => $this->approvedAt];
    }
}
