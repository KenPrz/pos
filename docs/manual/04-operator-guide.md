# Operator Guide

This chapter is for whoever installs, runs, and keeps this system alive — comfortable
with a terminal, not assumed to know this repository already.

## Requirements

Docker and Docker Compose. That's the whole list — `make dev` brings up Postgres, the
API, the register app, and the back office in containers, hot-reloading against the
working tree, with nothing else installed on the host.

> Note: a fully native path (no Docker at all — Postgres, `php artisan serve`, `npm run
> dev` run directly) remains supported and is documented in the repo's root `CLAUDE.md`.
> This chapter covers the container path, which is the front door.

## First run

Five steps, in order — the third is a paste, not a command:

1. `cp .env.example .env` — copies the documented shape of the root `.env` file. Only
   needed the first time.
2. `make dev-key` — mints a Laravel `APP_KEY` using nothing but Docker (no vendor
   install, no compose, no existing key required). It prints a line like
   `base64:xxxxxxxx...`.
3. Paste that value into `.env` as `POS_DEV_APP_KEY`.
4. `make dev` — brings up the full dev stack: database, API, register app, back
   office.
5. `make seed` — runs a fresh migrate and seeds a believable business: by default one
   Manila grocery location (`GRC`), staff at every role, a real Philippine retail
   catalog. Set `POS_SEED_CATALOGS=grocery,restaurant,cafe` (any subset, comma-separated)
   to also bring up the Manila Restaurant (`RST`) and Manila Cafe (`CAF`) locations,
   covering food service alongside retail.

`make seed` prints three tables, and you'll want all three the first time:

- **Development PINs** — one row per person, with their PIN and role, e.g. Alice /
  `1111` / cashier @ GRC. Alice (cashier) and Bob (supervisor) hold their role at
  *every* seeded location; Maria (`3333`) is cashier at the first location and
  supervisor at the second when a second one exists; Priya (`4444`) is a global admin.
  Use one of these to clock in at a register.
