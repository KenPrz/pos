# End Of Day — design

A location-scoped **business-day close** for the back office: a manager reconciles the
day's registers, records the bank deposit and a fixed operational checklist, and freezes
an immutable, self-contained day record. Closing a day forbids exactly one thing —
opening a new shift at that location on that date — and is reversible only by an admin.

The register app is untouched. Everything reuses M2–M6 machinery (`ShiftTotals`, the
day-basis `SalesReport`, `AdminAccess`, the audit log, the sidebar location switcher).

## Why this exists

Today the smallest accountable unit is one register's shift: count the drawer, record
variance, revoke the staff sessions (`CloseShift`). There is no layer above that — nothing
that says "location L's day D is done, here are the consolidated totals, here's the cash
that went to the bank, and nobody may reopen the day by starting a shift on it." EOD is
that layer. It is a manager reconciliation, an immutable audit record, and a fixed
checklist, in one action.

## Constraints inherited from the codebase

- **Money is integer cents**, `bigint`/PHP `int`, wire suffix `_cents`. All arithmetic
  through `App\Domain\Money\Money`. (`docs/01-architecture.md`)
- **One action = one route = one controller = one Action class**; actions take an Input
  DTO, return a domain object, never touch HTTP. (`docs/04-backend-conventions.md`)
- **`business_date` is the local calendar day** at the location, already stored on every
  order and computed from the location timezone at open. EOD groups on it and never
  re-derives a timezone conversion. (`docs/02-data-model.md`)
- **Back-office authorization is `AdminAccess::holdsAnywhere`, never bare `can()`** — no
  permission team context exists on an admin request. Admin `FormRequest::authorize()`
  goes through `AuthorizesBackOffice::allowsBackOffice()`. (CLAUDE.md gotcha)
- **Sections are permission strings** in `AdminAccess::SECTIONS`; `sectionsFor()`
  intersects held permissions with that list and the login response returns `sections[]`.
  A new admin section *is* a new permission added to `SECTIONS`.
- **Financial ledgers are append-only.** The `business_days` row is a *reconciliation
  snapshot*, not a ledger entry — it reads from the ledgers, never mutates them.

## Data model — `business_days`

```sql
create table business_days (
  id                  uuid primary key default uuidv7(),
  location_id         uuid not null references locations(id),
  business_date       date not null,              -- local day at the location
  closed_by           uuid not null references users(id),
  closed_at           timestamptz not null default now(),

  -- snapshot, read from the ledgers at close so the row is self-contained.
  -- an auditor reads these columns; they never re-query the live ledgers.
  gross_sales_cents   bigint not null,
  refunds_cents       bigint not null,
  net_sales_cents     bigint not null,
  tax_cents           bigint not null,
  expected_cash_cents bigint not null,   -- sum over the day's shifts
  counted_cash_cents  bigint not null,   -- sum over the day's shifts
  variance_cents      bigint not null,   -- counted - expected
  shift_count         int    not null,

  deposit_cents       bigint not null default 0 check (deposit_cents >= 0),
  checklist           jsonb  not null,   -- fixed keys, see Checklist below
  note                text,

  reopened_at         timestamptz,
  reopened_by         uuid references users(id),

  check ((reopened_at is null) = (reopened_by is null)),
  unique (location_id, business_date)
);
```

The `unique (location_id, business_date)` is the entire "close a day once" invariant —
structural, in the taste of `one_open_shift_per_register`. The paired `check` on
`reopened_*` matches the shift table's `variance_approved_*` pairing.

**A business day is closed iff a row exists AND `reopened_at is null`.** Reopen sets
`reopened_at`/`reopened_by` (never deletes — the row is a record). Re-closing after a
reopen **updates the same row**: re-snapshots the totals from the (possibly changed)
ledgers and clears `reopened_at`. Reopen is exceptional by design, so the single-row
mutation is acceptable and the audit log carries the full close→reopen→close history.

### Checklist (fixed keys on the jsonb)

```json
{
  "cash_drop_confirmed": true,
  "spoilage_note": "2 trays pandesal",
  "next_day_note": "reorder rice"
}
```

Fixed keys, no new table, no config UI. `deposit_cents` is its own column (money), not a
checklist key. **Skipping an item is allowed and recorded** — the manager owns the call;
the row shows exactly what they did. (Deferred: admin-configurable checklist items — a
`checklist_items` CRUD — until a store actually asks.)

## Actions (`app/Actions/Admin/Day/`)

### `CloseBusinessDay`

Input: `locationId`, `businessDate`, `depositCents`, `checklist`, `note`, `actorId`.
In one transaction:

1. **Assert every shift at the location is closed.** Any register at the location with an
   open shift → `HasOpenShifts` (blocks). This is the reconciliation precondition.
2. **Assert zero open orders at the location for that `business_date`** →
   `HasOpenOrders`. `CloseShift` already guarantees no open order outlives its shift, so
   step 1 transitively covers this; step 2 is a cheap belt-and-suspenders check.
3. **Snapshot totals** from the ledgers: gross/refunds/net/tax from the same source the
   day-basis `SalesReport` uses; `expected/counted/variance` summed over the day's shifts
   via `ShiftTotals`; `shift_count`.
4. **Upsert the row** on `(location_id, business_date)`: insert, or if a reopened row
   exists, re-snapshot and clear `reopened_at`/`reopened_by`.
5. **Audit `day.close`** with the snapshot totals and the deposit.

