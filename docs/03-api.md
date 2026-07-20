# API

REST/JSON under `/api/v1`. Laravel 13 + Sanctum 4.3.

## Ground rules

- Money is **integer cents** in JSON: `{"total_cents": 1234}`. Never a string, never a
  float, never pre-formatted. The `_cents` suffix is mandatory on every monetary field —
  it makes a unit error visible in code review instead of in a customer's total.
- Quantities are **strings**: `{"qty": "0.500"}`. `numeric(12,3)` does not survive a
  round-trip through IEEE-754, and JS `number` is IEEE-754. This is the one place we
  accept string-typed numerics, and it's deliberate.
- Timestamps are ISO-8601 with offset.
- IDs are UUIDv7 strings.
- **The client never sends a total.** It sends intent ("add 2 of this variant"); the
  server computes and returns the money. A client-supplied total is a price-tampering
  vector and a source of drift, and there is no case where we need one.

## Auth

Two layers for the register (device, then staff), plus a third, independent tier for
the back office — see `01-architecture.md`.

```
POST /api/v1/registers/enroll        # admin-only, one time per device
  → { register_id, device_token }    # long-lived; store in the device keychain
```

Every subsequent request carries `Authorization: Bearer <device_token>`.

```
POST /api/v1/staff/login             # device token + PIN
  { "pin": "1234" }
  → { staff_token, user: { id, name, is_admin, permissions[] }, expires_at }
```

Requests that act on behalf of a person send **both**:

```
Authorization: Bearer <device_token>
X-Staff-Token: <staff_token>
```

The device token alone can read the catalog — a terminal showing the menu before anyone
clocks in is normal. It cannot touch money, and (correction, M4) it cannot look up orders
either; order lookup needs a staff session, per Orders below.

```
POST /api/v1/staff/logout
```

PIN attempts are rate-limited per register: 5 failures → 60s lockout, logged to
`audit_log`. The PIN keyspace is small, so this limiter is load-bearing rather than
decorative.

### Back office

A third, independent tier (M6) — no device, no location, no PIN:

```
POST /api/v1/admin/login
  { "email": "owner@example.com", "password": "..." }
  → { token, user: { id, name, email, is_admin } }   # 401 invalid_credentials

POST /api/v1/admin/logout
```

**Admin-only in v1:** wrong email, wrong password, deactivated, and non-admin all answer
identically (`401 invalid_credentials`) — the same user-enumeration defense as PIN login,
extended to the back office. See `05-rbac.md` for why there's no supervisor/bookkeeper
tier yet. Every request under `/admin/*` (below) carries this token the same way every
other tier carries its own:

```
Authorization: Bearer <admin token>
```

## Errors

One envelope (`01-architecture.md`):

```json
{
  "error": {
    "code": "insufficient_stock",
    "message": "Only 2 units of SKU-1234 remain.",
    "details": { "variant_id": "0199...", "requested": 5, "available": 2 }
  }
}
```

| HTTP | `code` examples |
| --- | --- |
| 400 | `validation_failed` |
| 401 | `invalid_device_token`, `invalid_pin`, `staff_session_expired`, `invalid_credentials` |
| 403 | `forbidden`, `requires_supervisor`, `wrong_location` (mostly structural — location scoping yields 404s; reserved for record/register location disagreements) |
| 404 | `not_found` |
| 409 | `order_version_conflict`, `insufficient_stock`, `shift_already_open`, `order_closed`, `idempotency_key_reused`, `no_open_shift`, `shift_already_closed`, `shift_has_open_orders`, `line_already_voided`, `payment_already_voided`, `payment_shift_closed` |
| 422 | `payment_exceeds_balance`, `refund_exceeds_original`, `refund_amount_zero`, `modifier_group_required`, `modifier_not_applicable`, `line_total_negative`, `transfer_target_no_shift`, `transfer_same_shift`, `variance_already_approved`, `variance_approval_not_required`, `insufficient_tender`, `order_has_payments`, `discount_scope_mismatch`, `order_not_zero`, `pin_already_in_use`, `split_too_fine`, `self_lockout` |
| 429 | `too_many_pin_attempts` |

`code` is stable forever once shipped; clients branch on it. `message` is for humans and
may change freely.

## Idempotency

