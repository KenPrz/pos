# Tauri register shell: design

Owner-directed: a **desktop shell for the register** at `frontend/native/`, built on
Tauri v2. It hosts the existing register SPA and adds the two things a browser tab
cannot do — drive a thermal printer and kick a cash drawer. `01-architecture.md`
reserved this folder in v1 and wrote down the seam; this spec fills it in.

Owner's calls: **groundwork with a mock driver** (no hardware purchased yet), **bundled
SPA talking to the API through Rust**, **first-run setup screen** for the server
address, and **make the `drawer.no_sale` rule real** with a backend endpoint.

## Why a shell exists at all

Not to be a third frontend. `frontend/native/` is the same register SPA plus a hardware
bridge. The seam is already law in `01-architecture.md` and this spec does not relitigate
it:

- The **server** decides *what* — receipt content from snapshot columns, whether the
  drawer may open, who authorized it.
- The **shell** does *how* — bytes to a printer, a pulse to a drawer.
- **No money decision ever lives in the shell.** A shell that decided when a drawer may
  open would put the fraud boundary on the terminal, where it cannot be audited.

One physical fact shapes everything downstream: **the drawer has no computer in it.** It
is kicked by the printer over RJ11. Printer and drawer are one device, one driver, one
transport — not two.

## Hosting: bundled SPA, HTTP through Rust

The register app static-exports cleanly — a single route, no route handlers, no server
actions, no `next/headers`. Verified: `output: 'export'` produces `out/index.html` and
nothing else is required.

A bundled page's origin is `tauri://localhost`, and **there is no Next rewrite in the
bundle**. That rewrite is what has made `fetch('/api/v1…')` same-origin since M0. Calling
the API directly from the webview would be cross-origin, requiring CORS headers on the
API and breaking the no-CORS invariant the project has held end to end. So API traffic
detours through Rust:

```
webview (tauri://localhost)            Rust core                     server
  api.ts ──invoke('api_request')──▶   reqwest ──https──▶   FrankenPHP /api/v1/*
```

The browser's origin model never applies, so **no server change and no CORS**. Two
properties follow from putting the detour in Rust rather than using a generic HTTP
plugin:

- **The base URL lives in Rust-held config, not in JS.** The webview passes a *path*, never
  a host. A compromised page cannot redirect a device token to an attacker's server.
- **The scope is enforced at runtime**, which a build-time capability URL allowlist cannot
  do for a server address that is configured on first run.

### The one SPA change

`src/lib/api.ts` has exactly one `fetch` call site. It gains a transport shim:

| Environment | Transport |
| --- | --- |
| Browser (deployed app) | `fetch('/api/v1' + path, init)` — byte-identical to today |
| Shell | `invoke('api_request', { path, method, headers, body })` |

The browser path is unchanged by construction, so the deployed register app and its 92
tests are unaffected. Detection is a single `isTauri()` check at module load.

**Error shape is part of the contract.** The SPA already models an unreachable server as
`ApiError('network_unreachable', 'Cannot reach the server.', 0)` and has UI for it. The
Rust transport maps its own transport failures onto that exact shape, so every existing
offline-state screen keeps working without change.

### Dual build

`next.config.ts` becomes env-driven:

```ts
output: process.env.POS_STATIC_EXPORT ? 'export' : 'standalone',
```

Production keeps its standalone Node server; the shell builds the static bundle from the
same source. Both outputs are built in CI so neither can rot.

## Hardware

```
frontend/native/src-tauri/src/
  main.rs        window, command registration
  config.rs      server URL + printer settings, in Tauri's app-config dir
  api.rs         api_request — the HTTP detour
  hardware/
    escpos.rs    receipt JSON → ESC/POS bytes    ← pure function, unit-tested
    driver.rs    trait Printer { write(&[u8]), kick_drawer() }
    mock.rs      writes bytes to a file, logs the kick
```

Two commands: `print_receipt(receipt)` and `open_drawer()`.

`open_drawer()` takes **no authorization argument, deliberately.** The webview and the
shell are one trust domain — a token passed from JS to Rust would be theatre, since
whatever could forge the call could also forge the token. Authority lives where it can be
audited: the SPA calls the server first, and the server writes the audit row whether or
not any drawer physically opens. The shell stays dumb, which is the seam working as
designed rather than a gap in it.

The SPA fetches `GET /api/v1/orders/{id}/receipt` — which already returns structured JSON
— and hands it to the shell. Rust decides nothing about *content*; it converts
server-provided JSON into bytes. That conversion is a pure function of JSON to `Vec<u8>`,
so it is genuinely testable without hardware, which is the point of putting the seam here.

**Only the mock driver ships in this spec.** It writes the exact bytes it would have sent
to a file under the app data dir and logs drawer kicks, so the whole path is exercisable
and reviewable with no printer in the building. The first real driver will be network
(raw TCP 9100) — roughly fifteen lines against the same trait — with USB and serial after
it, each behind the same `Printer` impl.

