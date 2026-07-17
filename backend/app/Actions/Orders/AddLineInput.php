<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class AddLineInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        public string $variantId,
        public string $qty,            // numeric string; never float — see docs/03-api.md
        public int $expectedVersion,
        public string $actorId,
        /** @var list<string> ids as selected; repeats are meaningful ("double bacon") */
        public array $modifierIds = [],
    ) {}
}