`Idempotency-Key: <uuidv4>` — honored on the routes carrying the idempotent middleware:
add-line, payments, refunds, shift close, cash movements, and split. The middleware
itself is header-presence-driven (it no-ops when the header is absent); **required**,
enforced by request validation, only on `/payments`, `/refunds`, and `/shifts/close`.
Add-line, split, and cash movements carry the middleware but validate nothing about the
header — sending one is honored and recommended, omitting one is accepted. Semantics in
`01-architecture.md`: replay with a matching body returns the stored response without
re-executing; replay with a different body is `409
idempotency_key_reused`. The key is a **global** primary key, not scoped per route or per
order — reusing one on a genuinely different request anywhere in the system collides the
same way (`01-architecture.md`).

## Optimistic locking

Mutating an order requires the version you read:

```
PATCH /api/v1/orders/{id}/lines/{lineId}
If-Match: 7
```

Stale version → `409 order_version_conflict` with the current state in `details`, so the
client can refetch and reapply without a second round-trip. Every successful order
mutation returns the incremented `version`.

---

## Catalog (read-mostly, device token sufficient)

```
GET /api/v1/catalog?location_id=&updated_since=
  → { categories[], products[], variants[], modifier_groups[], modifiers[], tax_rates[], discounts[] }
```

**One denormalized payload, not five REST resources.** A register needs the whole menu to
render, and five round-trips on a cold start is five chances to half-load a menu.
`updated_since` makes the warm path a small delta.

Prices in this payload are already **resolved for the requested location**
(`variant_location_prices` applied), so the register never implements price resolution.
Pricing logic living in exactly one place is worth the denormalization.

As of M4, `discounts[]` carries the location's active catalog discounts — what a
supervisor can apply, not what's already applied. Applied discounts live on the order
(see Discounts, below).

> **v1 notes:** the location is taken from the enrolled register, never from
> `location_id` — a device that could choose its pricing location would be a tampering
> vector. The parameter exists for the back office (M6). `updated_since` is not yet
> implemented (`modifiers` has no `updated_at` to diff on); registers full-sync.

```
GET /api/v1/catalog/lookup?barcode=012345678905&location_id=
  → { variant }                  # 404 not_found
```

The scanner path. Separate and narrow because it's the hottest read in retail and must
stay a single indexed lookup.

Back-office catalog CRUD lives under `/api/v1/admin/*` — see Back office, below. (An
earlier draft of this doc sketched it here as `/api/v1/products` etc. under an `admin`
role and with `DELETE`; neither survived contact with `05-rbac.md`'s correction that
admin isn't a role, or with M6's archive-never-delete decision. There is no `DELETE`
route anywhere in `/admin/*`.)

## Shifts

```
POST /api/v1/shifts/open
  { "opening_float_cents": 20000 }
  → { shift }                    # 409 shift_already_open

GET  /api/v1/shifts/current
  → { shift, expected_cash_cents, sales_summary }

POST /api/v1/shifts/{id}/cash-movements
  { "kind": "payout", "amount_cents": 1500, "reason": "Window cleaner" }
  → { cash_movement }            # supervisor

POST /api/v1/shifts/{id}/close    # Idempotency-Key required
  { "counted_cash_cents": 48750, "note": "" }
  → { shift, expected_cash_cents, variance_cents, requires_approval }
```

Close **never rejects a variance** — it records it (see the cash accountability section
of `02-data-model.md`). If `|variance|` exceeds the threshold,
`requires_approval: true` comes back and a supervisor confirms via:

```
POST /api/v1/shifts/{id}/approve-variance    # supervisor, location-scoped (not register-scoped)
  {}
  → { shift }                    # shift.variance_approved_by / variance_approved_at now set
                                  # 422 variance_approval_not_required, variance_already_approved
```

The shift is already closed by then. Approval is an audit event, not a gate — blocking the
close is how you end up with terminals unplugged mid-count and no data at all.

**Approving from the register that just closed 401s.** `CloseShift` revokes every staff
session bound to that register the moment it closes, and approval needs a staff session
like any other write. In practice this means a supervisor approves from a *different*
register at the same location — the check is on location, not on the specific register —
or, later, from the M6 back office. This is expected behaviour, not a bug to route around.

Closing a shift with open orders → `409` listing them in `details`. Those orders must be
closed or transferred first; a tab cannot outlive the drawer that's accountable for it.

## Stock

