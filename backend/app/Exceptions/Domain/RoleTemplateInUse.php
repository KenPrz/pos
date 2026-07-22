<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Deleting a template deletes its materialized `roles` row at every location outright
 * (not an archive — `role_templates` has no `is_active` column, and a dangling
 * `model_has_roles` row pointing at a deleted role would be a silent, unrecoverable
 * permission loss for whoever holds it). Unassigning first keeps that deletion honest.
 */
final class RoleTemplateInUse extends DomainException
{
    public function __construct(
        private readonly string $roleTemplateId,
        private readonly int $assignedUsers,
    ) {
        parent::__construct('Unassign this role everywhere first.');
    }

    public function errorCode(): string
    {
        return 'role_template_in_use';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'role_template_id' => $this->roleTemplateId,
            'assigned_users' => $this->assignedUsers,
        ];
    }
}
