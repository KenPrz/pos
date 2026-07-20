<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActivationCodes;
use App\Exceptions\Domain\ActivationCodeExpired;
use App\Exceptions\Domain\InvalidActivationCode;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a one-time activation code for the terminal's long-lived device token.
 *
 * The code IS the authorization — this runs unauthenticated (throttled by IP). Spending
 * the code and minting the token happen in one transaction under lockForUpdate, so a
 * code can never produce two tokens. If the response is lost in transit the code is
 * still spent; the admin reissues — accepted trade-off (see the design spec).
 */
final class ActivateRegister
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivationCodes $codes,
    ) {}

    public function execute(ActivateRegisterInput $in): EnrolledRegister
    {
        return DB::transaction(function () use ($in): EnrolledRegister {
            $register = Register::query()
                ->lockForUpdate()
                ->where('activation_code_lookup', $this->codes->lookup($in->activationCode))
                ->first();

            if ($register === null || $register->activation_code_redeemed_at !== null || ! $register->is_active) {
                throw new InvalidActivationCode;
            }

            if ($register->activation_code_expires_at === null || $register->activation_code_expires_at->isPast()) {
                throw new ActivationCodeExpired;
            }

            $register->forceFill(['activation_code_redeemed_at' => now()])->save();

            // No expiry: a till that logs itself out overnight is a till that cannot
            // open in the morning. Revocation is by reissuing the activation code.
            $token = $register->createToken("device:{$register->id}", ['device']);

            $this->audit->record('register.activate', $register, null, [], $register->id, $in->ip);

            return new EnrolledRegister($register, $token->plainTextToken);
        });
    }
}