```
POST /api/v1/stock/adjustments
  { "variant_id": "...", "qty_delta": "-2.000", "reason": "adjustment", "note": "" }
  → { level: { variant_id, qty } }   # supervisor; reason: adjustment | waste

POST /api/v1/stock/receipts
  { "variant_id": "...", "qty": "24.000", "note": "PO 4471" }
  → { level: { variant_id, qty } }   # supervisor

POST /api/v1/stock/counts
  { "variant_id": "...", "counted_qty": "18.000", "note": "" }
  → { level: { variant_id, qty } }   # supervisor

GET  /api/v1/stock/movements?variant_id=
  → { movements[], level }       # last 50 movements, plus the current level
```

The three write endpoints return the resulting `level`, not the movement they just
inserted — the register wants a fresh number to render, and the movement row is an
audit artifact, not something the caller needs echoed back.

All location-scoped to the acting register — stock is per-location, and a register only
ever touches the location it's enrolled at. Every write goes through the stock ledger
(`02-data-model.md`): movements are inserts, never updates, so the level is always a sum
of history, never a mutable counter that can drift from it. Gated `supervisor` throughout
— an adjustment moves sellable value out of the count the same way a void moves cash out
of the drawer, and letting receiving or counting bypass that would just move the hole
somewhere less audited.

## Orders

The lifecycle both retail and food service travel, at different speeds
(`00-overview.md`).

```
POST /api/v1/orders
  { "table_ref": "12", "customer_id": null }     # both optional
  → { order }                                    # status: open, version: 0

PATCH /api/v1/orders/{id}                        # If-Match
  { "table_ref": "14" }                          # or null to clear
  → { order }
```

Retail opens this implicitly on first scan; the cashier never sees it. Food service opens
it explicitly and names a table, and can rename it later — a party moves tables, the tab
doesn't move with a new order.

```
GET  /api/v1/orders?number=&status=&location_id=
GET  /api/v1/orders/{id}
```

A **targeted, location-scoped lookup** — a receipt number for a refund, recovering an
order the register lost track of, or (query `status=open`) the set of open tabs a floor
view renders. There is no separate browsing/paginated endpoint: the floor view is the
same lookup with `status=open`, capped at the last 20 open orders per location. Both
routes require a staff session, per Auth above.

Every order in the response carries what a floor view needs to render a tab card without
a second round-trip: `table_ref`, `opened_by_name`, `opened_at`, and `due_cents`
(`max(0, total_cents - paid_cents)` — the server does the subtraction so the client never
computes a balance it then trusts).

### Lines

```
POST   /api/v1/orders/{id}/lines                 # If-Match
  { "variant_id": "...", "qty": "1", "modifiers": ["<modifier_id>", ...] }
  → { order, line }

PATCH  /api/v1/orders/{id}/lines/{lineId}        # If-Match
  { "qty": "3" }
  → { order, line }

PATCH  /api/v1/orders/{id}/lines/{lineId}/prep   # no If-Match — see below
  { "state": "pending" | "in_progress" | "ready" }
  → { order, line }

DELETE /api/v1/orders/{id}/lines/{lineId}        # If-Match — voids, never deletes
  { "reason": "Customer changed mind" }
```

Adding a line does all of this **in one transaction**: resolve the location price, snapshot
name/SKU/price/tax-rate onto the line, validate modifier group `min_select`/`max_select`,
lock and decrement stock if tracked, recompute order totals, bump `version`. `modifiers`
is a flat list of modifier IDs — **repeats are legal** (a double shot is the same modifier
twice, not a distinct "double shot" catalog entry) and order is preserved. A modifier
that doesn't belong to the variant's product is `422 modifier_not_applicable`; a group
whose `min_select`/`max_select` isn't satisfied by the selection is
`422 modifier_group_required`; a selection whose deltas would take the line negative is
`422 line_total_negative` rather than a negative receipt line.

The whole order comes back on every line mutation. It's slightly more bytes than
returning the line alone, and it means the register's totals are **incapable** of drifting
from the server's — there is no client-side total to be stale.

`PATCH .../lines/{lineId}` sets the line's **absolute** quantity (not a delta); the stock
ledger sees only the difference. **Shrinking a line already fired to the kitchen is the
same fraud surface as voiding a sent line and takes the same permission** — decreasing
`qty` on a line whose `prep_state` is `in_progress` or `ready` without the void-a-line
permission is `403 forbidden`. Increasing a fired line's quantity needs no such gate; a
kitchen wanting a bigger portion isn't a fraud path. An already-voided line is
`409 line_already_voided`.

