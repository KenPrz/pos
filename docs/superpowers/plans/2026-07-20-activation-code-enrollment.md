# Activation-Code Enrollment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace raw-device-token enrollment with one-time, human-typeable activation codes: admins issue a code per terminal in the back office, the terminal exchanges it for a long-lived Sanctum device token, and a reissue locks the terminal out (device token + staff sessions) until a new code is entered.

**Architecture:** Codes live as three nullable columns on `registers` (HMAC lookup + expiry + redeemed-at), following the `Pins` hash-and-lookup pattern. Two new actions replace two old ones: `IssueActivationCode` (admin, replaces `ReissueDeviceToken`) and `ActivateRegister` (public, throttled, replaces the `registers/enroll` bootstrap). The register app gains an activation screen and a dedicated "disabled" lockout screen; the back office swaps its token-reissue block for a code-issue block with an activation StatusPill.

**Tech Stack:** Laravel 13.20 (PHP 8.5, Sanctum, Pest) · PostgreSQL 18 · Next.js 16 + React Query (vitest + testing-library) in both frontends.

**Spec:** `docs/superpowers/specs/2026-07-20-activation-code-enrollment-design.md` (approved).

## Global Constraints

- One system action = one route = one final invokable controller = one final Action class; actions take an Input DTO, never touch `Illuminate\Http\*` (`tests/Arch/ConventionsTest.php` enforces this mechanically).
- `declare(strict_types=1);` at the top of every PHP file.
- Never call `env()` outside `config/`; engineer-set values go in `config/pos.php`.
- Error envelope is `{ "error": { "code", "message", "details" } }`; validation failures are **400** `validation_failed` (not 422).
- Audit action naming: back-office actions prefixed `admin.` (`admin.register.code_issue`); register-side actions unprefixed (`register.activate`).
- Activation code: 10 chars from a 30-char unambiguous alphabet (`23456789ABCDEFGHJKMNPQRSTVWXYZ`), displayed `XXXXX-XXXXX`, single-use, **7-day expiry** (`config('pos.registers.activation_code_ttl_days')`). Input normalization: uppercase, hyphens/spaces stripped.
- Reissue = **full lockout**: revoke the register's device tokens AND every staff session with ability `register:{id}`, in the same transaction as storing the new code.
- Redemption errors: 401 `invalid_activation_code` (unknown OR already redeemed OR inactive register — deliberately indistinguishable), 401 `activation_code_expired` (known, unredeemed, past expiry).
- Lockout screen copy, verbatim: *"Your activation code has been disabled. Please contact an admin and request a new activation code."*
- Raw device tokens never leave the server via the API. The seeder keeps minting tokens server-side (`DatabaseSeeder.php` is untouched); all three e2e scripts stay green unmodified.
- Backend tests run against real Postgres. Natively: `cd backend && ./vendor/bin/pest …` with the compose `db` service up (`docker compose -f compose.dev.yml up -d db`). Containerized alternative: `docker compose -f compose.dev.yml exec --user pos api ./vendor/bin/pest …`.
- Commit messages: repo style (sentence-style, no conventional-commit prefixes, **no co-author trailers**).

---

### Task 1: `ActivationCodes` domain helper + config

**Files:**
- Create: `backend/app/Domain/Auth/ActivationCodes.php`
- Create: `backend/tests/Unit/Domain/ActivationCodesTest.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (singleton, next to the `Pins` one at line 26)
- Modify: `backend/config/pos.php` (new `registers` block + one `rate_limits` key)

**Interfaces:**
- Produces: `App\Domain\Auth\ActivationCodes` — `__construct(string $key)`, `generate(): string` (returns `XXXXX-XXXXX`), `normalize(string $code): string`, `lookup(string $code): string` (HMAC-SHA256 of the normalized code, keyed). Config keys `pos.registers.activation_code_ttl_days` (7) and `pos.rate_limits.activate_per_minute` (5). Tasks 3 and 5 consume all of these.

- [ ] **Step 1: Write the failing unit test**

`backend/tests/Unit/Domain/ActivationCodesTest.php` (Unit tests get no TestCase and no database — this class is pure, no facades):

```php
<?php

declare(strict_types=1);

use App\Domain\Auth\ActivationCodes;

it('generates a 5-5 grouped code from the unambiguous alphabet', function (): void {
    $codes = new ActivationCodes('base64:test-key');

    $code = $codes->generate();

    expect($code)->toHaveLength(11)
        ->and($code[5])->toBe('-')
        ->and(str_replace('-', '', $code))->toMatch('/^[23456789ABCDEFGHJKMNPQRSTVWXYZ]{10}$/');
});

it('normalizes case, spaces, and hyphens away', function (): void {
    $codes = new ActivationCodes('base64:test-key');

    expect($codes->normalize('abcde-fgh23'))->toBe('ABCDEFGH23')
        ->and($codes->normalize(' AB CDE-FGH23 '))->toBe('ABCDEFGH23');
});

it('produces the same lookup for every spelling of the same code, keyed by APP_KEY', function (): void {
    $codes = new ActivationCodes('base64:test-key');

    expect($codes->lookup('ABCDE-FGH23'))->toBe($codes->lookup('abcde fgh23'))
        ->and($codes->lookup('ABCDE-FGH23'))->not->toBe(new ActivationCodes('base64:other-key')->lookup('ABCDE-FGH23'));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/ActivationCodesTest.php`
Expected: FAIL — `Class "App\Domain\Auth\ActivationCodes" not found`

- [ ] **Step 3: Implement the helper**

`backend/app/Domain/Auth/ActivationCodes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Generation and lookup for one-time register activation codes.
 *
 * Same two-representation idea as Pins, minus the bcrypt authority: the code is
 * high-entropy (~49 bits over a 30-char alphabet), so the keyed HMAC is both the index
 * and the verifier. Keyed rather than plain SHA-256 so a database dump alone cannot
 * brute-force the code space offline — you also need APP_KEY.
 *
 * The key is injected rather than read from config here, so this stays a pure
 * collaborator and its tests need no container. See docs/04-backend-conventions.md.
 */
final readonly class ActivationCodes
{
    /** No 0/O, 1/I/L, or U — every character survives a phone call and a sticky note. */
    private const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private string $key,
    ) {}

    /** 10 random alphabet chars, displayed XXXXX-XXXXX. */
    public function generate(): string
    {
        $chars = '';
        for ($i = 0; $i < 10; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 5).'-'.substr($chars, 5);
    }

    /** "abcde fgh-23" and "ABCDE-FGH23" are the same code. */
    public function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', trim($code)));
    }

    /** Deterministic and keyed: useless to anyone holding the database but not APP_KEY. */
    public function lookup(string $code): string
    {
        return hash_hmac('sha256', $this->normalize($code), $this->key);
    }
}
```

- [ ] **Step 4: Register the singleton and config keys**

In `backend/app/Providers/AppServiceProvider.php`, add the import `use App\Domain\Auth\ActivationCodes;` and, directly under the `Pins::class` singleton (line 26):

```php
$this->app->singleton(ActivationCodes::class,
    fn (): ActivationCodes => new ActivationCodes((string) config('app.key')));
