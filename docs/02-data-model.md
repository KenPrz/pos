# Data Model

Postgres 18.4. DDL here is the design of record; Laravel migrations implement it.

## Conventions

- **PKs are `uuid default uuidv7()`.** Verified native in Postgres 18.4 — no `pgcrypto`,
  no extension, no application-side generation. UUIDv7 is time-ordered, so it indexes
  like a sequence (no B-tree page-split churn from random UUIDv4) while staying
  unguessable and stable if writes ever originate off-server.
  `uuid_extract_timestamp(id)` gives creation time for free.
- **Money is `bigint`** minor units. See `01-architecture.md`.
- **Quantities are `numeric(12,3)`** — you can sell 0.5 kg of cheese. Exact in Postgres,
  never float.
- **Rates are `bigint` micros** (millionths). 8.875% → `88750`. Basis points would be
  the obvious choice and are *wrong*: NYC's 8.875% needs sub-basis-point precision, and
  discovering that after launch means rewriting every stored rate.
- **Timestamps are `timestamptz`.** Always. A POS with naive timestamps across
  locations in different timezones cannot produce a correct daily report.
- `created_at`/`updated_at` on mutable tables; ledger tables get `created_at` only,
  because they are never updated.

---

## Organization

```sql
create table locations (
  id                 uuid primary key default uuidv7(),
  name               text not null,
  code               text not null unique,          -- 'DT', short, on receipts
  timezone           text not null,                 -- IANA, e.g. 'America/New_York'
  prices_include_tax boolean not null default false,
  address            jsonb,
  receipt_header     text,                          -- admin-editable copy
  receipt_footer     text,
  is_active          boolean not null default true,
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now()
);
```

`timezone` is per-location, not global: two stores can straddle a timezone boundary, and
"today's sales" must mean the local day at that store or the shift report is wrong.

`prices_include_tax` is per-location for the same reason a business can have a US and a
UK store. See the tax section in `01-architecture.md`.

`receipt_header`/`receipt_footer` are columns rather than config because marketing edits
the copy and must not need a deploy to do it — the config-vs-database rule is in
`04-backend-conventions.md`. The business name and address on the same receipt *are*
config, because those change roughly never.

```sql
create table users (
  id            uuid primary key default uuidv7(),
  name          text not null,
  email         text,                    -- back-office login; null for PIN-only staff
  password_hash text,
  pin_hash      text,                    -- bcrypt, register login. The authority.
  pin_lookup    text,                    -- HMAC-SHA256(pin, APP_KEY). An index, not a credential.
  is_admin      boolean not null default false,
  is_active     boolean not null default true,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now(),

  check (email is not null or pin_hash is not null)
);

create unique index users_email_unique on users (lower(email)) where email is not null;
create index users_pin_lookup on users (pin_lookup);
```

**`pin_lookup` exists for performance, and is safe because it is keyed.** Bcrypt is
salted, so a PIN cannot be looked up — login would have to `Hash::check` every candidate
at the location. Measured at cost 12 that is 225ms each, so twenty staff is a 4.5-second
login. Unshippable. With the lookup, login is one indexed query plus a single bcrypt
verify, and `pin_hash` remains the authority: a lookup collision can never authenticate
anyone.

A database-only leak reveals nothing without `APP_KEY`. And bcrypt was never the real
protection for a 4-digit secret anyway — 10,000 guesses is ~40 minutes offline. The actual
defences are device enrolment and rate limiting.

**`is_admin` is a flag rather than a role**, because it is the one capability that spans
every location and spatie's teams cannot express an assignment that does. Granted via
`Gate::before`. This is not the `role` column we removed — call sites still ask
`can('order.void')`. Full reasoning, and the package behaviour that forced it, in
`05-rbac.md`.

Email is nullable because a weekend cashier may never touch the back office; the check
constraint guarantees every user can authenticate *somehow*.

**There is no `role` column, and no `user_locations` pivot.** Both are owned by
`spatie/laravel-permission` with its teams feature enabled and the team key mapped to
`location_id` — see `05-rbac.md`. Role assignment is therefore `(user, role, location)`,
which means holding a role at a location *is* being assigned to that location; a separate
pivot would be a second source of truth that could disagree. "Which locations does this
user work at" is `select distinct location_id from model_has_roles where model_id = ?`.

