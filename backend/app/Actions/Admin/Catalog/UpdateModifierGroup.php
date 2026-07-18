<?php

// backend/app/Actions/Admin/Catalog/UpdateModifierGroup.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\ModifierGroup;
use Illuminate\Support\Facades\DB;

/**
 * Tightening min_select/max_select here only changes future add-line validation.
 * Lines already on an order carry frozen snapshots (order_line_modifiers +
 * order_lines.modifiers_total_cents) that are never re-derived from the live group, so
 * a stricter rule never moves money already rung up. See CLAUDE.md on order-line
 * snapshots and docs/00-overview.md.
 */
final class UpdateModifierGroup
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateModifierGroupInput $in): ModifierGroup
    {
        return DB::transaction(function () use ($in): ModifierGroup {
            $group = ModifierGroup::query()->lockForUpdate()->findOrFail($in->modifierGroupId);

            $group->fill($in->changes)->save();

            $this->audit->record('admin.modifier_group.update', $group, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $group;
        });
    }
}
