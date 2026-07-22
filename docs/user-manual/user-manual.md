# POS — User Manual

For the people who run the store: cashiers, supervisors, managers, and the
operator who installs the system. Covers the register and the back office.

## Revision History

| Version | Date | Changes |
| --- | --- | --- |
| 1.0 | 2026-07-22 | First edition: register, back office, troubleshooting, FAQ, glossary. |

# 1. Introduction

This manual covers everything you need to run the store day to day, and
everything a manager needs to set it up. It's written for four kinds of
reader, and you don't need to read the parts that aren't yours:

- **Cashiers** ring up sales and take payments.
- **Supervisors** do everything a cashier does, plus the actions that let
  money or stock leave the business without a customer noticing — voids,
  discounts, refunds, and approving a drawer that doesn't balance.
- **Managers** (admins) run the back office — the menu, staff, locations, and
  reports.
- **Operators** install and keep the system running.

The chapters are ordered the way the system is actually used: the overview
comes first (Chapters 2–3), then the register — the till a cashier or
supervisor stands at (Chapters 4–7, 14), then the back office a manager signs
into from a laptop (Chapters 8–13). Troubleshooting, an FAQ, and a glossary
sit at the back for when something goes wrong or a term is unfamiliar.

A few conventions hold throughout:

- Anything you tap or click — a button, a tab, a checkbox — appears in
  **bold**, exactly as it reads on screen: **Activate**, **Sign in**, **New
  user**.
- Money amounts are shown in pesos, because every screenshot in this manual
  comes from the same demo store in Manila — the figures and the prose always
  agree on what's on screen.
- **"Till"** means the same thing as **"register"** throughout this manual —
  a physical terminal, not a person. A store has several tills; each one is
  set up once and then used every shift after that.

# 2. System overview

## One order, two ways of selling

Underneath, every sale is the same thing: **open an order, add lines to it,
take money, close it.** The system doesn't run two separate programs for
retail and food service — it runs one order model and lets the till compress
or expand it:

- **Retail** moves through that lifecycle in well under a minute, so the
  till collapses it into a single cart screen: scan, tap **Pay**, done.
- **Food service** lingers in the open phase for an hour or more, so that
  phase gets its own screen — a table, a running tab, courses fired to the
  kitchen — before the till ever asks for money.

```mermaid
flowchart LR
  O[Open order] --> L[Add lines] --> P[Take payment] --> C[Closed]
  C --> R[Refund - new rows, original untouched]
```

A closed order is never edited. A refund doesn't erase or change the
original sale — it adds new rows on top of it, so the receipt from the day of
the sale still reprints exactly as it did that day, and the refund has its
own paper trail.

Cash accountability follows the same shape, one level up: a till's shift
opens with a counted float, runs sales and cash movements, and closes with
another count.

```mermaid
flowchart LR
  A[Open shift - count float] --> B[Sales and cash movements] --> Z[Z-report] --> X[Close - count drawer]
  X --> V{Variance over threshold?}
  V -- yes --> S[Supervisor approval from another register]
  V -- no --> D[Done]
```

If the counted drawer doesn't match what the system expects within a small
threshold, closing records a **variance** that a supervisor has to approve —
from a *different* till at the same location, never the one that just
closed. Chapter 7 covers why.

## The three surfaces

| Surface | Who uses it | How it signs in |
| --- | --- | --- |
| **Register** | Cashiers and supervisors, at a physical till | An activation code (once, per terminal — exchanged for a device token) plus a staff PIN (every shift) |
| **Back office** | Managers | An email and password — no till involved |
| **API** | The two apps above, and nothing else you should be talking to directly | Whatever the calling app is signed in as |

The register and the back office are separate applications, on separate
screens — a cashier's till never loads back-office code, and a manager's
laptop never needs a device token. Both talk to the same server underneath,
so a sale rung up at the till shows up in the back office's reports
immediately, with nothing to sync or refresh by hand.

## Roles

Three roles cover everything, and they're deliberately coarse:

- **Cashier** — rings up sales, opens and closes their own shift, takes
  payments.
- **Supervisor** — everything a cashier can do, plus voids, discounts,
  refunds, and approving a variance.
- **Admin** — full back-office access: catalog, staff, locations, reports,
  the audit trail.

Cashier and supervisor are assigned **per location** — the same person can be
a cashier at one store and a supervisor at another, and the two never mix.
Admin is different: it's a property of the person, not a role tied to a
location, so an admin is an admin everywhere. There's no in-between tier yet
— you're an admin with full back-office access, or you have none of it.

## Locations and the demo store

Every screenshot in this manual comes from the same seeded demo business —
one company, three locations, all in the Asia/Manila timezone with prices
that already include tax:

| Code | Name |
| --- | --- |
| CAF | Manila Cafe |
| GRC | Manila Grocery |
| RST | Manila Restaurant |

Manila Grocery runs retail (scan and go), Manila Restaurant and Manila Cafe
run food service (tabs and tables). A location owns its own stock, its own
registers, and its own staff assignments — nothing about a role or a stock
count crosses from one location to another.

# 3. Getting started

## Activate a register (once per terminal)

The first time a till is turned on, it shows an **Activate this terminal**
screen and won't go any further until it's given an activation code.

![Figure 3.1 — Activation screen](assets/screenshots/001-activation.png)

The screen reads **"Enter the activation code issued for this terminal in the
back office."** — a manager issues that code, not the person standing at the
till. See [Issue an activation code](#issue-an-activation-code) in Chapter
11 for how; it also covers a brand-new till's very first code, since there's
no separate "first code" flow.

1. Get an activation code for this specific till from a manager.
2. Type it into **Activation code** (it's shaped like `XXXXX-XXXXX`).
3. Tap **Activate**.

The code is **single-use** and **expires after 7 days** — it's a one-time
credential the till exchanges for its own long-lived device token, not
something typed in more than once. From that point on, the device token,
never the code, is what proves this terminal's identity to the server.

Reissuing a code — for a lost till, or just as routine hygiene — kills the
*old* device token and every staff session bound to it, in the same instant
the new code is created. The old till doesn't get a grace period: it drops
straight to a **Terminal disabled** lockout screen the next time it talks to
the server. Chapter 11 shows that screen (Figure 11.5) and walks through
issuing a fresh code from the back office.

## Clock in with a PIN

Once a till is enrolled, every shift starts the same way: an **Enter PIN**
screen, shown whenever nobody's currently signed in.

![Figure 3.2 — Enter PIN](assets/screenshots/002-pin.png)

1. Type your 4–6 digit PIN.
2. Tap **Clock in**.

Five wrong PINs in a row locks that till out for 60 seconds. That's
deliberate: PINs are short on purpose, and the lockout is what makes a short
PIN safe to use at all.

## Sign in to the back office

The back office has its own **Sign in** screen — an email and a password,
nothing about a till or a device token involved.

![Figure 3.3 — Back-office sign in](assets/screenshots/021-bo-login.png)

1. Enter your **Email** and **Password**.
2. Tap **Sign in**.

Only admins can sign in here in this version — a wrong email, a wrong
password, a deactivated account, and an account that isn't an admin all
answer with the same message on purpose, so nobody can use the login screen
to probe which email addresses exist in the system.

### Common issues

| Symptom | Cause | Fix |
| --- | --- | --- |
| Activation code rejected | Expired (7 days) or already used | Get a fresh code from the back office (Chapter 11) |
| Till stuck on "Enter PIN", nothing happens | Five wrong PINs in a row | Wait 60 seconds, then try again |
| Back-office sign-in always says "incorrect" | Wrong email/password, account deactivated, or the account isn't an admin | All four cases look identical on purpose; ask another admin to check the account in Chapter 10 |

# 4. The register

A till moves through the same four stages every day, and each stage is just
whichever precondition isn't met yet — none of it is a setting:

1. **Activate this terminal** — until an activation code makes this terminal
   a register at all (Chapter 3).
2. **Enter PIN** — until somebody's clocked in (Chapter 3).
3. **Open shift** — until somebody's counted a float (Chapter 7).
4. A sale screen — the stage this chapter, and the next three, cover.

## Retail or food

What that sale screen looks like is one setting a manager makes in the back
office, not anything the till itself remembers — Chapter 11's
[Register mode](#register-mode) section covers changing it.

- **Retail** mode opens straight onto a scan field: **New sale**, an empty
  cart, and **Scan or type a barcode…** waiting for input. Nothing to tap
  before the first item goes in — Chapter 5 covers this mode start to
  finish.

![Figure 4.1 — Manila Grocery, Till 1, retail mode: an empty New sale screen](assets/screenshots/004-retail-empty.png)

- **Food** mode opens onto a category rail and a grid of item tiles instead,
  and adds one more link — **Tabs** — next to **Register** and **Refunds**
  (the next section covers the rest of the top bar). Chapter 6 covers the
  floor and the menu grid start to finish.

![Figure 4.2 — Manila Restaurant, Till 1, food mode: the menu grid, with Tabs alongside Register and Refunds](assets/screenshots/012-menu-grid.png)

There's no in-between and no per-shift toggle: a register is one mode or the
other, every shift, until a manager changes it.

## The top bar

Both figures above show the same header shape. On the left: **Register**
and **Refunds** (visible only if you can refund — Chapter 5), plus **Tabs**
on a food-mode till. On the right: the signed-in staff member's name and
**Clock out** — which ends only that PIN session, not the shift; the drawer
stays open for whoever clocks in next (Chapter 7). Just below that, the
screen's own heading row carries **Close shift** on the right — a different
button from **Clock out** above it, and available to whoever's clocked in
right now, cashier or supervisor alike.

# 5. Retail selling

Retail mode collapses the whole order lifecycle into one screen: scan,
**Pay**, tender, done. This chapter walks that path in order, using the same
sale — rung up at Manila Grocery, Till 1 — that every figure below comes
from.

## Scan or type a barcode

![Figure 5.1 — New sale, ready for the first scan](assets/screenshots/004-retail-empty.png)

1. Scan the item, or type its barcode into **Scan or type a barcode…** and
   press enter. The first scan opens the order for you — there's nothing to
   tap first.
2. Keep scanning. Each item lands in the cart below with its name and
   price; subtotal, tax, and total update as you go.

![Figure 5.2 — Three lines scanned: Nescafé Classic, Lucky Me! Pancit Canton, and Century Tuna Flakes](assets/screenshots/005-retail-cart.png)

The catalog's Fresh Market category (Figure 4.2) prices several items **per
kilogram** — Bangus, Dinorado Rice, Pork Liempo, Tilapia, Whole Chicken. A
scan or a tap always adds exactly one unit of whatever's scanned, though —
this version has no on-screen way for a cashier to type a weight in, so
"Bangus (per kg)" rings up as 1 kg at ₱220.00 unless the quantity is
corrected afterward, and there's no till button for that either (the same
documented gap Chapter 6 covers for a fired food line's quantity). A cart
line's quantity only shows on screen at all once it isn't a whole `1` — you
see that happen in practice on a split check, in Chapter 6.

## Void a line

Tap **Void** on any cart row to remove it, or **Void order** below the cart
to remove every line at once. Both only appear for a supervisor's PIN
session ([Roles](#roles), Chapter 2) — a cashier's own screen doesn't show
either button at all.

## Apply a discount

1. Tap **Discount**.
2. Type why into **Reason (required)…** — every discount stays disabled
   until you do.
3. Tap the discount by name to apply it to the whole order.

![Figure 5.3 — The discount panel open: 10% off and ₱50 off, reason not yet typed](assets/screenshots/006-retail-discount.png)

![Figure 5.4 — 10% off applied: subtotal ₱405.00, discount -₱45.00, tax recalculated on the discounted total](assets/screenshots/007-retail-discounted.png)

To remove one, tap the **✕** next to it in the cart. Like voiding,
**Discount** only appears for a supervisor. The back office's Discounts tab
(Chapter 9) carries its own **Requires supervisor** flag per discount, but
every discount already needs one today regardless of that flag, since only
the supervisor role holds the permission to open the panel at all.

## Pay, tender, and print

Tap **Pay — [amount]** (the button shows exactly what's owed).

- **Cash:** type what the customer handed you into **Cash tendered (owed:
  …)**, then tap **Take payment**. The next screen works out **Change** for
  you — never do that math yourself.

![Figure 5.5 — Cash tender: ₱500.00 tendered against ₱405.00 owed](assets/screenshots/008-retail-tender.png)

![Figure 5.6 — Payment complete: ₱95.00 change, receipt ready to print](assets/screenshots/009-retail-receipt.png)

- **Card:** type the terminal's own authorization into **Card terminal
  reference (owed: …)** instead, then tap **Take payment**. This till only
  *records* what the card terminal already did — it doesn't talk to a card
  terminal itself.

Tap **Print** for a paper receipt, or **New sale** to move on. In the
desktop shell, **Print** sends the receipt to the till's receipt printer; in
an ordinary browser tab, it opens the browser's own print dialog — Chapter
14 covers both.

## Refund a closed sale

Tap **Refunds** in the top bar (only visible if you can refund).

1. Type the receipt number into **Receipt number** and tap **Find order**.

![Figure 5.7 — Refund a sale, ready for a receipt number](assets/screenshots/010-refunds.png)

2. For each line being returned, type the quantity to refund. Leave
   **Restock** checked to put that stock back, or clear it if the goods are
   damaged.
3. Type a **Reason** and tap **Refund cash**.

The refund always comes out of *this* drawer as cash, whatever the sale was
originally paid with. Only a **closed** order can be refunded.

### Common issues

| Symptom | Cause | Fix |
| --- | --- | --- |
| "The requested resource does not exist." on a scan | The barcode doesn't match any active variant | Check the barcode, or find the item by its product/variant in the back office (Chapter 9) |
| No **Discount** or **Void** button on screen | You're signed in as a cashier — both only render for a supervisor | Ask a supervisor, or have them clock in to do it themselves ([Roles](#roles), Chapter 2) |
| "This refund would return more than was sold on this line." | The typed quantity is more than what's left on that line, net of any earlier refund | Refund no more than what's left on the line |

# 6. Food service

A food-mode till trades the scan-first screen for a floor of open tabs and a
menu grid, because a table lingers in the open phase for an hour or more
instead of closing in under a minute. Every figure below comes from Manila
Restaurant, Till 1.

## The floor and opening a tab

Tap **Tabs** to see every open tab at this location — the **Floor**. Tap
**Register** to come back to the sale screen.

![Figure 6.1 — The Floor, no tabs open yet](assets/screenshots/011-floor.png)

1. Tap **New tab**.
2. Optionally type the table into **Table (optional)…** — leave it blank
   for a walk-in or a bar tab.
3. Tap **Open tab**.

You land on the sale screen with an empty order already seated at that
table.

## The menu grid and modifiers

Food-mode tills show a category rail and a grid of tiles instead of relying
only on the scanner. Tap a category, then tap an item's tile to add it.

![Figure 6.2 — The menu grid: Fresh Market category, including several per-kilogram items](assets/screenshots/012-menu-grid.png)

If an item has no modifiers, it goes straight into the cart. If it has any,
a sheet pops up named after the item:

1. A group marked **required** needs a pick before **Add** goes live —
   **Rice** is required on Chicken Adobo, so **Add** stays disabled until
   one of **Plain Rice**, **Garlic Rice**, or **No Rice** is picked.
2. Tap a choice to select it; tap a repeat-legal one again to add a second
   (the chip would show `×2`).
3. Tap **Add**, or **Cancel** to back out.

![Figure 6.3 — Chicken Adobo's modifier sheet: Garlic Rice (+₱20.00) and Extra Egg (+₱25.00) picked, No Rice showing as -₱15.00](assets/screenshots/013-modifier-sheet.png)

A modifier's price is a **signed delta** on top of the item, not always an
extra charge — most add to the price (**Garlic Rice** +₱20.00, **Extra Egg**
+₱25.00 above), but **No Rice** *subtracts* ₱15.00 instead, because leaving
the rice off costs the kitchen less to make. Figure 6.3's picks land in the
cart as ₱265.00 for the Chicken Adobo (its own price, plus the two
modifiers), right alongside a plain Halo-Halo:

![Figure 6.4 — The tab's cart: Chicken Adobo with its two modifiers indented beneath it, and a plain Halo-Halo](assets/screenshots/014-tab-cart.png)

## Courses and firing

Every line the kitchen tracks carries a chip reading **Pending**,
**Cooking**, or **Ready**. Tap it to move the course forward: **Pending** →
**Cooking** (fires it to the kitchen) → **Ready** (on the pass) → back to
**Pending**. Sending a **Ready** or **Cooking** course back a step, or
shrinking its quantity once it's fired, needs the same authority as voiding
it outright — downgrading a course the kitchen already started is the same
fraud surface as removing it. A cashier's own tap on the chip only ever
moves it forward.

> Note: this version's till has no on-screen field to *change* an existing
> line's quantity at all — only **Void** (remove it entirely) and the prep
> chip (move it through **Pending** / **Cooking** / **Ready**) are exposed
> here. A genuine quantity correction on a fired line, or increasing a
> plain line's count, currently goes through the API directly rather than a
> till button.

## Transfer a tab to another register

On the Floor screen, a tab carries a **Transfer** chip (only if you can
transfer, and only when another till is available to receive it).

1. Tap **Transfer** under the tab.
2. Under **Send to**, tap the destination till.

![Figure 6.5 — Transferring T1 (₱415.00) from Till 1 to Till 2](assets/screenshots/016-transfer.png)

Only a register with an **open shift right now** shows up as a destination
— the transfer moves the tab's money onto *that* shift's ledger the instant
it lands, so the receiving till's drawer owns the sale from that point on,
not the one that rang it up.

## Split the check

Once you tap **Pay**, and only on an order that hasn't taken any payment
yet, a **Split bill** button appears alongside the tender form.

1. Tap **Split bill**.
2. Use the stepper's **−** / **+** to choose how many checks (2 to 10).

![Figure 6.6 — Splitting the RST-20260722-0001 tab into 2 checks, ₱207.50 each](assets/screenshots/015-split.png)

3. Tap **GO**. You're now looking at the first check, with a strip along
   the top showing **Check 1**, **Check 2**, and so on — take payment on
   each exactly like any other sale, and the next unpaid one opens
   automatically as soon as one closes.

Two even checks, like Figure 6.6's ₱207.50 and ₱207.50, split cleanly. A
three-way split rarely does — the totals still always sum back exactly to
the original, so a line's quantity on a split check can come out as a
fraction (`0.334`, say) rather than a whole number. That's the split
dividing everything exactly, not a mistake — the same fractional-quantity
field every order line carries, on show here instead of sitting at `1`.

# 7. Shifts and cash

A till's shift is the drawer's own accountability loop, one level above a
single sale: open with a counted float, run the day's sales and cash
movements, then close by counting again.

## Open with a counted float

If nobody's opened the drawer on this till yet, you land on **Open shift**
instead of a sale screen.

![Figure 7.1 — Open shift: opening float defaulted to ₱200.00](assets/screenshots/003-open-shift.png)

Check or edit the **Opening float**, then tap **Open drawer** — you're
dropped straight into a new sale. If a shift's already open on this till,
you skip this screen entirely.

## Cash movements

A payout, a deposit, a paid-in — any cash that moves in or out of the
drawer without a sale attached — is a real, audited action, and a reason is
required for all of them. This version's till has no on-screen form to
*record* one; the drawer only ever *displays* what's already been recorded
against it, in the Z-report below. Recording a movement currently goes
through the API directly rather than a till button.

## The Z-report — fetched before you close

The Z-report shows up as part of the *same* screen as closing itself, not a
separate report you pull up first and close afterward — and that's not
just a matter of button order. **Closing a shift revokes every staff
session bound to that register the instant it closes**, so the only moment
this till can ever show its own Z-report is right there on the close-shift
result, before the very session showing it goes dead. There's no
"check the Z-report, then close" sequence available on this till at all —
the numbers arrive together with the close.

## Close and count

1. Tap **Close shift**, in the sale screen's own header row — not the top
   bar, which just holds your name and **Clock out**.
2. Physically count the drawer *before* looking at anything on screen — the
   expected figure stays masked (`•••••`) until after you submit your
   count.

![Figure 7.2 — Close shift: Counted cash blank, Expected cash masked](assets/screenshots/017-close-shift.png)

3. Type what you counted into **Counted cash** and tap **Close**.

The masking is deliberate: seeing the expected total first turns counting
into just typing the number back, and a real drawer problem never surfaces.
Count first, then look.

## Variance and approval

The result — **Drawer reconciled** — reveals **Expected**, **Counted**, and
**Variance**, plus the Z-report itself: sales and refunds by tender, **Paid
in**, **Payouts**, **Drops**, and **Orders closed** / **Orders voided** /
**Orders split**.

![Figure 7.3 — Drawer reconciled: Expected ₱615.00, Counted ₱150.00, Variance -₱465.00, over the threshold](assets/screenshots/018-z-report.png)

A variance inside the store's threshold reconciles clean, no extra step.
Figure 7.3's -₱465.00 is well outside it, so the screen shows **Variance
exceeds the threshold — needs supervisor approval.** with an **Approve
variance** button — one that always fails if tapped right here. Closing
this shift already revoked this register's own sessions the instant it
closed (above), so the very screen offering that button is already running
on a dead one; tapping it just signs you back out to **Enter PIN**.

The rule behind it is real, and correctly scoped to the *location*, not one
specific terminal: a supervisor approves it from a **different, still-open**
register at the same location — never the till that just closed, because
that till's sessions are already gone. Approving today means calling the
API directly from that other till's session, the same way
`scripts/e2e-lunch-service.sh` proves it end to end — no register screen
anywhere renders a working **Approve variance** button for a shift other
than its own, so this is done outside any till's screen entirely, on
purpose, not a bug waiting to be routed around.

Tap **Print** for a paper copy of the Z-report, then **Done**. Closing signs
you out of this till completely — you land back at **Enter PIN**.

# 8. The back office

## Layout

Everything in the back office sits behind one sign-in. Once you're in, a
rail down the left holds six sections under two headings — **Operations**:
**Today**, **Catalog**, **Users**, **Locations & Registers**; **Insights**:
**Reports**, **Audit**. A **location switcher** sits above the rail, and your
name plus a **Sign out** button sit at the bottom.

**Today**, **Reports**, and the **Stock** report all read whichever location
the switcher is set to — there's no separate location picker on each of
those screens. Change the location once, at the top, and every screen that
cares about it follows along.

## Today

**Today** is what you land on right after signing in — a glance at whichever
location the switcher is set to, right now, with nothing to configure.

![Figure 8.1 — The Today landing screen, Manila Cafe](assets/screenshots/022-bo-today.png)

The screen shows:

- A row of four figures — **Net sales today**, **Orders closed**, **Refunds
  today**, and **Low stock** — the same numbers the Sales and Stock reports
  show, just for today and this location.
- **Needs attention** — a table of every low-stock variant and every
  inactive register at this location. Nothing to flag shows **"All clear"**
  instead of an empty table, exactly as it does in the figure above.
- **Recent activity** — the first page of the audit log (When, Action,
  User), trimmed to a glance.

Every number on **Today** already exists somewhere else in the back office —
gathered onto one screen rather than computed specially. If a figure here
ever looks off, the matching report (Reports, Stock, Locations & Registers,
Audit) is where to double-check it.

# 9. Catalog

**Catalog** is where the menu lives — categories, products and their
variants, modifier groups, discounts, and tax rates, each on its own tab.

![Figure 9.1 — The Products tab](assets/screenshots/023-bo-catalog.png)

## Products and variants

A **product** is the thing on the menu — a name, a description, a category.
A **variant** is what's actually sold under that product: its own SKU,
barcode, price, cost, and tax rate. Every product needs at least one variant
before it can be rung up at all.

1. In **Catalog**, tap **Products**, then **New**.
2. Fill in **Name**, **Description**, **Category**, and **Kind** (**Goods**
   or **Service**).
3. Tap **Save**.
4. Tap **Variants**, then **New**.
5. Pick the product, then fill in **Name**, **SKU**, **Barcode** (optional),
   **Price**, **Cost** (optional), **Tax rate**, and **Track inventory**.
6. Tap **Save**.

Prices and costs are typed as pesos (e.g. `120.00`) but travel to the server
as cents — a blank or unparseable price is refused before it's ever sent.

## Modifier groups

To attach a modifier group (a coffee's milk, a dish's extras) to a product,
open the product again — a **Modifier groups** panel at the bottom lists
every group as a checkbox.

![Figure 9.2 — Editing Chicken Adobo, with Rice ticked #1 and Add-ons #2](assets/screenshots/024-bo-product.png)

The figure above shows **Chicken Adobo** with **Rice** ticked first (shown
as **#1**) and **Add-ons** ticked second (**#2**) — the order you tick
groups in is recorded against each checkbox. Tapping **Save modifier
groups** is a separate action from the product's own **Save** button above
it, and it's a full replace of the product's attached groups, not an
add-one/remove-one — so an untouched box you meant to keep still has to be
ticked before you save.

Categories and modifier groups themselves have no archive toggle — only
products, their variants, and the modifiers inside a group, do. A group
with **Min select** at 1 or more shows up as *required* at the till: the
cashier can't confirm an item until every required group has a pick.

## Discounts and tax rates

**Discounts** (its own tab): **Name**, **Kind** (**Percent** or **Fixed
amount**), the matching value, **Scope** (**Order** or **Line**), **Requires
supervisor**, **Active**.

**Tax rates** (its own tab): **Name**, **Rate (%)** — typed as a percent
(e.g. `12.00`), stored and computed on the server as an exact fraction so
the math never drifts.

## Archive, never delete

There is no delete button anywhere in the back office. Unchecking **Active**
and tapping **Save** on a product, variant, discount, or tax rate **archives**
it instead — it leaves the register's menu, but every past order line,
receipt, and report that points at it keeps working, forever. A confirm
dialog asks first — **"Archive *Name*? It leaves the register catalog but
stays in history."** — and an archived row shows greyed with an **ARCHIVED**
badge and an **Unarchive** button.

### Common issues

| Symptom | Cause | Fix |
| --- | --- | --- |
| I archived an item and it's still on the till | The register's menu caches the catalog for 5 minutes | Normal — wait it out, or scan the barcode instead (a scan always hits the server) |
| A receipt from last month shows the old price after I repriced the variant | Every order line snapshots its price when it's added — receipts never look up the live catalog | Expected behavior, not a bug — see Chapter 12 for how this is proven |
| A new product has no Modifier groups panel | The panel only appears once the product has been saved once (so it has an id) | Save the product first, then open it again |

# 10. Users and roles

## Hire a cashier or a supervisor

1. In **Users**, tap **New user**.
2. Fill in **Name**, and either an **Email**, a **PIN**, or both — one of
   the two is required for a new hire.
3. Leave **Admin** unchecked for regular staff.
4. Under **Roles**, pick a location in **Add location**, pick **Cashier** or
   **Supervisor** in **Add role**, then tap **Add**. Repeat for every
   location this person works at.
5. Tap **Save**.

![Figure 10.1 — The Users list](assets/screenshots/025-bo-users.png)

The figure above shows the shape this takes in practice: **Alice Cashier**
holds the cashier role at all three locations, **Bob Supervisor** holds
supervisor at all three, **Maria Both** is a cashier at Manila Grocery and a
supervisor at Manila Restaurant, and **Priya Admin** — the only row with an
email and no location roles at all — is a full admin instead.

Saving the **Roles** table writes the *complete* set of that person's role
assignments in one action, the same way saving modifier groups does in
Chapter 9 — tap **Remove** next to a row to drop a single location
assignment before you save, rather than expecting to edit it after the fact.

## Give someone back-office access

Check **Admin** on their user record and save. There's no in-between tier
yet: admin is full access to **Catalog**, **Users**, **Locations &
Registers**, **Reports**, and **Audit**, or nothing.

## Deactivate a leaver

Open their user record, uncheck **Active**, and tap **Save** — you'll be
asked to confirm: **"Deactivate *Name*? They keep their history but can no
longer sign in."** Their name still shows correctly on every past order,
audit entry, and report they touched; deactivating only blocks a future
sign-in. A **Reactivate** button brings them back later if needed.

## The self-lockout guard

You cannot uncheck your **own** **Admin** box, and you cannot uncheck your
**own** **Active** box. Either one is refused outright, because there's
exactly one admin tier and no fallback above it — if you could lock
yourself out, there might be no one left who could undo it. Have a second
admin make the change if one genuinely needs to leave.

# 11. Locations and registers

## Location settings

**Locations & Registers** → **Locations** tab lists every store: **Code**,
**Name**, **Timezone**, **Prices include tax**, with an **Edit** action.

![Figure 11.1 — The Locations tab, all three seeded stores](assets/screenshots/027-bo-locations.png)

All three of the demo locations shown above — **Manila Cafe**, **Manila
Grocery**, **Manila Restaurant** — run on the `Asia/Manila` timezone with
**Prices include tax** set to **Yes**. Editing a location lets you change
its **Name**, **Code**, **Timezone** (checked against the IANA list),
**Prices include tax**, **Receipt header**, and **Receipt footer**. Flipping
**Prices include tax** only applies to future orders — an order already open
keeps the pricing basis it started with.

A location can be deactivated the same way as everything else in the back
office — uncheck **Active**, confirm, and staff can no longer sign in there,
though its history stays intact.

## Register mode

**Locations & Registers** → **Registers** tab lists every till across every
location, with its **Mode**.

![Figure 11.2 — The Registers tab across all three locations](assets/screenshots/028-bo-registers.png)

`Till 1` at Manila Grocery runs **retail** — the scan-and-cart screen; every
other till in the figure above runs **food** — the floor-and-menu-grid
screen. Flipping a register's mode is a two-tap edit:

1. **Locations & Registers** → **Registers** → **Edit** on the till.
2. Under **Mode**, tap **Retail** or **Food**.
3. Tap **Save**.

![Figure 11.3 — Editing Till 2 at Manila Restaurant, mode Food, status Enrolled](assets/screenshots/029-bo-register-editor.png)

There's no in-between — it's one mode or the other for the whole register,
and it decides which screen that till's app shows the next time someone
signs in there. A register can be deactivated the same way as a location;
**Reactivate** brings it back.

## Issue an activation code

A till proves itself with a device token, but a manager never handles that
token directly — the back office only ever deals in **activation codes**,
which the till itself exchanges for its device token. Every register's
editor shows an **Activation** status pill: **Enrolled**, **Code pending —
expires *date***, **Code expired**, or **Not enrolled** — Figure 11.3 above
shows **Enrolled**.

This is also how a brand-new register goes online for the first time — there
is no separate "first code" screen. **New register** → fill in **Location**,
**Name**, **Mode** → **Save**, then reopen that same till with **Edit** and
follow the steps below, exactly as they apply to replacing a lost or stolen
terminal's code.

1. **Locations & Registers** → **Registers** → **Edit** on the till.
2. Under **Activation**, tap **Issue activation code**.
3. Confirm: **"Issue a new activation code for *Name*? The current till goes
   dark immediately."**
4. The new code appears once, in a copy-me plate.
5. Type the code into the terminal's own **Activate this terminal** screen
   (Chapter 3).

![Figure 11.4 — A freshly issued code, single use, expires in 7 days](assets/screenshots/030-bo-activation-code.png)

The figure above shows the plate exactly as it appears: **"Activation code —
single use, valid for 7 days. Copy it now, it will not be shown again:"**
followed by the code itself, and the status pill already reading **"Code
pending — expires 2026-07-29"**. Copy it now — closing this screen and it's
gone for good; it's never written anywhere the back office can show again.

Issuing a code revokes the register's current device token *and* every staff
session bound to it, in the same transaction that stores the new code —
there's no window where a lost terminal and its replacement are both live.
The old till drops back to a lockout screen the instant it next tries to
talk to the server:

![Figure 11.5 — a reissued code locks the old terminal out](assets/screenshots/020-disabled.png)

The message reads exactly what it says: **"Your activation code has been
disabled. Please contact an admin and request a new activation code."**,
with the activation-code entry form right below it — so anyone still holding
the old terminal is locked out immediately, not eventually, and can get back
in the moment the new code is typed in. A code that expires unused (7 days)
shows **Code expired** instead — issue a fresh one the same way.

# 12. Reports

## Sales

**Reports** → **Sales** tab: pick a **From**/**To** date range, then a
group-by tab — **Day**, **Category**, or **User**. The location comes from
the sidebar's location switcher.

![Figure 12.1 — Sales by day, Manila Grocery, one closed order](assets/screenshots/031-bo-report-sales.png)

**Day** and **User** are **ledger-basis** — summed from actual payments and
refunds that moved money, with columns **Orders closed**, **Gross**,
**Refunds**, **Net**. The figure above shows exactly one order closed on
2026-07-22 at Manila Grocery: **₱405.00** gross, **₱0.00** refunded,
**₱405.00** net.

**Category** is **line-basis** instead — summed from order lines, joined to
*current* category names, with columns **Qty sold** and **Line total**.

These two bases are **not required to reconcile**, and that's by design, not
a bug to chase down. A payment covers a whole order at once; a category
breakdown has to attribute individual lines. They're answering different
questions — "how much cash and card came in" versus "what sold" — so don't
expect the Day total and the sum of the Category totals to match to the
cent.

Tap **Export CSV** on the **Sales** tab to download the report exactly as
displayed, with money figures as plain decimal strings rather than
currency-formatted text.

## Stock

**Reports** → **Stock** tab: tap **Low only** to filter down to items
running short. The table shows **SKU**, **Name**, **Qty** — a row under
threshold is highlighted and marked **— LOW**.

![Figure 12.2 — Stock levels at Manila Grocery](assets/screenshots/032-bo-report-stock.png)

# 13. Audit log

**Audit** shows every write anywhere in the system — the register and the
back office alike — one row per change: **When**, **Action**, **Entity**,
**User**, **Register**, **Payload**.

![Figure 13.1 — The audit log showing demo day activity at Manila Grocery](assets/screenshots/033-bo-audit.png)

The figure above shows what a shift's worth of activity looks like end to
end: a manager issuing an activation code (`admin.register.code_issue`) and
signing in (`admin.login`), then a supervisor's till closing a shift
(`shift.close`), taking a payment (`payment.take`), adding order lines
(`order.line.add`), and opening the order in the first place (`order.open`)
— each one tagged with the till it came from where one was involved.

1. Narrow down with **Entity type** (a dropdown of every recorded kind —
   Order, Payment, Refund, User, Product, and so on), **Entity id**, **User
   id**, **Action**, and a **From**/**To** date range.
2. Tap **Filter** to apply them.
3. Tap **Load more** to bring in the next page (50 rows at a time) without
   losing the rows already on screen.

Each row's **Payload** column, where there is one, is collapsed behind a
disclosure — tap it to see the raw JSON of what changed. A reprice, for
instance, logs the variant's price both **from** and **to**, so "who changed
this and what was it before" is always answerable without asking anyone to
remember.

This is where a store owner finds out who did what — a repriced variant
shows up as `admin.variant.update`, an activation-code issue as
`admin.register.code_issue`, a new product as `admin.product.create`, and so
on for every entity in the system — not a separate history screen per
entity.

# 14. The desktop shell and printing

The register also ships as a desktop app — a Tauri v2 shell that hosts the
very same SPA covered in Chapters 4–7, not a third frontend with its own
code. What the shell adds on top is a hardware bridge for the two things a
browser tab can't do on its own: drive a receipt printer and kick open a
cash drawer. The server still decides *what* happens — a sale closes, a
receipt is owed — the shell only decides *how* that reaches the hardware in
front of it.

## Printing

Tap **Print** on a completed sale (Chapter 5) or a closed shift's Z-report
(Chapter 7) exactly as you would in a browser tab. What happens next
depends on where the till is running:

- In an ordinary browser tab, **Print** opens the browser's own print
  dialog — nothing about the shell is involved.
- In the desktop shell, **Print** is meant to send the receipt straight to
  the till's thermal printer instead.

> Note: today, only a **mock** printer driver ships — it writes the receipt out to a file rather
> than actually driving hardware, so **Print** in the shell doesn't yet put
> paper through a real printer. A browser tab's print dialog is the one
> path here that already produces real paper, because it was never the
> shell's job to begin with.

## No-sale drawer opens

The top bar carries a **No sale** button, visible only in the desktop shell
(a browser tab has no drawer to pop, so it doesn't show one there) and only
if you can open the drawer this way. It's for opening the drawer with
nothing being sold — making change for another till, say.

1. Tap **No sale** in the top bar.
2. Type why in **Reason for opening the drawer…** (required).
3. Tap **Open drawer** to confirm — the No-sale form's own confirm button,
   not the one that starts a shift (Chapter 7); this one only pops the
   drawer.

The server records who opened it, at which register, and when, before the
drawer actually pops — that entry shows up in the back office's audit log
(Chapter 13) exactly like a void or a discount does.