The package's `roles` and `permissions` tables keep integer PKs, deliberately breaking
the uuid convention above. They're seeded reference data, never client-visible, and never
sorted by creation time — the reasons for uuidv7 don't apply. The rationale and the
required migration edits (the package ships integer team/morph keys that must be changed
to `uuid`) are in `05-rbac.md`.

**PIN collisions are an application-level invariant, not a database one.** Bcrypt hashes
are salted, so two identical PINs produce different hashes and no unique index can catch
them. But two staff at one location sharing PIN `1234` destroys attribution — the audit
log would name the wrong person, which is worse than useless in a dispute. So on PIN set,
`SetStaffPin` checks the candidate against active staff at that user's locations and
rejects a match. This is a rare case where an invariant genuinely cannot live in the
schema, and it has a dedicated test.

`pin_lookup` makes that check one exact query rather than a bcrypt scan. It still can't be
a unique index: uniqueness here is "no two staff *sharing a location*", and users belong
to several — which no simple index expresses.

```sql
create table registers (
  id                           uuid primary key default uuidv7(),
  location_id                  uuid not null references locations(id),
  name                         text not null,
  mode                         text not null default 'retail' check (mode in ('retail','food')),
  is_active                    boolean not null default true,
  activation_code_lookup       text unique,   -- keyed HMAC of the one-time code; plaintext never stored
  activation_code_expires_at   timestamptz,
  activation_code_redeemed_at  timestamptz,
  created_at                   timestamptz not null default now(),
  updated_at                   timestamptz not null default now(),
  unique (location_id, name)
);
```

The device's long-lived Sanctum token is polymorphic on `registers` — the register *is*
the token's owner. The token is minted only by redeeming an activation code
(`POST /registers/activate`); the code itself is never stored in plaintext, only as
HMAC-SHA256 keyed by `APP_KEY` (same reasoning as `users.pin_lookup`, above), so a
database dump alone cannot brute-force the code space.

**`mode` is the entire register-UI seam M5 needed** — one column and one check
constraint, no new order-model table. It ships on the login response
(`03-api.md`) and picks the register's screen (menu grid + tabs vs. barcode scanner);
the order lifecycle underneath is identical either way. A register can be re-enrolled
into a different mode without touching a single order row.

---

## Cash accountability

```sql
create table shifts (
  id                  uuid primary key default uuidv7(),
  register_id         uuid not null references registers(id),
  opened_by           uuid not null references users(id),
  opened_at           timestamptz not null default now(),
  opening_float_cents bigint not null check (opening_float_cents >= 0),

  closed_by           uuid references users(id),
  closed_at           timestamptz,
  counted_cash_cents  bigint,
  expected_cash_cents bigint,
  variance_cents      bigint,
  close_note          text,

  variance_approved_by uuid references users(id),
  variance_approved_at timestamptz,

  check ((closed_at is null) = (counted_cash_cents is null)),
  check ((variance_approved_by is null) = (variance_approved_at is null))
);

create unique index one_open_shift_per_register
  on shifts (register_id) where closed_at is null;
```

That partial index is the whole concurrency story for shifts: two cashiers racing to open
the same register produce one winner and one constraint violation, with no application
check and no lock. Prefer this shape wherever an invariant can be expressed structurally.

The paired `check` makes "closed" and "counted" inseparable — you cannot close a drawer
without counting it, at the schema level.

`variance_approved_by`/`variance_approved_at` were forward-declared nullable at M2, before
`ApproveVariance` (M5) existed to write them; the second paired `check` — added in M5
alongside `registers.mode` — makes "approved" and "approved by whom, when" inseparable the
same way. Approval never blocks the close itself (`03-api.md`): the shift is already
closed by the time a supervisor signs off, and the pair is written together or not at
all.

```sql
create table cash_movements (
  id           uuid primary key default uuidv7(),
  shift_id     uuid not null references shifts(id),
  kind         text not null check (kind in ('payout','paid_in','drop')),
  amount_cents bigint not null check (amount_cents > 0),
  reason       text not null,
  user_id      uuid not null references users(id),
  created_at   timestamptz not null default now()
);
```

