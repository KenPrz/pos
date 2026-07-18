<?php

// backend/app/Actions/Admin/Catalog/CreateModifier.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Modifier;
use Illuminate\Support\Facades\DB;

final class CreateModifier
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateModifierInput $in): Modifier
    {
        return DB::transaction(function () use ($in): Modifier {
            $modifier = Modifier::create([
                'group_id' => $in->groupId,
                'name' => $in->name,
                'price_delta_cents' => $in->priceDeltaCents,
                'position' => $in->position,
                'is_active' => $in->isActive,
            ]);

            $this->audit->record('admin.modifier.create', $modifier, $in->actorId, [
                'name' => $in->name, 'group_id' => $in->groupId, 'price_delta_cents' => $in->priceDeltaCents,
            ]);

            return $modifier;
        });
    }
}
