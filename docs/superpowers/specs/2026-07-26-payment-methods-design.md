# Payment methods — design

An admin-managed, per-location **tender taxonomy**: a *payment method group* holds a
group code and one driver; a *payment method* holds a method code and belongs to exactly
one group. Both codes are unique per location. The till renders the location's active
methods as tender buttons; every payment and refund records the method it was taken on,
and the Z-report and the back-office sales report break down by method and by group.

The `PaymentDriver` seam is untouched. Groups and methods are the layer *above* it:
admins name and organize tenders, code still decides behaviour.

## Why this exists

Today a tender is one of two hardcoded strings. `payments.driver` is `'cash'` or
`'external_card'`, `TakePaymentRequest` validates `in:cash,external_card`, and the till
renders a two-button toggle. That is enough to move money and not enough to run a store:
"external_card ₱8,900" on a Z-report does not tell a supervisor how much came in on
GCash versus Visa, a card-only kiosk and a cash-only stall cannot be configured
differently, and adding a tender that behaves exactly like an existing one — a second
e-wallet, a meal voucher — requires a deploy.

Groups and methods make the taxonomy data. Adding GCash becomes a row an admin writes,
because it is a *name* for behaviour that already exists in code. Adding Stripe Terminal
is still a driver class, because it is genuinely new behaviour.

## Constraints inherited from the codebase

- **Money is integer cents**, `bigint`/PHP `int`, wire suffix `_cents`; all arithmetic
  through `App\Domain\Money\Money`. (`docs/01-architecture.md`)
- **One action = one route = one controller = one Action class**; actions take an Input
  DTO, return a domain object, never touch HTTP. (`docs/04-backend-conventions.md`)
- **The payment driver contract is `authorize`/`capture`**, drivers resolve from
  `DriverRegistry` keyed by `code()`, and `Capabilities` declares refundability.
  (`docs/01-architecture.md`)
- **Financial records are append-only.** `payments.amount_cents` is immutable once
  written; a correction is a void plus a new row. (`docs/00-overview.md`)
- **Order lines snapshot** name, SKU, price and tax rate so a receipt reprints
  identically years later. A tender's method name is receipt-facing and gets the same
  treatment.
- **Archive, never delete.** There is no `DELETE` route anywhere under `/admin/*`;
  deactivation is `PATCH { "is_active": false }`. (`docs/03-api.md`)
- **Back-office authorization is `AdminAccess::holdsAnywhere`, never bare `can()`** — no
  permission team context exists on an admin request; `FormRequest::authorize()` goes
  through `AuthorizesBackOffice::allowsBackOffice()`. (CLAUDE.md gotcha)
- **A new admin section is a new permission** in `AdminAccess::SECTIONS`, plus one entry
  in the back office's `SECTION_DEFS` registry.
- **Push invariants into Postgres** where it can express them; application checks are the
  fallback. (`docs/01-architecture.md`)
- **Shared UI is byte-identical** between `frontend/web` and `frontend/back-office`; a
  visual pattern that exists twice is a component. (root `DESIGN.md`)

## Data model — two new tables

```sql
create table payment_method_groups (
  id          uuid primary key default uuidv7(),
  location_id uuid not null references locations(id),
  code        text not null,          -- 'CASH', 'CARD', 'EWALLET' — immutable after create
  name        text not null,          -- 'Cash', 'Cards', 'E-wallets' — editable
  driver      text not null,          -- the code seam: 'cash' | 'external_card'
  sort_order  integer not null default 0,
  is_active   boolean not null default true,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);

create unique index payment_method_groups_code
  on payment_method_groups (location_id, code);

-- Exists only so payment_methods can point at (id, location_id) as a composite FK.
create unique index payment_method_groups_id_location
  on payment_method_groups (id, location_id);

alter table payment_method_groups add constraint payment_method_groups_driver
  check (driver in ('cash','external_card'));

create table payment_methods (
  id          uuid primary key default uuidv7(),
  location_id uuid not null references locations(id),
  group_id    uuid not null,
  code        text not null,          -- 'CASH', 'VISA', 'GCASH' — immutable after create
  name        text not null,
  sort_order  integer not null default 0,
  is_active   boolean not null default true,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),

  -- "a method's group is at the method's location", in the schema rather than in trust.
  foreign key (group_id, location_id)
    references payment_method_groups (id, location_id)
);

create unique index payment_methods_code on payment_methods (location_id, code);
create index payment_methods_group on payment_methods (group_id);
```

