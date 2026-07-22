<?php

declare(strict_types=1);

namespace App\Actions\Admin\Settings;

final readonly class UpdateSettingsInput
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public array $changes,
        public string $actorId,
    ) {}
}
