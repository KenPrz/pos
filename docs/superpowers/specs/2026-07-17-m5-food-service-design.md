# M5 — Food service: design

Approved scope: the roadmap's five bullets (tabs/floor list, modifiers end-to-end, split
payments, transfer, register mode switch) **plus**, at the owner's direction:
approve-variance, the M4 triage items that touch M5 surfaces, `PATCH` line qty,
register-side `prep_state`, and **split evenly into N checks**.

Design decisions were calibrated against the current top of the line (Toast, Square for
Restaurants, Lightspeed K/L-Series) — sources at the bottom. Where we match, it's noted;
where we deliberately don't, the gap and its revival trigger are recorded in Out of scope.

## The thesis this milestone tests

`00-overview.md` bets food service is **screens, not tables**. Result: the order model
survives untouched. Total schema change in M5:

- `shifts.variance_approved_by uuid null references users(id)`, `shifts.variance_approved_at timestamptz null`
- `registers.mode text not null default 'retail' check (mode in ('retail','food'))`

Neither touches the order lifecycle. The thesis holds.

## Decisions (owner-approved)

| Decision | Choice | Why |
| --- | --- | --- |
| Floor-view freshness | React Query polling, `refetchInterval` 10s, floor view only | Zero infra; the `orders_open` partial index makes it one cheap read; a 10s-late tab card is slower than the human handoff anyway. WebSockets deferred — revive when a store reports double-seating from staleness. |
| Variance approval storage | The two nullable columns on `shifts` | Queryable ("which closes still need sign-off" — M6 reports) and idempotent (second approve → 422). Audit row written as well, as with every mutation. |
| Register mode | `registers.mode` column, admin-owned | Matches Lightspeed's device-profile pattern (Quick Service vs Table Service is set centrally, not on-device). Until M6 back office ships, set via seeder/psql — consistent with "back office last." |
| Transfer direction | Push: sender (or supervisor) picks the receiving register | Industry standard (Toast/Lightspeed): push to a clocked-in colleague, permission-gated with manager override. Ours pushes to a register-with-open-shift because our accountability unit is the drawer, not the server; the picker *displays* each shift's opener so it reads as people. |
| Split depth | Split payments **and** split-evenly-into-N-checks | Toast/Square's most-used split path. By-item and by-seat splits deferred (below). |

## Backend surface

All of it follows the M3/M4 conventions: one route = one controller = one final Action;
If-Match on order mutations; `Idempotency-Key` where money moves; every mutation audited;
the whole order back on every order mutation.

| Endpoint | Action | Notes |
| --- | --- | --- |
| `POST /orders` gains `table_ref` | extend `OpenOrder` | Nullable; retail sends nothing. |
| `PATCH /orders/{id}` `{ table_ref }` | `SetTableRef` | Open orders only; parties move tables. Nullable to clear. |
| `GET /orders?status=open` | extend list payload | Adds `table_ref`, opener name, `opened_at`, `total_cents`, `due_cents` — the floor view. Location-scoped as of M4; unchanged. |
| `POST /orders/{id}/lines` gains `modifiers: [id, ...]` | extend `AddLineToOrder` | Validation + snapshot below. Repeated ids are legal ("double bacon") — each repeat is an `order_line_modifiers` row and counts toward `max_select`. |
| `PATCH /orders/{id}/lines/{lineId}` `{ qty }` | `UpdateLineQty` | If-Match. Stock delta under the same `SELECT … FOR UPDATE` as add-line (up = further decrement, may refuse on insufficient stock; down = restock). Totals recomputed; modifier total rescales from frozen snapshots (never re-reads the catalog); order discounts re-resolve exactly as add-line does. Decreasing a **fired** line (prep_state in_progress/ready) requires the same supervisor permission as voiding a sent line — reducing cooked food is the fraud surface voids already guard. |
| `PATCH /orders/{id}/lines/{lineId}/prep` `{ state }` | `SetLinePrepState` | pending → in_progress → ready, any staff with order access. No If-Match, no version bump — prep is operational, not financial. Audited. Semantics mapped to industry coursing: `pending` = held/not fired, `in_progress` = fired, `ready` = pass-ready. Lines on a `mode='retail'` register stay `null` (column unused, as in M3/M4). |
| `POST /orders/{id}/transfer` `{ register_id }` | `TransferOrder` | If-Match. Order open; target register active, same location, **has an open shift** (else 422 `transfer_target_no_shift`); target ≠ current shift (else 422 `transfer_same_shift`). Moves `shift_id` + `register_id`; `opened_by` stays as history; audit row records from/to. Permission `order.transfer` (supervisor tier — the register UI shows the Toast-style supervisor-override prompt for non-supervisors). |
| `POST /orders/{id}/split` `{ ways: N }` | `SplitOrder` | The new money-critical action — full semantics below. If-Match + Idempotency-Key. |
| `POST /shifts/{id}/approve-variance` | `ApproveVariance` | Supervisor (`shift.approve_variance`). Shift closed, variance over threshold, not yet approved (else 422 `variance_already_approved` / `variance_approval_not_required`). Sets the two new columns; audit row. Approval is an audit event after the fact, never a gate on closing — per `03-api.md`. |

