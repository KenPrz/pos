<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reports;

final readonly class SalesReportInput
{
    public function __construct(
        public string $locationId,
        public string $from,       // 'YYYY-MM-DD', inclusive
        public string $to,         // 'YYYY-MM-DD', inclusive
        public string $groupBy,    // 'day' | 'user' | 'category'
    ) {}
}