`PATCH .../prep` is the coursing verb the kitchen taps: `pending` (held) →
`in_progress` (fired) → `ready` (on the pass). Deliberately **no `If-Match` and no
`version` bump** — the kitchen marking food ready must never invalidate a till mid-tender,
and prep state is a KDS concern orthogonal to money. `order_lines.prep_state` was reserved
in the schema back at M2; this is the first action that writes it.

`DELETE` voids (`voided_at`), per `02-data-model.md`. Removing an already-sent line
requires `supervisor`.

As of M4, the add-line and void responses also carry the order's applied `discounts` rows
— `{id, discount_id, order_line_id, name, amount_cents, reason}` — not just totals, so the
register can offer removal without a separate round-trip.

### Transfer and split

```
POST /api/v1/orders/{id}/transfer                # If-Match, supervisor
  { "register_id": "<target register>" }
  → { order }                                    # register_id and shift_id now the target's
                                                  # 422 transfer_target_no_shift, transfer_same_shift
```

Hands a tab to another drawer — the accountability unit is the *shift*, not the person, so
transferring moves the order onto the target register's **open shift**. The acting
register doesn't have to be either side of the transfer: a supervisor at any register in
the location can move a tab between two others. The target register must have an open
shift to receive it (`422 transfer_target_no_shift`); transferring onto the shift the
order is already on is a no-op refused rather than silently accepted
(`422 transfer_same_shift`). Payments already taken keep the `shift_id` that physically
took them — a transfer never rewrites history, only where the *rest* of the tab is going.
This is also why closing a shift with open orders lists them: they have to be transferred
or closed first, per Shifts above.

```
POST /api/v1/orders/{id}/split                   # If-Match, Idempotency-Key recommended
  { "ways": 3 }                                   # 2..10
  → { orders: [ ... ] }                           # N new open orders; the original is voided
```

