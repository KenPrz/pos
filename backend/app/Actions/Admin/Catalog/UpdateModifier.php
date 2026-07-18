<?php

// backend/app/Actions/Admin/Catalog/UpdateModifier.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Modifier;
use Illuminate\Support\Facades\DB;

/**
 * Editing price_delta_cents here only affects future add-lines. Existing
 * order_line_modifiers rows are frozen snapshots taken at add time and are never
 * re-derived from the live modifier, so a repriced modifier never moves a total already
 * rung up. See CLAUDE.md on order-line snapshots.
 */
final class UpdateModifier
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateModifierInput $in): Modifier
    {
        return DB::transaction(function () use ($in): Modifier {
            $modifier = Modifier::query()->lockForUpdate()->findOrFail($in->modifierId);

            $modifier->fill($in->changes)->save();

            $this->audit->record('admin.modifier.update', $modifier, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $modifier;
        });
    }
}