```

In `backend/config/pos.php`, add after the `'staff'` block:

```php
'registers' => [
    // How long an unredeemed activation code stays valid. Reissue is one click, so
    // this leans short — it bounds the window a leaked code can enroll a rogue device.
    'activation_code_ttl_days' => 7,
],
```

and inside `'rate_limits'`:

```php
'activate_per_minute' => 5,
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/ActivationCodesTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Domain/Auth/ActivationCodes.php backend/tests/Unit/Domain/ActivationCodesTest.php backend/app/Providers/AppServiceProvider.php backend/config/pos.php
git commit -m "Activation codes: domain helper, config, singleton"
```

---

### Task 2: Migration — activation columns on `registers`

**Files:**
- Create: `backend/database/migrations/2026_07_20_000100_add_register_activation_columns.php`
- Modify: `backend/app/Models/Register.php` (casts only)

**Interfaces:**
- Produces: nullable columns `registers.activation_code_lookup` (text, unique), `registers.activation_code_expires_at` (timestamptz), `registers.activation_code_redeemed_at` (timestamptz). Model casts the two timestamps to `datetime`. Columns are **not** fillable — actions write them via `forceFill()`, so mass assignment can never touch a credential column.

- [ ] **Step 1: Write the migration**

`backend/database/migrations/2026_07_20_000100_add_register_activation_columns.php` (raw SQL, matching `2026_07_17_000100_add_m5_columns.php`):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The activation code is stored only as a keyed HMAC (see ActivationCodes.php);
        // the plaintext is shown to the admin exactly once and never persisted.
        DB::statement('alter table registers add column activation_code_lookup text');
        DB::statement('alter table registers add column activation_code_expires_at timestamptz');
        DB::statement('alter table registers add column activation_code_redeemed_at timestamptz');
        DB::statement('alter table registers add constraint registers_activation_code_lookup_unique unique (activation_code_lookup)');
    }

    public function down(): void
    {
        DB::statement('alter table registers drop constraint registers_activation_code_lookup_unique');
        DB::statement('alter table registers drop column activation_code_redeemed_at');
        DB::statement('alter table registers drop column activation_code_expires_at');
        DB::statement('alter table registers drop column activation_code_lookup');
    }
};
```

- [ ] **Step 2: Add the casts**

In `backend/app/Models/Register.php`, extend `casts()` (leave `$fillable` alone):

```php
protected function casts(): array
{
    return [
        'is_active' => 'boolean',
        'activation_code_expires_at' => 'datetime',
        'activation_code_redeemed_at' => 'datetime',
    ];
}
```

- [ ] **Step 3: Run the migration and the whole backend suite**

Run: `cd backend && php artisan migrate && ./vendor/bin/pest`
Expected: migration runs clean; full suite still green (462 tests) — nothing reads the new columns yet.

- [ ] **Step 4: Commit**

```bash
git add backend/database/migrations/2026_07_20_000100_add_register_activation_columns.php backend/app/Models/Register.php
git commit -m "Registers: activation-code columns (HMAC lookup, expiry, redeemed-at)"
```

---

### Task 3: `IssueActivationCode` replaces `ReissueDeviceToken`

**Files:**
- Create: `backend/app/Actions/Admin/Registers/IssueActivationCode.php`
- Create: `backend/app/Actions/Admin/Registers/IssueActivationCodeInput.php`
- Create: `backend/app/Actions/Admin/Registers/IssuedActivationCode.php`
- Create: `backend/app/Http/Controllers/Admin/Registers/IssueActivationCodeController.php`
- Create: `backend/app/Http/Requests/Admin/Registers/IssueActivationCodeRequest.php`
- Delete: `backend/app/Actions/Admin/Registers/ReissueDeviceToken.php`, `ReissueDeviceTokenInput.php`, `backend/app/Http/Controllers/Admin/Registers/ReissueDeviceTokenController.php`, `backend/app/Http/Requests/Admin/Registers/ReissueDeviceTokenRequest.php`
- Modify: `backend/routes/api.php` (route + import swap)
- Modify: `backend/tests/Feature/Admin/LocationRegisterTest.php` (replace the "Token reissue" section)

**Interfaces:**
- Consumes: `ActivationCodes` (Task 1), the columns from Task 2, `AuditLogger::record()`.
- Produces: `POST /api/v1/admin/registers/{register}/activation-code` (route name `admin.registers.activation_code`) → `201 { "data": { "activation_code": "XXXXX-XXXXX", "expires_at": "<ISO-8601>" } }`. Action `IssueActivationCode::execute(IssueActivationCodeInput): IssuedActivationCode` where `IssuedActivationCode` has `public Register $register; public string $activationCode;`. Audit action string: `admin.register.code_issue`. The old `/registers/{register}/token` route and its four classes are gone.

- [ ] **Step 1: Replace the reissue tests with issue tests (failing)**

In `backend/tests/Feature/Admin/LocationRegisterTest.php`, delete the `it('reissues a device token: …')` test (lines ~136–155) and add in its place (keep the `// --- Token reissue` comment updated to `// --- Activation code issue`). Add `use Laravel\Sanctum\PersonalAccessToken;` to the imports:

```php
it('issues an activation code and locks the register out: device and staff tokens die', function (): void {
    $location = Location::factory()->create();
    $register = Register::factory()->create(['location_id' => $location->id]);
    $deviceToken = $register->createToken("device:{$register->id}", ['device'])->plainTextToken;
    $staff = User::factory()->create();
    $staffToken = $staff->createToken("staff:{$register->id}", ["register:{$register->id}"], now()->addMinutes(480))->plainTextToken;

    $this->getJson('/api/v1/catalog', ['Authorization' => "Bearer {$deviceToken}"])->assertOk();

    $response = $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers)
        ->assertCreated();

    expect($response->json('data.activation_code'))->toMatch('/^[23456789A-Z]{5}-[23456789A-Z]{5}$/')
        ->and($response->json('data.expires_at'))->not->toBeNull();

    // Full lockout, immediately: the device token is dead...
    $this->getJson('/api/v1/catalog', ['Authorization' => "Bearer {$deviceToken}"])->assertStatus(401);
    // ...and the staff session bound to this register died with it.
    expect(PersonalAccessToken::findToken($staffToken))->toBeNull();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.register.code_issue', 'entity_id' => $register->id]);
});

it('a second issue supersedes the first code', function (): void {
    $location = Location::factory()->create();
    $register = Register::factory()->create(['location_id' => $location->id]);

    $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers)->assertCreated();
    $first = $register->fresh()->activation_code_lookup;

    $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers)->assertCreated();

    expect($register->fresh()->activation_code_lookup)->not->toBe($first);
});

it('a non-admin cannot issue an activation code', function (): void {
    $location = Location::factory()->create();
    $register = Register::factory()->create(['location_id' => $location->id]);
    $staff = User::factory()->create(['email' => 'nonadmin@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];

    $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $headers)->assertStatus(403);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/LocationRegisterTest.php`
