<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class CreateProductInput
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $categoryId,
        public readonly string $kind,
        public readonly string $actorId,
    ) {}
}
