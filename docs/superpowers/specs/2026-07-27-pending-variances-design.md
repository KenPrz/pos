# Pending variances — design

A read-only back-office queue of closed shifts whose drawer variance is over threshold and
not yet signed off, so a supervisor can see **which** drawers need approval without logging
into each register to find out.

Approval itself stays in the register app. This ships the missing half: knowing where to go.

## Why this exists

Variance approval is a register action (`POST /shifts/{shift}/approve-variance`), and the
only way to discover an unapproved variance today is to be standing at a till. With several
registers running, a supervisor has no list — they log into each one in turn.

Worse, the obvious instinct is wrong in a way that wastes the trip. From `CLAUDE.md`'s
gotchas:

> Approving a variance from the register that just closed will 401 — `CloseShift` revokes
> every staff session bound to that register.

`ApproveVariance` scopes by **location**, not register: it resolves the acting register's
location and then finds the shift within it. So approval works from *any other* till at that
location, and only from the offending one does it fail. A supervisor who walks to the
register showing the variance hits a dead end.

That is almost certainly the confusion this feature is meant to remove, so the view must say
where to approve from — not merely list what needs approving. **It deliberately does not link
to the offending register**, because that is the single place the action cannot be taken.

## Constraints inherited from the codebase

- **Money is integer cents** (`bigint`/PHP `int`), wire suffix `_cents`. Never a float.
- **One action = one route = one controller = one Action class**; actions take an Input DTO,
  return a domain object, never touch HTTP. (`docs/04-backend-conventions.md`)
- **Admin `FormRequest::authorize()` never calls bare `can()`** — no permission team context
  exists on an admin request. It goes through `AuthorizesBackOffice::allowsBackOffice()`, and
  a location-scoped request re-checks the specific location via
  `AdminAccess::locationIdsWhere()`. (CLAUDE.md gotcha)
- **A new admin section is a new entry in `AdminAccess::SECTIONS`** plus one in the back
  office's `SECTION_DEFS` registry, which owns URL ↔ section ↔ permission in one place.
- Admin lists are unpaginated in v1; `GET` lists are read-only — no transaction, no audit.
- **The shared UI set is byte-identical** between `frontend/web` and `frontend/back-office`;
  this work adds no components and touches none of them.
- Financial records are append-only; this feature only reads.

## What "pending" means

Taken directly from `ApproveVariance`'s own guards, so there is exactly one definition of
"needs approval" in the system rather than two that can drift:

```
closed_at IS NOT NULL                    -- an open shift is not yet countable
AND abs(variance_cents) > threshold      -- at or under threshold needs no sign-off
AND variance_approved_at IS NULL
```

`threshold` is `locations.variance_approval_threshold_cents`, falling back to
`config('pos.shifts.variance_approval_threshold_cents')` when the location sets none.
`GetBusinessDay` already resolves it exactly this way; this reuses that shape rather than
inventing a third.

Note the boundary is **strictly greater than**, matching `ApproveVariance`'s
`abs(...) <= $threshold` rejection. A variance exactly at the threshold is not pending, and
listing it would offer an approval the API refuses with
`422 variance_approval_not_required`.

## Permissions — no new permission

Supervisors already reach the back office: they hold `report.sales.view`, which is in
`AdminAccess::SECTIONS`. And they already hold **`shift.approve_variance`** — the permission
that names this exact capability.

So the section is gated on `shift.approve_variance`, which is added to
`AdminAccess::SECTIONS`. No new permission, no change to any default role, and the people who
can act on a variance are exactly the ones who can see the queue.

`AdminAccess::locationIdsWhere($user, 'shift.approve_variance')` then scopes the rows: a
supervisor at one store never sees another store's drawer counts. `is_admin` bypasses, as
everywhere.

This does mean `SECTIONS` gains a permission that is also a register-tier, money-leaves one.
That is a deliberate widening of what `SECTIONS` means — "an admin-tier surface exists for
this" rather than "this is an admin-only capability" — and it is the honest modelling here,
because the audience for the queue is precisely the role that approves.

## API

```
GET /api/v1/admin/variances                       # gated shift.approve_variance
  → { items: [ {
        shift_id, register_id, register_name,
        location_id, location_name,
        opened_by_name, opened_at, closed_at,
        expected_cash_cents, counted_cash_cents, variance_cents,
        threshold_cents
      } ] }
```

Unpaginated, ordered by `closed_at` descending — the most recent close is the one a
supervisor is most likely acting on. `threshold_cents` is returned per row so the client can
show *why* a row qualifies without re-deriving a rule the server owns.

No `location_id` query parameter: the list is already scoped to everywhere the caller holds
the permission, and a supervisor at one store has nothing to filter. If a multi-store admin
later wants narrowing, that is a client-side filter over a list that is already small.

## Back office

One section, at the registry's documented cost — one `SECTION_DEFS` entry, one sidebar item,
one render case in `Shell`:

```ts
variances: { path: '/variances', permissions: ['shift.approve_variance'] }
```

The sidebar item carries a **count badge**, reusing the pattern the low-stock badge already
established in `Shell`. The count is what makes this discoverable: a supervisor sees that two
drawers need attention without navigating anywhere, which is the actual problem being solved.

The page is a table over the existing `DataTable`, with `EmptyState` when nothing is pending
("No variances waiting for approval"). Each row shows register, who opened the shift, the
variance, and when it closed.

Above the table, one line of standing guidance:

> Variances are approved at a till. Sign in at **any register other than the one listed** —
> closing a shift signs its own register out, so approval must come from another terminal at
> the same location.

That sentence is the feature. Without it the queue tells a supervisor where to walk and lets
them find the dead end themselves.

## Testing

**Backend.** The three conditions each excluded independently: an open shift, an
at-or-under-threshold variance, an already-approved one. A location that overrides the
threshold and one that falls back to config. Per-location scoping for a non-admin holder, and
`is_admin` seeing every location. Ordering by `closed_at` descending.

**Back office.** The section is hidden without `shift.approve_variance` and visible with it;
the badge shows a count and disappears at zero; the table renders a row; the empty state
appears when nothing is pending.

## What this deliberately does not do

- **No approval from the back office.** Beyond the staff-session complexity, `ApproveVariance`
  audits with a `registerId` and the whole variance trail is register-attributed; approving
  from a location-less admin session would leave a hole in it. That is a real design question,
  not a port.
- **No link to the offending register** — see above; it is the one place approval fails.
- No filtering, no pagination, no date range. The queue is small by construction: it drains
  as supervisors work it.
