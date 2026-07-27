# Pending Variances Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only back-office queue of closed shifts whose drawer variance is over threshold and unapproved, so a supervisor can see which drawers need sign-off without logging into each register — and is told where approval actually works.

**Architecture:** One new admin endpoint (`GET /admin/variances`) backed by a single read-only action, gated on the existing `shift.approve_variance` permission, plus one back-office section with a sidebar count badge. Approval stays in the register app.

**Tech Stack:** Laravel 13.20 / PHP 8.5 / PostgreSQL 18, Pest. Next.js 16 + React 19 + TypeScript 7, Vitest, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-07-27-pending-variances-design.md`

## Global Constraints

- **Money is integer cents** (`bigint` / PHP `int`), wire suffix `_cents`. Never a float, in any layer.
- One action = one route = one controller = one Action class. Actions are `final`, take an Input DTO, return a domain object, **never touch HTTP**. Resources never query. `declare(strict_types=1);` everywhere.
- Never call `env()` outside `config/`. `tests/Arch/` enforces this.
- **Tests run against real PostgreSQL, never SQLite.** Dev Postgres is on **host port 5434**; `backend/.env`'s `DB_PORT=5432` is stale — override per command: `cd backend && DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest`. **Never edit `backend/.env`.** Never use `--parallel`.
- **Eloquent `create()` never hydrates DB column defaults** — set explicitly or `->refresh()`.
- **Admin `FormRequest::authorize()` NEVER calls bare `can()`** — no permission team context exists on an admin request, so `can()` silently answers wrongly. Use `AuthorizesBackOffice::allowsBackOffice()`.
- **Validation failures are `400 validation_failed`**, not Laravel's 422 (`app/Exceptions/ApiErrorEnvelope.php:39-43`).
- **Never read role assignments through spatie's `roles()`/`permissions()` relations** — query `model_has_roles` / `model_has_permissions` directly, which is what `AdminAccess` already does.
- Admin lists are unpaginated in v1. `GET` lists are read-only: no transaction, no audit row.
- **The shared UI set is byte-identical** between `frontend/web` and `frontend/back-office` (`src/styles/carbon.css`, `src/lib/utils.ts`, all of `src/components/ui/*`, `StatusPill`/`EmptyState`/`ConfirmDialog`). **Nothing here may touch any of them.**
- `tsconfig` sets `erasableSyntaxOnly` — type syntax must never emit runtime code.
- No `@testing-library/user-event` in this repo; use `fireEvent`.
- **Adding a frontend dependency does not reach the `make dev` containers** — not relevant here (this plan adds none), but do not add one.

---

## Task 1: The endpoint

**Files:**
- Modify: `backend/app/Domain/Rbac/AdminAccess.php`
- Create: `backend/app/Actions/Admin/Shifts/ListPendingVariances.php`, `ListPendingVariancesInput.php`
- Create: `backend/app/Http/Requests/Admin/Shifts/ListPendingVariancesRequest.php`
- Create: `backend/app/Http/Controllers/Admin/Shifts/ListPendingVariancesController.php`
- Create: `backend/app/Http/Resources/Admin/AdminPendingVarianceResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/PendingVariancesTest.php`

**Interfaces:**
- Produces: `GET /api/v1/admin/variances` → `{ data: { items: [...] } }`; route name `admin.variances.list`; `Permissions::SHIFT_APPROVE_VARIANCE` present in `AdminAccess::SECTIONS`. Task 2 consumes both.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Admin/PendingVariancesTest.php`. Read `tests/Feature/Admin/ReportsTest.php` first for the admin-header setup and `tests/Feature/Shifts/CloseShiftTest.php` for how a shift is closed with a variance — reuse those idioms rather than inventing fixtures.

```php
<?php

declare(strict_types=1);

use App\Domain\Rbac\Permissions;
use App\Models\Shift;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'AAA', 'variance_approval_threshold_cents' => 500]);
    $this->register = registerAt($this->location);
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

/** A closed shift with a chosen variance. Closing is faked at the row level on purpose:
 *  this action reads the ledger, and driving CloseShift would drag in orders and tenders
 *  that say nothing about the query under test. */
function pv(object $t, int $varianceCents, array $overrides = []): Shift
{
    $shift = Shift::factory()->create(['register_id' => $t->register->id]);
    $shift->forceFill(array_merge([
        'closed_at' => now(),
        'counted_cash_cents' => 10000 + $varianceCents,
        'expected_cash_cents' => 10000,
        'variance_cents' => $varianceCents,
    ], $overrides))->save();

    return $shift->refresh();
}

