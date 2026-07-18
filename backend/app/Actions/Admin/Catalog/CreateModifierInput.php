<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class CreateModifierInput
{
    public function __construct(
        public readonly string $groupId,
        public readonly string $name,
        public readonly int $priceDeltaCents,
        public readonly int $position,
        public readonly bool $isActive,
        public readonly string $actorId,
    ) {}
}