Expected: the three new tests FAIL with 404 (route doesn't exist)

- [ ] **Step 3: Implement action, DTOs, request, controller**

`backend/app/Actions/Admin/Registers/IssueActivationCodeInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\Registers;

final readonly class IssueActivationCodeInput
{
    public function __construct(
        public string $registerId,
        public string $actorId,
    ) {}
}
```

`backend/app/Actions/Admin/Registers/IssuedActivationCode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\Registers;

use App\Models\Register;

/**
 * The register plus its plaintext activation code — shown to the admin exactly once,
 * never persisted, never retrievable again. Same convention as EnrolledRegister.
 */
final readonly class IssuedActivationCode
{
    public function __construct(
        public Register $register,
        public string $activationCode,
    ) {}
}
```

`backend/app/Actions/Admin/Registers/IssueActivationCode.php`:

```php
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
```

`backend/app/Http/Requests/Admin/Registers/IssueActivationCodeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Registers;

use App\Actions\Admin\Registers\IssueActivationCodeInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class IssueActivationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::REGISTER_ENROLL);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }

    public function toInput(): IssueActivationCodeInput
    {
        return new IssueActivationCodeInput(
            registerId: (string) $this->route('register'),
            actorId: $this->user()->id,
        );
    }
}
```

`backend/app/Http/Controllers/Admin/Registers/IssueActivationCodeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Registers;

use App\Actions\Admin\Registers\IssueActivationCode;
use App\Http\Requests\Admin\Registers\IssueActivationCodeRequest;
use Illuminate\Http\JsonResponse;

final class IssueActivationCodeController
{
    public function __invoke(IssueActivationCodeRequest $request, IssueActivationCode $action): JsonResponse
    {
        $issued = $action->execute($request->toInput());

        return response()->json([
            'data' => [
                // Shown exactly once. Never retrievable again — only supersedable.
                'activation_code' => $issued->activationCode,
                'expires_at' => $issued->register->activation_code_expires_at?->toIso8601String(),
            ],
        ], 201);
    }
}
```

- [ ] **Step 4: Swap the route and delete the four old files**

In `backend/routes/api.php`: replace the import `use App\Http\Controllers\Admin\Registers\ReissueDeviceTokenController;` with `use App\Http\Controllers\Admin\Registers\IssueActivationCodeController;`, and replace the route block at lines 160–164 with:

```php
// Issues (or reissues) the register's one-time activation code — the only way a
// terminal gets a device token. Reissue is the lost/stolen-terminal path: the till
// goes dark immediately (device token + staff sessions revoked) and stays dark until
// the new code is typed at the terminal. See ActivateRegister.
Route::post('/registers/{register}/activation-code', IssueActivationCodeController::class)
    ->name('admin.registers.activation_code');
```

Then delete:

```bash
rm backend/app/Actions/Admin/Registers/ReissueDeviceToken.php \
   backend/app/Actions/Admin/Registers/ReissueDeviceTokenInput.php \
   backend/app/Http/Controllers/Admin/Registers/ReissueDeviceTokenController.php \
   backend/app/Http/Requests/Admin/Registers/ReissueDeviceTokenRequest.php
```

- [ ] **Step 5: Run the file, then the full backend suite**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/LocationRegisterTest.php && ./vendor/bin/pest`
Expected: PASS. If anything else referenced `ReissueDeviceToken`, the suite finds it — fix by deleting the reference (nothing outside these four files and the one test should).

- [ ] **Step 6: Commit**

```bash
git add -A backend
git commit -m "Admin: issue activation code replaces raw device-token reissue"
```

---

### Task 4: Remove `POST /registers/enroll`

**Files:**
- Delete: `backend/app/Actions/Auth/EnrollRegister.php`, `backend/app/Actions/Auth/EnrollRegisterInput.php`, `backend/app/Http/Controllers/Auth/EnrollRegisterController.php`, `backend/app/Http/Requests/Auth/EnrollRegisterRequest.php`, `backend/tests/Feature/Auth/EnrollRegisterTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: nothing. Keep `backend/app/Actions/Auth/EnrolledRegister.php` and `backend/app/Http/Resources/EnrolledRegisterResource.php` — Task 5 reuses both. Keep `Permissions::REGISTER_ENROLL` — it now authorizes code issuing (Task 3). The seeder is untouched (it mints tokens server-side, not through this route).

- [ ] **Step 1: Delete the route and files**

In `backend/routes/api.php`, remove the import `use App\Http\Controllers\Auth\EnrollRegisterController;` and the whole enroll block (lines 94–98, comment included):

```php
// DELETE THIS BLOCK:
    // Enrolment is bootstrapped by a back-office admin, so it authenticates with a user
    // session rather than a device token — the device has no identity yet.
    Route::post('/registers/enroll', EnrollRegisterController::class)
        ->middleware('auth:sanctum')
        ->name('registers.enroll');
```

```bash
rm backend/app/Actions/Auth/EnrollRegister.php \
   backend/app/Actions/Auth/EnrollRegisterInput.php \
   backend/app/Http/Controllers/Auth/EnrollRegisterController.php \
   backend/app/Http/Requests/Auth/EnrollRegisterRequest.php \
   backend/tests/Feature/Auth/EnrollRegisterTest.php
```

- [ ] **Step 2: Run the full backend suite**

Run: `cd backend && ./vendor/bin/pest`
Expected: PASS (fewer tests than before — the enroll test file is gone). A failure here means something still referenced the enroll route or classes; chase and remove it.

- [ ] **Step 3: Commit**

```bash
git add -A backend
git commit -m "Remove POST /registers/enroll: activation codes are the only enrollment path"
```

---

### Task 5: `ActivateRegister` — the public redemption endpoint

**Files:**
- Create: `backend/app/Actions/Auth/ActivateRegister.php`, `backend/app/Actions/Auth/ActivateRegisterInput.php`
- Create: `backend/app/Http/Controllers/Auth/ActivateRegisterController.php`, `backend/app/Http/Requests/Auth/ActivateRegisterRequest.php`
- Create: `backend/app/Exceptions/Domain/InvalidActivationCode.php`, `backend/app/Exceptions/Domain/ActivationCodeExpired.php`
- Create: `backend/tests/Feature/Auth/ActivateRegisterTest.php`
- Modify: `backend/routes/api.php` (new public route), `backend/app/Providers/AppServiceProvider.php` (new `activate` limiter), `backend/app/Http/Resources/EnrolledRegisterResource.php` (inline `{id, name, mode}`)

**Interfaces:**
- Consumes: `ActivationCodes` (Task 1), columns (Task 2), `EnrolledRegister` + `EnrolledRegisterResource` (kept in Task 4).
- Produces: `POST /api/v1/registers/activate` (name `registers.activate`, `throttle:activate`, no auth) with body `{ "activation_code": string }` → `201 { "data": { "register": { "id", "name", "mode" }, "device_token" } }`. Errors 401 `invalid_activation_code` / 401 `activation_code_expired` / 429 `too_many_requests`. Audit: `register.activate`. Task 8's web client consumes this exact shape.

- [ ] **Step 1: Write the failing feature tests**

`backend/tests/Feature/Auth/ActivateRegisterTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Register;
use App\Models\User;

/** Issue a code for the register through the real admin endpoint. */
function issueCodeFor(Register $register): string
{
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    return test()->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $headers)
        ->assertCreated()
        ->json('data.activation_code');
}

function freshRegister(): Register
{
    return Register::factory()->create(['location_id' => Location::factory()->create()->id]);
}

it('redeems an activation code for a working device token', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);

    $response = $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])
        ->assertCreated();

    expect($response->json('data.register'))->toEqual([
        'id' => $register->id, 'name' => $register->name, 'mode' => $register->mode,
    ]);

    $token = $response->json('data.device_token');
    $this->getJson('/api/v1/catalog', ['Authorization' => "Bearer {$token}"])->assertOk();

    $this->assertDatabaseHas('audit_log', ['action' => 'register.activate', 'entity_id' => $register->id]);
});

it('accepts the code typed lowercase without the hyphen', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);

    $this->postJson('/api/v1/registers/activate', ['activation_code' => strtolower(str_replace('-', '', $code))])
        ->assertCreated();
});

it('rejects an unknown code', function (): void {
    $this->postJson('/api/v1/registers/activate', ['activation_code' => 'AAAAA-AAAAA'])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_activation_code');
});

it('rejects a second redemption with the same error as an unknown code', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);
    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])->assertCreated();

    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_activation_code');
});

it('rejects an expired code distinctly, so the installer knows to ask for a reissue', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);

    $this->travel(8)->days();

    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])
        ->assertStatus(401)->assertJsonPath('error.code', 'activation_code_expired');
});

it('rejects a code for a deactivated register as invalid, not expired', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);
    $register->update(['is_active' => false]);

    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_activation_code');
});

it('a reissue invalidates the previous unredeemed code', function (): void {
    $register = freshRegister();
    $first = issueCodeFor($register);
    $second = issueCodeFor($register);

    $this->postJson('/api/v1/registers/activate', ['activation_code' => $first])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_activation_code');
    $this->postJson('/api/v1/registers/activate', ['activation_code' => $second])->assertCreated();
});

it('requires an activation_code in the body', function (): void {
    $this->postJson('/api/v1/registers/activate', [])
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('throttles activation attempts by IP', function (): void {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/registers/activate', ['activation_code' => 'AAAAA-AAAA'.$i])
            ->assertStatus(401);
    }

    $this->postJson('/api/v1/registers/activate', ['activation_code' => 'AAAAA-AAAAA'])
        ->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Auth/ActivateRegisterTest.php`
Expected: FAIL with 404s (route doesn't exist)

- [ ] **Step 3: Implement exceptions, action, request, controller**

`backend/app/Exceptions/Domain/InvalidActivationCode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Unknown, already-redeemed, or deactivated-register code — deliberately one error for
 * all three, so the endpoint is not an oracle for which codes exist.
 */
final class InvalidActivationCode extends DomainException
{
    public function __construct()
    {
        parent::__construct('That activation code is not valid.');
    }

    public function errorCode(): string
    {
        return 'invalid_activation_code';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
```

`backend/app/Exceptions/Domain/ActivationCodeExpired.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Known and unredeemed, but past its expiry — distinct so a real installer asks for a reissue instead of retyping. */
final class ActivationCodeExpired extends DomainException
{
    public function __construct()
    {
        parent::__construct('This activation code has expired. Ask an admin to issue a new one.');
    }

    public function errorCode(): string
    {
        return 'activation_code_expired';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
```

`backend/app/Actions/Auth/ActivateRegisterInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Auth;

final readonly class ActivateRegisterInput
{
    public function __construct(
        public string $activationCode,
        public ?string $ip,
    ) {}
}
```

`backend/app/Actions/Auth/ActivateRegister.php`:

```php
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
```

`backend/app/Http/Requests/Auth/ActivateRegisterRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Actions\Auth\ActivateRegisterInput;
use Illuminate\Foundation\Http\FormRequest;

final class ActivateRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The activation code is the authorization; the action decides if it's valid.
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'activation_code' => ['required', 'string', 'max:20'],
        ];
    }

    public function toInput(): ActivateRegisterInput
    {
        return new ActivateRegisterInput(
            activationCode: $this->string('activation_code')->toString(),
            ip: $this->ip(),
        );
    }
}
```

`backend/app/Http/Controllers/Auth/ActivateRegisterController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ActivateRegister;
use App\Http\Requests\Auth\ActivateRegisterRequest;
use App\Http\Resources\EnrolledRegisterResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ActivateRegisterController
{
    public function __invoke(ActivateRegisterRequest $request, ActivateRegister $action): JsonResponse
    {
        return EnrolledRegisterResource::make($action->execute($request->toInput()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
```

- [ ] **Step 4: Route, limiter, resource reshape**

In `backend/routes/api.php`, add the import `use App\Http\Controllers\Auth\ActivateRegisterController;` and, right after the `/health` route (where the enroll block used to be):

```php
    // The terminal's bootstrap: trades a one-time activation code (issued in the back
    // office) for the long-lived device token. Unauthenticated by design — the code IS
    // the credential — and throttled hard by IP because the code space is human-typeable.
    Route::post('/registers/activate', ActivateRegisterController::class)
        ->middleware('throttle:activate')
        ->name('registers.activate');
```

In `backend/app/Providers/AppServiceProvider.php`, `defineRateLimits()`, after the `admin-login` limiter:

```php
// Same class of control as the PIN limiter: a human-typeable code must not be
// guessable at network speed. By IP — the request is unauthenticated by definition.
RateLimiter::for('activate', fn (Request $request): Limit => Limit::perMinute(
    (int) config('pos.rate_limits.activate_per_minute')
)->by($request->ip()));
```

Replace `backend/app/Http/Resources/EnrolledRegisterResource.php`'s `toArray` body — the register app stores exactly `{id, name, mode}` as its `RegisterInfo` (mirrors `StaffSessionResource`'s register block; `RegisterResource` stays as-is for its other consumers):

```php
return [
    'register' => [
        'id' => $enrolled->register->id,
        'name' => $enrolled->register->name,
        'mode' => $enrolled->register->mode,
    ],
    // Returned exactly once. Never retrievable again — only revocable by reissue.
    'device_token' => $enrolled->deviceToken,
];
```

- [ ] **Step 5: Run the file, then the full backend suite**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Auth/ActivateRegisterTest.php && ./vendor/bin/pest`
Expected: PASS, including `tests/Arch/ConventionsTest.php` (new actions are final, HTTP-free).

- [ ] **Step 6: Commit**

```bash
git add -A backend
git commit -m "Public activation endpoint: code redeems for the device token"
```

---

### Task 6: Activation state on the admin register surface

**Files:**
- Modify: `backend/app/Actions/Admin/Registers/ListRegisters.php`
- Modify: `backend/app/Http/Resources/Admin/AdminRegisterResource.php`
- Modify: `backend/tests/Feature/Admin/LocationRegisterTest.php` (one new test)

**Interfaces:**
- Produces: every `AdminRegisterResource` payload gains `"activation": { "state": "enrolled"|"code_pending"|"code_expired"|"not_enrolled", "code_expires_at": string|null }` (`code_expires_at` non-null only for `code_pending`). Task 10's back-office `Register` type mirrors this exactly.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Admin/LocationRegisterTest.php`:

```php
it('reports activation state on the admin register list', function (): void {
    $location = Location::factory()->create();
    $register = Register::factory()->create(['location_id' => $location->id]);

    // Fresh register: no token, no code.
    $this->getJson('/api/v1/admin/registers', $this->headers)
        ->assertJsonPath('data.0.activation.state', 'not_enrolled');

    // Code issued → pending, with its expiry surfaced.
    $code = $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers)
        ->json('data.activation_code');
    $response = $this->getJson('/api/v1/admin/registers', $this->headers);
    $response->assertJsonPath('data.0.activation.state', 'code_pending');
    expect($response->json('data.0.activation.code_expires_at'))->not->toBeNull();

    // Redeemed → enrolled.
    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])->assertCreated();
    $this->getJson('/api/v1/admin/registers', $this->headers)
        ->assertJsonPath('data.0.activation.state', 'enrolled');

    // A new code locks it out again; 8 days later that code has expired.
    $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers);
    $this->travel(8)->days();
    $this->getJson('/api/v1/admin/registers', $this->headers)
        ->assertJsonPath('data.0.activation.state', 'code_expired');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/LocationRegisterTest.php`
Expected: FAIL — `data.0.activation.state` is missing (null)

- [ ] **Step 3: Implement**

`backend/app/Actions/Admin/Registers/ListRegisters.php` — one-line query change:

```php
return Register::query()->withExists('tokens as has_device_token')->orderBy('name')->get();
```

`backend/app/Http/Resources/Admin/AdminRegisterResource.php` — add to the returned array:

```php
'activation' => $this->activationState(),
```

and add the private method:

```php
/**
 * Presentation of the enrollment lifecycle. `has_device_token` is preloaded by
 * ListRegisters' withExists; the single-model paths (create/update responses) fall
 * back to an exists query.
 *
 * @return array{state: string, code_expires_at: \Illuminate\Support\Carbon|null}
 */
private function activationState(): array
{
    $hasDeviceToken = (bool) ($this->has_device_token ?? $this->tokens()->exists());

    if ($hasDeviceToken) {
        return ['state' => 'enrolled', 'code_expires_at' => null];
    }

    if ($this->activation_code_lookup !== null && $this->activation_code_redeemed_at === null) {
        $pending = $this->activation_code_expires_at?->isFuture() ?? false;

        return [
            'state' => $pending ? 'code_pending' : 'code_expired',
            'code_expires_at' => $pending ? $this->activation_code_expires_at : null,
        ];
    }

    return ['state' => 'not_enrolled', 'code_expires_at' => null];
}
```

- [ ] **Step 4: Run the file, then the full backend suite**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/LocationRegisterTest.php && ./vendor/bin/pest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add backend/app/Actions/Admin/Registers/ListRegisters.php backend/app/Http/Resources/Admin/AdminRegisterResource.php backend/tests/Feature/Admin/LocationRegisterTest.php
git commit -m "Admin registers: activation state on the resource"
```

---

### Task 7: Backend docs

**Files:**
- Modify: `docs/03-api.md` (auth section, error table, admin registers section)
- Modify: `docs/02-data-model.md` (registers table + note)

- [ ] **Step 1: Update `docs/03-api.md`**

In the **Auth** section, replace the enroll block (lines ~24–27):

```
POST /api/v1/registers/activate      # unauthenticated — the activation code IS the credential
  { "activation_code": "XXXXX-XXXXX" }
  → { register: { id, name, mode }, device_token }   # token is long-lived; store on the device
```

with a short paragraph after it: activation codes are issued per-register in the back office (`POST /admin/registers/{id}/activation-code`), are single-use, expire after 7 days, and are stored server-side only as a keyed HMAC; the raw device token never crosses the API. Redemption is throttled by IP (5/min). Note the two failure codes and that unknown/redeemed/deactivated are deliberately one error.

In the **error code table** (~line 95), extend the 401 row's code list with `invalid_activation_code`, `activation_code_expired`.

In **Locations and registers** (~lines 528–543), replace the `POST /api/v1/admin/registers/{id}/token → { token }` block and its paragraph with:

```
POST /api/v1/admin/registers/{id}/activation-code
  → { activation_code, expires_at }        # shown exactly once
```

and rewrite the paragraph: issuing (or reissuing) stores a new single-use code and, **in the same transaction**, deletes every device token for the register and every staff session bound to it — the till goes dark immediately and shows its "activation code disabled" screen until the new code is typed in. `GET /admin/registers` now carries `activation: { state, code_expires_at }` with the four states.

- [ ] **Step 2: Update `docs/02-data-model.md`**

Extend the `create table registers` block (~line 120) with the three columns:

```sql
  activation_code_lookup      text unique,   -- keyed HMAC of the one-time code; plaintext never stored
  activation_code_expires_at  timestamptz,
  activation_code_redeemed_at timestamptz,
```

After the "the register *is* the token's owner" paragraph, add two sentences: the token is minted only by redeeming an activation code (`POST /registers/activate`); the code is stored as HMAC-SHA256 keyed by `APP_KEY` (same reasoning as `users.pin_lookup`) so a database dump alone cannot brute-force the code space.

- [ ] **Step 3: Commit**

```bash
git add docs/03-api.md docs/02-data-model.md
git commit -m "Docs: activation-code enrollment in the API and data-model docs"
```

---

### Task 8: Web api client — `activateRegister`

**Files:**
- Modify: `frontend/web/src/lib/api.ts`
- Modify: `frontend/web/src/lib/api.test.ts`

**Interfaces:**
- Consumes: Task 5's wire shape.
- Produces: `api.activateRegister(activationCode: string): Promise<RegisterInfo>` — POSTs `/registers/activate`, stores `pos.device_token` + `pos.register_info`, returns the register. Task 9 consumes it.

- [ ] **Step 1: Write the failing test**

Append to `frontend/web/src/lib/api.test.ts` (reuse the existing `stubFetch`/`jsonResponse` helpers):

```ts
describe('activateRegister', () => {
  it('exchanges the code, then stores the device token and register info', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse({ data: { register: { id: 'reg-1', name: 'Till 1', mode: 'retail' }, device_token: '3|abc' } }, 201),
    )

    const register = await api.activateRegister('ABCDE-FGH23')

    const [, init] = fetchMock.mock.calls[0]
    expect(JSON.parse(String(init?.body))).toEqual({ activation_code: 'ABCDE-FGH23' })
    expect(register).toEqual({ id: 'reg-1', name: 'Till 1', mode: 'retail' })
    expect(tokens.device()).toBe('3|abc')
    expect(tokens.registerInfo()).toEqual({ id: 'reg-1', name: 'Till 1', mode: 'retail' })
  })
})
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd frontend/web && npm test`
Expected: FAIL — `api.activateRegister is not a function`

- [ ] **Step 3: Implement**

In `frontend/web/src/lib/api.ts`, add to the `api` object directly above `staffLogin`:

```ts
// The activation handshake: a one-time, human-typeable code (issued in the back
// office) is exchanged for this terminal's long-lived device token. The code is spent
// server-side the moment this succeeds; a failure leaves nothing stored.
activateRegister: async (activationCode: string): Promise<RegisterInfo> => {
  const result = await post<{ register: RegisterInfo; device_token: string }>('/registers/activate', {
    activation_code: activationCode,
  })
  tokens.setDevice(result.device_token)
  tokens.setRegisterInfo(result.register)
  return result.register
},
```

- [ ] **Step 4: Run tests + typecheck**

Run: `cd frontend/web && npm test && npm run typecheck`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/web/src/lib/api.ts frontend/web/src/lib/api.test.ts
git commit -m "Register app: activateRegister api call"
```

