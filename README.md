# POS

[![CI](https://github.com/KenPrz/pos/actions/workflows/ci.yml/badge.svg)](https://github.com/KenPrz/pos/actions/workflows/ci.yml)

A point-of-sale system for a single business across multiple locations, serving both
**retail** (scan a barcode, pay, leave) and **food service** (open a tab against a
table, add courses for an hour, split the check three ways) from **one order model** —
the same tables, the same lifecycle, different screens.

## What's in the box

Four surfaces over one Laravel API and one Postgres database:

| Surface | What it does |
| --- | --- |
| **Register** (`frontend/web`) | The till. Scanner-first for retail, menu grid + floor view for food service — tabs, modifiers, coursing, split checks, refunds, cash drawer with a blind count. |
| **Back office** (`frontend/back-office`) | The manager's side: catalog, staff and per-location roles, register settings, sales/stock reports, and a viewer for the append-only audit trail. |
| **Desktop shell** (`frontend/native`) | Tauri v2. Hosts the same register and adds what a browser tab cannot do: a thermal printer and a cash drawer. Mock driver only so far — it writes the ESC/POS bytes to a file. |
| **API** (`backend`) | Laravel 13 action-class architecture. Every mutation audited, every financial record append-only, all money in integer cents. |

Principles that shape everything (the reasoning lives in [`docs/`](docs/README.md)):

- **Money is integer cents, always.** One rounding primitive in one place; split
  payments and refunds are penny-exact by construction.
- **Financial records are append-only.** A refund is new rows; a closed order is never
  mutated; last year's receipt reprints byte-identically.
- **One order model.** Food service is retail plus a longer open phase — proven, not
  assumed: the food-service milestone shipped with zero new order tables.
- **Config is deployed, data is administered.** Engineers change config; admins change
  the database; nothing lives in both.
- **The server decides, the terminal obeys.** The API says what a receipt contains and
  whether a drawer may open, and audits who authorized it; the shell only turns that
  into bytes and a pulse. No money decision lives where it cannot be audited.

## Quick start

Requirements: Docker. Nothing else.

```bash
cp .env.example .env
make dev-key          # mints an APP_KEY — paste it into .env
make dev              # full stack: Postgres, API, register, back office
make seed             # demo data — prints dev PINs and device tokens
```

- Register: <http://localhost:5174> — issue the till an activation code in the back office, type it in once, clock in with a PIN
- Back office: <http://localhost:5175> — the printed admin email/password
- API health: <http://localhost:8000/api/v1/health>

`make help` lists every target — tests, e2e story proofs, backups (including
`make restore-drill`, because an untested backup is a rumor), and production
(`make prod-up`: one FrankenPHP edge, automatic TLS, host-routed domains).

The desktop shell is built separately, and needs a Rust toolchain plus WebKitGTK
(`libwebkit2gtk-4.1-dev libgtk-3-dev librsvg2-dev`):

```bash
cd frontend/native && npm install && npm run build   # bundles the register into a desktop app
```

On first launch it asks for the API's address, then enrols like any other terminal. See
[`frontend/native/README.md`](frontend/native/README.md).

## Documentation

- **[User Manual](https://github.com/KenPrz/pos/wiki)** — task-oriented guides by role
  (cashier, supervisor, manager, operator), synced to the wiki from
  [`docs/manual/`](docs/manual/) on every push. Every quoted button and command is
  verified against the source.
- **[Technical documentation](docs/README.md)** — architecture, data model, API,
  conventions, RBAC, and the roadmap. The design docs are the source of truth; the
  code follows them, and CI enforces the conventions mechanically (`tests/Arch`).
- **[CLAUDE.md](CLAUDE.md)** — how to run and develop, including the gotchas that cost
  an afternoon each to learn.
