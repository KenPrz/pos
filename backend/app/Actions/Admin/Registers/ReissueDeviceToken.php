<?php

// backend/app/Actions/Admin/Registers/ReissueDeviceToken.php
declare(strict_types=1);

namespace App\Actions\Admin\Registers;

use App\Actions\Auth\EnrolledRegister;
use App\Domain\Audit\AuditLogger;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * Kills every existing token for a register and mints a fresh one.
 *
 * The old till goes dark immediately — that's the point (lost/stolen terminal). Same
 * token shape as EnrollRegister; this is the "the box is fine, the credential isn't" path,
 * as opposed to enrol which is "this box has never had a credential."
 */
final class ReissueDeviceToken
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ReissueDeviceTokenInput $in): EnrolledRegister
    {
        return DB::transaction(function () use ($in): EnrolledRegister {
            $register = Register::query()->lockForUpdate()->findOrFail($in->registerId);

            $register->tokens()->delete();
            $token = $register->createToken("device:{$register->id}", ['device']);

            $this->audit->record('admin.register.token_reissue', $register, $in->actorId);

            return new EnrolledRegister($register, $token->plainTextToken);
        });
    }
}
