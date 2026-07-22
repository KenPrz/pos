<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * `cashier` and `supervisor` are the system templates every seed and every doc assumes
 * exist under those exact names — renaming or deleting one out from under a location
 * would strand every user still assigned it. Permissions on a system template are still
 * editable; the name and its existence are the only things pinned.
 */
final class RoleTemplateIsSystem extends DomainException
{
    public function __construct(private readonly string $roleTemplateId)
    {
        parent::__construct('System roles keep their name.');
    }

    public function errorCode(): string
    {
        return 'role_template_is_system';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'role_template_id' => $this->roleTemplateId,
        ];
    }
}