`location_id` is duplicated onto `payment_methods` deliberately: the uniqueness rule is
"unique per location", so the column has to be on the table the index is on. Duplicating
it would normally invite drift, which is exactly why the composite foreign key is there —
a method whose `location_id` disagrees with its group's is rejected by Postgres, not by a
validator someone can forget to run.

**The group is the behavioural bucket.** It names one driver; its methods are variants
that behave identically. `CARD (external_card)` → Visa, Mastercard. `EWALLET
(external_card)` → GCash, Maya. Same driver, different groups, because a supervisor
reconciling a drawer wants those totals apart.

### What cannot be edited, and why

- **`payment_methods.group_id` is immutable.** The group carries the driver, so moving a
  method between groups would silently change how the method behaves *and* retroactively
  re-bucket every historical payment taken on it. Changing a tender's behaviour is an
  archive-and-recreate.
- **Both `code` columns are immutable.** A code is a wire identifier the till sends and a
  report key rows are grouped by. Names are display copy and stay editable.

Neither is expressed as a database constraint — Postgres cannot forbid an `UPDATE` of one
column without a trigger, and the project has no triggers. The update actions simply
never accept those keys, and tests pin that.

### Columns added to `payments` and `refunds`

Both tables gain the same three:

```sql
payment_method_id   uuid not null references payment_methods(id),
payment_method_code text not null,      -- snapshot, receipt-facing
payment_method_name text not null,      -- snapshot, receipt-facing
-- driver text not null                 -- KEPT, derived from the group at write time
```

Code and name are snapshotted for the reason order lines snapshot price: renaming "GCash"
to "GCash QR" must not change what a receipt printed last year says. `payment_method_id`
stays for joins that legitimately want current data — the group a method belongs to, for
instance, which is safe to reach through because `group_id` is immutable.

**`driver` survives as a derived column.** It is written from the resolved group at
payment time and never read from the request again. Keeping it means all of `ShiftTotals`
needs no change at all — both `expectedCashCents` (which filters `driver = 'cash'` on
`payments` *and* on `refunds`) and `salesSummary` (which groups by it and reads the `cash`
key) keep working as written — and the `payments_change_balances` check constraint is
untouched. Dropping the column to "avoid duplication" would buy a rewrite of the
cash-accountability path in exchange for nothing.

### What the schema now refuses to allow

Two additions to the list in `docs/02-data-model.md`:

- A method cannot belong to another location's group. (Composite foreign key.)
- Two methods, or two groups, cannot share a code at one location. (Unique index.) The
  same code at two *different* locations is legal and expected.

## Domain — one resolver, drivers untouched

```php
final readonly class ResolvedPaymentMethod
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $groupCode,
        public string $driver,
    ) {}
}

final class PaymentMethodResolver
{
    public function resolve(string $locationId, string $code): ResolvedPaymentMethod;
}
```

`resolve()` throws `PaymentMethodUnknown` → `422 payment_method_unknown` when no method
at that location carries the code, and `PaymentMethodInactive` →
`422 payment_method_inactive` when the method or its group is archived. Two codes, not
one: "you turned this off yesterday" and "no such tender here" are different problems for
whoever reads the error.

