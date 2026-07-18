<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class SetProductModifierGroupsInput
{
    /** @param list<string> $groupIds */
    public function __construct(
        public readonly string $productId,
        public readonly array $groupIds,
        public readonly string $actorId,
    ) {}
}