it('lists a closed, over-threshold, unapproved variance', function (): void {
    $shift = pv($this, -1200);

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.items.0.shift_id', $shift->id)
        ->assertJsonPath('data.items.0.variance_cents', -1200)
        ->assertJsonPath('data.items.0.threshold_cents', 500)
        ->assertJsonPath('data.items.0.register_name', $this->register->name)
        ->assertJsonPath('data.items.0.location_name', $this->location->name);
});

it('excludes an open shift', function (): void {
    // An open shift has no count yet, so there is nothing to approve.
    Shift::factory()->create(['register_id' => $this->register->id]);

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()->assertJsonCount(0, 'data.items');
});

it('excludes a variance at or under the threshold', function (): void {
    // Strictly greater than, matching ApproveVariance's `abs(...) <= threshold` rejection:
    // listing an exactly-at-threshold variance would offer an approval the API refuses
    // with 422 variance_approval_not_required.
    pv($this, 500);
    pv($this, -500);
    pv($this, 100);

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()->assertJsonCount(0, 'data.items');
});

it('excludes an already-approved variance', function (): void {
    pv($this, -1200, ['variance_approved_at' => now(), 'variance_approved_by' => User::factory()->create()->id]);

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()->assertJsonCount(0, 'data.items');
});

it('falls back to the config threshold when the location sets none', function (): void {
    config(['pos.shifts.variance_approval_threshold_cents' => 2000]);
    $this->location->forceFill(['variance_approval_threshold_cents' => null])->save();

    pv($this, 1500);            // under the config default — excluded
    $over = pv($this, 2500);    // over it — listed

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.shift_id', $over->id)
        ->assertJsonPath('data.items.0.threshold_cents', 2000);
});

it('orders the most recently closed first', function (): void {
    $older = pv($this, -900, ['closed_at' => now()->subHours(3)]);
    $newer = pv($this, -900, ['closed_at' => now()]);

    $items = $this->getJson('/api/v1/admin/variances', $this->headers)->assertOk()->json('data.items');
    expect(array_column($items, 'shift_id'))->toBe([$newer->id, $older->id]);
});

it('scopes a non-admin holder to the locations they hold the permission at', function (): void {
    $other = provisionedLocation(['code' => 'BBB', 'variance_approval_threshold_cents' => 500]);
    $otherRegister = registerAt($other);
    $otherShift = Shift::factory()->create(['register_id' => $otherRegister->id]);
    $otherShift->forceFill([
        'closed_at' => now(), 'counted_cash_cents' => 8800,
        'expected_cash_cents' => 10000, 'variance_cents' => -1200,
    ])->save();

    $mine = pv($this, -1200);

    $supervisor = User::factory()->create(['email' => 's@pos.test', 'password_hash' => 'pw']);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->location->id);
    $supervisor->givePermissionTo(Permissions::SHIFT_APPROVE_VARIANCE);
    $registrar->forgetCachedPermissions();

    $items = $this->getJson('/api/v1/admin/variances', [
        'Authorization' => 'Bearer '.$supervisor->createToken('t')->plainTextToken,
    ])->assertOk()->json('data.items');

    expect(array_column($items, 'shift_id'))->toBe([$mine->id]);
});

