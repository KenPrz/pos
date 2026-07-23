# Manager Guide

This is for the back office — the screens a manager uses to set up the menu, hire and
manage staff, configure locations and registers, and read what the stores did. It's a
separate application from the register (see Getting Started's
[three surfaces](00-getting-started.md#the-three-surfaces)): a laptop, not a till, and an
email/password sign-in rather than a device token and a PIN.

> Note: signing in to the back office no longer requires **Admin** — anyone holding at
> least one back-office permission, through a role or a direct grant, can sign in and
> sees only the sections that permission unlocks. A user with just **Sales report**
> access, say, signs in and sees **Reports** and nothing else. **Admin** is still the
> one flag that unlocks every section at every location; everything narrower is a role
> or a grant a manager assigns from **Users**. See `05-rbac.md` for the full rule.

## Sign in

The back office shows its own **Sign in** screen — email and password, no till and no
device token involved.

1. Enter your **Email** and **Password**.
2. Tap **Sign in**.

> Note: a wrong email, a wrong password, a deactivated account, and an account that
> isn't an admin all answer with the same message — **"The email or password is
> incorrect."** — on purpose. A more specific answer would let someone probe which
> emails exist in the system.

Once in, a rail down the left holds every section you hold a permission for, grouped
under two headings — **Operations**: **Today**, **Catalog**, **Users**, **Locations &
Registers**, **Settings**, **End of Day**; **Insights**: **Reports**, **Audit**.
**Today** always shows; every other section only appears if you hold the permission
behind it, so two managers can see different rails depending on what each one was
granted — a full admin sees all eight. A **location switcher** sits above the rail;
**Today**, **Reports**, and
**Stock** all read whichever location it's set to — there are no per-screen location
pickers, and the switcher itself only offers locations your report permissions actually
cover. Tap **Sign out**, at the bottom of the rail, when you're done.

## Today

**Today** is what you land on right after signing in — a glance at the location the
sidebar switcher is set to, right now, with nothing to configure.

- A row of four figures: **Net sales today**, **Orders closed**, and **Refunds
  today** — the same ledger numbers the Sales report shows under its **Day** basis
  (captured payments and refunds that actually moved money, for today only) — and
  **Low stock**, a count of variants at or below their reorder threshold (the Stock
  report's **Low only** filter).
- **Needs attention**: a table of every low-stock variant and every inactive
  register at this location — **Name**, **Qty**, **Status** — each status a colored
  dot — yellow for low stock, red for a register that can't clock in a shift.
  Nothing to flag shows **"All clear"** instead of an empty table.
- **Recent activity**: the first page of the **Audit** log — **When**, **Action**,
  **User** — the same trail **Audit** itself shows in full, trimmed to a glance.

> Note: every number on **Today** already exists elsewhere in the back office —
> **Sales**, **Stock**, **Locations & Registers**, and **Audit** — gathered onto one
> screen rather than computed specially. If a figure here ever looks off, the
> matching report is where to go double-check it.

## Catalog

### Add a product

A **product** is the thing on the menu (Flat White); a **variant** is what's actually
sold — its SKU, barcode, and price (Flat White / Regular, $4.50). Every product needs
at least one variant before it's sellable.

1. In **Catalog**, tap the **Products** tab, then tap **New**.
2. Fill in **Name**, **Description**, **Category**, and **Kind** (**Goods** or
   **Service** — a label for your own reporting; either kind takes the same variant
   fields below, including a barcode and tracked stock, if you want them).
3. Tap **Save**.
4. Tap the **Variants** tab, then tap **New**.
5. Pick the **Product** you just created, then fill in **Name**, **SKU**, **Barcode**
   (optional), **Price**, **Cost** (optional), **Tax rate**, and **Track inventory**.
6. Tap **Save**.

> Note: **Price** and **Cost** are typed as a decimal amount in the store's currency
> (e.g. `4.25`) but travel the wire as cents — a blank or unparseable price is refused
> before it ever reaches the server ("Enter a valid price (e.g. 4.25).").

To attach modifier groups (a latte's milk, a burger's extras) to a product, open the
product again — the **Modifier groups** panel at the bottom lists every group as a
checkbox. Tick the ones this product should offer, then tap **Save modifier groups**.
This is a separate action from the product's own **Save** button above it, so fixing a
typo in the name can't accidentally change what's attached, and vice versa.

> Note: **Save modifier groups first** — tapping the checkbox is a full replace of the
> product's attached groups, not an add-one/remove-one, so an untouched box you meant to
> keep still has to be ticked before you save. A brand-new product has to be saved once
> (so it has an id) before this panel becomes available at all.

### Categories, tax rates, and modifier groups

- **Categories** (its own tab): **Name**, **Parent category** (optional, for nesting),
  **Sort order**. There's no archive here — a category can only be edited, not retired.
- **Tax rates** (its own tab): **Name**, **Rate (%)** — typed as a percent (`8.25`),
  stored and computed server-side as an exact fraction so the math never drifts the way
  repeated percent rounding would.
- **Modifier groups** (its own tab): **Name**, **Min select**, **Max select (blank =
  unlimited)** — a group with **Min select** at 1 or more is what shows up as
  *required* at the till (the cashier can't confirm an item until every required group
  has a pick). Each group has its own nested **Modifiers** table — **Name**, **Price
  delta**, **Position**, and (once saved) **Active** — reached by opening the group
  itself, since a modifier only makes sense attached to one group.

Back on a product's own editor, the order you tick modifier groups in **is** recorded —
each checkbox shows its tick order as **#1**, **#2**, and so on, and that position is
what **Save modifier groups** writes to the server.

> Note: that stored attach order doesn't currently reach the till, though. The
> register's modifier sheet always shows **required groups first**, and beyond that
> ordering it follows the group list the same way for every product — so ticking groups
> in a particular order here is recorded, but isn't yet what decides what a cashier sees
> first for *this* product.

> Note: like categories, a modifier **group** itself has no archive toggle — only the
> **modifiers** inside it do (its table's own **Unarchive** action). Retiring a whole
> group means archiving every modifier in it, or detaching the group from any product
> that offers it.

### Set up a discount

**Catalog** → **Discounts** tab → **New**: **Name**, **Kind** (**Percent** or **Fixed
amount**), the matching **Percent** or **Amount** field, **Scope** (**Order** or
**Line**), **Requires supervisor**, **Active**. Discounts archive the same way as
everything else in this section: uncheck **Active**, confirm, done.

> Note: **Scope** is stored either way, but the till only *offers* order-scope
> discounts today — the register's discount picker has no per-line version yet
> (Supervisor Guide). A line-scope discount you create here won't show up as anything
> a supervisor can tap until that screen exists.

### Reprice a variant — and what happens to old receipts

1. **Variants** tab → tap **Edit** on the row.
2. Change **Price** (or **Cost**, **Tax rate**, whatever needs updating).
3. Tap **Save**.

> Note: the cutoff is **per line, not per order**. Every order line snapshots the name,
> price, and tax rate at the moment it's **added** to the order — a receipt is built
> entirely from that snapshot, never by joining back to the live catalog — so a line
> already on a receipt reprints exactly as it did the day it was rung up, forever, no
> matter when the order itself closes. This is proven end-to-end in
> `scripts/e2e-admin-day.sh`: it reprices a variant right after a sale and re-fetches
> that same order's receipt to confirm the total didn't move.
>
> This matters most on a **long-open tab**: if you reprice a variant while a table's tab
> has been open for an hour, the round of drinks they already ordered keeps the old
> price — only a *new* course added to that same tab, after the reprice saves, picks up
> the new one. The same tab can carry both prices at once, correctly, and that's the
> conversation to have ready if a customer asks why two rounds of the same drink came
> out different on the check.

### Archive vs delete

There is no delete button anywhere in the back office, for anything. Unchecking
**Active** and tapping **Save** on a product, variant, discount, or tax rate **archives**
it instead — the row leaves the register's menu but every past order line, receipt, and
report that points at it keeps working, forever. Unchecking **Active** prompts a confirm
first:

> **"Archive *Name*? It leaves the register catalog but stays in history."**

Accept the dialog to go through with it, or dismiss it to back out and leave the row
untouched.

An archived row shows greyed with an **ARCHIVED** badge and an **Unarchive** button in
its table — tapping **Unarchive** brings it straight back, no confirm needed (there's
nothing destructive about restoring something).

> Note: archiving isn't instant at the till. The register's menu grid caches the catalog
> for **five minutes** before it re-checks the server, so a product you just archived can
> still show up on a till's screen for up to five minutes afterward — normal, not a bug.
> A barcode scan for the same item, by contrast, hits the server on every scan, so a
> scanned archived item stops ringing up immediately.

## Users

### Hire a cashier — or a supervisor

1. In **Users**, tap **New user**.
2. Fill in **Name**, and either an **Email**, a **PIN**, or both — one of the two is
   required for a new hire ("Enter an email or a PIN.").
3. Leave **Admin** unchecked for regular staff.
4. Under **Roles**, pick a location in **Add location**, pick a role in **Add role**,
   then tap **Add**. Repeat for every location this person works at.
5. Tap **Save**.

> Note: roles are per location — the same person can hold one role at one store and a
> different one at another (see Getting Started's [Roles](00-getting-started.md#roles)).
> Tap **Remove** next to a row in the Roles table to drop a location assignment.

> Note: **Cashier** and **Supervisor** ship as the two starting roles, but roles are no
> longer fixed — a manager holding **Manage roles** can add, rename, or reshape one from
> **Users → Roles** (below), and the **Add role** picker offers whatever roles currently
> exist, not just those two.

### Manage roles

**Users → Roles** (a manager needs the **Manage roles** permission, or **Admin**, to see
this tab) lists every role: its **Name**, its permissions, and how many people currently
hold it. **Cashier** and **Supervisor** are built in — their permissions can be edited,
but not their name, so nothing that assumes those two roles exist by name ever breaks.
Tap **New role** to add one of your own (a name plus a checklist of permissions, grouped
the same way this guide groups them); tap **Edit** on any role to change its permission
set. A role you added yourself can also be renamed or removed — removal is refused while
anyone still holds it, so unassign it from every user first.

### Give someone back-office access

Back-office access is no longer all-or-nothing. Checking **Admin** on a user record
still gives full access to every section at every location, same as before. Short of
that, granting **any** back-office permission — through a role, or a one-off direct
grant added right on the user's own record — is enough by itself: that person signs in
with their own email and password and sees exactly the sections their permissions
unlock, nothing more. A user granted only **Sales report** access, for instance, signs
in to **Reports** and nothing else. There's no longer a gap here waiting on a
bookkeeper role — grant exactly the one permission a bookkeeper needs.

### Deactivate a leaver

Open their user record, uncheck **Active**, and tap **Save**. You'll see:

> **"Deactivate *Name*? They keep their history but can no longer sign in."**

Their name still shows correctly on every past order, audit entry, and report they
touched — deactivating never rewrites history, it only blocks a future sign-in. The row
greys out with an **INACTIVE** badge and a **Reactivate** button if you need to bring
them back later.

### The self-lockout guard

You cannot uncheck your **own** **Admin** box, and you cannot uncheck your **own**
**Active** box — either one, on your own account, is refused with:

> **"You cannot remove your own admin access or deactivate your own account."**

This exists because there's exactly one admin tier and no supervisor fallback above it
(`05-rbac.md`) — if you could lock yourself out, there might be no one left who could
undo it. Have a second admin make the change if one genuinely needs to leave.

## Locations & Registers

### Location settings

**Locations & Registers** → **Locations** tab. Editing a location: **Name**, **Code**,
**Timezone** (typed against the IANA list — e.g. `America/Chicago` — and refused if it
doesn't match one), **Prices include tax**, **Receipt header**, **Receipt footer**,
**Variance approval threshold**, **Low stock threshold**.

> Note: **"Applies to future orders only — orders already open keep the pricing basis
> they started with."** Flipping **Prices include tax** mid-shift doesn't retroactively
> change how an order already open is taxed.

> Note: **Variance approval threshold** and **Low stock threshold** are optional —
> leave either blank and this location uses the store-wide default everyone starts
> with. Set one to override it just for this location: a busier store might tolerate a
> larger drawer variance before a shift close needs a supervisor's sign-off, and a
> store that turns over stock faster might want its low-stock warning to fire earlier.
> Clearing a field you'd previously set — save it blank again — puts the location back
> on the store-wide default; it doesn't turn the threshold off.

A location can be deactivated the same way as everything else — uncheck **Active**,
confirm **"Deactivate *Name*? Its history stays, but staff can no longer sign in
there."** — and reinstated later with **Reactivate**.

### Flip a register to food mode

**Locations & Registers** → **Registers** tab → **Edit** on the till.

1. Under **Mode**, tap **Food** (or **Retail** to switch back).
2. Tap **Save**.

`registers.mode` is what decides which screen that till's app renders next time someone
signs in there — a retail till shows the scan-and-cart screen, a food till shows the
floor and menu grid. There's no in-between; it's one or the other for the whole
register.

A register can be retired the same way as everything else here — uncheck **Active**,
confirm **"Deactivate *Name*? It can no longer clock in a shift."** — and brought back
with **Reactivate**.

### Issue an activation code

A till proves itself with a device token, but a manager never handles that token
directly — the back office only ever deals in **activation codes**, a short one-time
code the till itself exchanges for its device token. Every register's editor shows an
**Activation** status pill: **Enrolled**, **Code pending — expires *date***, **Code
expired**, or **Not enrolled**.

This is also how a brand-new register gets online for the first time — there's no
separate "first code" screen. **New register** → fill in **Location**, **Name**,
**Mode** → **Save** takes you back to the list (the **Activation** panel only appears
once the register exists); reopen that same till with **Edit** and the steps below
apply exactly as they do to replacing a lost or stolen terminal's code.

1. **Locations & Registers** → **Registers** tab → **Edit** on the till.
2. Under **Activation**, tap **Issue activation code**.
3. Confirm: **"Issue a new activation code for *Name*? The current till goes dark
   immediately."**
4. The new code appears once, in a copy-me plate: **"Activation code — single use,
   valid for 7 days. Copy it now, it will not be shown again:"** followed by the code
   itself (`XXXXX-XXXXX`). Copy it now — closing this screen and it's gone for good
   (it's never written anywhere the back office can show you again).
5. Type the code into the terminal's own **Activate this terminal** screen (Getting
   Started's [Signing in](00-getting-started.md#signing-in) section).

> Note: issuing a code revokes the register's current device token **and every staff
> session bound to it, in the same transaction** that stores the new code — there's no
> window where a lost terminal and its replacement are both live. The old till drops
> back to a **Terminal disabled** screen the instant it next tries to talk to the
> server — **"Your activation code has been disabled. Please contact an admin and
> request a new activation code."**, with the activation-code entry form right below
> it — so anyone still holding it is locked out immediately, not eventually, and can
> get back in the moment the new code is typed in.
>
> Note: an activation code is single-use and expires 7 days after it's issued. A code
> that expired unused shows **Code expired** on the status pill — issue a fresh one the
> same way.

## Settings

**Settings** (visible to **Admin** or anyone granted the **Manage settings**
permission) holds the business identity that prints on every receipt: **Business
name**, **Business address**, **Business tax ID**. Each field shows whether it's
currently set here or falling back to the value the system shipped with, and clearing a
field back to blank and saving returns it to that default — the same "blank means use
the default" behavior as the per-location thresholds above.

1. **Settings** → edit any field.
2. Tap **Save**.

> Note: unlike a price or a tax rate, business identity is never snapshotted onto an
> order — a change here shows up immediately, on a **reprint of an old receipt too**,
> the same way a letterhead change applies to every letter from then on, old and new
> alike. That's different from Catalog (Chapter 9), where a repriced product's past
> receipts deliberately never change.

## End of Day

**End of Day** (visible to **Admin** or anyone granted the **Close business day**
permission) closes one location's trading day: you reconcile what every till did, record
the cash going to the bank, and freeze the result. It's the layer above a drawer — a
shift closes one register, End of Day closes the whole store for that date.

The screen always shows one **location** (from the switcher above the rail) and one
**Business date**, which starts on that location's *own* local today. That matters at a
store in a different timezone from you: the date shown is the store's day, not your
browser's. You can pick an earlier date, but never a later one.

> Note: "never a later one" includes tonight. A business date is the store's *calendar*
> day, so tomorrow only becomes pickable when the store's clock passes midnight — not
> when you finish closing. Close at 11:17 PM and the next date stays greyed out until
> 12:00 AM. That's protection, not a bug: a day that hasn't started has nothing on it,
> and if it could be picked tonight it could be *closed* tonight — which would stop
> every till from opening in the morning until an admin reopened the day.

### Close the day

1. **End of Day** → check the **Business date** is the day you mean.
2. Read the pills across the top. Anything in amber is a **blocker** — **"N open
   shift(s) — close them first"** or **"N open order(s)"** — and **Close day** stays
   greyed out until both are clear.
3. Check the **Consolidated totals** card: **Net sales**, **Tax**, **Expected cash**,
   **Counted cash**, **Variance**, and how many **Shifts** the day covered.
4. Fill in the **Close checklist**: tick **Cash drop confirmed**, type the **Deposit**
   going to the bank, and add **Spoilage / waste**, a **Note for tomorrow**, or a general
   **Note** if there's anything worth recording. Every one of these is optional — an
   empty checklist closes the day just fine, and what you did or didn't fill in is part
   of the record.
5. Tap **Close day** and confirm.

> Note: a blue **"N unapproved variance(s)"** pill is a *warning*, not a blocker — it
> counts drawers that came up over or short by more than the approval threshold and
> haven't been signed off yet. The day closes either way, deliberately: a day that
> refuses to close over one unsigned drawer is a day that gets closed by other means,
> and then there's no record at all. Chase the approval (Chapter 7's variance rules),
> but don't let it hold the close hostage.

### What closing actually does

Two things, and only two.

- **It freezes the day's numbers.** The totals are copied onto the record as they stood
  at close. If a refund lands against that date afterwards, the live reports move but the
  frozen record doesn't — that's the point of it.
- **It blocks new shifts.** Anyone trying to open a drawer at that location on that date
  is refused with **"The business day is closed. Reopen it before opening a shift."**

Nothing else changes. Refunds, reports, and variance approvals on that date all still
work — closing a day is a reconciliation milestone, not a lock on the ledger.

> Note: closing is a once-per-day act. Closing an already-closed day is refused with
> **"That day is already closed."** rather than quietly overwriting what's on file — so a
> second tap can never rewrite a deposit figure somebody already signed off on.

### Reopen a closed day

Reopening is **Admin only** — not even a manager holding **Close business day** can do
it, because reopening is what un-blocks trading on a date that was already signed off.

1. **End of Day** → pick the closed date. The screen shows a green **Day closed** pill
   and the checklist as it was filed.
2. Type a **Reason for reopening** — it's required, and **Reopen day** stays disabled
   until you've written one.
3. Tap **Reopen day** and confirm.

Shifts can open on that date again immediately. When the day is closed a second time, the
totals are re-snapshotted from scratch, so the record reflects whatever happened in
between. Every close and every reopen — with its reason — lands in the audit log.

## Reports

### Read the sales report

**Reports** → **Sales** tab: pick a **From**/**To** date range, then a group-by tab —
**Day**, **Category**, or **User**. The location comes from the sidebar's **location
switcher** — change it there and the report refetches for the new location.

> **"Basis: ledger (captured payments & refunds)"** shows under **Day** and **User** —
> these are summed from actual payments and refunds that moved money, with columns
> **Orders closed**, **Gross**, **Refunds**, **Net**.
>
> **"Basis: line-based sales mix"** shows under **Category** — this is summed from order
> lines instead (joined to the *current* category names, which a report is allowed to do
> even though a receipt never may), with columns **Qty sold** and **Line total**.

> Note: these two bases are **not required to reconcile**, and that's by design, not a
> bug to chase down. A payment covers a whole order at once; a category breakdown has to
> attribute individual lines. They're answering different questions — "how much cash and
> card came in" versus "what sold" — so don't expect the Day total and the sum of the
> Category totals to match to the cent.

### Check stock and low-stock items

**Reports** → **Stock** tab: the location comes from the sidebar's **location
switcher**; optionally tap **Low only** to filter down to items running short. The
table shows **SKU**, **Name**, **Qty** — a row under threshold is highlighted and its
quantity marked **— LOW**.

### Export CSV

On the **Sales** tab, tap **Export CSV** to download the report exactly as displayed —
same date range, same location, same group-by — as a spreadsheet-ready file. Money
figures land as plain decimal strings rather than currency-formatted text, so the file
drops straight into a spreadsheet without any reformatting before it can be totaled or
charted.

## Audit

### Investigate with the audit trail

**Audit** shows every write anywhere in the system — the register and the back office
alike — one row per change: **When**, **Action**, **Entity**, **User**, **Register**,
**Payload**.

1. Narrow down with **Entity type** (a dropdown of every recorded kind — Order, Payment,
   Refund, User, Product, and so on), **Entity id**, **User id**, **Action**, and a
   **From**/**To** date range.
2. Tap **Filter** to apply them.
3. Tap **Load more** to bring in the next page (50 rows at a time) without losing the
   rows already on screen.

Each row's **Payload** column, where there is one, is collapsed behind a **Payload**
disclosure — tap it to see the raw JSON of what changed. A reprice, for instance, logs
the variant's price both **from** and **to**, so "who changed this and what was it
before" is always answerable without asking anyone to remember.

> Note: this is where a store owner actually finds out who did what — a repriced variant
> shows up as `admin.variant.update`, an activation-code issue as
> `admin.register.code_issue`,
> a new product as `admin.product.create`, and so on for every entity — not a separate
> history screen per entity.

## See also

- [Getting Started](00-getting-started.md) — the three surfaces, roles, and how each
  one signs in.
- [Supervisor Guide](02-supervisor-guide.md) — the discount picker and refund flow a
  supervisor uses at the till, once you've set up a discount here.