---

### Task 9: Register app — ActivationScreen and the disabled lockout flow

**Files:**
- Create: `frontend/web/src/register/ActivationScreen.tsx`, `frontend/web/src/register/ActivationScreen.test.tsx`
- Modify: `frontend/web/src/register/SessionScreens.tsx` (delete `SetupScreen`; `PinScreen` stays)
- Modify: `frontend/web/src/register/Register.tsx` (stage machine)
- Modify: `frontend/web/src/register/Register.test.tsx` (new routing test)
- Modify: `frontend/web/src/register/SaleScreen.tsx`, `FloorScreen.tsx`, `RefundScreen.tsx`, `ShiftScreens.tsx` (pass the error to `onSessionExpired`)

**Interfaces:**
- Consumes: `api.activateRegister` (Task 8), `ApiError.code === 'invalid_device_token'` (existing).
- Produces: `ActivationScreen({ disabled?: boolean; activate: (code: string) => Promise<unknown>; onActivated: () => void })`. `Register`'s `Stage` union gains `{ name: 'disabled' }` and loses nothing else; every screen's `onSessionExpired` prop becomes `(err?: unknown) => void`.

- [ ] **Step 1: Write the failing ActivationScreen tests**

`frontend/web/src/register/ActivationScreen.test.tsx`:

```tsx
// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ActivationScreen } from './ActivationScreen'
import { ApiError } from '../lib/api'

afterEach(cleanup)

describe('ActivationScreen', () => {
  it('activates with the entered code and reports success', async () => {
    const activate = vi.fn(async () => ({}))
    const onActivated = vi.fn()
    render(<ActivationScreen activate={activate} onActivated={onActivated} />)

    fireEvent.change(screen.getByLabelText(/activation code/i), { target: { value: 'ABCDE-FGH23' } })
    fireEvent.click(screen.getByRole('button', { name: /activate/i }))

    await waitFor(() => expect(onActivated).toHaveBeenCalled())
    expect(activate).toHaveBeenCalledWith('ABCDE-FGH23')
  })

  it('shows the server message when the code is rejected, and re-enables the form', async () => {
    const activate = vi.fn(async () => {
      throw new ApiError('invalid_activation_code', 'That activation code is not valid.', 401)
    })
    render(<ActivationScreen activate={activate} onActivated={vi.fn()} />)

    fireEvent.change(screen.getByLabelText(/activation code/i), { target: { value: 'WRONG-WRONG' } })
    fireEvent.click(screen.getByRole('button', { name: /activate/i }))

    expect(await screen.findByText('That activation code is not valid.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /activate/i })).toBeEnabled()
  })

  it('shows the disabled banner with the exact lockout copy, form still available', () => {
    render(<ActivationScreen disabled activate={vi.fn()} onActivated={vi.fn()} />)

    expect(
      screen.getByText('Your activation code has been disabled. Please contact an admin and request a new activation code.'),
    ).toBeInTheDocument()
    expect(screen.getByLabelText(/activation code/i)).toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd frontend/web && npm test`