Unapproved variances **warn, they do not block** — same philosophy as variance itself (a
day that refuses to close over an unsigned $0.02 gets closed by other means, and then
there is no record). The preview surfaces them; the manager decides.

### `ReopenBusinessDay`

Input: `locationId`, `businessDate`, `reason` (required), `actorId`. `is_admin` only.
Sets `reopened_at`/`reopened_by`; audits `day.reopen` with the reason. This is the only
thing that un-forbids opening a shift on a closed date.

### `GetBusinessDay`

Read-only, powers the EOD screen for a `(location, date)`:
- per-register shift breakdown (open/closed, variance, approval state),
- open-order count,
- consolidated snapshot totals (computed live when the day is still open; read from the
  row once closed),
- a computed `closable` boolean + a `blockers[]` list (open shifts, open orders) and a
  `warnings[]` list (unapproved variances),
- the close record itself (`closed_by`, `closed_at`, deposit, checklist, note,
  reopened state) when a row exists.

## The one guard — `OpenShift`

`OpenShift` gains a single check: a closed `business_days` row for
`(location_id, today's business_date)` → throw `DayClosed` → **`409 day_closed`**. This
is the entire write-path footprint of the feature. Nothing else is forbidden:
`ApproveVariance` on that day's shifts stays legal (blocking it strands a closed shift's
variance), refunds and reports are unaffected.

## API (back office, admin auth)

```
GET  /api/v1/admin/locations/{location}/day?date=YYYY-MM-DD   GetBusinessDay
POST /api/v1/admin/locations/{location}/day/close             CloseBusinessDay
POST /api/v1/admin/locations/{location}/day/reopen            ReopenBusinessDay   (is_admin)
GET  /api/v1/admin/locations/{location}/days                  list history (the record)
```

- New permission **`day.close`**, added to `Permissions` and to `AdminAccess::SECTIONS`
  (so it is simultaneously the authorization and the nav section). Not a MONEY_LEAVES
  permission — closing a day moves no cash.
- Every admin `FormRequest::authorize()` here goes through
  `AuthorizesBackOffice::allowsBackOffice()`; `GetBusinessDay`/close gate on `day.close`,
  `reopen` gates on `is_admin`.
- `docs/03-api.md` and `docs/05-rbac.md` updated: the endpoint list and the permission
  catalog are the same list, and a new `day_closed` error code is registered.
- `date` defaults to the location's local today when omitted.

## Frontend — back office only (`frontend/back-office/`)

One new **"End of Day"** section, shown only to holders of `day.close` (driven by the
existing `sections[]` on the login response). One page:

- **Location** via the existing sidebar switcher; **date** via native `<input type="date">`
  (defaults to today). No date-picker dependency.
- **Blockers panel** — open shifts and open orders, each linking to where they're
  resolved; unapproved variances shown as a non-blocking warning.
- **Consolidated Z** — the day's totals table, reusing the day-report presentation
  components already in the back office.
- **Checklist form** — the fixed items + a deposit-cents money input + a note.
- **Close** — disabled while any blocker is present; confirms through the shared
  `ConfirmDialog` (not `window.confirm`).
- **Already-closed date** renders the read-only record; admins see a **Reopen** button
  (reason required) behind the same `ConfirmDialog`.

Shared-component rule applies: any `carbon.css` / `ui/*` / `StatusPill` / `EmptyState` /
`ConfirmDialog` edit is byte-identical across both frontends or made in neither. This
feature is expected to add no shared components (it composes existing ones).

## Error codes (new)

| Code | HTTP | When |
| --- | --- | --- |
| `day_closed` | 409 | `OpenShift` on a location+date whose business day is closed |
| `day_has_open_shifts` | 409 | `CloseBusinessDay` with a register still open at the location |
| `day_has_open_orders` | 409 | `CloseBusinessDay` with an open order at the location for that date |

## Testing

Backend feature tests (`tests/Feature/Admin/Day/`):
- close happy path — asserts the snapshot totals equal an independent ledger computation
  **at both tax modes** (VAT-inclusive and exclusive locations);
- blocked by an open shift; blocked by an open order;
- `OpenShift` → `409 day_closed` once the day is closed;
- reopen (admin) clears the block; a non-admin reopen is `403`;
- `unique (location_id, business_date)` — a second close on an already-closed, un-reopened
  day is rejected;
- `day.close` permission gate (holder in, non-holder out) via `AdminAccess`.

`tests/Arch/` applies unchanged (actions final, no HTTP in actions, no `env()` outside
config, strict types). Back-office: component tests for the EOD page + `npm run typecheck`
+ `npm run build`.

**e2e** — extend `scripts/e2e-admin-day.sh`: after the retail day at GRC, close the
business day, assert `OpenShift` returns `409 day_closed`, reopen it, assert opening a
shift then succeeds.

## Deliberately not built (YAGNI — trigger to revive each)

- **Safe ledger / running cash-on-hand** — deposit is a recorded number + note. Revive
  when floats need to draw from a tracked safe balance rather than being typed at open.
- **Configurable checklist items** (`checklist_items` CRUD) — fixed keys until a store
  asks for a different list.
- **Hard freeze** — variance approval stays legal on a closed day; a full freeze would
  strand an unsigned shift and make reopen mandatory rather than exceptional.
- **Cross-location "close all"** — one location per close, matching the per-location
  report model; a multi-location sweep is a UI convenience over this same action.
- **Row versioning for reopen** — reopen re-snapshots the one row; the audit log is the
  history of every close and reopen.