Splits **evenly**: every line's qty, tax, discount, and modifier total is divided into
`ways` parts with `Money::allocate`/the milli-quantity equivalent — the same
earliest-absorbs-the-remainder rule as any other split in this system
(`01-architecture.md`), never a per-child recompute (recomputing 1/N of a tax would mint
pennies that don't sum back). Child totals always sum exactly to the original. Stock is
untouched — it left the ledger when the lines were first added, and the children inherit
that claim — so the original order is closed out **voided without restock**, not through
`VoidOrder` (which does restock by design). The children are independent orders from the
moment they're created: each closes on its own tender, and nothing about them refers back
to the parent except the audit trail. Any applied discounts are frozen at the split: each
child inherits its allocated *share* of a discount as a fixed amount, so mutating a child
afterwards (adding a line, changing a qty) does not re-scale it off the live discount —
the share only ever clamps down if the base it sits on shrinks. Refused if the order
already has payments (`422 order_has_payments` — split what's owed, not what's already
been paid), or if any line's qty in thousandths is smaller than `ways` and so cannot
divide into that many non-zero parts (`422 split_too_fine`).

### Discounts

```
POST   /api/v1/orders/{id}/discounts             # If-Match, usually supervisor
  { "discount_id": "...", "order_line_id": null, "reason": "Manager comp" }
DELETE /api/v1/orders/{id}/discounts/{discountId}
```

Sending `order_line_id: null` makes it order-level. The server resolves percent → cents
and stores the resolved amount.

### Closing

```
POST /api/v1/orders/{id}/void                    # If-Match, supervisor
  { "reason": "Walkout" }
  → { order }                                    # restocks tracked lines
```

An order closes **automatically** when captured payments reach `total_cents` — there is no
"close" endpoint, because a manual close would be a second, disagreeing definition of
"paid in full."

```
POST /api/v1/orders/{id}/settle                  # If-Match
  → { order }                                    # 422 order_not_zero
```

Closes a zero-total order — 100% comped, fully discounted — without a tender. Valid only
when `total_cents == 0` and the order has lines; otherwise `422 order_not_zero`. This is
not a second, competing definition of "closed" — it's the same "captured payments reach
the total" rule evaluated at a total of zero, where there is no payment left to capture.

```
POST /api/v1/orders/{id}/reopen                  # supervisor
```

For food service: a customer orders another round after settling. Audited.

## Payments

```
POST /api/v1/orders/{id}/payments                # Idempotency-Key REQUIRED, If-Match
  { "driver": "cash", "amount_cents": 5000, "tendered_cents": 6000 }
  → { payment: { status: "captured", change_cents: 1000 },
      order:   { paid_cents: 5000, status: "closed", version: 8 } }
```

Change is computed **server-side, in integers**. The client displays what it's told; it
never does the subtraction itself.

`amount_cents` and `tendered_cents` are separate fields, and the distinction is
load-bearing: on a $50 bill, handing over $60 is not a $60 payment — it is $50 applied and
$10 change. Tendering *less* than the amount applied is `422 insufficient_tender`, which
is a different thing from underpaying the order (that's just a partial payment, and the
order stays open).

`external_card`:

```json
{ "driver": "external_card", "amount_cents": 5000, "reference": "auth 004321" }
```

Recorded as `captured` immediately — we're a ledger for it, not a processor
(`01-architecture.md`).

Splitting is just several payments; the order closes when they sum to the total. Under-
paying leaves it open. Over-paying is `422 payment_exceeds_balance` — for cash, the
overage is *change*, not a payment, which is exactly why `tendered_cents` and
`amount_cents` are different fields.

```
POST /api/v1/payments/{id}/void                  # supervisor; before shift close only
```

A future async driver (Stripe Terminal) returns `status: "pending"` from this same
endpoint and settles via webhook + `GET /api/v1/payments/{id}`. The shape does not change
— that's the point of `authorize`/`capture` in the driver contract.

## Refunds

```
POST /api/v1/refunds                             # Idempotency-Key required, supervisor
  {
    "original_order_id": "...",
    "driver": "cash",
    "reason": "Faulty",
    "lines": [ { "original_order_line_id": "...", "qty": "1", "restock": true } ]
  }
  → { refund }
```

Amounts are **derived from the original lines**, never sent by the client — a
client-specified refund amount is an open till. Validated inside the transaction against
prior refunds on each line (`422 refund_exceeds_original`). A derived amount of zero — a
fully discounted line, or a quantity that rounds to nothing — is refused
(`422 refund_amount_zero`) rather than writing a no-op refund. `driver: "external_card"`
is rejected: that money never came through us.

The original order is never modified.

## Back office (`/api/v1/admin/*`, admin-only)

Every route below requires the admin bearer token from Auth, above. All of it is
conventional CRUD — `GET` lists (unpaginated; v1's tables are seed-sized, not
production-scale), `POST` creates, `PATCH` applies only the keys it's sent — with one
deliberate exception: **there is no `DELETE` route anywhere under `/admin/*`.** Catalog
rows, locations, and registers are **archived, never deleted** — `PATCH { "is_active":
false }` — because an order line, a receipt, or a report from last month still points at
that row by id, and a hard delete would either cascade into history or leave a dangling
reference. Every mutation writes one `audit_log` row (`admin.<entity>.create` /
`admin.<entity>.update`), which is what the audit viewer below reads.

### Catalog

```
GET|POST /api/v1/admin/categories        PATCH /api/v1/admin/categories/{id}
GET|POST /api/v1/admin/tax-rates         PATCH /api/v1/admin/tax-rates/{id}
GET|POST /api/v1/admin/products          PATCH /api/v1/admin/products/{id}
GET|POST /api/v1/admin/variants          PATCH /api/v1/admin/variants/{id}
GET|POST /api/v1/admin/modifier-groups   PATCH /api/v1/admin/modifier-groups/{id}
GET|POST /api/v1/admin/modifiers         PATCH /api/v1/admin/modifiers/{id}
GET|POST /api/v1/admin/discounts         PATCH /api/v1/admin/discounts/{id}

PUT /api/v1/admin/products/{id}/modifier-groups
  { "group_ids": ["<modifier_group_id>", ...] }
  → { product }
```

`PUT`, not `PATCH`, on the attach endpoint: it replaces the product's **entire**
modifier-group set in one call (ordered by array position), the same full-set-replace
shape as `roles` on Users, below — there is no add-one/remove-one pair. A product
response's `modifier_group_ids` (a plain ordered id array) is present on every read, not
only the ones that eager-load the richer `modifier_groups` shape — an attach editor
seeded from a response that omits it would save back an empty set and silently detach
everything the product had.

### Users

```
GET|POST /api/v1/admin/users            PATCH /api/v1/admin/users/{id}
  { "name": "...", "email": "...", "pin": "...", "is_admin": false,
    "roles": [ { "location_id": "...", "role": "cashier" } ] }
```

`roles`, when sent, replaces every existing assignment for that user — full-set-replace,
never an add/remove pair. Omitting `roles` from a `PATCH` leaves assignments untouched;
sending `roles: []` clears every one. An admin cannot demote or deactivate **themselves**
through this endpoint — `422 self_lockout` — because with only one auth tier above
`admin` (none), there's no guaranteed second admin online to undo it.

### Locations and registers

```
GET|POST /api/v1/admin/locations        PATCH /api/v1/admin/locations/{id}
GET|POST /api/v1/admin/registers        PATCH /api/v1/admin/registers/{id}
  { "mode": "retail" | "food" }                       # picks the register's UI

POST /api/v1/admin/registers/{id}/token
  → { token }
```

Reissue is the lost/stolen-terminal path: every existing personal-access token for that
register is deleted and the replacement minted **in the same transaction**, so there is
no window where a lost credential and its replacement are both live. `registers.mode` is
the one schema addition M5 needed (`06-roadmap.md`) and simply picks which UI the
register app renders.

```
GET /api/v1/registers/open-shifts        # staff tier, not admin
  → { items: [ { register_id, register_name, shift_id, opened_by_name }, ... ] }
```

Not under `/admin` on purpose — this is the register app asking about **other active,
currently-open-shift** registers at its own location, excluding itself (populating a
transfer picker, or finding another open register to approve a variance from), which is
a staff action even though it never touches money or the back office. It needs a staff
session like any other staff-tier route, not an admin one.

## Reports

```
GET /api/v1/reports/z?shift_id=                                       # staff tier
  → { shift, sales_by_driver, refunds_by_driver, movements,
      orders_closed, orders_voided, orders_split, expected_cash_cents }

GET /api/v1/admin/reports/sales?location_id=&from=&to=&group_by=day|category|user
  → { rows[], totals, basis }

GET /api/v1/admin/reports/stock?location_id=&low_only=true
  → { rows[] }

GET /api/v1/admin/audit?entity_type=&entity_id=&user_id=&action=&from=&to=&page=
  → { rows[], page, has_more }                                        # 50 rows/page
```

All date filtering is on `business_date` (`02-data-model.md`), so a report means the same
thing regardless of the timezone of whoever runs it. The Z-report's `orders_split` (M6)
counts the *originals* `POST /orders/{id}/split` leaves behind (voided, not closed)
separately from ordinary voids, so a busy split day at the till doesn't read as a wave of
walkouts.

**`group_by=day` and `group_by=user` are LEDGER-basis** — summed straight from captured
`payments` and `refunds`, i.e. money that actually moved and who moved it. **`group_by=
category` is LINE-basis** — summed from non-voided lines of closed orders, joined to the
**live** catalog for a human-readable category name (a report is allowed to do that join;
a receipt never is, since it must reprint identically to what it said on the day it was
made). The response's `basis` field names which kind of number a given `group_by`
produced. **The two bases are not required to reconcile with each other** — a line-level
discount changes what a line's total was without changing what tender captured it — and
that's a fact about what each slice measures, not a bug to chase down.

The audit viewer is read-only (no `audit_log` row for reading the audit log) and
paginates at 50 rows. `entity_type`/`entity_id`, `user_id`, and `action` are each covered
by a dedicated index on `audit_log`; only a bare date range with none of those three set
falls back to a sequential scan, an accepted cost for a back-office read at this scale.

## Drawer

```
POST /api/v1/drawer/no-sale              # open the drawer with no sale attached
```

Requires `drawer.no_sale` (supervisor). `reason` is mandatory and the opening is bound to
the register's open shift — no open shift is `409 no_open_shift`. Moves no money, so
there is no table: the audit row is the record, and the back office's audit viewer reads
it. Only the desktop shell can act on the response; a browser has no drawer to open.

## Receipts

```
GET /api/v1/orders/{id}/receipt          # → structured JSON, rendered client-side
```

Built **entirely from snapshot columns** — never joined to the live catalog. Reprinting a
receipt from 2024 next year must produce identical bytes, which is the whole reason those
columns exist.

## Rate limits

| Scope | Limit |
| --- | --- |
| PIN attempts | 5 / 60s per register |
| Catalog full sync | 10 / min per register |
| Everything else | 300 / min per device token |

Deliberately loose: a busy lunch rush is not an attack, and a POS that rate-limits a
queue of real customers has failed at being a POS.