Browser printing is **not** removed. The existing `.receipt` markup and its print CSS
(including the `hero-amount` 24px print override) stay exactly as they are; the shell adds
a path rather than replacing one.

### Printing must never block a sale

A sale is closed on the server before anything is printed. A printer that is out of paper,
unplugged, or absent therefore cannot roll anything back, and must not try. Print and
drawer failures surface as a notice on the register and are retryable from the completed
sale; they never change order state. This is a hard rule: the alternative is a till that
refuses to take money because a printer is jammed.

## Making the rule real: the no-sale endpoint

`05-rbac.md:192` gates `drawer.no_sale` — grouped with payouts and drops under **"money
leaves"**, supervisor-only — but `03-api.md` has no endpoint behind it. It is a gate with
no door, harmless only because no software could open a drawer. The shell changes that.

**No migration is required.** `cash_movements` has a table because `ShiftTotals` aggregates
it; a no-sale moves no money, so there is nothing to aggregate. The audit row *is* the
record, and M6's audit viewer already reads it.

```
POST /api/v1/drawer/no-sale
  auth       device token + staff session, like every register write
  permission drawer.no_sale
  body       { "reason": "<required, non-empty>" }
  binds to   the register's OPEN shift — the shift is the accountability unit
  writes     audit->record('drawer.no_sale', $shift, actorId, { reason }, registerId)
  200        → { "authorized": true }
  403        → the staff member lacks drawer.no_sale
  409        → no open shift — reuses the existing NoOpenShift exception
               (errorCode 'no_open_shift'), no new exception class
  422        → reason missing or empty
```

Modelled directly on `RecordCashMovement`, whose own comment makes the argument for the
mandatory reason: *"an unexplained drawer movement is the classic internal-theft vector."*
Same action-class shape as every other register write — Input DTO → Action → domain object,
serialization in the controller, no HTTP knowledge in the action.

The register gains a **No sale** button, visible only when the staff member
`can('drawer.no_sale')` **and** the shell is present — in a plain browser nothing can open
a drawer, so offering the button would be a lie. The shell pulses **only** after the server
answers 200; a 403 opens nothing.

## First-run configuration

A bundled app has no implicit origin, so it must be told which server to talk to before it
can call anything. Boot becomes:

1. Shell starts, loads the bundled SPA.
2. SPA asks the shell for config.
3. **No server URL yet** → a **Connect this terminal** screen.
4. The URL is validated against `GET /api/v1/health` before it is saved — a typo is caught
   at setup, not at the first sale.
5. Then today's existing flow, unchanged: enrol the device token, PIN, shift.

That screen is built from the existing component vocabulary (`Card`, `Input`, `Button`,
`FieldRow`), so it inherits the design language rather than introducing a second UI
toolkit. It sits naturally beside "Enroll this terminal", which is the same kind of
one-time terminal setup step. The URL is reconfigurable later from the same screen.

## Testing

- **Rust:** unit tests for ESC/POS encoding (a pure function — the highest-value tests
  here), config round-trip, and the mock driver's byte output.
- **TypeScript:** the transport shim — the browser branch is unchanged, the shell branch
  maps errors onto `ApiError` correctly. The existing **92 register tests stay green and
  unmodified**; that is the proof the deployed app is untouched.
- **Backend:** the no-sale slice gets the same treatment every register write gets —
  permission denied, no open shift, missing reason, and the happy path writing exactly one
  audit row.
- **CI:** `cargo fmt --check`, `cargo clippy`, `cargo test`, plus a build of *both* Next
  outputs. Full Tauri bundling is **not** in CI: it needs WebKitGTK system dependencies and
  buys nothing until there is something to distribute.

## Out of scope

| Deferred | Revive when |
| --- | --- |
| Real ESC/POS drivers (network, USB, serial) | A printer physically exists. The trait and the byte encoder are already built; a driver is the small part. |
| Offline-tolerant writes | `00-overview.md`'s trigger fires. The shell is the host that makes it reachable, but v1's online-only decision stands. |
| Auto-update, code signing, installers | A second terminal needs to be provisioned by someone who isn't you. |
| Windows / macOS packaging | A pilot runs on something other than Linux. |
| Scale integration | Anything is sold by weight. |
| Barcode scanners | Never — they are keyboard-wedge HID devices and already work in a plain browser. The shell is not needed and adding a code path for them would be pure cost. |

## Risks

- **WebKitGTK, not Chrome.** The reworked UI was eyeballed in Chrome; the shell renders in
  the platform webview, which on Linux is WebKitGTK. The register screens need a look in
  the real webview, and the plan allocates a task to it.
- **The dual build must not break production.** `output` becoming conditional is a change to
  a file that ships the deployed app. CI building both outputs is the guard, and it is a
  verifiable claim rather than a hope.
- **`frontend/web/next.config.ts` is currently modified in the working tree** (`output:
  'export'`). Provenance is ambiguous — it was already listed as modified at session start.
  The env-driven form supersedes it either way; the plan's first task resolves it
  explicitly rather than silently.