`payout` (petty cash out), `paid_in` (cash in), `drop` (moved to safe). All positive;
`kind` carries the sign. Storing a signed amount instead would let a typo turn a payout
into a paid-in, and `reason` is mandatory because an unexplained drawer movement is the
single most common vector for internal theft.

**Variance** is computed at close, never stored as a running total:

```
expected = opening_float
         + cash sales           (captured cash payments this shift)
         - cash refunds
         + paid_ins - payouts - drops
variance = counted - expected
```

Non-zero variance is *recorded, never blocked*. A drawer that refuses to close because
it's $0.02 short is a drawer that gets closed by unplugging the terminal, and then you
have no data at all. Variance beyond a threshold requires a `supervisor` to approve, and
that approval lands in the audit log.

---

## Catalog

```sql
create table categories (
  id         uuid primary key default uuidv7(),
  name       text not null,
  parent_id  uuid references categories(id),
  sort_order int not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table tax_rates (
  id          uuid primary key default uuidv7(),
  name        text not null,              -- 'Standard VAT', 'NYC Combined'
  rate_micros bigint not null check (rate_micros >= 0),   -- 8.875% -> 88750
  is_active   boolean not null default true,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);

create table products (
  id          uuid primary key default uuidv7(),
  name        text not null,
  description text,
  category_id uuid references categories(id),
  kind        text not null default 'goods' check (kind in ('goods','service')),
  is_active   boolean not null default true,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);

create table product_variants (
  id              uuid primary key default uuidv7(),
  product_id      uuid not null references products(id) on delete cascade,
  name            text not null,            -- 'Blue / L'; 'Default' when trivial
  sku             text not null,
  barcode         text,
  price_cents     bigint not null check (price_cents >= 0),
  cost_cents      bigint check (cost_cents >= 0),
  tax_rate_id     uuid references tax_rates(id),
  track_inventory boolean not null default true,
  position        int not null default 0,
  is_active       boolean not null default true,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  deleted_at      timestamptz
);

create unique index variants_sku_unique
  on product_variants (sku) where deleted_at is null;
create unique index variants_barcode_unique
  on product_variants (barcode) where barcode is not null and deleted_at is null;
```

**Every product has at least one variant, even a t-shirt with no options.** The UI hides
a lone "Default" variant. This is the design's most important simplification: the sale
path always resolves to a variant, so there is never a branch reading "if the product has
options, do X, else do Y." One code path, checked once at catalog write time instead of
at every register tap.

The unique indexes are partial on `deleted_at` so a retired SKU's number can be reissued
without the old rows blocking it.

