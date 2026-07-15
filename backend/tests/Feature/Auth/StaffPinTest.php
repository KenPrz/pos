<?php

declare(strict_types=1);

use App\Actions\Auth\SetStaffPin;
use App\Actions\Auth\SetStaffPinInput;
use App\Actions\Auth\StaffLogin;
use App\Actions\Auth\StaffLoginInput;
use App\Domain\Auth\Pins;
use App\Domain\Auth\StaffDirectory;
use App\Domain\Rbac\RoleProvisioner;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\InvalidPin;
use App\Exceptions\Domain\PinAlreadyInUse;
use App\Exceptions\Domain\TooManyPinAttempts;
use App\Models\Location;
use App\Models\Register;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $provisioner = app(RoleProvisioner::class);
    $provisioner->provisionGlobal();

    $this->downtown = Location::factory()->create(['code' => 'DT']);
    $this->airport = Location::factory()->create(['code' => 'AP']);
    $provisioner->provisionForLocation($this->downtown);
    $provisioner->provisionForLocation($this->airport);

    $this->register = Register::factory()->create(['location_id' => $this->downtown->id]);
});

function staffAt(Location $location, string $role = Roles::CASHIER): User
{
    $user = User::factory()->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($location->id);
    $user->assignRole($role);
    $registrar->forgetCachedPermissions();

    return $user;
}

function setPin(User $user, string $pin): User
{
    return app(SetStaffPin::class)->execute(new SetStaffPinInput(
        userId: $user->id,
        pin: $pin,
        actorId: $user->id,
    ));
}

describe('the PIN collision check', function (): void {
    it('rejects a PIN already used by someone at the same location', function (): void {
        // Bcrypt is salted, so no unique index can catch this — one of the rare
        // invariants that genuinely cannot live in the schema, which is exactly why it
        // needs a dedicated test. See docs/05-rbac.md.
        setPin(staffAt($this->downtown), '1234');

        expect(fn () => setPin(staffAt($this->downtown), '1234'))
            ->toThrow(PinAlreadyInUse::class);
    });

    it('allows the same PIN at a location they do not share', function (): void {
        // Attribution only breaks when two people can be at the same till.
        setPin(staffAt($this->downtown), '1234');

        expect(setPin(staffAt($this->airport), '1234')->pin_hash)->not->toBeNull();
    });

    it('lets a person change their own PIN to the one they already have', function (): void {
        $user = setPin(staffAt($this->downtown), '1234');

        expect(setPin($user, '1234')->pin_hash)->not->toBeNull();
    });

    it('ignores deactivated staff', function (): void {
        $former = staffAt($this->downtown);
        setPin($former, '1234');
        $former->forceFill(['is_active' => false])->save();

        expect(setPin(staffAt($this->downtown), '1234')->pin_hash)->not->toBeNull();
    });

    it("checks an admin's PIN against every location, since they can act anywhere", function (): void {
        setPin(staffAt($this->airport), '9876');

        $admin = User::factory()->admin()->create();

        expect(fn () => setPin($admin, '9876'))->toThrow(PinAlreadyInUse::class);
    });
});

describe('the lookup index', function (): void {
    it('stores both a bcrypt hash and a keyed lookup', function (): void {
        $user = setPin(staffAt($this->downtown), '1234');

        expect($user->pin_hash)->not->toBeNull()
            ->and($user->pin_lookup)->toBe(app(Pins::class)->lookup('1234'))
            // The hash is salted; the lookup is deterministic. They do different jobs.
            ->and($user->pin_hash)->not->toBe($user->pin_lookup);
    });

    it('never authenticates on the lookup alone', function (): void {
        // The lookup is an index, not a credential. If the hash does not verify, nobody
        // gets in — even if the lookup matched.
        $user = setPin(staffAt($this->downtown), '1234');
        $user->forceFill(['pin_hash' => bcrypt('0000')])->save();

        expect(app(StaffDirectory::class)->findByPin($this->downtown->id, '1234'))->toBeNull();
    });

    it('finds staff without bcrypt-checking every person at the location', function (): void {
        // Measured at 225ms per bcrypt check, scanning 20 staff is a 4.5-second login.
        foreach (range(1, 12) as $i) {
            setPin(staffAt($this->downtown), (string) (1000 + $i));
        }

        $target = setPin(staffAt($this->downtown), '4321');

        $started = microtime(true);
        $found = app(StaffDirectory::class)->findByPin($this->downtown->id, '4321');
        $elapsed = (microtime(true) - $started) * 1000;

        expect($found?->id)->toBe($target->id)
            ->and($elapsed)->toBeLessThan(400.0, 'login must not scan every hash');
    });
});

