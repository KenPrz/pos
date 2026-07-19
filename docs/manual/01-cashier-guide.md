# Cashier Guide

This is for your first shift on the register. It assumes you've already read the
Getting Started chapter's [Signing in](00-getting-started.md#signing-in) section — this
chapter picks up from a PIN screen you already know how to use.

## Clock in and clock out

Every enrolled till shows an **Enter PIN** screen whenever nobody's signed in.

1. Type your 4–6 digit PIN.
2. Tap **Clock in**.

When you're done working — a break, the end of your shift, handing the till to the next
person — tap **Clock out** in the top-right corner of the screen.

> Note: **Clock out** only ends your own PIN session. It does not close the drawer. The
> shift stays open on this register for whoever clocks in next, until someone actually
> closes it — see Close your shift, below.

## Open a shift

If nobody's opened the drawer on this till yet, you'll land on **Open shift** instead of
a sale screen.

1. Check or edit the **Opening float** — the cash you're starting the drawer with (it
   defaults to `200.00`).
2. Tap **Open drawer**.

You're dropped straight into a new sale. If a shift is already open on this till (someone
else started it, or you're resuming your own), you skip this screen entirely.

## Ring up a retail sale

What you see: a **New sale** panel with a scan field at the top and an empty cart below.

1. Scan the item, or type its barcode into **Scan or type a barcode…** and press enter.
   The first scan opens the order for you — there's nothing to tap first.
2. Keep scanning. Each item lands in the cart below with its name and price; subtotal,
   tax, and total update as you go.
3. Tap **Pay — $X.XX** (the button shows the amount due).
4. Choose **Cash** or **Card**.
   - **Cash:** type what the customer handed you into **Cash tendered (owed: …)**, then
     tap **Take payment**. The next screen shows the **Change** due, worked out by the
     server — you never do that math yourself.
   - **Card:** type the terminal's authorization into **Card terminal reference (owed:
     …)** (e.g. `auth 004321`), then tap **Take payment**. This till only *records* the
     card result — it doesn't talk to a card terminal itself.
5. Tap **Print** for a paper receipt, or **New sale** to move on.

> Note: if a supervisor's discount brings the total to exactly zero, **Pay** is replaced
> by **Close — fully comped** (or **Close empty order** for an empty cart) — tap it to
> finish the order without taking any payment at all.

## Switch to tabs

If your till is a **retail** register, skip ahead to Close your shift — this section and
the two after it are for **food-mode** registers only. Ask a manager if you're not sure
which yours is.

A food-mode till shows a **Tabs** link next to your name once a shift is open. Tap it to
see every open tab at this location (the **Floor**); tap **Register** to come back to the
sale screen.

## Open a new tab

1. On the Floor screen, tap **New tab**.
2. Optionally type the table into **Table (optional)…** (up to 20 characters) — leave it
   blank for a walk-in or a bar tab.
3. Tap **Open tab**.

You land on the sale screen with an empty order already seated at that table.

## Build the order from the menu

Food-mode tills show a category rail and a grid of tiles instead of relying only on the
scanner. Tap a category, then tap an item's tile to add it.

If an item has no modifiers (like a plain cheese by the kilo), it goes straight into the
cart. If it has any (a latte's milk, a shot count), a sheet pops up named after the item:

1. Any group marked **required** needs a pick before you can confirm — **Add** stays
   disabled until every required group has one.
2. Tap a choice to select it. Tap the *same* choice again to add a second (a double
   shot, say) — the chip shows `×2`, `×3`, and so on as you keep tapping.
3. Tap **Add** to drop it into the cart, or **Cancel** to back out without adding
   anything.

## Fire and track courses

Every food line that the kitchen tracks carries a chip reading **Pending**, **Cooking**,
or **Ready**. Tap it to move the course forward: **Pending** → **Cooking** (fires it to
the kitchen) → **Ready** (on the pass) → back to **Pending**.

> Note: once a course reaches **Ready**, only a supervisor can send it back a step — see
> the Supervisor Guide.

## Resume a tab from the floor

The Floor screen lists every open tab at this location as a card: the table or tab name,
who opened it, how long it's been open, and what's due. Tap a card that's yours to pick
it back up — the sale screen loads with that order and a "Resumed order …" notice.

> Note: you can only resume your *own* tabs from here — a card opened by another till is
> disabled, and a card of yours is disabled too while a *different* sale is already in
> progress on your screen (finish or park it first).

## Split the check and pay each part

Once you tap **Pay**, and only on an order that hasn't taken any payment yet, a
**Split bill** button appears alongside the tender form.

1. Tap **Split bill**.
2. Use the stepper's **−** / **+** to choose how many checks (2 to 10) — the preview
   below shows an even split of the total.
3. Tap **GO**.
4. You're now looking at the first check. A strip along the top shows **Check 1**,
   **Check 2**, and so on, with the one you're paying highlighted and any already-closed
   ones marked **Paid**. Take payment on each exactly like any other sale (Cash or
   Card) — the next unpaid check opens automatically as soon as one closes.
5. Once every check is paid, the screen totals up **All checks settled — N checks**,
   with each check's own receipt to print.

> Note: a split check's line quantities can come out as a fraction (like `0.334`) rather
> than a whole number on the receipt — that's the split dividing everything exactly so
> the checks always add back up to the original total, not a mistake.

## Pick up a tab transferred to you

When a supervisor hands a tab to your till, it just appears as another card on your
Floor screen — resume it exactly as in Resume a tab, above. Any payment you take on it
counts on your drawer, not the till it came from.

## Close your shift

1. Tap **Close shift**, next to your name at the top of the sale screen.
2. Physically count the drawer *before* looking at anything on screen — the expected
   figure is masked (`•••••`) until after you submit your count. This is deliberate: see
   the note below.
3. Type what you counted into **Counted cash**.
4. Tap **Close**.
5. The result — **Drawer reconciled** — reveals **Expected**, **Counted**, and
   **Variance**, plus the shift's Z-report: sales and refunds by tender, **Paid in**,
   **Payouts**, **Drops**, and **Orders closed** / **Orders voided** / **Orders split**.
6. Tap **Print** for a paper copy, then **Done**. Closing signs you out of this till
   completely — you'll land back at **Enter PIN**.

> Note: the expected total stays hidden until *after* you count, on purpose. If you
> could see it first, a fast count just types the number back instead of actually
> counting, and a real drawer problem never surfaces. Count first, then look.

> Note: a variance inside the store's threshold reconciles clean, no extra step. Outside
> it, you'll see **Variance exceeds the threshold — needs supervisor approval.** — that's
> not something you can clear yourself. A supervisor has to sign off, and not from this
> till (see the Supervisor Guide's variance-approval section for why).

## What error messages mean

- **"Only *N* units remain."** — the item ran out mid-sale. Pull it from the order, or
  ask a supervisor about a stock correction.
- **A scan or an add-item comes back with an error instead of updating the cart.** —
  usually the order changed under you: a supervisor voided a line, or two taps landed at
  once. The screen already knows the current state; just try the action again and it
  goes through against the latest totals, not the stale ones you were looking at.
- **"Cannot reach the server."** — the till lost its connection. Re-scanning the same
  barcode right after is safe; it won't add the item twice once the connection's back.

## See also

- [Getting Started](00-getting-started.md) — enrollment, signing in, roles.
- [Supervisor Guide](02-supervisor-guide.md) — voids, discounts, refunds, and everything
  else that needs a supervisor.
