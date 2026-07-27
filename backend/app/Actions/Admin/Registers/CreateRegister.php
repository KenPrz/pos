<?php

// backend/app/Actions/Admin/Registers/CreateRegister.php
declare(strict_types=1);

namespace App\Actions\Admin\Registers;

use App\Domain\Audit\AuditLogger;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * Creates a register row with no device token. The register only becomes usable once
 * its activation code is issued via IssueActivationCode.
 */
final class CreateRegister
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateRegisterInput $in): Register
    {
        return DB::transaction(function () use ($in): Register {
            // Explicit, not left to the column default: create() never hydrates DB
            // defaults onto the returned model, and the response is built from this
            // instance, not a re-fetch.
            $register = Register::create([
                'location_id' => $in->locationId,
                'name' => $in->name,
                'mode' => $in->mode,
                'is_active' => $in->isActive,
                'screen_keyboard_enabled' => $in->screenKeyboardEnabled,
            ]);

            $this->audit->record('admin.register.create', $register, $in->actorId, [
                'location_id' => $in->locationId, 'name' => $in->name, 'mode' => $in->mode,
                'screen_keyboard_enabled' => $in->screenKeyboardEnabled,
            ]);

            return $register;
        });
    }
}
