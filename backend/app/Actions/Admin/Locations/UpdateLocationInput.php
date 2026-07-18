<?php

declare(strict_types=1);

namespace App\Actions\Admin\Locations;

final readonly class UpdateLocationInput
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public string $locationId,
        public array $changes,
        public string $actorId,
    ) {}
}
