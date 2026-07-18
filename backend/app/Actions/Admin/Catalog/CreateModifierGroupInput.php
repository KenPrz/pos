<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class CreateModifierGroupInput
{
    public function __construct(
        public readonly string $name,
        public readonly int $minSelect,
        public readonly ?int $maxSelect,
        public readonly string $actorId,
    ) {}
}
