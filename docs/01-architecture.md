# Architecture

## Stack

Versions verified against the registries on 2026-07-15, not from memory.

| Layer | Choice | Version |
| --- | --- | --- |
| Language (backend) | PHP | 8.5.0 |
| Backend framework | Laravel | 13.20 |
| API auth | Laravel Sanctum | 4.3 |
| Database | PostgreSQL | 18 (`postgres:18-alpine`) |
| Frontend | React + TypeScript | 19.2 / 7.0 |
| Frontend framework | Next.js (app router) + TanStack React Query | 16 |
| Local infra | Docker Compose | 29.1 |

Notes on the less obvious picks:

- **Postgres, not MySQL.** We want `numeric` for stock quantities, real `check`
  constraints, partial unique indexes (for soft-deleted SKUs), `jsonb` for the audit
  log's payload, and transactional DDL for safe migrations. All are load-bearing below.
- **TypeScript 7.** The native compiler. Fast, but new enough that if we hit a toolchain
  bug the escape hatch is pinning to 5.x — no source changes required.
- **Sanctum, not Passport.** We're issuing tokens to our own first-party terminals. There
  is no third-party OAuth client to authorize, so Passport is pure overhead.
- **Next.js 16 replaced Vite at M4**, mid-milestone, at the owner's direction. React Query
  came with it for server-state caching. The port changed what renders the API responses,
  not the responses themselves — the API client contract held throughout.

## Topology

```
┌──────────────┐   HTTPS/JSON    ┌──────────────────┐
│ Register     │ ──────────────► │ Laravel API      │
│ React SPA    │ ◄────────────── │ (stateless)      │
│ (browser)    │                 └────────┬─────────┘
└──────────────┘                          │
                                          ▼
                                  ┌──────────────┐
                                  │ PostgreSQL   │
                                  └──────────────┘
```

The API is stateless; all session state is in tokens and the database. That keeps the
door open to running several API instances behind a load balancer without touching
application code.

**There is no queue worker in v1.** Everything a sale needs happens synchronously inside
the request. Receipt emails and report generation are the first things that will want a
queue; when that day comes, add Redis and Horizon. Adding it now would be scaffolding
with nothing to run on it.

`frontend/web/` is a Next.js app serving one client-boundary register under a server
shell; `/api` rewrites replace the Vite dev proxy (same single-origin story).

**M6 added a second frontend, not a route inside the first.** `frontend/back-office/`
is its own Next.js app on its own port (5175), talking to the same API through the same
`/api` rewrite pattern. The two apps share conventions (money formatting, catalog types,
the shape of the API client) but not a build or a deployment — a cashier's terminal never
ships back-office code, and a back-office session never needs the register's offline or
hardware seams. The original plan called this "routes within one app, separated by
permission"; building it showed that a device-token-authenticated register and a
password-authenticated, location-less back office are different enough sessions that
sharing a build bought nothing and cost a permission check on every route to keep the two
audiences apart.

`frontend/native/` is reserved for a **desktop shell** (Electron or Tauri) and now exists.
It is not a second frontend: the plan is that it hosts the same SPA and adds the two
things a browser cannot do.

**1. Hardware.** This is the real reason it exists. A browser tab cannot kick a cash
drawer, drive an ESC/POS thermal printer, or talk to a scale. WebUSB and WebSerial exist
but are permission-prompted, Chromium-only, and not something to bet a lunch rush on.
Meanwhile `02-data-model.md` has a cash drawer, `05-rbac.md` gates `drawer.no_sale`, and
`03-api.md` returns a receipt — none of which physically happen without a hardware bridge.
So the design keeps a seam:

- The **server** decides *what* (receipt content from snapshot columns, whether the
  drawer may open, who authorized it) — that's auditable and testable, and it stays in
  the API.
- The **shell** does *how* (bytes to a printer, a pulse to a drawer) — device-specific,
  untestable in CI, and deliberately dumb.

Built as of the shell milestone: the SPA is bundled as a static export, API traffic
detours through a Rust `api_request` command (no CORS, and the server address lives in
Rust so the webview never names a host), and receipt JSON becomes ESC/POS bytes in a pure
Rust function. `POST /api/v1/drawer/no-sale` finally gives `drawer.no_sale` a door: the
server authorizes and audits, the shell only pulses. Only the mock driver ships — it
writes the exact bytes to disk — because no printer has been bought yet.

The rule is that no money decision ever lives in the shell. A shell that decided when a
drawer may open would put the fraud boundary on the terminal, where it can't be audited.

Until the shell exists, receipts print via the browser's print dialog and the drawer is
opened by hand. That's genuinely usable for a pilot and is why hardware isn't blocking v1.

