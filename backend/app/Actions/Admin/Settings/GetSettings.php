<?php

declare(strict_types=1);

namespace App\Actions\Admin\Settings;

use App\Domain\Settings\Settings;

final class GetSettings
{
    public function __construct(private readonly Settings $settings) {}

    /** @return list<array{key: string, value: mixed, source: 'db'|'config'}> */
    public function execute(): array
    {
        return $this->settings->all();
    }
}
