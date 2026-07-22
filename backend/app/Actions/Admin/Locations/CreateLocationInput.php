<?php

declare(strict_types=1);

namespace App\Actions\Admin\Locations;

final readonly class CreateLocationInput
{
    public function __construct(
        public string $name,
        public string $code,
        public string $timezone,
        public bool $pricesIncludeTax,
        public ?string $receiptHeader,
        public ?string $receiptFooter,
        public ?int $varianceApprovalThresholdCents,
        public ?string $lowStockThreshold,
        public string $actorId,
    ) {}
}
