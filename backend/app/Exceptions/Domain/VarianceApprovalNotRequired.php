<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Approval only applies to a closed shift whose variance exceeds the configured
 * threshold. `reason` tells the client which precondition failed:
 * `'shift_open'` (still counting) or `'under_threshold'` (nothing to sign off).
 */
final class VarianceApprovalNotRequired extends DomainException
{
    public function __construct(
        private readonly string $shiftId,
        private readonly string $reason,
    ) {
        parent::__construct('This shift\'s variance does not require approval.');
    }

    public function errorCode(): string
    {
        return 'variance_approval_not_required';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['shift_id' => $this->shiftId, 'reason' => $this->reason];
    }
}