**2. Offline, eventually.** A desktop shell can hold a local database, which makes the
offline-tolerant path in `00-overview.md` reachable rather than theoretical. That is
*not* licence to start caching writes now — the v1 decision stands, and the idempotency
keys are the on-ramp. It only means the road exists when the trigger fires.

## Deployment topology

Three images, one edge, single-host `docker compose` for both dev and prod — no
Kubernetes, no registry, no separate load balancer. `docs/06-roadmap.md`'s M7 section
has the full story of what shipped and what's a named deferral.

- **Three images.** `backend/Dockerfile` (FrankenPHP + PHP 8.5, multi-stage: `vendor`
  → `prod`, non-root) and one identical-shape Dockerfile each for `frontend/web` and
  `frontend/back-office` (`npm ci` → `next build` with `output: 'standalone'` → a
  minimal `node:24-alpine` runner). `postgres:18-alpine` is used unmodified — no custom
  image.
- **One FrankenPHP edge.** FrankenPHP *is* Caddy, so the `api` image is the single
  public entrypoint in production: its Caddyfile terminates TLS for both public
  hostnames (`POS_REGISTER_DOMAIN`, `POS_ADMIN_DOMAIN` — certificates
  auto-provisioned and auto-renewed), serves `/api/*` itself, and reverse-proxies
  everything else to `web:3000` or `back-office:3000` by host. Reverse proxy, TLS
  termination, and the PHP runtime collapse into one container instead of three;
  `web` and `back-office` are not published to the host at all in prod.
- **Host routing, not path routing.** The register and back-office domains are
  distinguished by the `Host` header, each routed to its own Next.js service; `/api/*`
  on either host reaches the same API.
- **No-CORS survives production end to end.** Each Next.js app's `/api` rewrite
  (`next.config.ts`) targets `process.env.API_ORIGIN`, so the browser has seen exactly
  one origin from M0 onward — only what backs the rewrite changes: `http://
  127.0.0.1:8000` for native dev, `http://api:8000` on the dev compose network, and in
  prod nothing needs setting at all, because Caddy already routes `/api/*` to the api
  service before the request ever reaches a Next server.
- **Single-host compose, dev and prod sharing a shape.** `compose.dev.yml` (`make
  dev`) runs all four services with bind mounts and hot reload; `compose.prod.yml`
  (`make prod-up`) runs the built images with no bind mounts, internal-only networking
  for `db`/`web`/`back-office`, and secrets via an uncommitted `.env`
  (`.env.prod.example` documents every required var). Only `compose.prod.yml` names
  its Compose project `pos` (`compose.dev.yml` is `pos-dev`, its own separate volume
  namespace, no collision risk) — a real collision hazard on a host that ever ran the
  retired `infra/docker-compose.yml` under the same default project name — see the
  prod compose file's own comments before a first boot there.
- **Backups are a `make` target, not a hope.** `make backup` dumps the running
  database; `make restore-drill` restores the newest dump into a throwaway container
  and prints row counts, so "the backup works" is provable on demand rather than
  assumed.

The backend follows a strict action-class architecture: one system action is one route,
one single-action controller, one Action class, returned through one Resource. The rules,
the layering, and a worked example are in `04-backend-conventions.md`. Two decisions in
this document — where the transaction boundary sits and where the optimistic-lock check
happens — are resolved there, because both depend on that structure.

## Money

The rule from `00-overview.md`, made concrete, because this is where POS systems get
quietly and expensively wrong.

Implemented in `app/Domain/Money/` (`Money`, `Quantity`, `TaxRate`, `Discount`, `Tender`)
and mirrored on the client in `frontend/web/src/lib/money.ts`.

- Every monetary amount is a **`bigint` of minor units** (cents). `1234` is $12.34.
- The currency is fixed per business at setup. It is *not* a column on every row —
  storing it 40 times invites 40 chances to disagree.
- **PHP:** amounts are `int`. Never `float`. Casting money to float is a bug even when
  it looks fine, because `0.1 + 0.2 !== 0.3` and a cashier will eventually find the
  input that proves it.
- **Postgres:** `bigint`. Never `money` (locale-dependent, genuinely unusable) and never
  `float8`. `numeric` is acceptable for *quantities* but not for money — integers make
  the "no fractional cents" invariant structural rather than aspirational.
- **JSON:** amounts cross the wire as integers. `{"total_cents": 1234}`. The frontend formats
  for display at the very last moment and never parses a formatted string back.
- **TypeScript:** a branded type, so cents can't be assigned to a plain number by
  accident:
  ```ts
  type Cents = number & { readonly __brand: 'Cents' }
  ```

