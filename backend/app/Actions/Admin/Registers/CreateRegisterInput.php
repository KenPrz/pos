<?php

declare(strict_types=1);

namespace App\Actions\Admin\Registers;

final readonly class CreateRegisterInput
{
    public function __construct(
        public string $locationId,
        public string $name,
        public string $mode,
        public bool $isActive,
        public string $actorId,
    ) {}
}