`TakePayment` and `RefundOrder` call the resolver inside their existing transaction and
hand `$resolved->driver` to `DriverRegistry` exactly as they hand `$in->driver` today.
`PaymentDriver`, `Capabilities`, `CashDriver`, `ExternalCardDriver` and `DriverRegistry`
are unchanged — adding a processor is still a class plus a registry entry.

One rule moves to a better home. Refunds currently reject cards with
`'driver' => ['required', 'in:cash']` in `RefundOrderRequest`. That becomes a check of
`Capabilities::refundable` on the resolved method's driver inside the action →
`422 refund_method_not_refundable`. The rule now lives where the capability is declared
instead of being restated as a validation string, and it will keep working for a future
driver without anyone editing a rule list. `ExternalCardDriver::refund()`'s "unreachable"
`LogicException` stays as the backstop it already is, with its comment updated to name
the new gate.

## API

### Till-facing

`GET /catalog` gains one key. The action is already location-aware and its own doc
comment argues for a single denormalized payload over extra round-trips, so the till's
method list rides along rather than getting an endpoint of its own — a tender button
appearing is the same freshness story as a price change.

```
GET /api/v1/catalog
  → { ..., payment_methods: [
        { id, code, name, group_code, group_name, driver, sort_order }
      ] }
```

Active methods in active groups only, ordered by group `sort_order`, then group `code`,
then method `sort_order`, then method `code` — a total order, so two methods sharing a
sort value do not render in a different sequence per request.

```
POST /api/v1/orders/{id}/payments             # Idempotency-Key REQUIRED, If-Match
  { "payment_method_code": "GCASH", "amount_cents": 5000, "reference": "0917..." }
  → { payment: { ..., payment_method_code, payment_method_name, driver }, order: {...} }

POST /api/v1/refunds                          # Idempotency-Key required, supervisor
  { "original_order_id": "...", "payment_method_code": "CASH", "reason": "...",
    "lines": [ ... ] }

GET  /api/v1/orders/{id}/receipt
  → payments[] carry payment_method_code and payment_method_name (snapshots)

GET  /api/v1/reports/z?shift_id=
  → { shift, sales_by_method, sales_by_group, refunds_by_method, refunds_by_group,
      movements, orders_closed, orders_voided, orders_split, expected_cash_cents }
```

`driver` leaves the request body of both endpoints and `sales_by_driver` /
`refunds_by_driver` leave the Z-report response. This breaks the current register
payloads and the e2e scripts; all of them are ours and are updated in the same change.
Keys of the four Z maps are codes, and only codes with activity appear — the shape the
existing driver maps already had.

### Back office

Gated `payment_method.manage`. No `DELETE`; archiving is `PATCH { "is_active": false }`.

```
GET   /api/v1/admin/payment-method-groups?location_id=
POST  /api/v1/admin/payment-method-groups
        { location_id, code, name, driver, sort_order }
PATCH /api/v1/admin/payment-method-groups/{group}
        { name?, sort_order?, is_active? }          # code, driver, location_id refused

GET   /api/v1/admin/payment-methods?location_id=
POST  /api/v1/admin/payment-methods
        { location_id, group_id, code, name, sort_order }
PATCH /api/v1/admin/payment-methods/{method}
        { name?, sort_order?, is_active? }          # code, group_id, location_id refused
```

Both lists are unpaginated, like every other admin list in v1. Mutations audit
`admin.payment_method_group.create|update` and `admin.payment_method.create|update`.
Every `authorize()` goes through `AuthorizesBackOffice::allowsBackOffice()`, and the
`location_id` — query param on a list, body key on a create, the row's own value on an
update — is checked against `AdminAccess::locationIdsWhere()`, the same way the two
report endpoints already scope theirs. Holding `payment_method.manage` somewhere is what
gets a non-admin into the section; it is not a blank cheque over every store's tenders.

A group's `driver` is refused on `PATCH` for the same reason `group_id` is refused on a
method: it is the behaviour, and behaviour is archive-and-recreate.

### Sales report

