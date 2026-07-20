<?php

declare(strict_types=1);

namespace App\Actions\Admin\Registers;

final readonly class IssueActivationCodeInput
{
    public function __construct(
        public string $registerId,
        public string $actorId,
    ) {}
}
