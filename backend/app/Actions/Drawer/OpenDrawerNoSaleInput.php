<?php

declare(strict_types=1);

namespace App\Actions\Drawer;

final readonly class OpenDrawerNoSaleInput
{
    public function __construct(
        public string $registerId,
        public string $reason,
        public string $actorId,
    ) {}
}