```
GET /api/v1/admin/reports/sales?location_id=&from=&to=&group_by=payment_method
  → { rows: [ { method_code, method_name, group_code, group_name,
                gross_cents, refunds_cents, net_cents } ],
      totals, basis: "ledger" }                     # gated report.sales.view
```

Ledger-basis, like `group_by=day` and `group_by=user` — summed from captured `payments`
and from `refunds`, i.e. money that actually moved. It groups on the **snapshot** columns,
so a renamed method does not retroactively rewrite last month's rows, and joins to the
live tables only for `group_code`/`group_name`. Filtering stays on `business_date`.

### Archiving semantics

Archiving a group hides its methods from the till without touching their rows — the
catalog query filters on both `is_active` flags. That is one switch for "we have stopped
taking cards" instead of five, and re-activating the group brings back exactly the methods
that were live before.

A location with no active methods is a legal configuration that cannot take payment. No
constraint refuses the last archive; the till shows an empty state naming the back office
instead. A constraint here would mean the system refusing an admin's correct answer for a
card-only kiosk, and it would have to be enforced against a state — "zero active rows" —
that a partial index cannot express anyway. Zero-total orders still settle through
`POST /orders/{id}/settle`, which involves no tender.

## Back office UI

One new section, at the cost the registry documents: one entry in `SECTION_DEFS`, one
sidebar item, one render case in `Shell`.

```ts
'payment-methods': { path: '/payment-methods', permissions: ['payment_method.manage'] }
```

Scoped by the sidebar location switcher that already exists — the screen shows one
location's tenders, and switching location reloads it, like Registers and Reports.

Master–detail on one screen: groups listed with code, name, a driver badge and a
`StatusPill` for active/archived; each group's methods nested beneath it in sort order.
Create and edit dialogs and archive toggles built from the shared `ui/*` set,
`ConfirmDialog` for archiving, `EmptyState` when a location has no groups yet. Immutable
fields render read-only on an edit dialog rather than being absent, so an admin can see
the code they cannot change.

## Register UI

`SaleScreen`'s hardcoded Cash/Card toggle is replaced by buttons generated from
`catalog.payment_methods`, in the admin's order, under group-name headings when the
location has more than one group. The selected method's `driver` picks the input, which is
the same branch the screen has today, keyed off resolved data instead of a literal:

- `cash` → cash-handed-over field, server-computed change on the outcome screen
- `external_card` → optional reference field, "recorded on the card terminal" copy

Nothing else about the tender flow, the split flow, or the idempotency-key handling moves.
When the location has no active methods the tender zone shows an empty state naming the
back office instead of an unusable button row.

## Provisioning a location's defaults

`App\Domain\Payments\PaymentMethodProvisioner` writes one location's default set — group
`CASH` (driver `cash`) with method `CASH`, group `CARD` (driver `external_card`) with
method `CARD` — and is idempotent, skipping any code that already exists at that location.

It is called from three places: the backfill migration, `CreateLocation`, and the
`provisionedLocation()` test helper.

`CreateLocation` matters most. RBAC v2 fixed a confirmed bug where a location created in
the back office got no roles provisioned and was therefore unusable; a location with no
payment methods is the same bug with a different noun — every tender at it would 422.
Roles have `RoleProvisioner` for exactly this reason, and tenders now have the
counterpart.

## Migration and seeding

One migration, in order:

1. Create both tables with their indexes and constraints.
2. Provision the default set into every existing location (the same `CASH`/`CARD` pairs
   `PaymentMethodProvisioner` writes, inlined as SQL — a migration must not depend on a
   domain class that may change under it).
3. Add the three columns to `payments` and `refunds`, nullable.
4. Backfill: join each row to its location (`payments` through `orders`, `refunds`
   directly) and set the method matching the row's existing `driver`.
5. `set not null` on all six columns.