Expected: FAIL — cannot resolve `./ActivationScreen`

- [ ] **Step 3: Implement `ActivationScreen`**

`frontend/web/src/register/ActivationScreen.tsx`:

```tsx
'use client'

import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { ApiError } from '../lib/api'

const DISABLED_MESSAGE =
  'Your activation code has been disabled. Please contact an admin and request a new activation code.'

/**
 * The terminal's enrollment form: exchanges a one-time activation code (issued in the
 * back office) for this device's long-lived token. `disabled` renders the lockout
 * variant shown when the server revoked this terminal's token mid-life. `activate` is
 * injected (same pattern as ServerSetupScreen) so tests need no network.
 */
export function ActivationScreen({
  disabled = false,
  activate,
  onActivated,
}: {
  disabled?: boolean
  activate: (code: string) => Promise<unknown>
  onActivated: () => void
}) {
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (busy || code.trim() === '') return
    setBusy(true)
    setError(null)
    try {
      await activate(code.trim())
      onActivated()
    } catch (err) {
      setBusy(false)
      setError(err instanceof ApiError ? err.message : 'Activation failed.')
    }
  }

  return (
    <Card className="mx-auto mt-xxl w-full max-w-[480px]">
      <CardHeader>
        <CardTitle>{disabled ? 'Terminal disabled' : 'Activate this terminal'}</CardTitle>
      </CardHeader>
      <CardContent>
        {disabled ? (
          <p className="type-body-sm mb-lg text-error">{DISABLED_MESSAGE}</p>
        ) : (
          <p className="type-body-sm mb-lg text-ink-muted">
            Enter the activation code issued for this terminal in the back office.
          </p>
        )}
        <form className="flex flex-col gap-lg" onSubmit={submit}>
          <label className="type-body-sm flex flex-col gap-xs">
            Activation code
            <Input
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="XXXXX-XXXXX"
              autoFocus
              autoComplete="off"
              className="min-h-[48px] uppercase"
            />
          </label>
          {error && <p className="type-body-sm text-error">{error}</p>}
          <Button type="submit" size="lg" className="w-full" disabled={busy}>
            {busy ? 'Activating…' : 'Activate'}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
```

Delete `SetupScreen` from `frontend/web/src/register/SessionScreens.tsx` (the whole function, lines 9–37; `PinScreen` and the imports it needs stay — drop the now-unused `tokens` import only if `PinScreen` no longer references it; it does reference it at line 56, so keep it).

- [ ] **Step 4: Run the ActivationScreen tests**

Run: `cd frontend/web && npm test`
Expected: ActivationScreen tests PASS; the suite as a whole FAILS to compile — `Register.tsx` still imports `SetupScreen`. That's the next step.

- [ ] **Step 5: Rewire `Register.tsx`**

In `frontend/web/src/register/Register.tsx`:

1. Imports: `import { PinScreen } from './SessionScreens'` and `import { ActivationScreen } from './ActivationScreen'`.
2. `Stage` union: add `| { name: 'disabled' }` after `| { name: 'setup' }`.
3. `SECTION_LABEL`: change `setup: 'Enroll Terminal'` to `setup: 'Activate Terminal'` and add `disabled: 'Terminal Disabled'`.
4. Replace the `sessionExpired` alias (line 123) with:

