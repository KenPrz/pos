<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class CreateCategoryInput
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $parentId,
        public readonly int $sortOrder,
        public readonly string $actorId,
    ) {}
}
