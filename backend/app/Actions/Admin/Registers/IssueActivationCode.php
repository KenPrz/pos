<?php

declare(strict_types=1);

namespace App\Actions\Admin\Registers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActivationCodes;
use App\Models\Register;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues (or reissues) the register's one-time activation code.
 *
 * This is a full lockout, not a credential swap: the register's device tokens AND every
 * staff session bound to it die in the same transaction the new code is stored, so the
 * till goes dark the instant this commits and stays dark until someone types the new
 * code at the terminal (ActivateRegister). Raw device tokens never cross the API.
 */
final class IssueActivationCode
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivationCodes $codes,
    ) {}

    public function execute(IssueActivationCodeInput $in): IssuedActivationCode
    {
        return DB::transaction(function () use ($in): IssuedActivationCode {
            $register = Register::query()->lockForUpdate()->findOrFail($in->registerId);

            $code = $this->codes->generate();

            $register->forceFill([
                'activation_code_lookup' => $this->codes->lookup($code),
                'activation_code_expires_at' => now()->addDays((int) config('pos.registers.activation_code_ttl_days')),
                'activation_code_redeemed_at' => null,
            ])->save();

            $register->tokens()->delete();

            // Staff sessions are User-owned tokens whose ability pins them to this
            // register (see EnsureStaffSession). The uuid makes the LIKE unambiguous.
            PersonalAccessToken::query()
                ->where('abilities', 'like', "%register:{$register->id}%")
                ->delete();

            $this->audit->record('admin.register.code_issue', $register, $in->actorId);

            return new IssuedActivationCode($register, $code);
        });
    }
}