`track_inventory = false` covers services and open-ended items (a coffee you don't count).

### Per-location pricing

```sql
create table variant_location_prices (
  variant_id  uuid not null references product_variants(id) on delete cascade,
  location_id uuid not null references locations(id) on delete cascade,
  price_cents bigint not null check (price_cents >= 0),
  primary key (variant_id, location_id)
);
```

Override table, not a required column. Resolution: location override, else the variant's
base price. The airport store charges more; the other nine stores need no rows at all.

### Modifiers

```sql
create table modifier_groups (
  id          uuid primary key default uuidv7(),
  name        text not null,             -- 'Milk', 'Cook temp'
  min_select  int not null default 0,
  max_select  int,                       -- null = unlimited
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  check (max_select is null or max_select >= min_select)
);

create table modifiers (
  id                uuid primary key default uuidv7(),
  group_id          uuid not null references modifier_groups(id) on delete cascade,
  name              text not null,       -- 'Oat milk'
  price_delta_cents bigint not null default 0,   -- may be negative
  position          int not null default 0,
  is_active         boolean not null default true
);

create table product_modifier_groups (
  product_id uuid not null references products(id) on delete cascade,
  group_id   uuid not null references modifier_groups(id) on delete cascade,
  position   int not null default 0,
  primary key (product_id, group_id)
);
```

`min_select = 1` makes a group required ("choose a cook temp"). `price_delta_cents` is
signed here — unlike cash movements — because a discount modifier ("no cheese, −50¢") is
a real thing and the sign is the meaning, not a typo risk.

Modifiers are never stocked. If you need to count it, it's a variant. See
`00-overview.md`.

---

## Inventory

Two tables: an immutable ledger, and a cached level derived from it.

```sql
create table stock_movements (
  id          uuid primary key default uuidv7(),
  variant_id  uuid not null references product_variants(id),
  location_id uuid not null references locations(id),
  qty_delta   numeric(12,3) not null check (qty_delta <> 0),
  reason      text not null check (reason in (
                'sale','refund','adjustment','receive',
                'transfer_in','transfer_out','waste','count')),
  ref_type    text,          -- 'order_line', 'refund_line', ...
  ref_id      uuid,
  user_id     uuid references users(id),
  note        text,
  created_at  timestamptz not null default now()
);

create index stock_movements_variant_loc on stock_movements (variant_id, location_id, created_at);

create table stock_levels (
  variant_id  uuid not null references product_variants(id) on delete cascade,
  location_id uuid not null references locations(id) on delete cascade,
  qty         numeric(12,3) not null default 0,
  updated_at  timestamptz not null default now(),
  primary key (variant_id, location_id)
);
```

**Invariant:** for every `(variant_id, location_id)`,
`stock_levels.qty = sum(stock_movements.qty_delta)`.

Both are written in the same transaction. `stock_levels` exists purely so the hot path
(read a level, lock it, sell) is one indexed row instead of an aggregate over a table
that grows forever. A nightly job re-derives levels from the ledger and alarms on drift —
if the cache and the ledger disagree, the *ledger* is right, always.

This is why stock is a ledger and not a number: "why is my count wrong" is answerable by
selecting rows, and every movement names a reason, a user, and the order it came from.

Selling the last unit safely:

```sql
begin;
select qty from stock_levels
 where variant_id = $1 and location_id = $2
   for update;                       -- serializes concurrent sellers
-- if qty < requested -> rollback, 409 insufficient_stock
insert into stock_movements (...) values (..., -requested, 'sale', ...);
update stock_levels set qty = qty - requested, updated_at = now()
 where variant_id = $1 and location_id = $2;
commit;
```

Pessimistic on purpose. The invariant is "never sell what we don't have," and optimistic
retry cannot promise that under contention. Only variants with `track_inventory = true`
take the lock — everything else skips it entirely and never contends.

---

## Sales

```sql
create table customers (
  id         uuid primary key default uuidv7(),
  name       text,
  email      text,
  phone      text,
  note       text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table orders (
  id                 uuid primary key default uuidv7(),
  number             text not null,              -- 'DT-20260715-0042', human-facing
  location_id        uuid not null references locations(id),
  register_id        uuid not null references registers(id),
  shift_id           uuid not null references shifts(id),
  business_date      date not null,              -- local day at the location

  opened_by          uuid not null references users(id),
  closed_by          uuid references users(id),
  customer_id        uuid references customers(id),
  table_ref          text,                       -- food service; null for retail

  status             text not null default 'open'
                       check (status in ('open','closed','voided')),

  prices_include_tax boolean not null,           -- snapshot at open
  subtotal_cents     bigint not null default 0,
  discount_cents     bigint not null default 0,
  tax_cents          bigint not null default 0,
  total_cents        bigint not null default 0,
  paid_cents         bigint not null default 0,

  version            int not null default 0,     -- optimistic lock

  opened_at          timestamptz not null default now(),
  closed_at          timestamptz,
  voided_at          timestamptz,
  void_reason        text,

  unique (location_id, number)
);

create index orders_open on orders (location_id, status) where status = 'open';
create index orders_business_date on orders (location_id, business_date);
```

`table_ref` being a nullable text column *is* the entire retail/food-service split in the
schema. Everything else is shared. That's the payoff from `00-overview.md`: one lifecycle,
one set of tables, and the UI decides whether the open phase is visible.

`prices_include_tax` is **snapshotted at open**, not read from `locations` at close. An
admin flipping that setting mid-shift must not retroactively change the arithmetic of
orders already in flight. Same principle as frozen line prices.

`business_date` is the local calendar day, computed from the location's timezone at open.
It's stored rather than derived because every report groups by it, and re-deriving a
timezone conversion in every query is both slow and a bug farm.

### Order numbers

Human-facing, per location per day, and necessarily concurrency-safe:

```sql
create table order_counters (
  location_id   uuid not null references locations(id),
  business_date date not null,
  next_val      int not null default 1,
  primary key (location_id, business_date)
);

-- atomic; no read-modify-write race
insert into order_counters (location_id, business_date, next_val)
values ($1, $2, 2)
on conflict (location_id, business_date)
  do update set next_val = order_counters.next_val + 1
returning next_val - 1 as seq;
```

A Postgres sequence won't do: sequences don't reset per day per location, and they leak
gaps on rollback. Gaps matter here — an auditor reading a receipt book with holes in it
asks questions, and "the database rolled back" is not an answer anyone enjoys giving.

### Lines

```sql
create table order_lines (
  id                    uuid primary key default uuidv7(),
  order_id              uuid not null references orders(id) on delete cascade,
  variant_id            uuid not null references product_variants(id),

  -- frozen at add time; receipts must be reproducible forever
  name_snapshot         text not null,
  sku_snapshot          text not null,
  unit_price_cents      bigint not null,
  tax_rate_micros       bigint not null,

  qty                   numeric(12,3) not null check (qty > 0),
  modifiers_total_cents bigint not null default 0,
  discount_cents        bigint not null default 0,
  tax_cents             bigint not null default 0,
  line_total_cents      bigint not null,

  prep_state            text check (prep_state in ('pending','in_progress','ready')),

  position              int not null default 0,
  voided_at             timestamptz,
  voided_by             uuid references users(id),
  created_at            timestamptz not null default now()
);

create table order_line_modifiers (
  id                uuid primary key default uuidv7(),
  order_line_id     uuid not null references order_lines(id) on delete cascade,
  modifier_id       uuid not null references modifiers(id),
  name_snapshot     text not null,
  price_delta_cents bigint not null
);
```

**The `_snapshot` columns and the frozen rate are the most important thing on this table.**
Rename a product, reprice it, or change a tax rate, and last week's receipt must still
reprint byte-identical. Joining to `product_variants` for the name at print time would
silently rewrite history. The FK to `variant_id` stays for reporting ("how many of this
SKU did we sell"), but it is never the source of display or price data.

`prep_state` is the KDS seam named in `00-overview.md`. Reserved nullable at M2; M5 is the
first thing that writes it, via `PATCH .../lines/{id}/prep` (`03-api.md`) driving the
register's own coursing chips. There is still no separate kitchen-display screen — that
non-goal stands — but the column earned its keep at one migration's cost instead of a
schema change over a live `orders` table later.

Lines are **voided, never deleted** (`voided_at`), because "what did the cashier remove
from this order, and when" is a fraud question.

### Discounts

```sql
create table discounts (
  id                  uuid primary key default uuidv7(),
  name                text not null,
  kind                text not null check (kind in ('percent','fixed')),
  percent_micros      bigint,        -- kind='percent'; 10% -> 100000
  amount_cents        bigint,        -- kind='fixed'
  scope               text not null check (scope in ('order','line')),
  requires_supervisor boolean not null default true,
  is_active           boolean not null default true,
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now(),

  check ((kind = 'percent' and percent_micros is not null and amount_cents is null)
      or (kind = 'fixed'   and amount_cents  is not null and percent_micros is null))
);

create table order_discounts (
  id            uuid primary key default uuidv7(),
  order_id      uuid not null references orders(id) on delete cascade,
  order_line_id uuid references order_lines(id) on delete cascade,  -- null = order-level
  discount_id   uuid references discounts(id),                      -- null = ad-hoc
  name_snapshot text not null,
  amount_cents  bigint not null check (amount_cents >= 0),
  applied_by    uuid not null references users(id),
  reason        text,
  created_at    timestamptz not null default now()
);
```

The `check` makes a percent discount with a cash amount unrepresentable rather than
merely discouraged.

`order_discounts.amount_cents` stores the **resolved** cents, not the percentage. A 10%
discount on an order that later gains a line does not silently re-scale; it is
recalculated and rewritten explicitly, by code we can test.

`applied_by` is mandatory and `requires_supervisor` defaults true because discounts are
how money leaves a till with a smile.

### Payments

```sql
create table payments (
  id             uuid primary key default uuidv7(),
  order_id       uuid not null references orders(id),
  shift_id       uuid not null references shifts(id),
  driver         text not null,             -- 'cash', 'external_card'
  status         text not null check (status in
                   ('pending','authorized','captured','voided','failed')),

  amount_cents   bigint not null check (amount_cents > 0),
  tendered_cents bigint,                    -- cash only
  change_cents   bigint,                    -- cash only

  reference      text,                      -- external terminal reference
  driver_payload jsonb,

  user_id        uuid not null references users(id),
  created_at     timestamptz not null default now(),
  captured_at    timestamptz
);

create index payments_order on payments (order_id);
create index payments_shift on payments (shift_id) where status = 'captured';
```

Append-only in the way that matters: **`amount_cents` is immutable once written.**
`status` must transition (that's what `authorize`/`capture` means), but a payment's
amount never changes — to correct one, void it and write a new row. Every transition is
mirrored into `audit_log`, so the sequence of states is reconstructible.

Several payments per order is a split bill. No extra structure needed:
`sum(amount_cents where status='captured') = orders.paid_cents`, and the order closes
when that reaches `total_cents`.

`shift_id` on the payment (not just the order) is what makes drawer variance computable —
cash is counted per shift, and an order that spans a shift boundary must attribute each
tender to the shift that physically received it.

### Refunds

A refund is **new rows, never a mutation** — principle 2.

```sql
create table refunds (
  id                uuid primary key default uuidv7(),
  original_order_id uuid not null references orders(id),
  location_id       uuid not null references locations(id),
  register_id       uuid not null references registers(id),
  shift_id          uuid not null references shifts(id),
  business_date     date not null,
  driver            text not null,
  amount_cents      bigint not null check (amount_cents > 0),
  reason            text not null,
  user_id           uuid not null references users(id),
  created_at        timestamptz not null default now()
);

create table refund_lines (
  id                     uuid primary key default uuidv7(),
  refund_id              uuid not null references refunds(id) on delete cascade,
  original_order_line_id uuid not null references order_lines(id),
  qty                    numeric(12,3) not null check (qty > 0),
  amount_cents           bigint not null check (amount_cents > 0),
  restock                boolean not null default true
);
```

The original order is untouched and stays `closed` forever. Reporting reads
`orders − refunds`; it never reads a mutated order, because there isn't one.

`restock` is per line and defaults true, but a returned melted ice cream goes in the bin,
not back on the shelf — so the cashier can decline the restock, and when they do, no
`stock_movement` is written.

Over-refunding is prevented by checking the sum of prior `refund_lines` for each original
line inside the refund transaction. `external_card` payments cannot be refunded through
us at all — the money never passed through this system, and pretending otherwise would
corrupt both the drawer count and the card reconciliation.

---

## Infrastructure tables

```sql
create table idempotency_keys (
  key           text primary key,
  request_hash  text not null,             -- sha256(method + path + body)
  response_code int  not null,
  response_body jsonb not null,
  created_at    timestamptz not null default now()
);

create index idempotency_keys_created on idempotency_keys (created_at);  -- pruning

create table audit_log (
  id          uuid primary key default uuidv7(),
  user_id     uuid references users(id),
  register_id uuid references registers(id),
  action      text not null,               -- 'order.void', 'discount.apply', ...
  entity_type text not null,
  entity_id   uuid,
  payload     jsonb,
  ip          inet,
  created_at  timestamptz not null default now()
);

create index audit_log_entity on audit_log (entity_type, entity_id, created_at);
create index audit_log_user on audit_log (user_id, created_at);
```

`audit_log` has no FK cascade and is never deleted. Everything a `supervisor` role gates
writes a row here — that list of actions and this table are the same design, viewed from
two sides.

---

## What the schema refuses to allow

Worth stating plainly, since these are the reasons for the constraints above:

- A closed order cannot be edited. (Enforced in the domain layer; `status` + immutable
  payments make the intent unambiguous.)
- A drawer cannot be closed without a count. (`check` constraint.)
- Two shifts cannot be open on one register. (Partial unique index.)
- A percent discount cannot carry a cash amount. (`check` constraint.)
- A movement cannot exist without a reason. (`not null` + `check in (...)`.)
- Stock cannot go negative on a tracked variant. (`FOR UPDATE` + domain check.)
- A receipt cannot be rewritten by a later catalog edit. (Snapshot columns.)
