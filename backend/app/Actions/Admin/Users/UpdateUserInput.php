<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

final class UpdateUserInput
{
    /**
     * @param  array<string, mixed>  $changes  Fillable user columns only (name, email,
     *                                         password_hash, is_admin, is_active) — pin
     *                                         and roles are handled separately below.
     * @param  list<array{location_id: string, role: string}>|null  $roles  null means "not
     *                                                                      submitted, leave alone"; [] means "clear every assignment".
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $changes,
        public readonly ?string $pin,
        public readonly ?array $roles,
        public readonly string $actorId,
    ) {}
}
