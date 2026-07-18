<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reports;

final readonly class StockReportInput
{
    public function __construct(
        public string $locationId,
        public bool $lowOnly,
    ) {}
}
