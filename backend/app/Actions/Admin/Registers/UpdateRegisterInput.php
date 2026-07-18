<?php

declare(strict_types=1);

namespace App\Actions\Admin\Registers;

final readonly class UpdateRegisterInput
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public string $registerId,
        public array $changes,
        public string $actorId,
    ) {}
}