### Rounding

Rounding is only ever needed for **percentage-based** math: tax and percentage
discounts. Every such calculation:

1. Computes in integers.
2. Rounds **half away from zero** at the point of the percentage application.
3. Rounds **per line**, then sums. Never sums then rounds.

Per-line rounding is chosen because the receipt must add up in front of a customer who
is checking it with their phone. Sum-then-round produces a total that is arithmetically
defensible and visibly wrong on paper, and "the receipt is wrong" is a conversation no
cashier should have to win.

The remaining hazard is **penny allocation** when splitting a bill: three ways on 1000
cents is 334, 333, 333 — the earliest part absorbs the remainder. The rule itself is
arbitrary; that it is deterministic and totals exactly is not. Never split by dividing and
rounding each share, which invents or destroys pennies.

Implemented as `Money::allocate()` / `allocateByRatios()`, with a property test asserting
the parts always sum to the whole across every amount and split — written before the
feature that needed it, not after.

### Tax-inclusive vs tax-exclusive

A general-purpose POS cannot dodge this. US retail adds tax at checkout; EU/UK/AU
display it in the shelf price.

`locations.prices_include_tax` (boolean) drives it:

- **Exclusive:** `line_total = qty × unit_price`, then `tax = round(line_total × rate)`,
  and the customer pays `line_total + tax`.
- **Inclusive:** `unit_price` already contains the tax, so we *extract* it:
  `tax = round(line_total × rate / (1 + rate))`. The customer pays `line_total`.

Both paths store `tax_amount` on the line, so downstream reporting never needs to know
which mode was used. The mode affects extraction, not representation.

## Auth

Two distinct concepts that POS systems routinely conflate, to their cost:

1. **Register authentication.** A terminal is enrolled once and holds a long-lived
   Sanctum token identifying *the device*. This is the machine's identity.
2. **Staff authorization.** A cashier taps a 4–6 digit PIN to start acting on that
   register. This yields a short-lived staff session (default 8h, ends at shift close).

They are separate because the device is trusted (it's physically in your store, it was
enrolled by an admin) but the person is not (they change every few hours). A PIN is a
weak secret and is *only* acceptable because it's presented from an already-authenticated
device on a private network — a PIN alone is never sufficient to reach the API.

PINs are hashed with bcrypt, are never logged, and are rate-limited per register (5
attempts, then a 60s lockout) to blunt the small keyspace. Back-office users log in with
a real email and password; they do not get PINs.

3. **Back-office authentication.** A third, independent tier, added in M6: `POST
   /admin/login` takes an email and password and returns a Sanctum token with no device
   and no location behind it at all — there is no terminal to trust and no register to
   read a team id from. This is why the back office is **admin-only** in v1 rather than
   role-gated like the register: every other permission check in this system gets its
   location from the physical till that made the request, and a login endpoint with
   neither a device nor a register has nothing to hang a team-scoped role on. `05-rbac.md`
   has the full rationale and the named deferral (a read-only bookkeeper role) that
   revives this decision once there's a real accountant to build it for.

### Roles

Coarse and boring on purpose:

- `cashier` — open/modify/close own orders, take payments, open/close own shift.
- `supervisor` — cashier, plus: void lines, apply manual discounts, no-sale drawer open,
  reopen an order, approve variance, stock adjustments/receiving/counting.
- `admin` — not a role. `users.is_admin` plus a `Gate::before` bypass, because spatie's
  teams cannot express a role assignment that spans locations. Full rationale in
  `05-rbac.md`.

The actions gated at `supervisor` are exactly the ones that let someone take value out of
the business without a customer noticing — cash out of the drawer or sellable stock out
of the count. That's the whole design rationale: the permission boundary follows the
fraud surface, not an org chart.

Implemented with `spatie/laravel-permission`, and **roles are scoped per location** — a
supervisor at one store is not a supervisor at another. Call sites ask
`can('order.discount.apply')`, never `role === 'supervisor'`. The permission catalog, the
teams configuration, and the split between permissions (capability) and policies (record
access) are in `05-rbac.md`.

## Idempotency

Non-negotiable even though we're online-only. The failure this prevents: a cashier taps
"Charge $50", the response is lost to a flaky network, the client retries, and the
customer is charged twice.

The routes carrying the idempotent middleware — add-line, payments, refunds, and shift
close — accept an `Idempotency-Key` header (client-generated UUIDv4). Payments, refunds,
and shift close **require** it.

```
idempotency_keys
  key           text primary key
  request_hash  text        -- SHA-256 of method + path + body
  response_code int
  response_body jsonb
  created_at    timestamptz
```

