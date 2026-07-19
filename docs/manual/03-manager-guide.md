# Manager Guide

This is for the back office — the screens a manager uses to set up the menu, hire and
manage staff, configure locations and registers, and read what the stores did. It's a
separate application from the register (see Getting Started's
[three surfaces](00-getting-started.md#the-three-surfaces)): a laptop, not a till, and an
email/password sign-in rather than a device token and a PIN.

> Note: signing in to the back office is **admin-only** in this version — there's no
> supervisor or bookkeeper tier that can see reports without also being able to change
> the catalog or deactivate staff. See [Roles](00-getting-started.md#roles) and
> `05-rbac.md` for why.

## Sign in

The back office shows its own **Sign in** screen — email and password, no till and no
device token involved.

1. Enter your **Email** and **Password**.
2. Tap **Sign in**.

> Note: a wrong email, a wrong password, a deactivated account, and an account that
> isn't an admin all answer with the same message — **"The email or password is
> incorrect."** — on purpose. A more specific answer would let someone probe which
> emails exist in the system.

Once in, six sections sit in a rail down the left, grouped under two headings —
**Operations**: **Today**, **Catalog**, **Users**, **Locations & Registers**;
**Insights**: **Reports**, **Audit**. A **location switcher** sits above the rail;
**Today** reads whichever location it's set to (**Reports** and **Stock** keep their
own location pickers for now, unchanged). Tap **Sign out**, at the bottom of the rail,
when you're done.

## Today

**Today** is what you land on right after signing in — a glance at the location the
sidebar switcher is set to, right now, with nothing to configure.

- A row of four figures: **Net sales today**, **Orders closed**, and **Refunds
  today** — the same ledger numbers the Sales report shows under its **Day** basis
  (captured payments and refunds that actually moved money, for today only) — and
  **Low stock**, a count of variants at or below their reorder threshold (the Stock
  report's **Low only** filter).
- **Needs attention**: every low-stock variant (name and quantity) and every inactive
  register at this location, each with a colored status dot — yellow for low stock,
  red for a register that can't clock in a shift. Nothing to flag shows **"All
  clear."** instead of an empty table.
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

> Note: **Price** and **Cost** are typed as dollars (e.g. `4.25`) but travel the wire as
> cents — a blank or unparseable price is refused before it ever reaches the server
> ("Enter a valid price (e.g. 4.25).").

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
4. Under **Roles**, pick a location in **Add location**, pick **Cashier** or
   **Supervisor** in **Add role**, then tap **Add**. Repeat for every location this
   person works at.
5. Tap **Save**.

> Note: roles are per location — the same person can be a cashier at one store and a
> supervisor at another (see Getting Started's [Roles](00-getting-started.md#roles)).
> Tap **Remove** next to a row in the Roles table to drop a location assignment.

### Give someone back-office access

Check **Admin** on their user record and save. There's no in-between tier yet — admin
is full access to everything under **Catalog**, **Users**, **Locations & Registers**,
**Reports**, and **Audit**, or nothing. A read-only bookkeeper role is a named gap in
this version (`05-rbac.md`), waiting on the first person who actually needs it.

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
doesn't match one), **Prices include tax**, **Receipt header**, **Receipt footer**.

> Note: **"Applies to future orders only — orders already open keep the pricing basis
> they started with."** Flipping **Prices include tax** mid-shift doesn't retroactively
> change how an order already open is taxed.

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

### Replace a lost terminal

A register's device token is its identity — whoever holds it can act as that till. If
one goes missing (a stolen tablet, a till that needs re-imaging), reissue its token
rather than trying to track the old one down.

This is also how a brand-new register gets its very first token — there's no separate
"issue a token" screen. **New register** → fill in **Location**, **Name**, **Mode** →
**Save** takes you back to the list without a token in sight (the editor doesn't offer
one for a register that doesn't exist yet); reopen that same till with **Edit** and the
steps below apply exactly as they do to a lost terminal.

1. **Locations & Registers** → **Registers** tab → **Edit** on the till.
2. Under **Device token**, tap **Reissue token**.
3. Confirm: **"Reissue *Name*'s token? The current till goes dark immediately."**
4. The new token appears once, in a copy-me plate: **"New token — copy it now, it will
   not be shown again:"** followed by the token itself. Copy it now — closing this
   screen and it's gone for good (it's never written anywhere the back office can show
   you again).
5. Enroll the replacement terminal with it (Getting Started's
   [Signing in](00-getting-started.md#signing-in) section).

> Note: reissuing kills every existing token for that register **in the same
> transaction** that mints the new one — there's no window where the lost terminal and
> its replacement are both live. The old till drops back to **Enroll this terminal** the
> instant it next tries to talk to the server; anyone still holding it is locked out
> immediately, not eventually.

## Reports

### Read the sales report

**Reports** → **Sales** tab: pick a **From**/**To** date range and a **Location**, then
a group-by chip — **Day**, **Category**, or **User**.

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

**Reports** → **Stock** tab: pick a **Location**, optionally tap **Low only** to filter
down to items running short. The table shows **SKU**, **Name**, **Qty** — a row under
threshold is highlighted and its quantity marked **— LOW**.

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
> shows up as `admin.variant.update`, a token reissue as `admin.register.token_reissue`,
> a new product as `admin.product.create`, and so on for every entity — not a separate
> history screen per entity.

## See also

- [Getting Started](00-getting-started.md) — the three surfaces, roles, and how each
  one signs in.
- [Supervisor Guide](02-supervisor-guide.md) — the discount picker and refund flow a
  supervisor uses at the till, once you've set up a discount here.