```tsx
  // The terminal's token was revoked server-side (activation code reissued). Everything
  // cached belongs to the old identity; drop all of it and show the lockout screen.
  const deviceDisabled = () => {
    queryClient.clear()
    tokens.clearDevice()
    tokens.clearStaff()
    setUser(null)
    setResumeOrder(null)
    setActiveOrder(null)
    setStage({ name: 'disabled' })
  }

  // 401s fork on `code`, not just status: a dead STAFF session goes back to the PIN
  // screen, but a dead DEVICE token means this terminal was disabled — mid-session
  // revocation must not be misread as staff-session expiry.
  const sessionExpired = (err?: unknown) => {
    if (err instanceof ApiError && err.code === 'invalid_device_token') {
      deviceDisabled()
      return
    }
    endSession()
  }
```

5. In the `loading-shift` effect (line 143), pass the error: `else if (err instanceof ApiError && err.status === 401) sessionExpired(err)`.
6. Replace the `setup` render block (line 228) and PinScreen's `onDeviceInvalid` (line 236):

```tsx
        {stage.name === 'setup' && (
          <ActivationScreen activate={(code) => api.activateRegister(code)} onActivated={() => setStage({ name: 'pin' })} />
        )}
        {stage.name === 'disabled' && (
          <ActivationScreen disabled activate={(code) => api.activateRegister(code)} onActivated={() => setStage({ name: 'pin' })} />
        )}
```

```tsx
            onDeviceInvalid={deviceDisabled}
```

- [ ] **Step 6: Thread the error through every screen's `onSessionExpired`**

Mechanical, four files — change the prop **type** to `(err?: unknown) => void` and pass the in-scope error at each 401 branch:

- `frontend/web/src/register/SaleScreen.tsx`: line 104 type; line 153 `fail` helper → `return onSessionExpired(err)`.
- `frontend/web/src/register/FloorScreen.tsx`: line 50 type; line 64 → `return onSessionExpired(err)`; lines 88 and 91 → `onSessionExpired(openOrders.error)` / `onSessionExpired(openShiftRegisters.error)`.
- `frontend/web/src/register/RefundScreen.tsx`: line 17 type; line 29 → `return onSessionExpired(err)`.
- `frontend/web/src/register/ShiftScreens.tsx`: lines 22 and 115 types; line 35 and 147 and 164 (each inside a catch/onError with `err` in scope) → `onSessionExpired(err)`; line 122 → `onSessionExpired(zReport.error)`.

- [ ] **Step 7: Add the mid-session routing test**

In `frontend/web/src/register/Register.test.tsx`, add a module mock for the api (below the existing `../lib/shell` mock) and one test. Import `screen` from `@testing-library/react` and `ApiError, api` from `../lib/api`:

```tsx
vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return { ...actual, api: { ...actual.api, currentShift: vi.fn() } }
})
```

```tsx
describe('device revocation routing', () => {
  it('lands on the disabled screen when a mid-session 401 carries invalid_device_token', async () => {
    localStorage.setItem('pos.device_token', 'dead-token')
    localStorage.setItem('pos.staff_token', 'live-staff-token')
    vi.mocked(api.currentShift).mockRejectedValue(
      new ApiError('invalid_device_token', 'This device is not enrolled.', 401),
    )

    renderRegister()

    expect(
      await screen.findByText('Your activation code has been disabled. Please contact an admin and request a new activation code.'),
    ).toBeInTheDocument()
    expect(localStorage.getItem('pos.device_token')).toBeNull()
    expect(localStorage.getItem('pos.staff_token')).toBeNull()
  })

  it('goes back to the PIN screen when the 401 is a plain staff-session expiry', async () => {
    localStorage.setItem('pos.device_token', 'live-token')
    localStorage.setItem('pos.staff_token', 'dead-staff-token')
    vi.mocked(api.currentShift).mockRejectedValue(
      new ApiError('staff_session_expired', 'No staff session.', 401),
    )

    renderRegister()

    expect(await screen.findByText('Enter PIN')).toBeInTheDocument()
    expect(localStorage.getItem('pos.device_token')).toBe('live-token')
  })
})
```

- [ ] **Step 8: Run the full web suite**

Run: `cd frontend/web && npm test && npm run typecheck && npm run build`
Expected: PASS. If an existing Register test breaks because stage `'setup'` now renders `ActivationScreen`, the fix is in the test's expectations (the screens changed by design), not the component.

- [ ] **Step 9: Commit**

```bash
git add frontend/web/src
git commit -m "Register app: activation screen and device-disabled lockout flow"
```

---

### Task 10: Back office — issue activation code UI

**Files:**
- Modify: `frontend/back-office/src/lib/api.ts` (`Register` type, `registers.issueActivationCode`)
- Modify: `frontend/back-office/src/admin/places/RegisterEditor.tsx`
- Modify: `frontend/back-office/src/admin/places/RegisterEditor.test.tsx`

**Interfaces:**
- Consumes: Task 3's endpoint, Task 6's `activation` field, `StatusPill({ tone, children })`, `ConfirmDialog`.
- Produces: `api.registers.issueActivationCode(registerId): Promise<{ activation_code: string; expires_at: string }>`; `Register` type gains `activation: RegisterActivation`.

- [ ] **Step 1: Update the api client and types**

In `frontend/back-office/src/lib/api.ts`, extend the `Register` type (line 248):

```ts
export type RegisterActivation = {
  state: 'enrolled' | 'code_pending' | 'code_expired' | 'not_enrolled'
  code_expires_at: string | null
}

export type Register = {
  id: string
  location_id: string
  name: string
  mode: 'retail' | 'food'
  is_active: boolean
  activation: RegisterActivation
}

export type IssuedActivationCode = { activation_code: string; expires_at: string }
```

Replace `reissueToken` in the `registers` block:

```ts
registers: {
  ...catalogEntity<Register>('registers', 'register'),
  // Issues (or reissues) the register's one-time activation code. The server revokes
  // the register's device token AND its staff sessions in the same transaction — the
  // old till goes dark the instant this succeeds (IssueActivationCode.php) and shows
  // its lockout screen until the new code is typed in. The code comes back exactly
  // once; raw device tokens never cross the API anymore.
  issueActivationCode: (registerId: string): Promise<IssuedActivationCode> =>
    post<IssuedActivationCode>(`/admin/registers/${registerId}/activation-code`, {}),
},
```

- [ ] **Step 2: Update the RegisterEditor tests (failing)**

In `frontend/back-office/src/admin/places/RegisterEditor.test.tsx`:

1. Mock swap: `registers: { ...actual.api.registers, update: vi.fn(), create: vi.fn(), issueActivationCode: vi.fn() }`.
2. Fixture: `const REGISTER: Register = { id: 'reg-1', location_id: 'loc-1', name: 'Front counter', mode: 'retail', is_active: true, activation: { state: 'enrolled', code_expires_at: null } }`.
3. Replace the two reissue tests (the `not.toHaveBeenCalled` cancel test at ~line 116 and the confirm test at ~121) with:

```tsx
  it('cancelling the issue ConfirmDialog leaves the code un-issued', () => {
    renderEditor()

    fireEvent.click(screen.getByRole('button', { name: /issue activation code/i }))
    fireEvent.click(screen.getByRole('button', { name: /^cancel$/i }))

    expect(api.registers.issueActivationCode).not.toHaveBeenCalled()
  })

  it('issues a code on confirm and shows it exactly once', async () => {
    vi.mocked(api.registers.issueActivationCode).mockResolvedValue({
      activation_code: 'ABCDE-FGH23',
      expires_at: '2026-07-27T12:00:00+00:00',
    })
    renderEditor()

    fireEvent.click(screen.getByRole('button', { name: /issue activation code/i }))
    fireEvent.click(screen.getByRole('button', { name: /^issue code$/i }))

    await waitFor(() => expect(api.registers.issueActivationCode).toHaveBeenCalledWith('reg-1'))
    expect(await screen.findByText('ABCDE-FGH23')).toBeInTheDocument()
  })

  it('shows the activation state pill', () => {
    renderEditor({ register: { ...REGISTER, activation: { state: 'code_pending', code_expires_at: '2026-07-27T12:00:00+00:00' } } })

    expect(screen.getByText(/code pending/i)).toBeInTheDocument()
  })
```

- [ ] **Step 3: Run to verify they fail**

Run: `cd frontend/back-office && npm test`
Expected: FAIL — `issueActivationCode` missing / new UI not rendered

- [ ] **Step 4: Rework the editor's token section**

In `frontend/back-office/src/admin/places/RegisterEditor.tsx`:

1. Imports: add `import { StatusPill, type StatusPillTone } from '../../components/StatusPill'` and pull `type IssuedActivationCode, type RegisterActivation` from `../../lib/api`.
2. State: replace `reissuedToken` with `const [issuedCode, setIssuedCode] = useState<IssuedActivationCode | null>(null)` and `pendingReissue` with `pendingIssue`.
3. Mutation:

```tsx
  const issue = useMutation({
    mutationFn: () => api.registers.issueActivationCode(register?.id ?? ''),
    onSuccess: (code) => {
      setIssuedCode(code)
      setError(null)
      invalidate()
    },
    onError: (err) => fail(err, 'Could not issue an activation code.'),
  })
```

4. Above the component, the pill mapping:

```tsx
const ACTIVATION_TONE: Record<RegisterActivation['state'], StatusPillTone> = {
  enrolled: 'success',
  code_pending: 'info',
  code_expired: 'warning',
  not_enrolled: 'neutral',
}

function activationLabel(activation: RegisterActivation): string {
  switch (activation.state) {
    case 'enrolled': return 'Enrolled'
    case 'code_pending': return `Code pending — expires ${activation.code_expires_at?.slice(0, 10) ?? ''}`
    case 'code_expired': return 'Code expired'
    case 'not_enrolled': return 'Not enrolled'
  }
}
```

5. Replace the whole "Device token" section (lines 168–185) with:

```tsx
      {register && (
        <>
          <Divider />
          <div className="mb-md flex items-center justify-between gap-md">
            <CardTitle>Activation</CardTitle>
            <StatusPill tone={ACTIVATION_TONE[register.activation.state]}>
              {activationLabel(register.activation)}
            </StatusPill>
          </div>
          <p className="type-body-sm text-ink-muted mb-md">
            Issuing a new activation code locks this terminal out immediately; it comes back
            when someone enters the new code on the till.
          </p>
          <Button type="button" variant="secondary" disabled={issue.isPending} onClick={() => setPendingIssue(true)}>
            {issue.isPending ? 'Issuing…' : 'Issue activation code'}
          </Button>
          {issuedCode && (
            <Card elevated className="mt-md">
              <p className="type-body-sm text-ink-muted mb-xs">
                Activation code — single use, valid for 7 days. Copy it now, it will not be shown again:
              </p>
              <code className="type-money block select-all break-all text-ink">{issuedCode.activation_code}</code>
            </Card>
          )}
        </>
      )}
```

6. Replace the reissue `ConfirmDialog` (lines 202–212) with:

```tsx
      <ConfirmDialog
        open={pendingIssue}
        onOpenChange={setPendingIssue}
        message={`Issue a new activation code for ${register?.name ?? 'this register'}? The current till goes dark immediately.`}
        confirmLabel="Issue code"
        destructive
        onConfirm={() => {
          setPendingIssue(false)
          issue.mutate()
        }}
      />
```

7. Update the component's doc comment (lines 15–26) to describe the code flow instead of the token flow.

- [ ] **Step 5: Run the full back-office suite**

Run: `cd frontend/back-office && npm test && npm run typecheck && npm run build`
Expected: PASS. Typecheck will also catch any other place constructing a `Register` without `activation` (e.g. other test fixtures) — add `activation: { state: 'not_enrolled', code_expires_at: null }` to those fixtures.

- [ ] **Step 6: Commit**

```bash
git add frontend/back-office/src
git commit -m "Back office: issue activation codes instead of raw device tokens"
```

---

### Task 11: Project docs + full verification

**Files:**
- Modify: `CLAUDE.md` (Status section), `docs/06-roadmap.md` (record)

- [ ] **Step 1: Record the change**

In `CLAUDE.md`'s Status section, after the "UI rework complete" paragraph, add:

```
**Activation-code enrollment complete** — terminals enroll by exchanging a one-time,
7-day, admin-issued activation code (`POST /registers/activate`) for their long-lived
device token; raw tokens never cross the API. Reissuing from the back office revokes
the device token *and* the register's staff sessions in one transaction and the till
shows a lockout screen until the new code is typed in. `POST /registers/enroll` and
the raw-token reissue endpoint are gone. Seeder and e2e scripts unchanged (tokens are
minted server-side).
```

In `docs/06-roadmap.md`, add a matching short record in the same style as the existing milestone records (what changed, route/table deltas, test counts after the run below).

- [ ] **Step 2: Run everything**

```bash
cd backend && ./vendor/bin/pest
cd ../frontend/web && npm test && npm run typecheck && npm run build
cd ../back-office && npm test && npm run typecheck && npm run build
```

Expected: all green. Record the new suite counts in the roadmap entry from Step 1. If the containerized stack is up, also run `make test` and `make e2e` — the e2e scripts must pass **unmodified** (they use seeder-minted tokens).

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md docs/06-roadmap.md
git commit -m "Docs: record activation-code enrollment"
```

---

## Self-review notes (already applied)

- Spec coverage: storage columns (T2), issue+lockout (T3), enroll removal (T4), redemption endpoint + errors + throttle (T5), admin status field (T6), API/data-model docs (T7), register client + screens + mid-session routing (T8–9), back-office UI (T10), records (T11). Out-of-scope items from the spec (idempotent redemption, token storage hardening, code delivery) have no tasks, by design.
- Type consistency: `IssuedActivationCode {register, activationCode}` (T3) is read by its controller only; wire shape `{activation_code, expires_at}` matches T10's `IssuedActivationCode` TS type; `RegisterInfo {id, name, mode}` matches T5's resource reshape and T8's client; `activation {state, code_expires_at}` matches T6 (PHP) and T10 (TS).
- The `has_device_token` fallback in T6 uses `tokens()` as a query (not a lazy relation load), so `Model::preventLazyLoading` does not fire.