The old `driver` values are not touched. Step 2 before step 4 is what makes step 5 safe:
every historical row has a method to point at, because the pair it needs is created
first.

The seeder gives each Manila location the same set: `CASH` → Cash; `CARD` → Visa,
Mastercard; `EWALLET` → GCash, Maya. PH-realistic, and it means the till renders a real
multi-group layout out of the box rather than a two-button special case that hides
grouping bugs. The set is small and shared across locations, so it lives in a seeder class
rather than joining the committed JSON under `database/seeders/data/`.

## RBAC

`payment_method.manage` joins `Permissions::all()` and `Permissions::grouped()` under
Administration, and `AdminAccess::SECTIONS`. Granted by no default role — same shape as
`day.close`, `catalog.manage` and the other admin-tier permissions: only admins do this,
and admins bypass the gate entirely. It is **not** in `moneyLeaves()`; configuring a
tender name does not move money out of a till, and every payment taken on a method is
still gated by `payment.take` and recorded against a user and a shift.

## Testing

**Backend.** `PaymentMethodResolver`: unknown code, archived method, archived group,
another location's code (all 422, distinct codes). `TakePayment`: cash and card paths both
write `payment_method_id` plus both snapshots and derive `driver` from the group; a method
from another location is refused. `RefundOrder`: a method whose driver is not refundable
is refused by capabilities, and cash still refunds. Admin CRUD: create, update, archive;
duplicate code at one location refused; the *same* code at two locations accepted; a group
from another location refused by the composite FK; `code`, `driver` and `group_id` refused
on `PATCH`. Reports: Z grouped by method and by group across two groups sharing a driver;
`group_by=payment_method` on snapshot columns, including a method renamed after the sale.
RBAC: non-holder 403, holder at another location refused its `location_id`, `is_admin`
exempt. `PaymentMethodProvisioner`: writes the default set, is idempotent on a second
call, and a location created through `POST /admin/locations` can take a cash payment
immediately. Migration backfill is covered by the suite running against a seeded database.

`tests/Arch/` must stay green — new actions are `final`, take Input DTOs, touch no HTTP,
and no `env()` appears outside `config/`.

**Register.** Buttons render from the catalog payload in sort order with group headings;
the input follows the driver; the payment posts `payment_method_code`; the empty state
appears when the location has no methods.

**Back office.** Section hidden without the permission and visible with it; the registry
entry resolves `/payment-methods`; the CRUD screen creates, edits and archives.

**End to end.** `e2e-retail-day.sh` and `e2e-lunch-service.sh` post `payment_method_code`
(GCash on one tender in the lunch script, so a non-cash e-wallet crosses the wire at
least once). `e2e-admin-day.sh` gains a beat: create a group and a method, see it in the
catalog the till fetches, take a payment on it, read it back on the Z-report.

## Documentation

- `docs/02-data-model.md` — a Payment methods section ahead of Payments, the three
  columns on both ledger tables, and the two new entries under "What the schema refuses
  to allow".
- `docs/03-api.md` — the two request shapes, the catalog key, the four Z fields, the
  admin CRUD block, the new `group_by`, and the three new error codes.
- `docs/01-architecture.md` — the payment driver contract section gains a paragraph:
  groups and methods are the admin-facing taxonomy above the seam; a new *name* for
  existing behaviour is a row, a new behaviour is still a driver class.
- `docs/05-rbac.md` — `payment_method.manage`: admin-tier, no default role, doubles as
  the back-office section.
- `docs/06-roadmap.md` — a "Payment methods complete" record.
- `CLAUDE.md` — a Status entry.
- `docs/user-manual/user-manual.md` — a cashier passage on choosing a tender and an admin
  passage on configuring groups and methods, plus `glossary.md` entries for both terms.
  Screenshots recaptured with `make manual-shots`; `.github/workflows/manual.yml` rebuilds
  the PDF.

The GitHub wiki regenerates from `docs/` in CI — edited here, never there.
