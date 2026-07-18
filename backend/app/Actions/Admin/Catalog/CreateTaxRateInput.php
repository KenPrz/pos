<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class CreateTaxRateInput
{
    public function __construct(
        public readonly string $name,
        public readonly int $rateMicros,
        public readonly bool $isActive,
        public readonly string $actorId,
    ) {}
}
