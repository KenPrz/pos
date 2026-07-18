<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class UpdateModifierGroupInput
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public readonly string $modifierGroupId,
        public readonly array $changes,
        public readonly string $actorId,
    ) {}
}
