<?php

declare(strict_types=1);

namespace App\Actions\Admin\Settings;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\Settings;
use Illuminate\Support\Facades\DB;

final class UpdateSettings
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array{key: string, value: mixed, source: 'db'|'config'}> */
    public function execute(UpdateSettingsInput $in): array
    {
        return DB::transaction(function () use ($in): array {
            $cleared = [];

            foreach ($in->changes as $key => $value) {
                if ($value === null) {
                    $this->settings->clear($key);
                    $cleared[] = $key;

                    continue;
                }

                $this->settings->set($key, $value);
            }

            $this->audit->record('admin.settings.update', 'Settings', $in->actorId, [
                'changed' => array_keys($in->changes),
                'cleared' => $cleared,
            ]);

            return $this->settings->all();
        });
    }
}
