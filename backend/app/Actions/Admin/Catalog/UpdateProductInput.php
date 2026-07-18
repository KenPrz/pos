<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class UpdateProductInput
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public readonly string $productId,
        public readonly array $changes,
        public readonly string $actorId,
    ) {}
}
