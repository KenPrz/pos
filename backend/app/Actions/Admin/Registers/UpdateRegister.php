<?php

// backend/app/Actions/Admin/Registers/UpdateRegister.php
declare(strict_types=1);

namespace App\Actions\Admin\Registers;

use App\Domain\Audit\AuditLogger;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

final class UpdateRegister
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateRegisterInput $in): Register
    {
        return DB::transaction(function () use ($in): Register {
            $register = Register::query()->lockForUpdate()->findOrFail($in->registerId);

            $register->fill($in->changes)->save();

            $this->audit->record('admin.register.update', $register, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $register;
        });
    }
}
