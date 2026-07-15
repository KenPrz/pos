<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Audit\AuditLogger;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * Enrol a terminal, once, and hand it a long-lived device token.
 *
 * The device is trusted because an admin physically enrolled it; the *person* at it is
 * not, which is why a PIN is still required to touch money. See docs/01-architecture.md.
 */
final class EnrollRegister
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(EnrollRegisterInput $in): EnrolledRegister
    {
        return DB::transaction(function () use ($in): EnrolledRegister {
            $register = Register::query()->create([
                'location_id' => $in->locationId,
                'name' => $in->name,
            ]);

            // No expiry: a till that logs itself out overnight is a till that cannot
            // open in the morning. Revocation is by deactivating the register.
            $token = $register->createToken("device:{$register->id}", ['device']);

            $this->audit->record('register.enroll', $register, $in->actorId, [
                'location_id' => $in->locationId,
                'name' => $in->name,
            ]);

            return new EnrolledRegister($register, $token->plainTextToken);
        });
    }
}