it('refuses a session without the permission', function (): void {
    $nobody = User::factory()->create(['email' => 'n@pos.test', 'password_hash' => 'pw']);

    $this->getJson('/api/v1/admin/variances', [
        'Authorization' => 'Bearer '.$nobody->createToken('t')->plainTextToken,
    ])->assertStatus(403);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest tests/Feature/Admin/PendingVariancesTest.php`
Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Add the permission to the section list**

In `backend/app/Domain/Rbac/AdminAccess.php`, add `Permissions::SHIFT_APPROVE_VARIANCE` to the `SECTIONS` array. Put it after `REPORT_STOCK_VIEW`.

Add a sentence to the class docblock noting that `SECTIONS` now includes one register-tier permission: it means "an admin-tier surface exists for this", not "this is an admin-only capability". Supervisors hold it and are precisely the audience for the variance queue.

Do **not** add it to `Permissions::all()` (it is already there) and do **not** change any role's permission set.

- [ ] **Step 4: Write the Input DTO and action**

`ListPendingVariancesInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\Shifts;

final readonly class ListPendingVariancesInput
{
    /** @param list<string>|null $locationIds null = every location (admin) */
    public function __construct(public ?array $locationIds) {}
}
```

`ListPendingVariances.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\Shifts;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closed shifts whose drawer variance is over threshold and not yet signed off.
 *
 * The three conditions are lifted from ApproveVariance's own guards so there is exactly one
 * definition of "needs approval" in the system: an open shift has no count yet, an
 * at-or-under-threshold variance is refused with `under_threshold`, and an approved one is
 * refused with `variance_already_approved`. Listing any of them would offer an approval the
 * API rejects.
 *
 * Read-only: no transaction, no audit entry.
 */
final class ListPendingVariances
{
    /** @return Collection<int, object> */
    public function execute(ListPendingVariancesInput $in): Collection
    {
        // Unlike GetBusinessDay, this spans locations, each of which may override the
        // threshold — so it is resolved per row in SQL rather than once in PHP, with the
        // config default bound as the coalesce fallback.
        $default = (int) config('pos.shifts.variance_approval_threshold_cents');

        $query = DB::table('shifts as s')
            ->join('registers as r', 'r.id', '=', 's.register_id')
            ->join('locations as l', 'l.id', '=', 'r.location_id')
            ->join('users as u', 'u.id', '=', 's.opened_by')
            ->whereNotNull('s.closed_at')
            ->whereNull('s.variance_approved_at')
            ->whereRaw('abs(s.variance_cents) > coalesce(l.variance_approval_threshold_cents, ?)', [$default]);

        // null = admin, every location. An empty list is a holder with no locations, which
        // must return nothing rather than everything — `whereIn` with [] does that.
        if ($in->locationIds !== null) {
            $query->whereIn('r.location_id', $in->locationIds);
        }

        return $query
            ->orderByDesc('s.closed_at')
            ->get([
                's.id as shift_id',
                'r.id as register_id', 'r.name as register_name',
                'l.id as location_id', 'l.name as location_name',
                'u.name as opened_by_name',
                's.opened_at', 's.closed_at',
                's.expected_cash_cents', 's.counted_cash_cents', 's.variance_cents',
                DB::raw("coalesce(l.variance_approval_threshold_cents, {$default}) as threshold_cents"),
            ]);
    }
}
```

**Note the `DB::raw` interpolation of `$default`** — it is an `(int)` cast of a config value, never user input, so it cannot carry injection. If that reads uncomfortably, bind it instead with `selectRaw('coalesce(l.variance_approval_threshold_cents, ?) as threshold_cents', [$default])` alongside the column list; either is fine, but say which you chose.

- [ ] **Step 5: Write the resource**

`AdminPendingVarianceResource.php` — a plain serializer over the query row, casting the money columns to `int` (pgsql returns them as strings) and the timestamps to ISO 8601:

```php
    public function toArray(Request $request): array
    {
        return [
            'shift_id' => $this->shift_id,
            'register_id' => $this->register_id,
            'register_name' => $this->register_name,
            'location_id' => $this->location_id,
            'location_name' => $this->location_name,
            'opened_by_name' => $this->opened_by_name,
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'expected_cash_cents' => (int) $this->expected_cash_cents,
            'counted_cash_cents' => (int) $this->counted_cash_cents,
            'variance_cents' => (int) $this->variance_cents,
            // Returned per row so the client can show WHY a row qualifies without
            // re-deriving a rule the server owns.
            'threshold_cents' => (int) $this->threshold_cents,
        ];
    }
```

- [ ] **Step 6: Write the request and controller**

`ListPendingVariancesRequest.php` — `authorize()` returns `$this->allowsBackOffice(Permissions::SHIFT_APPROVE_VARIANCE)`, `rules()` returns `[]`, and `toInput()` resolves the scope:

```php
    public function toInput(): ListPendingVariancesInput
    {
        $user = $this->user();

        // null for an admin (every location); otherwise exactly where they hold it.
        return new ListPendingVariancesInput(
            locationIds: app(AdminAccess::class)->locationIdsWhere($user, Permissions::SHIFT_APPROVE_VARIANCE),
        );
    }
```

There is no `location_id` parameter to validate, so unlike the other admin lists this needs no `withValidator` scoping check — the scope *is* the caller's held locations. Say so in a comment, or a later reader will think it was forgotten.

The controller mirrors `ListPaymentMethodsController`: `__invoke(Request, Action): JsonResponse` returning `['data' => ['items' => AdminPendingVarianceResource::collection($action->execute($request->toInput()))]]`.

- [ ] **Step 7: Register the route**

In `backend/routes/api.php`, inside the `admin` group, near the reports block:

```php
        // Closed shifts needing variance sign-off. Read-only — approval itself stays a
        // register action (POST /shifts/{shift}/approve-variance), because the audit trail
        // is register-attributed.
        Route::get('/variances', ListPendingVariancesController::class)->name('admin.variances.list');
```

- [ ] **Step 8: Run the tests**

```bash
cd backend
DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest tests/Feature/Admin/PendingVariancesTest.php
DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest tests/Arch
DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest
```
Expected: all green. If a test asserting an exact `sections[]` array or permission count now fails, update its expected value — the section list legitimately grew by one.

- [ ] **Step 9: Commit**

```bash
git add backend
git commit -m "feat(shifts): admin endpoint listing pending drawer variances"
```

---

## Task 2: The back-office section

**Files:**
- Modify: `frontend/back-office/src/lib/api.ts`, `src/admin/navigation.ts`, `src/admin/Shell.tsx`
- Create: `frontend/back-office/src/admin/variances/VariancesSection.tsx`, `VariancesSection.test.tsx`
- Modify: `frontend/back-office/src/admin/navigation.test.ts`, `Shell.test.tsx`

**Interfaces:**
- Consumes: `GET /admin/variances` and `Permissions::SHIFT_APPROVE_VARIANCE` in `SECTIONS` (Task 1).

- [ ] **Step 1: Write the failing tests**

Add to `navigation.test.ts`:

```ts
it('resolves /variances for a holder and falls back to today without it', () => {
  expect(resolveSection('/variances', ['shift.approve_variance'])).toBe('variances')
  expect(resolveSection('/variances', ['catalog.manage'])).toBe('today')
})
```

Create `VariancesSection.test.tsx` following `PaymentMethodsSection.test.tsx`'s shape (QueryClientProvider wrapper, `api` spy, `afterEach(cleanup)`, `fireEvent` — this repo has no `user-event`):

```tsx
const rows = [{
  shift_id: 's-1', register_id: 'r-1', register_name: 'Till 2',
  location_id: 'loc-1', location_name: 'Manila Grocery',
  opened_by_name: 'Alice', opened_at: '2026-07-27T01:00:00Z', closed_at: '2026-07-27T09:14:00Z',
  expected_cash_cents: 10000, counted_cash_cents: 8800, variance_cents: -1200, threshold_cents: 500,
}]

it('lists a pending variance with its register, opener and amount', async () => {
  vi.spyOn(api.variances, 'list').mockResolvedValue(rows)
  renderSection()

  expect(await screen.findByText('Till 2')).toBeInTheDocument()
  expect(screen.getByText('Alice')).toBeInTheDocument()
})

it('tells the supervisor to approve from a DIFFERENT register', async () => {
  vi.spyOn(api.variances, 'list').mockResolvedValue(rows)
  renderSection()

  // The whole point of the view: closing a shift signs its own register out, so the
  // listed register is the one place approval 401s.
  expect(await screen.findByText(/any register other than the one listed/i)).toBeInTheDocument()
})

it('shows an empty state when nothing is pending', async () => {
  vi.spyOn(api.variances, 'list').mockResolvedValue([])
  renderSection()

  expect(await screen.findByText(/no variances waiting/i)).toBeInTheDocument()
})
```

Add to `Shell.test.tsx`, matching its existing nav cases (nav items are `<a>` links inside a `navigation` landmark named "Sections", so use `getByRole('link', …)`):

```tsx
it('shows Variances only to a holder of shift.approve_variance', () => {
  renderShell({ sections: ['shift.approve_variance'] })
  expect(screen.getByRole('link', { name: /variances/i })).toBeInTheDocument()
})

it('hides Variances without the permission', () => {
  renderShell({ sections: ['catalog.manage'] })
  expect(screen.queryByRole('link', { name: /variances/i })).not.toBeInTheDocument()
})
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `cd frontend/back-office && npm test -- navigation VariancesSection Shell`
Expected: FAIL.

- [ ] **Step 3: Add the API client and types**

In `src/lib/api.ts`:

```ts
// Verified against AdminPendingVarianceResource.php. A "pending" variance is a CLOSED
// shift whose |variance| exceeds its location's threshold and which nobody has signed
// off — the server owns that rule; `threshold_cents` is returned so the client can show
// why a row qualifies without re-deriving it.
export type PendingVariance = {
  shift_id: string
  register_id: string
  register_name: string
  location_id: string
  location_name: string
  opened_by_name: string
  opened_at: string
  closed_at: string
  expected_cash_cents: number
  counted_cash_cents: number
  variance_cents: number
  threshold_cents: number
}
```

and on the `api` object:

```ts
  // No location_id parameter: the list is already scoped to everywhere the caller holds
  // shift.approve_variance (admins see all).
  variances: {
    list: (): Promise<PendingVariance[]> =>
      request<{ items: PendingVariance[] }>('/admin/variances').then((r) => r.items),
  },
```

- [ ] **Step 4: Add the registry entry**

In `src/admin/navigation.ts`, add between `locations` and `settings` (key order is the sidebar's Operations order):

```ts
  variances: { path: '/variances', permissions: ['shift.approve_variance'] },
```

- [ ] **Step 5: Write the section**

Create `src/admin/variances/VariancesSection.tsx`. **Read `src/admin/places/PlacesSection.tsx` and `src/admin/payments/PaymentMethodsSection.tsx` first** and match their query/401 idiom (`useQuery`, a `useEffect` that calls `onUnauthorized` on a 401 `ApiError`, loading and error paragraphs).

Structure:

- A `CardTitle` heading.
- The standing guidance line, above the table:

  > Variances are approved at a till. Sign in at **any register other than the one listed** — closing a shift signs its own register out, so approval must come from another terminal at the same location.

- `EmptyState` (`{ title, description? }` — check the real props) with "No variances waiting for approval" when the list is empty.
- Otherwise a table over the existing `DataTable` with columns: Register, Location, Opened by, Closed, Expected, Counted, Variance. Format money through the file's existing money helper — **integer cents, never a float, never client-side arithmetic**.

Use only design tokens that exist in `src/styles/carbon.css` (`border-hairline`, `bg-surface-1` exist; `border-border`, `bg-field` do not). **Do not add or edit any shared component.**

- [ ] **Step 6: Wire it into `Shell`**

In `src/admin/Shell.tsx`:

- Import `VariancesSection`.
- `const canApproveVariance = holdsSection('variances', sections)`.
- A query for the count, mirroring the existing low-stock badge query exactly (same `useQuery` shape, `enabled` on the permission, count off `.length`):

```tsx
  const variancesQuery = useQuery({
    queryKey: ['admin', 'variances'],
    queryFn: () => api.variances.list(),
    enabled: canApproveVariance,
  })
  const pendingVarianceCount = variancesQuery.data?.length ?? 0
```

- The nav item in the Operations section, after Locations & Registers, carrying the badge the same way Today carries low stock:

```tsx
        ...(canApproveVariance
          ? [{
              key: 'variances',
              label: 'Variances',
              href: pathForSection('variances'),
              count: pendingVarianceCount > 0 ? pendingVarianceCount : undefined,
            }]
          : []),
```

- The render case: `{section === 'variances' && <VariancesSection onUnauthorized={onUnauthorized} />}`.

- [ ] **Step 7: Verify**

```bash
cd frontend/back-office && npm test && npm run typecheck && npm run build
```
Expected: all green. If `Shell.test.tsx`'s `ALL_SECTIONS` fixture asserts a nav-item count, update it — the list legitimately grew.

- [ ] **Step 8: Commit**

```bash
git add frontend/back-office
git commit -m "feat(back-office): pending variances queue with a sidebar count"
```

---

## Task 3: Docs

**Files:**
- Modify: `docs/03-api.md`, `docs/05-rbac.md`, `docs/06-roadmap.md`, `CLAUDE.md`, `docs/user-manual/user-manual.md`

- [ ] **Step 1: `docs/03-api.md`** — document `GET /admin/variances` in the back-office section: the shape, that it is gated on `shift.approve_variance`, that it is scoped to the caller's held locations with no `location_id` parameter, and the three conditions that make a variance pending. Note that approval remains `POST /shifts/{shift}/approve-variance` on the register tier.

- [ ] **Step 2: `docs/05-rbac.md`** — record that `shift.approve_variance` is now in `AdminAccess::SECTIONS`, and *why*: it is the first register-tier, money-leaves permission to appear there, which widens `SECTIONS` from "admin-only capability" to "an admin-tier surface exists for this". Supervisors hold it and are exactly the audience for the queue.

- [ ] **Step 3: `docs/06-roadmap.md` and `CLAUDE.md` Status** — an entry in the shape of the neighbouring ones: what shipped, the one thing worth remembering (the view deliberately does **not** link to the offending register, because `CloseShift` revokes that register's staff sessions and approval must come from another terminal at the same location), that approval stays register-side because the audit trail is register-attributed, and the final suite counts.

- [ ] **Step 4: `docs/user-manual/user-manual.md`** — a short passage in the shifts/cash chapter: where to find the queue, what it lists, and — most importantly — that you approve from a *different* till than the one shown. Add a Revision History row. **Do not rebuild the PDF**; CI does it on push under `docs/user-manual/**`.

- [ ] **Step 5: Commit**

```bash
git add docs CLAUDE.md
git commit -m "docs: pending variances queue"
```

---

## Final verification

- [ ] `make test` — all three suites
- [ ] `make e2e` — unaffected, but must stay green
