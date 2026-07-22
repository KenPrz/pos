<?php

declare(strict_types=1);

namespace App\Actions\Admin\Roles;

final class CreateRoleInput
{
    /** @param list<string> $permissions */
    public function __construct(
        public readonly string $name,
        public readonly array $permissions,
        public readonly string $actorId,
    ) {}
}