- **Device tokens** — one row per till, register name and device token (e.g. `GRC /
  Till 1`). These are for scripts and direct API work (the e2e scripts consume them
  this way) — the register's activation screen no longer takes a raw token at all.
  To bring a till online through its own screen, sign in to the back office with the
  printed admin login below and issue that till an activation code (see the Manager
  Guide's [Issue an activation code](03-manager-guide.md#issue-an-activation-code)),
  then type the code into the till (see [Getting
  Started](00-getting-started.md#signing-in)).
- **Back-office login** — an email and password (`POST /api/v1/admin/login`) for
  signing in to the back office.

> Note: `make seed` is destructive — it's a *fresh* migrate every time. Re-run it
> whenever you want a clean slate, but not against data you meant to keep.

Once it's up: the API is at `http://127.0.0.1:8000`, the register app at
`http://127.0.0.1:5174`, the back office at `http://127.0.0.1:5175`. `make ps` shows
the actual host ports if you've overridden any of them (see `POS_DEV_*_PORT` in
[Troubleshooting](#troubleshooting)).

## Connect the desktop shell to a server

Only in the desktop shell (`frontend/native/`) — a browser tab already knows its own
origin, so this step doesn't apply there. Before a shell terminal can even reach the
**Activate this terminal** screen (see [Signing in](00-getting-started.md#signing-in)), it
needs to know which server to talk to.

1. On first launch, the shell shows its own **Connect this terminal** screen, asking for
   a **Server address**.
2. Type the POS server's own address into **Server address** (placeholder
   `https://pos.example.com`).
3. Tap **Connect**.

> Note: the address is checked before it's saved — a wrong address fails right here with
> **"Cannot reach that server. Check the address and try again."**, not at the first sale
> of the morning. While it checks, the button reads **Connecting…**.

Once it connects, the address is saved on the terminal for good — you won't be asked
again on that machine — and the shell moves straight into the ordinary activation flow
above.

## Everyday commands

`make help` lists every target. The ones you'll reach for:

| Target | Does |
| --- | --- |
| `help` | List available targets |
| `dev` | Bring up the full dev stack (db, api, register, back office) |
| `dev-down` | Stop the dev stack (volumes survive) |
| `logs` | Tail dev stack logs |
| `ps` | Dev stack status |
| `dev-key` | Mint an APP_KEY for the root .env — no vendor, no compose, no existing key needed |
| `seed` | Fresh migrate + seed (prints dev PINs and device tokens) |
| `migrate` | Run pending migrations |
| `e2e` | Reseed (twice — see comment above), run all three committed e2e proofs, THEN LEAVE THE DEV DB DIRTY with two seeds' + e2e-admin-day's data (re-run `make seed` after for a clean slate). Needs the api container reachable at http://127.0.0.1:8000 — the scripts hardcode it; override POS_DEV_API_PORT back to 8000 in root .env if something else is squatting on it. |
| `test` | All suites, in containers |
| `test-backend` | Pest against the compose db (creates pos_test if missing) |
| `test-web` | Register app vitest |
| `test-bo` | Back-office vitest |
| `typecheck` | tsgo on both frontend apps |
| `clean` | Dev stack down AND volumes destroyed (asks first) |
| `build` | Build all three production images |
| `prod-up` | Start the production stack (needs .env — see .env.prod.example) |
| `prod-down` | Stop the production stack |
| `prod-logs` | Tail production logs |
| `backup` | pg_dump -Fc the stack db -> backups/pos-<utc>.dump (COMPOSE=prod for prod) |
| `restore` | Restore FILE=backups/... into the running db (DESTRUCTIVE, asks first) |
| `restore-drill` | Prove the newest backup restores: throwaway db, row counts, teardown |

## Production

Production runs the same three images behind one edge (the API container is
FrankenPHP, which is also Caddy — it terminates TLS and reverse-proxies to the two
frontends by hostname). It's still one `docker compose` stack, just a different
compose file.

Before the first boot:

1. **DNS.** Point two hostnames at the host: one for the register app, one for the
   back office. Both need ports 80 and 443 reachable from the internet if you want
   real certificates (see TLS, below).
2. **`.env`**, copied from `.env.prod.example` and filled in, sitting beside
   `compose.prod.yml`:

   | Variable | One line |
   | --- | --- |
   | `POS_DB_PASSWORD` | Postgres password for the prod db container — a real secret, not dev's throwaway default. |
   | `POS_APP_KEY` | Laravel APP_KEY — mint with `make dev-key`, paste the `base64:...` value. |
   | `POS_REGISTER_DOMAIN` | Public hostname for the register app + API (needs DNS + reachable 80/443 for a real certificate). |
   | `POS_ADMIN_DOMAIN` | Public hostname for the back-office app (same requirement). |
   | `POS_CURRENCY` | ISO 4217 currency code (e.g. `USD`) — required, the app refuses to boot without it. |
   | `POS_BUSINESS_NAME` | Legal/trading name printed on receipts and the Z-report. |
   | `POS_BUSINESS_ADDRESS` | Optional — printed on receipts if set. |
   | `POS_BUSINESS_TAX_ID` | Optional — tax/VAT registration id printed on receipts if set. |
   | `POS_TLS_ISSUER` | Optional, default `acme` — set to `internal` for a domainless local boot. |
   | `POS_MIGRATE_ON_BOOT` | Optional, default `1` — runs `php artisan migrate --force` on every api boot; set `0` to migrate by hand instead. |

3. `make prod-up` — builds and starts the production stack.

### TLS

TLS is automatic — Caddy provisions and renews certificates for
`POS_REGISTER_DOMAIN` and `POS_ADMIN_DOMAIN` on its own once DNS and 80/443 are in
place. No certificate files to manage.

To smoke-test without real DNS, set `POS_TLS_ISSUER=internal` and use
`register.localhost` / `admin.localhost` as the two domains (both resolve to
`127.0.0.1` with no `/etc/hosts` edit). Caddy issues from its own local CA instead of
a public one, so `curl` needs `-k` against it.

> Note: the Compose project name `pos` (only `compose.prod.yml` uses it —
> `compose.dev.yml` is `pos-dev`, a separate volume namespace) claims the
> `pos_pgdata` volume outright. If this host ever ran the retired
> `infra/docker-compose.yml` (same default project name), it attaches to that same
> volume — a real database, not a fresh one. Tear that down with `-v` first, or boot
> this stack with `COMPOSE_PROJECT_NAME` set to something else.

## Backups

```
make backup           # pg_dump -Fc the stack db -> backups/pos-<utc>.dump
make restore FILE=backups/pos-....dump    # DESTRUCTIVE, asks first
make restore-drill    # prove the newest backup actually restores
```

Add `COMPOSE=prod` to any of the three to target the production stack instead of dev
(both stacks name their db service `db` and their database/user `pos`, so one set of
targets covers either).

`make restore` overwrites the live database — it asks you to type `restore` to
confirm. `make restore-drill` doesn't touch anything live: it restores the newest
dump into a throwaway Postgres container, prints row counts, and tears the container
down.

Run the drill after every backup you'd actually rely on. As the roadmap puts it: **an
untested backup is a rumor.**

## Tests & proofs

```
make test    # all three suites (backend/web/back-office), in containers
make e2e     # the three committed end-to-end proofs, against the running stack
```

> Note: `make e2e` reseeds the dev database twice and leaves it dirty on purpose —
> two seeds' worth of fixtures plus everything `e2e-admin-day.sh` wrote. Run `make
> seed` again afterward before using the dev stack for anything else.

## Troubleshooting

**Register shows "Terminal disabled" on its own.** Its device token was rejected (the
API returns `invalid_device_token`) — most often because a manager issued that
register a new activation code, which revokes the old device token and every staff
session bound to it in the same action. The lockout screen shows the same message the
till would show either way: **"Your activation code has been disabled. Please contact
an admin and request a new activation code."**, with the activation-code entry form
right below it. Get a fresh code from the back office (see the Manager Guide's [Issue
an activation code](03-manager-guide.md#issue-an-activation-code)) and type it in on
that same screen (see [Getting Started](00-getting-started.md#signing-in)).

**A till's drawer won't reconcile at close.** Read the Z-report for that shift first
— it breaks down sales by tender and any cash movements (payouts, paid-ins) recorded
during the shift. If the counted cash still doesn't match, the close records a
variance that needs approval.

> Note: variance approval must come from a **different** register at the same
> location than the one that just closed. Closing a shift revokes every staff session
> bound to that register, so a request from the just-closed till 401s — the check is
> on location, not the specific terminal, so a request from any other open till there
> succeeds. This version's register app doesn't have a screen for it, though: a till
> only ever shows its own currently open shift, never another till's, so there's no
> button to tap at that other till either. Approving today means calling the API
> directly — `POST /api/v1/shifts/{id}/approve-variance` — with a staff session from
> that other till, the way `scripts/e2e-lunch-service.sh` does it (see the
> [Supervisor Guide](02-supervisor-guide.md)).

**Port already in use.** Override the host-side port in `.env` rather than stopping
whatever's already listening: `POS_DEV_API_PORT`, `POS_DEV_WEB_PORT`,
`POS_DEV_BACKOFFICE_PORT`, or `POS_DEV_DB_PORT`. The containers keep listening on
their usual internal ports either way.