Handling:

1. Key unseen → process, store the response, return it.
2. Key seen, `request_hash` matches → return the stored response verbatim. Do not
   re-execute.
3. Key seen, `request_hash` differs → `409 Conflict`. The same key was reused for a
   different request, which means the client has a bug, and guessing which one they
   meant is worse than telling them.

Insertion of the key and the work it guards happen in **one transaction**, so a crash
mid-write cannot leave a key claiming success for work that rolled back. That constraint
forces the idempotency middleware to *open* the transaction that the action then nests
inside; see `04-backend-conventions.md`.

Keys are pruned after 24h — comfortably longer than any client will retry.

This table is also precisely the mechanism an offline write-queue would replay through,
which is why it's in v1 despite v1 being online-only.

## Concurrency

Two servers can touch one tab at once. Two cashiers can sell the last unit.

- **Orders** use optimistic locking: an integer `version` column, incremented on write.
  A mutation sends the version it read; a mismatch returns `409` and the client refetches
  and reapplies. This is right for orders because collisions are rare and blocking a
  server mid-service is worse than occasionally asking them to retry.
- **Stock** uses the ledger (`02-data-model.md`). Movements are inserts, and concurrent
  inserts don't conflict. Where we must not oversell, we take a `SELECT … FOR UPDATE` on
  the stock-level row inside the transaction. This is pessimistic *specifically because*
  the invariant is "never sell what we don't have", and optimistic retry can't guarantee
  that under contention.
- **Shifts:** a partial unique index enforces at most one open shift per register.
  Concurrency-safe by construction, no application check needed:
  ```sql
  create unique index one_open_shift_per_register
    on shifts (register_id) where closed_at is null;
  ```

That last pattern — push the invariant into the database where it can't be raced —
is the preferred solution throughout. Application-level checks are a fallback for
invariants Postgres can't express.

## Payment driver contract

Cash is the first driver, but the seam is defined now so a processor doesn't require
reshaping the order flow later.

```php
interface PaymentDriver
{
    public function code(): string;              // 'cash', 'external_card', 'stripe_terminal'
    public function capabilities(): Capabilities; // refundable? needs hardware? async?

    // Begin a tender. May complete immediately (cash) or return PENDING (terminal).
    public function authorize(PaymentIntent $intent): PaymentResult;

    // Settle a prior authorization. Cash is a no-op; card processors are not.
    public function capture(Payment $payment): PaymentResult;

    public function refund(Payment $payment, int $amountCents): PaymentResult;
    public function void(Payment $payment): PaymentResult;
}
```

The contract is `authorize`/`capture` **even though cash doesn't need two steps**,
because a driver that can't express "pending, waiting on the customer to tap" forces
every caller to be rewritten the day we add a real reader. Cash implements `capture` as
a no-op returning success. That's a small, contained lie in the cash driver, versus a
large refactor of the order flow later.

Drivers are resolved from a registry keyed by `code`, so adding one is a class plus a
config entry.

v1 ships:

- **`cash`** — drawer, tender amount, change due. Change is calculated by us, in
  integers. Requires an open shift.
- **`external_card`** — "the customer paid on the standalone card terminal." Records
  amount and an optional reference. We're a ledger here, not a processor. Explicitly
  *not* refundable through us: the money didn't go through us, so pretending we can send
  it back is a lie that would corrupt reconciliation.

## Error format

One shape, everywhere, so the client has one code path:

```json
{
  "error": {
    "code": "insufficient_stock",
    "message": "Only 2 units of SKU-1234 remain.",
    "details": { "variant_id": "...", "requested": 5, "available": 2 }
  }
}
```

`code` is a stable machine-readable string — clients branch on it, and it never changes
once shipped. `message` is human-readable, may change, and is never parsed. `details` is
code-specific and typed per code on the frontend.

## Testing

- **Domain math is unit-tested first.** Tax, discounts, change, split allocation. These
  are pure integer functions with no I/O, they are where the expensive bugs live, and
  they are trivial to test — there is no excuse for testing them via HTTP.
- **API tests hit real Postgres**, not SQLite. We rely on partial indexes, `FOR UPDATE`,
  and `jsonb`; SQLite silently lacks them, so a green SQLite suite would be actively
  misleading about whether our concurrency invariants hold.
- **The invariants that must have dedicated tests**, because each represents money
  walking out the door:
  - A replayed idempotency key charges once.
  - Concurrent sales of the last unit: one succeeds, one gets `insufficient_stock`.
  - A closed order rejects mutation.
  - Split payments sum exactly to the order total, no lost or invented pennies.
  - Shift variance = counted − (float + cash sales − payouts).
