# Manila catalog seeders — design

**Date:** 2026-07-22
**Status:** Approved

## Goal

Replace the Downtown/London demo seed with three env-selectable, Manila-based catalog
seeders — Grocery (200 items), Restaurant (30 items), Cafe (20 items) — each backed by a
committed JSON data file sourced from real Philippine market data, priced in Philippine
pesos. The e2e scripts move onto the new seed data and stay green.

## Requirements

- Three catalog seeders: `GrocerySeeder` (200 sellable items), `RestaurantSeeder`
  (30 detailed menu items), `CafeSeeder` (20 food and beverage items).
- Item data is sourced from the internet (real Filipino brands, dishes, and price
  points) and committed as JSON files the seeders consume.
- Selection is env-driven: `POS_SEED_CATALOGS`, comma-separated, **default `grocery`**.
- Each enabled catalog brings its own Manila location; disabled catalogs create
  nothing ("location follows its catalog"). Downtown and London are gone.
- Currency is Philippine pesos (`POS_CURRENCY=PHP`), prices in centavos, PH VAT 12%.
- `make e2e` (all three scripts) and all three test suites end green.

## Not in scope

- No schema changes, no API changes, no frontend changes (Intl formats ₱ from the
  currency the API already reports).
- No change to how tests use factories; the seeder remains dev-only.

## Data files

`backend/database/seeders/data/{grocery,restaurant,cafe}.json`, committed. Sourcing:

- **grocery.json — 200 items.** Researched from Open Food Facts' Philippine catalog and
  Manila supermarket listings: real brands (Lucky Me!, Century Tuna, Argentina, San
  Miguel, Nescafé, Bear Brand, …) with real `480`-prefix EAN-13 barcodes where
  sourceable; otherwise a checksum-valid generated `480` EAN-13. Fields: product name,
  category, variant name, sku, barcode, `price_cents`, `cost_cents`,
  `track_inventory`. Includes a handful of per-kg items (rice, bangus, pork) sold by
  fractional quantity, and a few multi-variant products (e.g. soft drink sizes) so the
  variant model is exercised. All other items tracked.
- **restaurant.json — 30 items.** Researched from actual Filipino restaurant menus:
  adobo, sinigang na baboy, kare-kare, sisig, lechon kawali, pancit, lumpia, halo-halo,
  and so on, across categories (appetizers, mains, noodles & rice, desserts, drinks).
  Each item carries a description and its modifier groups: rice (required), serving
  size, spice level, add-ons (repeat-legal, priced deltas — signed, so "no rice" style
  negative deltas are representable).
- **cafe.json — 20 items.** Researched from Manila cafe menus: kapeng barako, espresso
  drinks, iced drinks, ensaymada, pandesal, pastries. Espresso drinks share milk
  (required) and size/extras modifier groups.

Prices are integer **peso centavos** at realistic 2026 Manila price points. JSON shape
is one object per catalog: `categories`, `modifier_groups` (with `modifiers`), and
`products` (with `variants` and modifier-group references by name).

## Selection mechanism

- `config/pos.php` gains `'seed_catalogs' => env('POS_SEED_CATALOGS', 'grocery')` —
  the seeder reads `config('pos.seed_catalogs')`, never `env()` (arch rule).
- `.env.example` (root and backend) document `POS_SEED_CATALOGS` and set
  `POS_CURRENCY=PHP`.
- Unknown catalog names in the list fail the seed loudly, not silently.

## Seeder architecture

`DatabaseSeeder` keeps what is catalog-independent and delegates the rest:

1. Global role provisioning (unchanged).
2. PH VAT 12% tax rate (`rate_micros = 120_000`), shared by all catalogs.
3. Discounts: "10% off" (percent) and "₱50 off" (fixed 5000 centavos).
4. For each name in `pos.seed_catalogs`, run the matching seeder class.
5. Staff + role assignment across whichever locations now exist.
6. Print PINs, device tokens, and back-office login, as today.

Each catalog seeder (`GrocerySeeder`, `RestaurantSeeder`, `CafeSeeder`):

- Creates its location — Grocery `GRC`, Restaurant `RST`, Cafe `CAF`; all
  `Asia/Manila`, `prices_include_tax = true`, peso-appropriate receipt copy.
- Creates two registers: `Till 1` / `Till 2`. Grocery: both `retail`. Restaurant and
  Cafe: both `food`.
- Loads its JSON file; creates categories, modifier groups/modifiers, products,
  variants.
- Receives opening stock through `StockLedger` for tracked variants (so
  `stock_levels.qty = sum(movements)` from day one), at its own location only.
- Returns/collects device tokens for the final printout.

Staff (same PINs as today) map onto whatever exists:

- Alice — cashier at the first enabled location.
- Bob — supervisor at the first enabled location.
- Maria — cashier at the first enabled location, supervisor at the second **if** a
  second exists (the two-location RBAC story degrades gracefully to one role).
- Priya — global admin, unchanged (PIN + back-office password).

## e2e script updates

All three scripts re-anchor on the new seed; the flows they prove are unchanged.

- **`e2e-retail-day.sh`** → Grocery location. Three known grocery barcodes replace the
  T-shirt trio; expected totals recomputed for VAT-inclusive pricing (total = shelf
  price sum − discount; tax is derived, not added).
- **`e2e-lunch-service.sh`** → Restaurant location. A dish with a required modifier
  group plus a repeat-legal add-on replaces the Latte/Oat/Extra-shot anchors; a per-kg
  grocery item (catalog is global, so it is visible from any register) keeps the
  fractional-quantity split proof. Register-mode assertions updated to the restaurant's
  tills.
- **`e2e-admin-day.sh`** → Grocery location (`GRC` replaces `DT`); drawer/report
  expectations recomputed for VAT-inclusive pricing; the activation-code and hiring
  flows are untouched.
- Scripts need all three catalogs. `make e2e` seeds itself (twice) and greps the
  printed token table for `DT / Till 1|2`; the target changes to pass
  `POS_SEED_CATALOGS=grocery,restaurant,cafe` into its seed invocations (via
  `docker compose exec -e`) and to extract `GRC / Till 1` (retail-day, admin-day)
  and `RST / Till 1|2` (lunch-service) tokens instead. A plain `make seed` stays
  grocery-only.

## Docs

- `docs/06-roadmap.md`: record the change (what shipped, suite counts).
- `CLAUDE.md` Status section: seeder description updated (Manila, env-selectable
  catalogs, PHP currency).
- Root `README`/CLAUDE.md seeding instructions mention `POS_SEED_CATALOGS`.

## Error handling

- Seeder fails loudly on: unknown catalog name, unreadable/invalid JSON, duplicate
  SKU/barcode within the data files (validated at seed time so bad data cannot
  half-seed — the whole run is transactional per existing seed behavior).
- JSON files are validated by a backend test (shape + counts: 200/30/20 + barcode
  checksums + SKU/barcode uniqueness), so data drift breaks CI, not a demo.

## Testing

- Backend: a seeder test per catalog (run each seeder against the test DB; assert
  location, register modes, item counts, stock through the ledger, modifier wiring)
  plus the JSON-validation test above. Existing 476 tests stay green.
- Frontends: no changes expected; suites must stay green (currency arrives from the
  API and formats via Intl).
- `make e2e`: all three scripts green against a stack seeded with all three catalogs.

## Success criteria

`make seed` (default) produces a Grocery-only Manila store in pesos;
`POS_SEED_CATALOGS=grocery,restaurant,cafe make seed` produces all three locations;
`make test` and `make e2e` are green; the three JSON files contain 200/30/20 real,
Filipino-flavored items.
