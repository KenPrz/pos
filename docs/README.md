# POS — Design Docs

Design-first. Read in order; each assumes the one before it.

| Doc | What's in it |
| --- | --- |
| [00-overview.md](00-overview.md) | What we're building, why retail and food service are one system, principles, v1 decisions, non-goals, glossary. |
| [01-architecture.md](01-architecture.md) | Stack and versions, topology, deployment topology, money and rounding rules, auth, idempotency, concurrency, payment driver contract, error format, testing. |
| [02-data-model.md](02-data-model.md) | Full Postgres schema with rationale. The core artifact. |
| [03-api.md](03-api.md) | REST surface, auth flows, order lifecycle, error codes. |
| [04-backend-conventions.md](04-backend-conventions.md) | Action-class architecture: controller → request → action → resource. Rules, layering, worked example, configuration. |
| [05-rbac.md](05-rbac.md) | `spatie/laravel-permission`: per-location roles, permission catalog, permissions vs policies. |
| [06-roadmap.md](06-roadmap.md) | Milestones M0–M7 and their sequencing rationale. |

## The five-line version

A POS for one business across several locations, serving both retail and food service
from **one order lifecycle** — retail just runs through it in a minute while a restaurant
tab lingers for an hour. Laravel 13 + Postgres 18 + React 19. Online-only in v1, with
idempotency keys everywhere as the on-ramp to offline later. Money is always integer
cents, financial records are append-only, and stock is a ledger rather than a number.

## Decisions locked for v1

| Decision | Choice | Reversibility |
| --- | --- | --- |
| Offline | Online-only | Deliberate on-ramp built (idempotency keys) |
| Tenancy | Single business, multi-location | **Expensive to change** — revisit early if ever selling to others |
| Payments | Driver contract; cash + external card | Cheap — add a driver class |
| Retail vs food service | One order model, `table_ref` distinguishes | Tested at M5 |

## Next step

M0 in [06-roadmap.md](06-roadmap.md): Docker Compose + Laravel + Vite skeleton that boots.
