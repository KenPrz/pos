<?php

// backend/app/Actions/Admin/Catalog/CreateModifierGroup.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\ModifierGroup;
use Illuminate\Support\Facades\DB;

final class CreateModifierGroup
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateModifierGroupInput $in): ModifierGroup
    {
        return DB::transaction(function () use ($in): ModifierGroup {
            $group = ModifierGroup::create([
                'name' => $in->name,
                'min_select' => $in->minSelect,
                'max_select' => $in->maxSelect,
            ]);

            $this->audit->record('admin.modifier_group.create', $group, $in->actorId, [
                'name' => $in->name, 'min_select' => $in->minSelect, 'max_select' => $in->maxSelect,
            ]);

            return $group;
        });
    }
}
