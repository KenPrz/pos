<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

final class CreateUserInput
{
    /** @param list<array{location_id: string, role: string}> $roles */
    public function __construct(
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $password,
        public readonly ?string $pin,
        public readonly bool $isAdmin,
        public readonly array $roles,
        public readonly string $actorId,
    ) {}
}