**Split payments need no new backend.** Multiple partial captures against one order have
worked since M3 (`payment_exceeds_balance` guards, auto-close at paid-in-full,
per-payment `shift_id` keeps drawers accountable). M5 adds the UI and the
server-computed `due_cents` on `OrderResource` (M4 triage item) so no client ever does
`total - paid` itself.

## SplitOrder semantics

"Split evenly into N checks" must respect append-only records, single-site rounding, and
the stock ledger. In one transaction:

**Preconditions:** order open; `paid_cents = 0` (else 422 `order_has_payments` — split
before anyone pays, matching Toast's normal flow); `ways` an integer 2–10 (400 otherwise);
If-Match version check under `lockForUpdate`, like every order mutation.

**Mechanics:**

1. For each non-voided line, allocate `qty` (milli-precision), `line_total_cents`,
   `tax_cents`, `modifiers_total_cents`, and `discount_cents` into N parts with
   `Money::allocateByRatios` / the milli equivalent — each column **sums exactly** to the
   original; no per-child recomputation, because recomputing 1/N of a tax would mint
   pennies. Child lines copy the frozen snapshots (name, SKU, unit price, tax rate) and
   the `order_line_modifiers` rows (name + per-unit delta, for display; the money lives in
   the allocated `modifiers_total_cents`).
2. `order_discounts` rows are cloned onto each child with allocated amounts (same
   allocator, same exactness rule) — order-level rows onto each child order, line-level
   rows onto the matching child line.
3. Create N child orders — own numbers from the counter, same `table_ref`, shift,
   register, `business_date`, `prices_include_tax`, `opened_by` — each with its allocated
   lines and totals. Fractional quantities are first-class: a burger split three ways is
   `qty "0.334"` / `"0.333"` / `"0.333"`, printed as such (Toast does the same).
4. **Stock is not touched.** It left the ledger when the original lines were added; the
   children inherit the claim. The original order is closed out as
   `voided_at`/`void_reason = "split into #101, #102, #103"` **without restock** —
   `SplitOrder` writes this state itself rather than calling `VoidOrder` (which restocks
   by design). Voided orders are already excluded from all reporting sums, so nothing
   double-counts; money reporting stays on the payments/refunds ledgers as always.
5. Audit row on the original linking every child id, and one on each child linking back.

**Receipts:** children print through the existing receipt path — snapshots, fractional
qtys, their own payments. Nothing new.

**Refunds:** operate on child orders like any order. Restock of fractional quantities
already works (M4 refunds are qty-based).

## Modifier arithmetic (the other money-critical part)

- Deltas are **per unit**: 2 burgers + bacon(+150) = +300.
  `modifiers_total_cents = Money::fraction(Σ deltas × qty)` — through the single rounding
  primitive. `OrderTotals` already includes `modifiers_total_cents` in the taxable base,
  so tax needs no new code.
- Validation inside `AddLineToOrder`'s transaction:
  - every modifier id belongs to a group attached to the line's product and is active —
    else 422 `modifier_not_applicable` (new code);
  - per-group selection count (repeats counted) within `[min_select, max_select]` — a
    missing required group or an overshoot is 422 `modifier_group_required` (code already
    reserved in `03-api.md`);
  - a resolved line total below zero (negative deltas outweigh the price) is refused 422.
- Snapshots into `order_line_modifiers` (name + delta frozen at add time); receipts render
  modifiers indented under their line. Last year's "oat milk +60" prints identically
  forever, same rule as everything else on a receipt.
- `UpdateLineQty` rescales from the frozen rows. The catalog is never consulted after add.

## Register UI

- **Carbon bar**: TABS button with open-tab count; staff name; the register's mode decides
  the default face.
- **Mode `'food'`** boots to the **menu grid**: category rail + product tiles from the
  already-shipped denormalized catalog payload. Tapping a product with modifier groups
  opens the **modifier sheet** — required groups first (Toast's ordering), min/max
  enforced client-side before ADD (server re-validates; the client check is UX, not
  authority). A small scan field stays available in grid mode — Lightspeed strips
  scanning from Quick Service mode; we deliberately don't, because "cafe that also sells
  retail merch" is this product's founding premise. Mode `'retail'` boots to the scan-first
  screen exactly as today.
- **Floor/tab view** (polling, 10s): tab cards — table ref, order number, server, age,
  total/due — tap to resume; NEW TAB with a table-ref pad; TRANSFER on each card opens
  the register picker (register name + its shift's opener), with the supervisor-override
  PIN prompt when the actor lacks `order.transfer`.
- **Split flow**: on the tender screen, SPLIT ×N (2–10) calls `SplitOrder`, lands on the
  first child; a child strip shows each check's number/due and jumps between them; each
  child pays through the normal tender screen (cash or card, partial payments still
  legal) and prints its own receipt.
- **Prep chips** on tab lines cycle pending → in progress → ready (HELD / FIRED / READY
  labels).
- **Blind count** (industry correction to M4's close screen): the Z-report is still
  fetched at close-screen mount — close revokes the staff session, so fetching later is
  impossible — but expected cash and variance are **masked until the counted amount is
  submitted**. The cashier counts blind, per standard cash-handling practice; the
  reveal happens with the close result.
- DESIGN.md console-chrome language throughout: plates, chips, 44px targets, warm color
  for action only.

## RBAC

- `order.transfer` — exists since M2 (supervisor tier). Enforced by `TransferOrder`.
- `shift.approve_variance` — exists (the M2 permission catalog shipped it alongside the
  `requires_approval` flag). Enforced by `ApproveVariance`.
- `SetLinePrepState`, `SetTableRef`, `SplitOrder`, `UpdateLineQty` (increase) — normal
  order-mutation permission. `UpdateLineQty` decrease on a fired line — supervisor, same
  gate as voiding a sent line.

## New error codes

| Code | Status | Raised by |
| --- | --- | --- |
| `modifier_not_applicable` | 422 | AddLineToOrder — modifier not on this product / inactive |
| `modifier_group_required` | 422 | AddLineToOrder — required group unanswered or count outside min/max (already reserved in 03-api.md) |
| `transfer_target_no_shift` | 422 | TransferOrder |
| `transfer_same_shift` | 422 | TransferOrder |
| `variance_already_approved` | 422 | ApproveVariance |
| `variance_approval_not_required` | 422 | ApproveVariance |
| `order_has_payments` | 422 | SplitOrder (reused — same meaning as its M4 uses) |

## M4 triage items folded in

- `due_cents` server-computed on `OrderResource` (used by floor view + split flow).
- `ApplyDiscount` filters voided lines from its resolution base.
- Cross-order idempotency collision tests (two orders, same key text, distinct hashes).
- Lock/status/version preamble extraction — the M5 actions would be the 6th, 7th, and 8th
  copies; extract once (`OrderMutationGuard` or equivalent), retrofit the M4 five.

Remaining triage (per-mount key UX, api.test gaps, vestigial Vite bits, hardcoded proxy
target, `requires_supervisor` flag enforcement) rides to M6 as before.

## Testing / done-when

- Pest per action, matching M3/M4 depth: modifier min/max/repeat matrix; per-unit delta ×
  fractional qty rounding; transfer race (two transfers, one If-Match loser) and
  target-shift validation; qty-change stock reconciliation both directions; SplitOrder
  penny-exactness property (every allocated column sums to the original, across odd
  totals and 2–10 ways); split-then-refund restock; approve-variance idempotence;
  blind-count masking covered by a frontend test.
- The cross-order collision tests from triage.
- Frontend: modifier sheet enforcement, split strip flow, floor polling render, mode boot.
- **E2E lunch service** (committed, token via env like `e2e-retail-day.sh`): open two tabs
  → courses with modifiers added over time → fire/ready one course → transfer one tab to
  the other register → split the other three ways → pay children (mixed cash/card) →
  close both shifts → drawers reconcile → one over-threshold variance approved by a
  supervisor.
- The done-when from the roadmap: **a cafe could run a lunch service** — open a tab, add
  courses over an hour, split three ways.

## Out of scope, with revival triggers

| Deferred | Revive when |
| --- | --- |
| Split by item / by seat | A party asks to pay for exactly their own items. By-seat additionally needs `order_lines.seat` — a schema change we don't spend until then. |
| Graphical floor plan with sections | A store wants a drawable room. Needs table entities; `table_ref` text is the v1 decision from `02-data-model.md`. |
| KDS / kitchen printing | A kitchen asks (roadmap trigger unchanged). `prep_state` is the seam and M5 now exercises it. |
| Tips | Card tips wanted on receipts/reports. `payments` has no tip column today; cash tips are jar-level. Decide before any card-present integration. |
| WebSockets for the floor | Staleness causes a real double-seat. Polling interval is one config value until then. |

## Sources (industry calibration)

- Toast: [modifier groups](https://support.toasttab.com/en/article/Creating-Modifier-Groups-and-Modifiers-1492803987509), [required/optional behavior](https://support.toasttab.com/en/article/Required-Optional-Modifiers), [split checks](https://support.toasttab.com/en/article/Splitting-Checks-by-Item-1492811097734), [transfer a check](https://support.toasttab.com/en/article/Transferring-a-Check-to-Another-Employee-1493069784457), [server item firing](https://support.toasttab.com/en/article/Using-Server-Item-Firing), [course firing](https://support.toasttab.com/en/article/Course-Firing-Options)
- Square: [split by seat/item/evenly](https://squareup.com/help/us/en/article/8165-split-a-payment-and-check-with-square-for-restaurants)
- Lightspeed: [Quick Service mode as a device profile](https://resto-support.lightspeedhq.com/hc/en-us/articles/360039212374-About-Quick-Service-mode), [firing a course](https://resto-support.lightspeedhq.com/hc/en-us/articles/360020992054-Firing-a-course), [check splitting](https://k-series-support.lightspeedhq.com/hc/en-us/articles/360051089493-Check-splitting)
- Cash handling: [blind counts and variance thresholds](https://www.xenia.team/daily-ops-checklists/cash-handling-procedures-retail), [drawer reconciliation](https://docs.ncrvoyix.com/restaurant/aloha-pos/implementing/employee_reconciliation/reconciling_the_cash_drawer)
