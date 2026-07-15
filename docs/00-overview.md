# POS — Overview and Scope

## What this is

A point-of-sale system for a single business operating multiple locations. It serves
both retail (scan a barcode, pay, leave) and food service (open a tab against a table,
add items over an hour, split the bill) from one core model.

## The central idea

Retail and restaurant POS look like different products, but they are the same product
at different speeds.

Both are: *open an order, attach lines to it, take money, close it.*

- **Retail** moves through that lifecycle in about sixty seconds, so the "open order"
  phase is invisible and the UI collapses it into a single cart screen.
- **Food service** lingers in the open phase for an hour, so that phase gets a name
  ("the tab", "table 12"), a screen of its own, and the ability to be handed between
  staff.

We model **one order lifecycle** and let the UI compress or expand it. We do not build
two systems behind a shared login. Where the domains genuinely diverge, we add an
optional concept rather than a parallel hierarchy:

| Need | Retail | Food service | Our model |
| --- | --- | --- | --- |
| Sellable thing | SKU with barcode | Menu item | `product_variant` |
| "Blue, size L" | Distinct stocked SKU | — | Variant (own barcode, own stock) |
| "Extra shot, no onions" | — | Priced adjustment | Modifier (not stocked) |
| Where the order lives | Nowhere, it's instant | Table 12 | Optional `order.table_ref` |
| Who owns the order | The register | The server | `order.opened_by` |

Variants and modifiers are the distinction people most often get wrong, so to be
explicit: **a variant is a thing you count in a stockroom; a modifier is a thing you
say to the person making it.** "Large" is a variant of a t-shirt and a modifier of a
latte, and that is correct — it depends on whether you keep a shelf of them.

## Principles

1. **Money is integers.** Amounts are `bigint` in the currency's minor unit (cents).
   No floats anywhere, in any layer, ever. See `01-architecture.md`.
2. **Financial records are append-only.** A closed order is immutable. A refund is a
   new record, never a mutation of the original. A payment is never edited. If you
   want to know what happened, the rows tell you.
3. **Stock is a ledger, not a number.** We record movements and derive levels. "Why is
   my count wrong?" must always be answerable.
4. **The server owns the truth.** v1 is online-only (see below), so there is exactly
   one authority for every ID, price, and total. The client never computes a total it
   then sends us.
5. **Never double-charge.** Every mutating request carries an idempotency key. A retry
   on a flaky network must be a no-op, not a second payment.
6. **Prices are decided at order time and then frozen.** A line item stores the price
   it was sold at. Changing a product's price tomorrow must not rewrite yesterday's
   receipts.

## v1 decisions

These were chosen deliberately; the reasoning matters as much as the choice.

- **Online-only.** The terminal requires the server. This is the single largest
  simplification available to us: the server issues all IDs and owns all state, and
  there is no sync engine, no conflict resolution, and no client-side schema
  versioning. The cost is real — a wifi outage stops sales — and the mitigation is
  that principle 5 (idempotency keys everywhere) is exactly the groundwork an
  offline-tolerant write queue would need later. We are building the on-ramp now and
  deferring the road.
- **Single business, multi-location.** Locations are first-class: stock, prices,
  registers, and staff assignments are location-scoped. There is no tenant isolation
  layer, and adding one later would be a painful migration — so if this ever becomes a
  product sold to other businesses, that is a decision to revisit early, not late.
- **Payments are pluggable, cash is the first driver.** We define a payment driver
  contract and implement cash (drawer, tender, change) plus "card, settled externally"
  against it. No processor integration, so no PCI surface in v1. Stripe Terminal or
  Adyen slots in behind the same contract.

## Non-goals for v1

Named explicitly so they don't creep in:

- Offline operation of any kind.
- Multi-tenancy.
- Card processing / PCI scope.
- Accounting integrations, payroll, purchase orders, supplier management.
- Kitchen display system. (The data model leaves room: an order line has a prep state.
  We just aren't building the screen.)
- Loyalty, gift cards, store credit.
- E-commerce or any second sales channel.
- Multi-currency. One currency per business, fixed at setup.

## Glossary

Terms are used in this exact sense throughout the docs and the code.

- **Location** — a physical store or venue. Owns stock and registers.
- **Register** — a terminal. A physical station, not a person.
- **Shift** — one register's cash session: opened with a float, closed with a count,
  producing a variance. The unit of cash accountability.
- **Product** — a catalog concept ("T-shirt"). Not directly sellable.
- **Variant** — a sellable, stockable SKU ("T-shirt / Blue / L"). Has the barcode and
  the price. Every product has at least one, even if the UI hides it.
- **Modifier** — an order-time adjustment with a price delta. Not stocked.
- **Order** — the unit of sale, from open to closed. Retail and food service both.
- **Line** — one variant on an order, with its frozen price and its modifiers.
- **Tender** — one act of paying (cash, card). An order may have several; that's a
  split bill.
- **Payment** — the record of a tender. Append-only.
