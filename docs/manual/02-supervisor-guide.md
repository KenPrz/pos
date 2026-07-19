# Supervisor Guide

This is for a shift lead who already knows the [Cashier Guide](01-cashier-guide.md)'s
flows. Everything here is a permission a cashier doesn't have — the actions that let
money or stock leave the business without a customer noticing (see the Getting Started
chapter's [Roles](00-getting-started.md#roles) section).

## How an override actually happens at the till

Every button in this chapter is gated by a permission tied to whoever is currently
clocked in at that specific register — it isn't a password prompt layered on top of a
cashier's own session. If a screen shows **Void**, **Discount**, **Refunds**,
**Transfer**, or **Approve variance**, it's because the person signed in right now, at
this till, holds the supervisor role at this location — roles are assigned per location,
so being a supervisor at one store doesn't carry over to another (see Getting Started).

In practice: a cashier who hits something that needs you taps **Clock out**, you clock in
with your own PIN to do the one thing, then clock out again so they can sign back in.
The shift itself — the open drawer — isn't touched by any of this; only the PIN session
changes hands.

## Void a line or void a whole order

**A line:**

1. Tap **Void** on the cart row.
2. Type why in **Reason for the void…** (required).
3. Tap **Confirm void** — or **Keep** to back out without voiding it.

**The whole order:**

1. Tap **Void order**, below the cart.
2. Type why in **Reason for voiding the whole order…**.
3. Tap **Void order** to confirm — or **Keep** to back out.

> Note: voiding a line or an order **restocks** whatever tracked stock those lines took
> out — the exact reverse of adding a line, which decremented it. A line that's already
> voided answers with a conflict rather than voiding twice.

## Apply or remove a discount

1. Tap **Discount**.
2. Type a reason into **Reason (required)…** — every discount button stays disabled
   until you do.
3. Tap the discount by name to apply it to the whole order.

To take one off, tap the **✕** next to it in the cart.

> Note: this picker only offers your location's order-wide discounts — there's no
> per-line discount picker at the till in this version. Whatever you apply here covers
> the whole order, not a single item.

## Refund a closed sale

Tap **Refunds** in the top bar (only visible if you can refund) to open **Refund a
sale**.

1. Type the receipt number into **Receipt number** and tap **Find order**.
2. For each line being returned, type the quantity to refund in its box. Leave
   **Restock** checked to put that stock back, or clear it if the goods are damaged or
   otherwise unsellable.
3. Type a **Reason**.
4. Tap **Refund cash**.

The refund always comes out of *this* drawer as cash, whatever the sale was originally
paid with — a card sale can't be refunded to the card, since that money never passed
through this till in the first place.

> Note: only a **closed** order can be refunded. Looking up one that isn't answers with
> something like "Order *DT-20260716-0001* is open — only closed orders can be
> refunded." — find the right receipt number, or let the order close first.

## Transfer a tab to another register

On the Floor screen, each of your own tabs carries a **Transfer** chip (visible only if
you can transfer, and only when another till is available to receive it).

1. Tap **Transfer** under the tab.
2. Under **Send to**, tap the destination till.

> Note: only registers with an **open shift right now** show up as a destination. The
> target's drawer has to already be open, because the transfer moves the tab's money
> onto *that* shift's ledger the instant it lands — not just to a different person.

## Change the quantity on a fired line

The permission and the underlying action exist (`order.line.update`, same rule a void
uses: **decreasing** a course's quantity once it's already **Cooking** or **Ready** needs
the same authority as voiding it outright, since shrinking a sent course is the same
fraud surface). **Increasing** a fired line's quantity needs no such gate — a kitchen
being asked for more of something isn't a fraud path.

The same authority gates the other direction on the prep chip, too: sending a
**Ready** (or **Cooking**) course back a step is a downgrade out of a fired state, so it
takes the void permission exactly like the quantity decrease above — a cashier's own tap
on that chip only ever moves it forward.

> Note: this version's register screen has no on-screen field for changing an existing
> line's quantity — only **Void** (remove it entirely) and the prep chip (move it through
> **Pending** / **Cooking** / **Ready**) are exposed here. A quantity correction on a
> fired line currently has to go through whoever operates the system directly against
> the API (see `03-api.md`'s Lines section), not a till button.

## Record a cash movement

Paid-ins, payouts, and drops against the drawer are real, audited actions
(`shift.cash_movement`), and they're what fill in the Z-report's **Paid in**,
**Payouts**, and **Drops** rows once you read them.

> Note: like the fired-line quantity change above, this version's register has no
> on-screen form to *record* one — the drawer only *displays* what's already been
> recorded. Recording a movement goes through the API directly (`03-api.md`'s Shifts
> section: a kind, an amount, and a reason) rather than a till button, until a screen for
> it ships.

## Read the Z-report

Once a shift closes, its result screen shows a **Z-report**: **Sales — cash**,
**Sales — external_card** (one row per tender actually used that shift), the same
broken down for **Refunds**, then **Paid in**, **Payouts**, **Drops**, and
**Orders closed** / **Orders voided** / **Orders split**.

> Note: a split shows up under **Orders split**, never **Orders voided** — even though
> splitting a check does technically void the original order server-side, to make room
> for the checks it becomes. That's bookkeeping, not the fraud-relevant kind this report
> is watching for, which is exactly why it's counted separately: reading **Orders
> voided** as "genuine voids for the day" stays safe.

## Approve a variance — a documented gap in this version

When a shift closes outside the store's variance threshold, its own result screen shows
**Variance exceeds the threshold — needs supervisor approval.** with an **Approve
variance** button. Tapping it right there always fails: closing a shift immediately
revokes every staff session tied to that register, so the very screen showing that
button is already running on a dead session — the click lands as a session error and
signs you straight back out to **Enter PIN**.

The rule behind it is real and correctly location-scoped: approval is checked against
the location, not a specific terminal, so a *different*, still-open till at the same
location is allowed to approve it. But like the two gaps above, no register screen
exists yet for that — each till's screen only ever knows about its own currently open
shift (`GET /api/v1/shifts/current`), never a *different* till's. Nothing here lists
another till's shift or renders an Approve button for it.

Today, approving from elsewhere means calling the API directly —
`POST /api/v1/shifts/{id}/approve-variance`, using a staff session from that other till
— the same way `scripts/e2e-lunch-service.sh` does it, not a button anywhere in this
app yet.

> Note: this is expected behavior, not a bug to route around — see the Operator Guide's
> [Troubleshooting](04-operator-guide.md#troubleshooting) for the same rule from the
> other side of the counter. The revocation itself is deliberate (`docs/06-roadmap.md`'s
> M5 notes); it's the register screen for reaching the approval from elsewhere that
> doesn't exist yet.

## See also

- [Cashier Guide](01-cashier-guide.md) — the sale, tab, and close-shift flows this
  chapter builds on.
- [Manager Guide](03-manager-guide.md) — catalog, staff, and reports, once you need the
  back office.
