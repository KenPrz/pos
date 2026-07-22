<?php

declare(strict_types=1);

namespace App\Actions\Admin\Roles;

final class DeleteRoleInput
{
    public function __construct(
        public readonly string $roleTemplateId,
        public readonly string $actorId,
    ) {}
}
