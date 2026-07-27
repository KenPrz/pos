<?php

declare(strict_types=1);

namespace App\Actions\Admin\Shifts;

final readonly class ListPendingVariancesInput
{
    /** @param list<string>|null $locationIds null = every location (admin) */
    public function __construct(public ?array $locationIds) {}
}