describe('login', function (): void {
    it('finds the person behind the PIN', function (): void {
        $alice = setPin(staffAt($this->downtown), '1111');

        $session = app(StaffLogin::class)->execute(
            new StaffLoginInput($this->register, '1111')
        );

        expect($session->user->id)->toBe($alice->id)
            ->and($session->token)->not->toBeEmpty()
            ->and($session->expiresAt->isFuture())->toBeTrue();
    });

    it('refuses a PIN belonging to someone at another location', function (): void {
        // The register is downtown; this person only works at the airport.
        setPin(staffAt($this->airport), '7777');

        expect(fn () => app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '7777')))
            ->toThrow(InvalidPin::class);
    });

    it('refuses a deactivated account', function (): void {
        $user = setPin(staffAt($this->downtown), '1111');
        $user->forceFill(['is_active' => false])->save();

        expect(fn () => app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '1111')))
            ->toThrow(InvalidPin::class);
    });

    it('binds the session to the register it was issued at', function (): void {
        // A staff token lifted from one terminal must be inert on another.
        setPin(staffAt($this->downtown), '1111');
        $other = Register::factory()->create(['location_id' => $this->downtown->id]);

        $session = app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '1111'));
        $token = Laravel\Sanctum\PersonalAccessToken::findToken($session->token);

        expect($token->can("register:{$this->register->id}"))->toBeTrue()
            ->and($token->can("register:{$other->id}"))->toBeFalse();
    });

    it('resolves permissions for the register location, not an empty list', function (): void {
        // Login runs before EnsureStaffSession, so the action must set the team context
        // itself. Without it this returns silently empty — the exact failure mode
        // docs/05-rbac.md warns about, and it is quiet rather than loud.
        setPin(staffAt($this->downtown, Roles::SUPERVISOR), '2222');

        $session = app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '2222'));

        expect($session->user->getAllPermissions()->pluck('name'))
            ->toContain('order.discount.apply')
            ->not->toBeEmpty();
    });
});

describe('the lockout', function (): void {
    it('locks a register after too many wrong PINs', function (): void {
        // A 4-digit PIN is 10,000 guesses — minutes of brute force without this. The PIN
        // is only an acceptable secret because of enrolment plus this limiter.
        setPin(staffAt($this->downtown), '1111');

        $max = (int) config('pos.staff.pin_max_attempts');

        foreach (range(1, $max) as $ignored) {
            expect(fn () => app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '0000')))
                ->toThrow(InvalidPin::class);
        }

        expect(fn () => app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '0000')))
            ->toThrow(TooManyPinAttempts::class);
    });

    it('locks the register even against the correct PIN', function (): void {
        // Otherwise the limiter tells an attacker precisely when they have guessed right.
        setPin(staffAt($this->downtown), '1111');

        foreach (range(1, (int) config('pos.staff.pin_max_attempts')) as $ignored) {
            rescue(fn () => app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '0000')));
        }

        expect(fn () => app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '1111')))
            ->toThrow(TooManyPinAttempts::class);
    });

    it('counts per register, so one till cannot lock out another', function (): void {
        setPin(staffAt($this->downtown), '1111');
        $other = Register::factory()->create(['location_id' => $this->downtown->id]);

        foreach (range(1, (int) config('pos.staff.pin_max_attempts')) as $ignored) {
            rescue(fn () => app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '0000')));
        }

        expect(app(StaffLogin::class)->execute(new StaffLoginInput($other, '1111'))->user)
            ->not->toBeNull();
    });

    it('clears the counter on a successful login', function (): void {
        setPin(staffAt($this->downtown), '1111');

        rescue(fn () => app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '0000')));
        app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '1111'));

        // A fresh budget of attempts, not one remaining.
        foreach (range(1, (int) config('pos.staff.pin_max_attempts')) as $ignored) {
            expect(fn () => app(StaffLogin::class)->execute(new StaffLoginInput($this->register, '0000')))
                ->toThrow(InvalidPin::class);
        }
    });
});
