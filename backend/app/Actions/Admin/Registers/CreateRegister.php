<?php

// backend/app/Actions/Admin/Registers/CreateRegister.php
declare(strict_types=1);

namespace App\Actions\Admin\Registers;

use App\Domain\Audit\AuditLogger;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * Creates a register row with no device token. This is the admin-UI path; the register
 * only becomes usable once it is enrolled (POST /registers/enroll, which mints the first
 * token) or its activation code is issued via IssueActivationCode. Both are legal.
 */
final class CreateRegister
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateRegisterInput $in): Register
    {
        return DB::transaction(function () use ($in): Register {
            $register = Register::create([
                'location_id' => $in->locationId,
                'name' => $in->name,
                'mode' => $in->mode,
                'is_active' => $in->isActive,
            ]);

            $this->audit->record('admin.register.create', $register, $in->actorId, [
                'location_id' => $in->locationId, 'name' => $in->name, 'mode' => $in->mode,
            ]);

            return $register;
        });
    }
}
