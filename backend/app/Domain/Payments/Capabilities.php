<?php

declare(strict_types=1);

namespace App\Domain\Payments;

final readonly class Capabilities
{
    public function __construct(
        public bool $refundable,
        public bool $async,
    ) {}
}
