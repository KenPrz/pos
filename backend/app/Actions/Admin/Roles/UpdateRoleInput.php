<?php

declare(strict_types=1);

namespace App\Actions\Admin\Roles;

final class UpdateRoleInput
{
    /** @param list<string>|null $permissions null means "not submitted, leave alone" */
    public function __construct(
        public readonly string $roleTemplateId,
        public readonly ?string $name,
        public readonly ?array $permissions,
        public readonly string $actorId,
    ) {}
}
