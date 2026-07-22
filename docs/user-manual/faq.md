# 16. FAQ

## Why don't the day and category reports reconcile?

They're not supposed to. **Day** and **User** are **ledger-basis** — summed from actual
payments and refunds that moved money. **Category** is **line-basis** — summed from
individual order lines, joined to current category names. A payment covers a whole
order at once; a category breakdown has to attribute individual lines within it. They
answer different questions — "how much cash and card came in" versus "what sold" — so
the Day total and the sum of the Category totals aren't expected to match to the cent.
See Chapter 12.

## What does VAT-inclusive pricing mean for my totals and receipts?

At a location with **Prices include tax** set to **Yes** (all three demo stores are),
the shelf price *is* the total — a ₱220.00 item rings up as ₱220.00, full stop. The tax
shown on the receipt is **extracted** from that price for the record, not added on top
of it. Nothing about the customer's total changes based on tax; only how much of it is
labeled tax versus subtotal. See Chapters 2 and 11.

## Why can't I delete a product?

Nothing in the back office deletes. Unchecking **Active** and saving **archives** a
product, variant, discount, or tax rate instead — it leaves the register's menu, but
every past order line, receipt, and report that points at it keeps working, forever. A
closed order's lines are a snapshot of name, price, and tax rate at the time of sale, so
archiving something never rewrites history. See Chapter 9.

## Why did my discount need a supervisor?

Every discount needs one today, regardless of what its own **Requires supervisor**
checkbox says in the back office. That flag exists on the Discounts tab, but it's
currently inert — the **Discount** button on the till only ever renders for a
supervisor's PIN session in the first place, so nothing short of that role can open the
panel at all. See Chapter 5.

## What happens to an open tab when a register is reissued?

Nothing, to the tab itself. Issuing a new activation code only touches that register's
device token and its staff sessions — it never touches orders or shifts. The tab stays
open exactly where it was; you simply can't act on it from *that* till until it's
re-activated with the new code (Chapter 11). In the meantime it's still visible, and
still transferable to another till, from the Floor at that location (Chapter 6).

## Can two people use one till at the same time?

Not in the same PIN session. A session belongs to one person, shown by name in the top
bar (Chapter 4). **Clock out** ends only that person's session — not the shift, so the
drawer and any open tabs stay exactly as they were for whoever clocks in next. Two
cashiers share a till across a day by clocking out and back in between them, one at a
time, not by both being signed in at once.
