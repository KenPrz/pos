# Tauri Register Shell Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Tauri v2 desktop shell at `frontend/native/` that hosts the register SPA and adds thermal-printer and cash-drawer access, with every money decision staying on the server.

**Architecture:** The shell bundles `frontend/web`'s static export. Because a bundled page has origin `tauri://localhost` and no Next rewrite, API traffic detours through a Rust `api_request` command (no CORS, and the base URL is held in Rust so the webview never names a host). Hardware is a `Printer` trait with a mock implementation; receipt JSON from the server is converted to ESC/POS bytes by a pure Rust function. A new `POST /api/v1/drawer/no-sale` endpoint makes the already-gated `drawer.no_sale` permission real.

**Tech Stack:** Tauri v2 (Rust 1.96), reqwest (rustls), serde; Next.js 16 static export; Laravel 13.20 / PHP 8.5 / Pest for the backend slice.

## Global Constraints

- **The shell never decides money.** It converts server-provided JSON to bytes and pulses a drawer. No pricing, no authorization, no totals arithmetic in Rust.
- **Printing never blocks a sale.** The order is closed server-side before printing; print and drawer failures surface as a notice and never change order state.
- **The browser path stays byte-identical.** `frontend/web`'s existing 92 tests pass **unmodified** — that is the proof the deployed app is untouched.
- **Money is integer cents everywhere, including Rust.** `i64`, never a float, in any layer.
- **No new CORS.** The API is never called cross-origin from the webview.
- **Existing labels are frozen.** New surfaces (the setup screen, the No sale button) introduce new labels; no existing label changes.
- **Backend conventions:** one route = one controller = one Action class; actions take an Input DTO and know nothing about HTTP; `declare(strict_types=1)`; actions are `final`; no `env()` outside `config/`. `tests/Arch/` enforces this mechanically.
- **Commit after every task.** No Co-Authored-By trailers.

## File Structure

| File | Responsibility |
| --- | --- |
| `backend/app/Actions/Drawer/OpenDrawerNoSale.php` | Authorize + audit a no-sale drawer opening |
| `backend/app/Actions/Drawer/OpenDrawerNoSaleInput.php` | Input DTO |
| `backend/app/Http/Requests/Drawer/OpenDrawerNoSaleRequest.php` | Permission check + validation + `toInput()` |
| `backend/app/Http/Controllers/Drawer/OpenDrawerNoSaleController.php` | HTTP serialization only |
| `backend/tests/Feature/Drawer/NoSaleTest.php` | Endpoint behaviour |
| `frontend/web/next.config.ts` | Dual output: standalone (prod) / export (shell) |
| `frontend/web/src/lib/transport.ts` | Browser-vs-shell transport seam |
| `frontend/web/src/lib/shell.ts` | Shell detection + hardware/config command wrappers |
| `frontend/web/src/register/SetupScreen.tsx` | First-run "Connect this terminal" |
| `frontend/native/src-tauri/src/main.rs` | Window + command registration |
| `frontend/native/src-tauri/src/config.rs` | Server URL persistence + URL normalization |
| `frontend/native/src-tauri/src/api.rs` | `api_request` — the HTTP detour |
| `frontend/native/src-tauri/src/hardware/escpos.rs` | Receipt JSON → ESC/POS bytes (pure) |
| `frontend/native/src-tauri/src/hardware/driver.rs` | `Printer` trait + driver selection |
| `frontend/native/src-tauri/src/hardware/mock.rs` | Mock driver writing bytes to disk |

---

### Task 1: Backend — the no-sale endpoint

**Files:**
- Create: `backend/app/Actions/Drawer/OpenDrawerNoSale.php`, `backend/app/Actions/Drawer/OpenDrawerNoSaleInput.php`, `backend/app/Http/Requests/Drawer/OpenDrawerNoSaleRequest.php`, `backend/app/Http/Controllers/Drawer/OpenDrawerNoSaleController.php`
- Modify: `backend/routes/api.php` (inside the existing `Route::middleware('staff')->group(...)`), `docs/03-api.md`
- Test: `backend/tests/Feature/Drawer/NoSaleTest.php`

**Interfaces:**
- Consumes: `App\Domain\Audit\AuditLogger::record()`, `App\Models\Shift::openFor(string $registerId)`, `App\Exceptions\Domain\NoOpenShift`, `App\Domain\Rbac\Permissions::DRAWER_NO_SALE` (all exist already).
- Produces: `POST /api/v1/drawer/no-sale` returning `{"data":{"authorized":true,"shift_id":"<uuid>"}}`. Task 8's SPA calls it before pulsing the drawer.

**No migration.** `cash_movements` has a table because `ShiftTotals` aggregates it; a no-sale moves no money, so the audit row is the record.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Drawer/NoSaleTest.php`:

```php
<?php
// backend/tests/Feature/Drawer/NoSaleTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->shift = Shift::factory()->create([
        'register_id' => $this->register->id,
        'opened_by' => $this->cashier->id,
        'opening_float_cents' => 10000,
    ]);
});

it('authorizes a supervisor and writes exactly one audit row', function (): void {
    $this->postJson('/api/v1/drawer/no-sale', ['reason' => 'Change for a twenty'],
        staffHeaders($this->register, $this->supervisor))
        ->assertOk()
        ->assertJsonPath('data.authorized', true)
        ->assertJsonPath('data.shift_id', $this->shift->id);

    $rows = DB::table('audit_log')
        ->where('action', 'drawer.no_sale')
        ->where('entity_id', $this->shift->id)
        ->get();

    expect($rows)->toHaveCount(1);
    expect(json_decode((string) $rows->first()->payload, true))
        ->toBe(['reason' => 'Change for a twenty']);
    expect($rows->first()->user_id)->toBe($this->supervisor->id);
    expect($rows->first()->register_id)->toBe($this->register->id);
});

it('refuses a cashier — the permission is supervisor-only', function (): void {
    $this->postJson('/api/v1/drawer/no-sale', ['reason' => 'Change for a twenty'],
        staffHeaders($this->register, $this->cashier))
        ->assertStatus(403);

    $this->assertDatabaseMissing('audit_log', ['action' => 'drawer.no_sale']);
});

it('requires a reason — an unexplained drawer opening is the whole thing we are preventing', function (): void {
    $this->postJson('/api/v1/drawer/no-sale', ['reason' => ''],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(422);

    $this->assertDatabaseMissing('audit_log', ['action' => 'drawer.no_sale']);
});

it('refuses when no shift is open on this register', function (): void {
    $this->shift->forceFill(['closed_at' => now(), 'closed_by' => $this->cashier->id,
        'counted_cash_cents' => 0, 'expected_cash_cents' => 0, 'variance_cents' => 0])->save();

    $this->postJson('/api/v1/drawer/no-sale', ['reason' => 'Change for a twenty'],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'no_open_shift');
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Drawer/NoSaleTest.php`
Expected: FAIL — 404, the route does not exist yet.

- [ ] **Step 3: Write the Input DTO**

Create `backend/app/Actions/Drawer/OpenDrawerNoSaleInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Drawer;

final readonly class OpenDrawerNoSaleInput
{
    public function __construct(
        public string $registerId,
        public string $reason,
        public string $actorId,
    ) {}
}
```

- [ ] **Step 4: Write the Action**

Create `backend/app/Actions/Drawer/OpenDrawerNoSale.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Drawer;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\NoOpenShift;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Opening the drawer with no sale attached. Moves no money, so unlike a cash movement
 * there is nothing to aggregate and no table — the audit row IS the record.
 *
 * `reason` is mandatory for the same argument RecordCashMovement makes: an unexplained
 * drawer opening is the classic internal-theft vector. See docs/05-rbac.md.
 */
final class OpenDrawerNoSale
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(OpenDrawerNoSaleInput $in): object
    {
        return DB::transaction(function () use ($in): object {
            // A drawer opening belongs to a shift: the shift is the accountability unit,
            // so an opening with no open shift has nothing to attribute it to.
            $shift = Shift::openFor($in->registerId) ?? throw new NoOpenShift($in->registerId);

            $this->audit->record('drawer.no_sale', $shift, $in->actorId, [
                'reason' => $in->reason,
            ], registerId: $in->registerId);

            return (object) ['authorized' => true, 'shift_id' => $shift->id];
        });
    }
}
```

- [ ] **Step 5: Write the FormRequest**

Create `backend/app/Http/Requests/Drawer/OpenDrawerNoSaleRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Drawer;

use App\Actions\Drawer\OpenDrawerNoSaleInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class OpenDrawerNoSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::DRAWER_NO_SALE);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:200'],
        ];
    }

    public function toInput(): OpenDrawerNoSaleInput
    {
        return new OpenDrawerNoSaleInput(
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            reason: $this->string('reason')->toString(),
            actorId: $this->user()->id,
        );
    }
}
```

- [ ] **Step 6: Write the controller**

Create `backend/app/Http/Controllers/Drawer/OpenDrawerNoSaleController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Drawer;

use App\Actions\Drawer\OpenDrawerNoSale;
use App\Http\Requests\Drawer\OpenDrawerNoSaleRequest;
use Illuminate\Http\JsonResponse;

final class OpenDrawerNoSaleController
{
    public function __invoke(OpenDrawerNoSaleRequest $request, OpenDrawerNoSale $action): JsonResponse
    {
        $result = $action->execute($request->toInput());

        return response()->json(['data' => [
            'authorized' => $result->authorized,
            'shift_id' => $result->shift_id,
        ]]);
    }
}
```

- [ ] **Step 7: Register the route**

In `backend/routes/api.php`, add the import alongside the other controller imports:

```php
use App\Http\Controllers\Drawer\OpenDrawerNoSaleController;
```

Then inside the existing `Route::middleware('staff')->group(function (): void {` block, directly after the `shifts.approve-variance` route:

```php
            // No idempotency middleware: a repeat is a genuinely separate drawer opening
            // and must produce its own audit row, not silently replay the first one.
            Route::post('/drawer/no-sale', OpenDrawerNoSaleController::class)
                ->name('drawer.no-sale');
```

- [ ] **Step 8: Run the tests**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Drawer/NoSaleTest.php`
Expected: PASS, 4 tests.

Then the full suite plus architecture rules:

Run: `cd backend && ./vendor/bin/pest`
Expected: PASS — 466 tests (462 existing + 4 new).

- [ ] **Step 9: Document the endpoint**

In `docs/03-api.md`, find the `## Receipts` section and add a new section immediately before it:

```markdown
## Drawer

```
POST /api/v1/drawer/no-sale              # open the drawer with no sale attached
```

Requires `drawer.no_sale` (supervisor). `reason` is mandatory and the opening is bound to
the register's open shift — no open shift is `409 no_open_shift`. Moves no money, so
there is no table: the audit row is the record, and the back office's audit viewer reads
it. Only the desktop shell can act on the response; a browser has no drawer to open.
```

- [ ] **Step 10: Commit**

```bash
git add backend/app/Actions/Drawer backend/app/Http/Requests/Drawer \
        backend/app/Http/Controllers/Drawer backend/tests/Feature/Drawer \
        backend/routes/api.php docs/03-api.md
git commit -m "Drawer: no-sale endpoint — the gated permission gets a door"
```

---

### Task 2: Dual Next output + the transport seam

**Files:**
- Modify: `frontend/web/next.config.ts`, `frontend/web/src/lib/api.ts` (the `request` function, ~line 104), `frontend/web/package.json`
- Create: `frontend/web/src/lib/transport.ts`, `frontend/web/src/lib/transport.test.ts`

**Interfaces:**
- Produces: `send(path: string, init: RequestInit): Promise<{ status: number; body: string }>` and `inShell(): boolean`, both from `src/lib/transport.ts`. Tasks 5 and 8 use `inShell()`.

**Note on the working tree:** `frontend/web/next.config.ts` may already be modified to `output: 'export'` from an earlier experiment. This task replaces that line with the env-driven form, which supersedes it either way.

- [ ] **Step 1: Add the Tauri API dependency**

Run: `cd frontend/web && npm install --save-exact @tauri-apps/api@2.9.0`

This is imported by `transport.ts` but only *called* inside the `inShell()` branch, so it is inert in the browser build.

- [ ] **Step 2: Write the failing test**

Create `frontend/web/src/lib/transport.test.ts`:

```ts
// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { inShell, send } from './transport'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('inShell', () => {
  it('is false in a plain browser', () => {
    expect(inShell()).toBe(false)
  })

  it('is true when Tauri has injected its internals', () => {
    vi.stubGlobal('__TAURI_INTERNALS__', {})
    expect(inShell()).toBe(true)
  })
})

describe('send', () => {
  it('uses relative fetch in the browser, preserving the /api/v1 prefix', async () => {
    const fetchMock = vi.fn(async () => new Response('{"data":1}', { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const result = await send('/health', { method: 'GET' })

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/health')
    expect(result).toEqual({ status: 200, body: '{"data":1}' })
  })

  it('propagates a non-2xx status rather than throwing', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{"error":{}}', { status: 422 })))

    await expect(send('/orders', { method: 'POST' })).resolves.toEqual({
      status: 422,
      body: '{"error":{}}',
    })
  })
})
```

- [ ] **Step 3: Run it and watch it fail**

Run: `cd frontend/web && npm test -- transport`
Expected: FAIL — cannot resolve `./transport`.

- [ ] **Step 4: Write the transport module**

Create `frontend/web/src/lib/transport.ts`:

```ts
import { invoke } from '@tauri-apps/api/core'

/**
 * The one place that knows whether we are a browser tab or the desktop shell.
 *
 * A bundled shell page has origin `tauri://localhost` and no Next rewrite, so the
 * relative `/api/v1` URL that keeps the browser single-origin does not exist there.
 * The shell detours through Rust instead: no CORS, and the server address lives in
 * Rust config so the webview never names a host. See
 * docs/superpowers/specs/2026-07-20-tauri-register-shell-design.md.
 */
export type TransportResponse = { status: number; body: string }

export function inShell(): boolean {
  return typeof window !== 'undefined' && '__TAURI_INTERNALS__' in window
}

export async function send(path: string, init: RequestInit): Promise<TransportResponse> {
  if (!inShell()) {
    const response = await fetch(`/api/v1${path}`, init)
    return { status: response.status, body: await response.text() }
  }

  return invoke<TransportResponse>('api_request', {
    req: {
      path,
      method: (init.method ?? 'GET').toUpperCase(),
      headers: (init.headers ?? {}) as Record<string, string>,
      body: typeof init.body === 'string' ? init.body : null,
    },
  })
}
```

- [ ] **Step 5: Run the transport tests**

Run: `cd frontend/web && npm test -- transport`
Expected: PASS, 4 tests.

- [ ] **Step 6: Port `request()` onto the transport**

In `frontend/web/src/lib/api.ts`, add to the imports at the top of the file:

```ts
import { send } from './transport'
```

Then replace the body of `request<T>` (the `let response: Response` declaration through the `const body: unknown = ...` line) so the function reads:

```ts
async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', ...(init?.headers as Record<string, string>) }
  const device = tokens.device()
  const staff = tokens.staff()
  if (device) headers.Authorization = `Bearer ${device}`
  if (staff) headers['X-Staff-Token'] = staff

  let response: { status: number; body: string }

  try {
    response = await send(path, { ...init, headers })
  } catch (cause) {
    // The network never reached us. v1 is online-only (docs/00-overview.md), so this is
    // a real, expected state the UI has to show rather than swallow. The shell's Rust
    // transport maps its own failures here too, so offline screens work identically.
    throw new ApiError('network_unreachable', 'Cannot reach the server.', 0, {
      cause: String(cause),
    })
  }

  const ok = response.status >= 200 && response.status < 300

  let body: unknown = null
  try {
    body = JSON.parse(response.body)
  } catch {
    body = null
  }

  if (!ok) {
    // 503 from /health is a normal, well-formed response carrying `data`, not an error
    // envelope. Anything else that fails must be one.
    if (isErrorBody(body)) {
      throw new ApiError(body.error.code, body.error.message, response.status, body.error.details)
    }
    if (isSuccessBody<T>(body)) {
      return body.data
    }
    throw new ApiError('unexpected_response', `Unexpected ${response.status} from ${path}.`, response.status)
  }

  if (!isSuccessBody<T>(body)) {
    throw new ApiError('unexpected_response', `Malformed response from ${path}.`, response.status)
  }

  return body.data
}
```

- [ ] **Step 7: Make the Next output env-driven**

In `frontend/web/next.config.ts`, replace the `output:` line with:

```ts
  // Two shapes from one source: the deployed app is a standalone Node server; the Tauri
  // shell bundles a static export (it has no Node runtime and no rewrite). CI builds
  // both so neither can rot.
  output: process.env.POS_STATIC_EXPORT ? 'export' : 'standalone',
```

- [ ] **Step 8: Prove the browser path is untouched and both outputs build**

Run: `cd frontend/web && npm test`
Expected: PASS — 96 tests (92 existing **unmodified** + 4 new).

Run: `cd frontend/web && npm run typecheck`
Expected: clean.

Run: `cd frontend/web && npm run build`
Expected: succeeds, `.next/standalone` present.

Run: `cd frontend/web && POS_STATIC_EXPORT=1 npm run build && ls out/index.html`
Expected: succeeds, `out/index.html` exists.

- [ ] **Step 9: Commit**

```bash
git add frontend/web/next.config.ts frontend/web/src/lib/transport.ts \
        frontend/web/src/lib/transport.test.ts frontend/web/src/lib/api.ts \
        frontend/web/package.json frontend/web/package-lock.json
git commit -m "Register: transport seam + dual Next output for the shell"
```

---

### Task 3: Tauri scaffold — a window that loads the bundled SPA

**Files:**
- Create: `frontend/native/package.json`, `frontend/native/.gitignore`, `frontend/native/README.md`, and the `frontend/native/src-tauri/` tree produced by `tauri init`
- Modify: `frontend/native/src-tauri/tauri.conf.json` (after init)

**Interfaces:**
- Produces: a runnable shell. `npm run build` in `frontend/native` produces a bundle; `npm run dev` runs against `frontend/web`'s dev server on 5174.

- [ ] **Step 1: Create the package**

```bash
mkdir -p frontend/native
cd frontend/native
npm init -y
npm install --save-exact --save-dev @tauri-apps/cli@2.9.0
```

- [ ] **Step 2: Scaffold src-tauri**

```bash
cd frontend/native
npx tauri init --app-name "POS Register" --window-title "POS Register" \
  --frontend-dist ../../web/out --dev-url http://localhost:5174 \
  --before-dev-command "" --before-build-command ""
```

This creates `src-tauri/` with `Cargo.toml`, `src/main.rs`, `tauri.conf.json`, `capabilities/default.json`, and the default icon set. Icons come free here, which is why we scaffold rather than hand-write.

- [ ] **Step 3: Set the identifier and bundle targets**

Edit `frontend/native/src-tauri/tauri.conf.json` so `identifier` and `bundle` read:

```json
  "identifier": "test.pos.register",
  "bundle": {
    "active": true,
    "targets": ["deb", "appimage"],
    "icon": ["icons/32x32.png", "icons/128x128.png", "icons/icon.icns", "icons/icon.ico"]
  }
```

- [ ] **Step 4: Add the build scripts**

Replace `"scripts"` in `frontend/native/package.json` with:

```json
  "scripts": {
    "dev": "tauri dev",
    "build:spa": "cd ../web && POS_STATIC_EXPORT=1 npm run build",
    "build": "npm run build:spa && tauri build",
    "test": "cd src-tauri && cargo test",
    "lint": "cd src-tauri && cargo fmt --check && cargo clippy -- -D warnings"
  }
```

`dev` expects `frontend/web`'s dev server already running on 5174 (`npm run dev` there, or the docker dev stack). `build` produces the static export first because `tauri build` reads `frontendDist`.

- [ ] **Step 5: Ignore build artifacts**

Create `frontend/native/.gitignore`:

```
node_modules/
src-tauri/target/
```

- [ ] **Step 6: Verify it compiles**

Run: `cd frontend/native/src-tauri && cargo build`
Expected: succeeds. On a bare Linux host this needs WebKitGTK development packages; if it fails with a `webkit2gtk` pkg-config error, install `libwebkit2gtk-4.1-dev`, `libgtk-3-dev`, `librsvg2-dev`, and `build-essential`, then re-run.

- [ ] **Step 7: Write the README**

Create `frontend/native/README.md`:

```markdown
# POS register — desktop shell

Tauri v2 shell that hosts the register SPA (`frontend/web`) and adds the two things a
browser cannot do: drive a thermal printer and kick a cash drawer.

It is not a third frontend. It bundles the same SPA and adds a hardware bridge. The
seam is `docs/01-architecture.md`: the server decides *what*, the shell does *how*, and
no money decision lives here.

## Running

    npm run dev      # expects frontend/web's dev server on :5174
    npm run build    # static-exports the SPA, then bundles

## Tests

    npm test         # cargo test — ESC/POS encoding, config, path validation
    npm run lint     # cargo fmt --check && cargo clippy -D warnings

## System dependencies (Linux)

    libwebkit2gtk-4.1-dev libgtk-3-dev librsvg2-dev build-essential

Design: `docs/superpowers/specs/2026-07-20-tauri-register-shell-design.md`
```

- [ ] **Step 8: Commit**

```bash
git add frontend/native
git commit -m "Shell: Tauri v2 scaffold hosting the register SPA"
```

---

### Task 4: Server URL config + the `api_request` command

**Files:**
- Create: `frontend/native/src-tauri/src/config.rs`, `frontend/native/src-tauri/src/api.rs`
- Modify: `frontend/native/src-tauri/src/main.rs`, `frontend/native/src-tauri/Cargo.toml`

**Interfaces:**
- Produces four Tauri commands consumed by Task 5:
  - `get_config() -> { server_url: Option<String> }`
  - `set_server_url(url: String) -> Result<(), String>`
  - `check_server(url: String) -> bool`
  - `api_request(req: { path, method, headers, body }) -> Result<{ status: u16, body: String }, String>`

**Why `check_server` exists:** the setup screen must validate an address *before* it is
saved, but a webview `fetch` to that address would be cross-origin from
`tauri://localhost` and die on CORS — the exact thing this whole detour avoids. It cannot
use `api_request` either, because that reads the *saved* URL and nothing is saved yet. So
the probe is its own command taking a candidate URL.

- [ ] **Step 1: Add dependencies**

In `frontend/native/src-tauri/Cargo.toml`, add to `[dependencies]`:

```toml
reqwest = { version = "0.12", default-features = false, features = ["json", "rustls-tls"] }
```

`serde`, `serde_json`, and `tauri` are already there from the scaffold.

- [ ] **Step 2: Write the failing tests**

Create `frontend/native/src-tauri/src/config.rs` containing **only** the tests and the two pure functions' signatures is not how Rust works, so write the tests at the bottom of the file you are about to create. Start by creating `frontend/native/src-tauri/src/config.rs` with this test module and nothing else:

```rust
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalizes_a_url_by_trimming_whitespace_and_trailing_slashes() {
        assert_eq!(normalize_server_url("  https://pos.example.com/  ").unwrap(), "https://pos.example.com");
    }

    #[test]
    fn rejects_an_empty_address() {
        assert!(normalize_server_url("   ").is_err());
    }

    #[test]
    fn rejects_an_address_without_a_scheme() {
        assert!(normalize_server_url("pos.example.com").is_err());
    }

    #[test]
    fn accepts_plain_http_for_a_lan_till() {
        assert_eq!(normalize_server_url("http://192.168.1.10:8000").unwrap(), "http://192.168.1.10:8000");
    }

    #[test]
    fn config_round_trips_through_json() {
        let cfg = Config { server_url: Some("https://pos.example.com".into()) };
        let raw = serde_json::to_string(&cfg).unwrap();
        assert_eq!(serde_json::from_str::<Config>(&raw).unwrap().server_url, cfg.server_url);
    }

    #[test]
    fn a_missing_file_deserializes_to_an_empty_config() {
        assert_eq!(serde_json::from_str::<Config>("{}").unwrap().server_url, None);
    }
}
```

- [ ] **Step 3: Run and watch it fail**

Run: `cd frontend/native/src-tauri && cargo test`
Expected: FAIL — `normalize_server_url` and `Config` are not defined.

- [ ] **Step 4: Implement config.rs**

Prepend to `frontend/native/src-tauri/src/config.rs` (above the test module):

```rust
use serde::{Deserialize, Serialize};
use std::fs;
use std::path::PathBuf;
use tauri::Manager;

/// Terminal configuration, persisted in Tauri's app-config dir.
///
/// The server address lives HERE, in Rust, not in the webview. The webview passes a
/// path and never a host, so a compromised page cannot redirect a device token to
/// another server.
#[derive(Debug, Default, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Config {
    #[serde(default)]
    pub server_url: Option<String>,
}

/// Pure so it can be tested without an app handle: trims, strips trailing slashes, and
/// insists on a scheme. Plain http is allowed — a till on a shop LAN is a real case.
pub fn normalize_server_url(input: &str) -> Result<String, String> {
    let trimmed = input.trim().trim_end_matches('/');

    if trimmed.is_empty() {
        return Err("Server address is required.".to_string());
    }
    if !trimmed.starts_with("http://") && !trimmed.starts_with("https://") {
        return Err("Server address must start with http:// or https://".to_string());
    }

    Ok(trimmed.to_string())
}

fn config_path(app: &tauri::AppHandle) -> Result<PathBuf, String> {
    let dir = app.path().app_config_dir().map_err(|e| e.to_string())?;
    fs::create_dir_all(&dir).map_err(|e| e.to_string())?;
    Ok(dir.join("config.json"))
}

/// A missing or unreadable config is an unconfigured terminal, not an error: first run
/// is the common case, and the setup screen is the recovery path either way.
pub fn load(app: &tauri::AppHandle) -> Config {
    let Ok(path) = config_path(app) else {
        return Config::default();
    };
    let Ok(raw) = fs::read_to_string(path) else {
        return Config::default();
    };
    serde_json::from_str(&raw).unwrap_or_default()
}

pub fn save(app: &tauri::AppHandle, config: &Config) -> Result<(), String> {
    let path = config_path(app)?;
    let raw = serde_json::to_string_pretty(config).map_err(|e| e.to_string())?;
    fs::write(path, raw).map_err(|e| e.to_string())
}

#[tauri::command]
pub fn get_config(app: tauri::AppHandle) -> Config {
    load(&app)
}

#[tauri::command]
pub fn set_server_url(app: tauri::AppHandle, url: String) -> Result<(), String> {
    let normalized = normalize_server_url(&url)?;
    let mut config = load(&app);
    config.server_url = Some(normalized);
    save(&app, &config)
}

/// Probes a CANDIDATE address before it is saved. This cannot be a webview `fetch` (that
/// would be cross-origin from `tauri://localhost` and die on CORS) and it cannot be
/// `api_request` (which reads the saved URL, and nothing is saved yet). Returns a plain
/// bool: the setup screen only needs "can I reach a POS server here?".
#[tauri::command]
pub async fn check_server(url: String) -> bool {
    let Ok(normalized) = normalize_server_url(&url) else {
        return false;
    };

    let Ok(response) = reqwest::Client::new()
        .get(format!("{normalized}/api/v1/health"))
        .send()
        .await
    else {
        return false;
    };

    // /health answers 503 when the database is down. That is still a POS server at this
    // address, which is all the setup screen is asking.
    response.status().is_success() || response.status().as_u16() == 503
}
```

- [ ] **Step 5: Run the config tests**

Run: `cd frontend/native/src-tauri && cargo test`
Expected: PASS, 6 tests.

- [ ] **Step 6: Write the failing test for path validation**

Create `frontend/native/src-tauri/src/api.rs` with only this test module:

```rust
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_an_ordinary_api_path() {
        assert!(validate_path("/orders/123/receipt").is_ok());
    }

    #[test]
    fn rejects_a_path_that_is_really_an_absolute_url() {
        assert!(validate_path("https://evil.example.com/steal").is_err());
    }

    #[test]
    fn rejects_a_scheme_smuggled_mid_path() {
        assert!(validate_path("/orders/../../https://evil.example.com").is_err());
    }

    #[test]
    fn rejects_a_relative_path() {
        assert!(validate_path("orders").is_err());
    }
}
```

- [ ] **Step 7: Run and watch it fail**

Run: `cd frontend/native/src-tauri && cargo test`
Expected: FAIL — `validate_path` is not defined.

- [ ] **Step 8: Implement api.rs**

Prepend to `frontend/native/src-tauri/src/api.rs`:

```rust
use crate::config;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;

#[derive(Debug, Deserialize)]
pub struct ApiRequest {
    pub path: String,
    pub method: String,
    pub headers: HashMap<String, String>,
    pub body: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct ApiResponse {
    pub status: u16,
    pub body: String,
}

/// The webview supplies a path, never a host. Anything that looks like it is trying to
/// become an absolute URL is refused, so a compromised page cannot point the shell — and
/// the device token it carries — at another server.
pub fn validate_path(path: &str) -> Result<(), String> {
    if !path.starts_with('/') || path.contains("://") {
        return Err("Invalid API path.".to_string());
    }
    Ok(())
}

#[tauri::command]
pub async fn api_request(app: tauri::AppHandle, req: ApiRequest) -> Result<ApiResponse, String> {
    validate_path(&req.path)?;

    let base = config::load(&app)
        .server_url
        .ok_or_else(|| "No server configured.".to_string())?;

    let method = reqwest::Method::from_bytes(req.method.as_bytes()).map_err(|e| e.to_string())?;
    let mut builder = reqwest::Client::new().request(method, format!("{base}/api/v1{}", req.path));

    for (name, value) in req.headers {
        builder = builder.header(name, value);
    }
    if let Some(body) = req.body {
        builder = builder.body(body);
    }

    // Transport failures return Err, which the SPA's transport shim turns into the same
    // `network_unreachable` ApiError the browser produces — so every offline screen the
    // register already has keeps working unchanged.
    let response = builder.send().await.map_err(|e| e.to_string())?;
    let status = response.status().as_u16();
    let body = response.text().await.map_err(|e| e.to_string())?;

    Ok(ApiResponse { status, body })
}
```

- [ ] **Step 9: Register the modules and commands**

Replace `frontend/native/src-tauri/src/main.rs` with:

```rust
// Prevents an extra console window on Windows in release.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod api;
mod config;

fn main() {
    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![
            config::get_config,
            config::set_server_url,
            config::check_server,
            api::api_request,
        ])
        .run(tauri::generate_context!())
        .expect("error while running the POS register shell");
}
```

- [ ] **Step 10: Run everything**

Run: `cd frontend/native/src-tauri && cargo test`
Expected: PASS, 10 tests.

Run: `cd frontend/native/src-tauri && cargo fmt --check && cargo clippy -- -D warnings`
Expected: clean.

- [ ] **Step 11: Commit**

```bash
git add frontend/native/src-tauri
git commit -m "Shell: server-url config and the api_request detour"
```

---

### Task 5: First-run "Connect this terminal" screen

**Files:**
- Create: `frontend/web/src/lib/shell.ts`, `frontend/web/src/register/SetupScreen.tsx`, `frontend/web/src/register/SetupScreen.test.tsx`
- Modify: `frontend/web/src/register/Register.tsx`

**Interfaces:**
- Consumes: `inShell()` from `src/lib/transport.ts`; the `get_config` / `set_server_url` commands from Task 4.
- Produces: `shell.getConfig()`, `shell.setServerUrl(url)` from `src/lib/shell.ts`, used by Task 8 as well.

**Labels (new surface — these are the exact strings):** heading `Connect this terminal`, field label `Server address`, placeholder `https://pos.example.com`, button `Connect` / `Connecting…`, failure notice `Cannot reach that server. Check the address and try again.`

- [ ] **Step 1: Write the shell wrapper**

Create `frontend/web/src/lib/shell.ts`:

```ts
import { invoke } from '@tauri-apps/api/core'
import { inShell } from './transport'

/**
 * Thin wrappers over the desktop shell's commands. Every one of these is a no-op in a
 * browser tab: the register is fully usable without the shell, which is why v1 shipped
 * before it existed.
 */
export type ShellConfig = { server_url: string | null }

export async function getConfig(): Promise<ShellConfig | null> {
  if (!inShell()) return null
  return invoke<ShellConfig>('get_config')
}

export async function setServerUrl(url: string): Promise<void> {
  if (!inShell()) return
  await invoke('set_server_url', { url })
}

/**
 * Probes a candidate address through Rust. Deliberately NOT a webview `fetch`: that would
 * be cross-origin from `tauri://localhost` and die on CORS, which is the whole reason API
 * traffic detours through Rust in the first place.
 */
export async function checkServer(url: string): Promise<boolean> {
  if (!inShell()) return false
  return invoke<boolean>('check_server', { url })
}
```

- [ ] **Step 2: Write the failing test**

Create `frontend/web/src/register/SetupScreen.test.tsx`:

```tsx
// @vitest-environment jsdom
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SetupScreen } from './SetupScreen'

describe('SetupScreen', () => {
  it('saves a valid address and reports success', async () => {
    const onConnected = vi.fn()
    const save = vi.fn(async () => {})
    const check = vi.fn(async () => true)

    render(<SetupScreen onConnected={onConnected} save={save} check={check} />)

    fireEvent.change(screen.getByLabelText('Server address'), {
      target: { value: 'https://pos.example.com' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Connect' }))

    await waitFor(() => expect(onConnected).toHaveBeenCalled())
    expect(save).toHaveBeenCalledWith('https://pos.example.com')
  })

  it('does not save an address it cannot reach', async () => {
    const onConnected = vi.fn()
    const save = vi.fn(async () => {})
    const check = vi.fn(async () => false)

    render(<SetupScreen onConnected={onConnected} save={save} check={check} />)

    fireEvent.change(screen.getByLabelText('Server address'), {
      target: { value: 'https://typo.example.com' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Connect' }))

    expect(await screen.findByText('Cannot reach that server. Check the address and try again.')).toBeTruthy()
    expect(save).not.toHaveBeenCalled()
    expect(onConnected).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 3: Run and watch it fail**

Run: `cd frontend/web && npm test -- SetupScreen`
Expected: FAIL — cannot resolve `./SetupScreen`.

- [ ] **Step 4: Build the screen**

Create `frontend/web/src/register/SetupScreen.tsx`:

```tsx
'use client'

import { useState } from 'react'
import { Button } from '../components/ui/button'
import { Card, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'

/**
 * Shown once, before enrolment, and only in the desktop shell: a bundled app has no
 * implicit origin, so it must be told which server to talk to. `check` and `save` are
 * injected so this is testable without Tauri.
 */
export function SetupScreen({
  onConnected,
  save,
  check,
}: {
  onConnected: () => void
  save: (url: string) => Promise<void>
  check: (url: string) => Promise<boolean>
}) {
  const [url, setUrl] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  return (
    <main className="flex min-h-dvh items-center justify-center bg-canvas p-lg text-ink">
      <Card className="w-full max-w-[28rem] p-lg">
        <CardTitle>Connect this terminal</CardTitle>
        <p className="type-body-sm mt-sm text-ink-muted">
          The address of the POS server this till talks to.
        </p>
        <form
          className="mt-lg flex flex-col gap-md"
          onSubmit={async (e) => {
            e.preventDefault()
            if (busy || url.trim() === '') return
            setBusy(true)
            setError(null)
            // Validate before saving: a typo caught here is a typo not discovered at the
            // first sale of the morning.
            const reachable = await check(url.trim())
            if (!reachable) {
              setError('Cannot reach that server. Check the address and try again.')
              setBusy(false)
              return
            }
            await save(url.trim())
            setBusy(false)
            onConnected()
          }}
        >
          <label className="type-body-sm flex flex-col gap-xs">
            Server address
            <Input
              autoFocus
              className="min-h-[48px]"
              placeholder="https://pos.example.com"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
            />
          </label>
          {error && <p className="type-body-sm text-error">{error}</p>}
          <div>
            <Button type="submit" size="lg" disabled={busy}>
              {busy ? 'Connecting…' : 'Connect'}
            </Button>
          </div>
        </form>
      </Card>
    </main>
  )
}
```

- [ ] **Step 5: Run the test**

Run: `cd frontend/web && npm test -- SetupScreen`
Expected: PASS, 2 tests.

- [ ] **Step 6: Gate the app on configuration**

In `frontend/web/src/register/Register.tsx`, add to the imports:

```tsx
import { useEffect, useState } from 'react'
import { inShell } from '../lib/transport'
import { checkServer, getConfig, setServerUrl } from '../lib/shell'
import { SetupScreen } from './SetupScreen'
```

(`useEffect`/`useState` are already imported — merge rather than duplicate.)

Then, immediately after the existing `const [foodMode, setFoodMode] = useState(false)` declaration, add:

```tsx
  // In the shell, nothing can be fetched until we know which server to ask. `null` means
  // "still checking", which must not flash the setup screen at a configured till.
  const [configured, setConfigured] = useState<boolean | null>(inShell() ? null : true)

  useEffect(() => {
    if (!inShell()) return
    void getConfig().then((config) => setConfigured(config?.server_url != null))
  }, [])
```

And as the first statement of the component's `return`, before the existing `<main>`:

```tsx
  if (configured === null) return null
  if (!configured) {
    return (
      <SetupScreen onConnected={() => setConfigured(true)} save={setServerUrl} check={checkServer} />
    )
  }
```

- [ ] **Step 7: Run the full suite**

Run: `cd frontend/web && npm test && npm run typecheck && npm run build`
Expected: PASS — 98 tests, clean typecheck, successful build. The 92 pre-existing tests remain unmodified.

- [ ] **Step 8: Commit**

```bash
git add frontend/web/src/lib/shell.ts frontend/web/src/register/SetupScreen.tsx \
        frontend/web/src/register/SetupScreen.test.tsx frontend/web/src/register/Register.tsx
git commit -m "Register: first-run server-address setup in the shell"
```

---

### Task 6: ESC/POS encoder

**Files:**
- Create: `frontend/native/src-tauri/src/hardware/mod.rs`, `frontend/native/src-tauri/src/hardware/escpos.rs`
- Modify: `frontend/native/src-tauri/src/main.rs`

**Interfaces:**
- Produces: `escpos::encode(receipt: &Receipt, currency: &str) -> Vec<u8>`, `escpos::money(cents: i64) -> String`, `escpos::DRAWER_KICK: [u8; 5]`, and the `Receipt` deserialization types. Task 7 consumes all of these.

The `Receipt` shape mirrors `backend/app/Http/Resources/ReceiptResource.php` exactly. Only the fields we print are modelled; `serde` ignores the rest.

- [ ] **Step 1: Write the failing tests**

Create `frontend/native/src-tauri/src/hardware/escpos.rs` with only this test module:

```rust
#[cfg(test)]
mod tests {
    use super::*;

    fn sample() -> Receipt {
        serde_json::from_str(
            r#"{
              "business": { "name": "Dev Trading Co" },
              "location": { "name": "Downtown", "header": "Thanks!", "footer": "See you soon" },
              "order": { "number": "N-0001", "business_date": "2026-07-20", "cashier": "Alice" },
              "lines": [
                { "name": "Flat white", "qty": "1.000", "line_total_cents": 450,
                  "modifiers": [{ "name": "Oat milk", "price_delta_cents": 50 }] },
                { "name": "Croissant", "qty": "2.000", "line_total_cents": 700, "modifiers": [] }
              ],
              "totals": { "subtotal_cents": 1150, "discount_cents": 0, "tax_cents": 115, "total_cents": 1265 }
            }"#,
        )
        .unwrap()
    }

    #[test]
    fn formats_cents_without_floats() {
        assert_eq!(money(0), "0.00");
        assert_eq!(money(5), "0.05");
        assert_eq!(money(1265), "12.65");
        assert_eq!(money(-250), "-2.50");
        assert_eq!(money(100_000_000), "1000000.00");
    }

    #[test]
    fn pads_a_row_to_the_paper_width_with_the_amount_right_aligned() {
        assert_eq!(row("Coffee", "4.50", 20), "Coffee          4.50");
    }

    #[test]
    fn truncates_a_name_too_long_for_the_paper_rather_than_wrapping_the_amount() {
        let line = row("A very long product name indeed", "4.50", 20);
        assert_eq!(line.chars().count(), 20);
        assert!(line.ends_with("4.50"));
    }

    #[test]
    fn starts_with_the_initialise_command_and_ends_with_a_cut() {
        let bytes = encode(&sample(), "USD");
        assert!(bytes.starts_with(&[0x1B, 0x40]));
        assert!(bytes.ends_with(&[0x1D, 0x56, 0x00]));
    }

    #[test]
    fn prints_every_line_its_modifiers_and_the_total() {
        let text = String::from_utf8_lossy(&encode(&sample(), "USD")).to_string();
        assert!(text.contains("Flat white"));
        assert!(text.contains("Oat milk"));
        assert!(text.contains("Croissant"));
        assert!(text.contains("N-0001"));
        assert!(text.contains("Dev Trading Co"));
        assert!(text.contains("12.65"));
    }

    #[test]
    fn shows_a_quantity_only_when_it_is_not_exactly_one() {
        let text = String::from_utf8_lossy(&encode(&sample(), "USD")).to_string();
        assert!(text.contains("2 x Croissant"));
        assert!(!text.contains("1 x Flat white"));
    }

    #[test]
    fn omits_a_zero_discount_row_but_prints_a_real_one() {
        let text = String::from_utf8_lossy(&encode(&sample(), "USD")).to_string();
        assert!(!text.contains("Discount"));

        let mut discounted = sample();
        discounted.totals.discount_cents = 200;
        let text = String::from_utf8_lossy(&encode(&discounted, "USD")).to_string();
        assert!(text.contains("Discount"));
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `cd frontend/native/src-tauri && cargo test`
Expected: FAIL — `Receipt`, `money`, `row`, and `encode` are not defined.

- [ ] **Step 3: Implement the encoder**

Prepend to `frontend/native/src-tauri/src/hardware/escpos.rs`:

```rust
use serde::Deserialize;

/// Mirrors backend/app/Http/Resources/ReceiptResource.php. Only the fields we put on
/// paper are modelled; serde ignores the rest, so the server can add fields freely.
///
/// Money is i64 cents, never a float — same rule as every other layer.
#[derive(Debug, Clone, Deserialize)]
pub struct Receipt {
    pub business: Business,
    pub location: LocationInfo,
    pub order: OrderInfo,
    pub lines: Vec<Line>,
    pub totals: Totals,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Business {
    pub name: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct LocationInfo {
    pub name: String,
    #[serde(default)]
    pub header: Option<String>,
    #[serde(default)]
    pub footer: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct OrderInfo {
    pub number: String,
    pub business_date: String,
    #[serde(default)]
    pub cashier: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Line {
    pub name: String,
    /// Quantity is a decimal STRING on the wire — numeric(12,3) does not survive
    /// IEEE-754. We only ever compare and print it, never do arithmetic on it.
    pub qty: String,
    pub line_total_cents: i64,
    #[serde(default)]
    pub modifiers: Vec<Modifier>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Modifier {
    pub name: String,
    #[serde(default)]
    pub price_delta_cents: i64,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Totals {
    pub subtotal_cents: i64,
    #[serde(default)]
    pub discount_cents: i64,
    pub tax_cents: i64,
    pub total_cents: i64,
}

/// 80mm paper at font A. 58mm paper is 32; a printer setting can select it later.
const WIDTH: usize = 42;

const INIT: [u8; 2] = [0x1B, 0x40];
const CUT: [u8; 3] = [0x1D, 0x56, 0x00];
const ALIGN_CENTRE: [u8; 3] = [0x1B, 0x61, 0x01];
const ALIGN_LEFT: [u8; 3] = [0x1B, 0x61, 0x00];

/// ESC p 0 — pulse pin 2. The drawer is kicked BY the printer; it has no computer in it.
pub const DRAWER_KICK: [u8; 5] = [0x1B, 0x70, 0x00, 0x19, 0xFA];

/// Integer-only cents formatting. A float here would eventually print the wrong total.
pub fn money(cents: i64) -> String {
    let sign = if cents < 0 { "-" } else { "" };
    let absolute = cents.abs();
    format!("{sign}{}.{:02}", absolute / 100, absolute % 100)
}

/// One line of the receipt: label left, amount hard right. The label is truncated rather
/// than wrapped, because an amount pushed onto its own line reads as a different total.
pub fn row(label: &str, amount: &str, width: usize) -> String {
    let amount_len = amount.chars().count();
    if amount_len >= width {
        return amount.chars().take(width).collect();
    }
    let room = width - amount_len;
    let label: String = label.chars().take(room).collect();
    format!("{label}{}{amount}", " ".repeat(room - label.chars().count()))
}

fn centred(text: &str, out: &mut Vec<u8>) {
    out.extend_from_slice(&ALIGN_CENTRE);
    out.extend_from_slice(text.as_bytes());
    out.push(b'\n');
    out.extend_from_slice(&ALIGN_LEFT);
}

fn line(text: &str, out: &mut Vec<u8>) {
    out.extend_from_slice(text.as_bytes());
    out.push(b'\n');
}

/// Server-provided JSON in, printer bytes out. This function decides NOTHING about what
/// the receipt says — that is the server's job, from snapshot columns, so a reprint next
/// year is identical. See docs/01-architecture.md.
pub fn encode(receipt: &Receipt, currency: &str) -> Vec<u8> {
    let mut out = Vec::new();
    out.extend_from_slice(&INIT);

    centred(&receipt.business.name, &mut out);
    centred(&receipt.location.name, &mut out);
    if let Some(header) = receipt.location.header.as_deref().filter(|h| !h.is_empty()) {
        centred(header, &mut out);
    }
    line("", &mut out);

    line(&format!("Order {}", receipt.order.number), &mut out);
    line(&receipt.order.business_date, &mut out);
    if let Some(cashier) = receipt.order.cashier.as_deref() {
        line(&format!("Served by {cashier}"), &mut out);
    }
    line(&"-".repeat(WIDTH), &mut out);

    for item in &receipt.lines {
        // "1.000" is the overwhelmingly common case and a leading "1 x" is just noise.
        let label = if item.qty == "1.000" {
            item.name.clone()
        } else {
            format!("{} x {}", item.qty.trim_end_matches('0').trim_end_matches('.'), item.name)
        };
        line(&row(&label, &money(item.line_total_cents), WIDTH), &mut out);

        for modifier in &item.modifiers {
            let delta = if modifier.price_delta_cents == 0 {
                String::new()
            } else {
                money(modifier.price_delta_cents)
            };
            line(&row(&format!("  {}", modifier.name), &delta, WIDTH), &mut out);
        }
    }

    line(&"-".repeat(WIDTH), &mut out);
    line(&row("Subtotal", &money(receipt.totals.subtotal_cents), WIDTH), &mut out);
    if receipt.totals.discount_cents != 0 {
        line(&row("Discount", &money(-receipt.totals.discount_cents), WIDTH), &mut out);
    }
    line(&row("Tax", &money(receipt.totals.tax_cents), WIDTH), &mut out);
    line(&row(&format!("Total ({currency})"), &money(receipt.totals.total_cents), WIDTH), &mut out);

    if let Some(footer) = receipt.location.footer.as_deref().filter(|f| !f.is_empty()) {
        line("", &mut out);
        centred(footer, &mut out);
    }

    // Feed clear of the cutter before cutting, or the last line is sliced.
    line("", &mut out);
    line("", &mut out);
    line("", &mut out);
    out.extend_from_slice(&CUT);
    out
}
```

- [ ] **Step 4: Create the module file**

Create `frontend/native/src-tauri/src/hardware/mod.rs`:

```rust
pub mod escpos;
```

Add to `frontend/native/src-tauri/src/main.rs`, below the existing `mod` lines:

```rust
mod hardware;
```

- [ ] **Step 5: Run the tests**

Run: `cd frontend/native/src-tauri && cargo test`
Expected: PASS, 17 tests.

Run: `cd frontend/native/src-tauri && cargo fmt --check && cargo clippy -- -D warnings`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add frontend/native/src-tauri/src
git commit -m "Shell: ESC/POS encoder — server JSON to printer bytes"
```

---

### Task 7: Printer trait, mock driver, and the hardware commands

**Files:**
- Create: `frontend/native/src-tauri/src/hardware/driver.rs`, `frontend/native/src-tauri/src/hardware/mock.rs`
- Modify: `frontend/native/src-tauri/src/hardware/mod.rs`, `frontend/native/src-tauri/src/main.rs`, `frontend/native/src-tauri/src/config.rs`

**Interfaces:**
- Consumes: `escpos::{encode, Receipt, DRAWER_KICK}` from Task 6.
- Produces two Tauri commands consumed by Task 8:
  - `print_receipt(receipt: Receipt, currency: String) -> Result<(), String>`
  - `open_drawer() -> Result<(), String>` — **no authorization argument by design** (see the spec: the webview and shell are one trust domain, so authority lives on the server).

- [ ] **Step 1: Write the failing test**

Create `frontend/native/src-tauri/src/hardware/mock.rs` with only this test module:

```rust
#[cfg(test)]
mod tests {
    use super::*;
    use crate::hardware::driver::Printer;

    #[test]
    fn writes_the_bytes_it_was_given_to_a_file() {
        let dir = std::env::temp_dir().join(format!("pos-mock-{}", std::process::id()));
        let printer = MockPrinter::new(dir.clone());

        printer.write(b"hello printer").unwrap();

        let written: Vec<_> = std::fs::read_dir(&dir).unwrap().filter_map(Result::ok).collect();
        assert_eq!(written.len(), 1);
        assert_eq!(std::fs::read(written[0].path()).unwrap(), b"hello printer");

        std::fs::remove_dir_all(dir).ok();
    }

    #[test]
    fn creates_its_directory_on_first_use() {
        let dir = std::env::temp_dir().join(format!("pos-mock-new-{}", std::process::id()));
        std::fs::remove_dir_all(&dir).ok();

        MockPrinter::new(dir.clone()).write(b"x").unwrap();

        assert!(dir.exists());
        std::fs::remove_dir_all(dir).ok();
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `cd frontend/native/src-tauri && cargo test`
Expected: FAIL — `MockPrinter` and `Printer` are not defined.

- [ ] **Step 3: Write the trait**

Create `frontend/native/src-tauri/src/hardware/driver.rs`:

```rust
/// Everything a till needs from a printer. The drawer is not a second device: it is
/// kicked by the printer over RJ11, so a drawer pulse is just more bytes.
///
/// Only MockPrinter implements this today. The first real driver will be network (raw
/// TCP 9100), then USB and serial — each is an impl of this trait and nothing else has
/// to change.
pub trait Printer: Send + Sync {
    fn write(&self, bytes: &[u8]) -> Result<(), String>;
}
```

- [ ] **Step 4: Write the mock driver**

Prepend to `frontend/native/src-tauri/src/hardware/mock.rs`:

```rust
use crate::hardware::driver::Printer;
use std::path::PathBuf;
use std::time::{SystemTime, UNIX_EPOCH};

/// Writes the exact bytes it would have sent to a file, so the whole hardware path is
/// reviewable with no printer in the building. Groundwork, per the spec.
pub struct MockPrinter {
    dir: PathBuf,
}

impl MockPrinter {
    pub fn new(dir: PathBuf) -> Self {
        Self { dir }
    }
}

impl Printer for MockPrinter {
    fn write(&self, bytes: &[u8]) -> Result<(), String> {
        std::fs::create_dir_all(&self.dir).map_err(|e| e.to_string())?;

        let stamp = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map_err(|e| e.to_string())?
            .as_nanos();

        std::fs::write(self.dir.join(format!("{stamp}.bin")), bytes).map_err(|e| e.to_string())
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `cd frontend/native/src-tauri && cargo test`
Expected: PASS, 19 tests.

- [ ] **Step 6: Wire up the commands**

Replace `frontend/native/src-tauri/src/hardware/mod.rs` with:

```rust
pub mod driver;
pub mod escpos;
pub mod mock;

use driver::Printer;
use tauri::Manager;

/// Resolves the configured driver. Only "mock" exists today; a real driver is selected
/// here by config once one is written.
fn printer(app: &tauri::AppHandle) -> Result<Box<dyn Printer>, String> {
    let dir = app
        .path()
        .app_data_dir()
        .map_err(|e| e.to_string())?
        .join("print-jobs");

    Ok(Box::new(mock::MockPrinter::new(dir)))
}

#[tauri::command]
pub fn print_receipt(
    app: tauri::AppHandle,
    receipt: escpos::Receipt,
    currency: String,
) -> Result<(), String> {
    printer(&app)?.write(&escpos::encode(&receipt, &currency))
}

/// No authorization argument, deliberately. A token passed from JS to Rust would be
/// theatre: whatever could forge this call could forge the token too. Authority lives
/// where it can be audited — the SPA asks the server first, and the server writes the
/// audit row whether or not a drawer physically opens. See docs/05-rbac.md.
#[tauri::command]
pub fn open_drawer(app: tauri::AppHandle) -> Result<(), String> {
    printer(&app)?.write(&escpos::DRAWER_KICK)
}
```

Add the two commands to the handler list in `frontend/native/src-tauri/src/main.rs`:

```rust
        .invoke_handler(tauri::generate_handler![
            config::get_config,
            config::set_server_url,
            api::api_request,
            hardware::print_receipt,
            hardware::open_drawer,
        ])
```

- [ ] **Step 7: Verify**

Run: `cd frontend/native/src-tauri && cargo test && cargo fmt --check && cargo clippy -- -D warnings`
Expected: PASS, 19 tests, clean lint.

- [ ] **Step 8: Commit**

```bash
git add frontend/native/src-tauri/src
git commit -m "Shell: printer trait, mock driver, print and drawer commands"
```

---

### Task 8: Register wiring — print through the shell, and the No sale button

**Files:**
- Modify: `frontend/web/src/lib/shell.ts`, `frontend/web/src/register/SaleScreen.tsx` (the two `window.print()` sites, lines ~468 and ~772), `frontend/web/src/register/Register.tsx` (top bar), `frontend/web/src/lib/api.ts` (add the no-sale call)
- Create: `frontend/web/src/register/NoSaleButton.tsx`, `frontend/web/src/register/NoSaleButton.test.tsx`

**Interfaces:**
- Consumes: `print_receipt` / `open_drawer` (Task 7), `POST /api/v1/drawer/no-sale` (Task 1), `inShell()` (Task 2).

**Labels (new surface):** button `No sale`, prompt `Reason for opening the drawer…`, confirm `Open drawer`, cancel `Cancel`, failure notice `Could not open the drawer.`

**Frozen:** the existing `Print` label and its browser behaviour do not change. In a browser, `Print` still calls `window.print()`; only inside the shell does it route to the printer.

- [ ] **Step 1: Extend the shell wrapper**

Append to `frontend/web/src/lib/shell.ts`:

```ts
import type { Receipt } from './api'

/** True when the shell can actually drive hardware — a browser tab never can. */
export const hasHardware = inShell

export async function printReceipt(receipt: Receipt, currency: string): Promise<void> {
  await invoke('print_receipt', { receipt, currency })
}

export async function openDrawer(): Promise<void> {
  await invoke('open_drawer')
}
```

- [ ] **Step 2: Add the API call**

In `frontend/web/src/lib/api.ts`, add to the exported `api` object next to `receipt`:

```ts
  drawerNoSale: (reason: string) =>
    request<{ authorized: boolean; shift_id: string }>('/drawer/no-sale', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reason }),
    }),
```

- [ ] **Step 3: Write the failing test**

Create `frontend/web/src/register/NoSaleButton.test.tsx`:

```tsx
// @vitest-environment jsdom
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { NoSaleButton } from './NoSaleButton'

describe('NoSaleButton', () => {
  it('asks the server first, and only then pulses the drawer', async () => {
    const authorize = vi.fn(async () => {})
    const pulse = vi.fn(async () => {})

    render(<NoSaleButton authorize={authorize} pulse={pulse} />)
    fireEvent.click(screen.getByRole('button', { name: 'No sale' }))
    fireEvent.change(screen.getByPlaceholderText('Reason for opening the drawer…'), {
      target: { value: 'Change for a twenty' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Open drawer' }))

    await waitFor(() => expect(pulse).toHaveBeenCalled())
    expect(authorize).toHaveBeenCalledWith('Change for a twenty')
  })

  it('does not pulse when the server refuses', async () => {
    const authorize = vi.fn(async () => {
      throw new Error('denied')
    })
    const pulse = vi.fn(async () => {})

    render(<NoSaleButton authorize={authorize} pulse={pulse} />)
    fireEvent.click(screen.getByRole('button', { name: 'No sale' }))
    fireEvent.change(screen.getByPlaceholderText('Reason for opening the drawer…'), {
      target: { value: 'Nope' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Open drawer' }))

    expect(await screen.findByText('Could not open the drawer.')).toBeTruthy()
    expect(pulse).not.toHaveBeenCalled()
  })

  it('will not submit an empty reason', () => {
    const authorize = vi.fn(async () => {})
    const pulse = vi.fn(async () => {})

    render(<NoSaleButton authorize={authorize} pulse={pulse} />)
    fireEvent.click(screen.getByRole('button', { name: 'No sale' }))
    fireEvent.click(screen.getByRole('button', { name: 'Open drawer' }))

    expect(authorize).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 4: Run and watch it fail**

Run: `cd frontend/web && npm test -- NoSaleButton`
Expected: FAIL — cannot resolve `./NoSaleButton`.

- [ ] **Step 5: Build the button**

Create `frontend/web/src/register/NoSaleButton.tsx`:

```tsx
'use client'

import { useState } from 'react'
import { Button } from '../components/ui/button'
import { Input } from '../components/ui/input'

/**
 * Opening the drawer with no sale attached. The server authorizes and audits it; this
 * component only asks. `authorize` and `pulse` are injected so it tests without Tauri.
 */
export function NoSaleButton({
  authorize,
  pulse,
}: {
  authorize: (reason: string) => Promise<void>
  pulse: () => Promise<void>
}) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  if (!open) {
    return (
      <Button type="button" variant="ghost" className="self-stretch" onClick={() => setOpen(true)}>
        No sale
      </Button>
    )
  }

  return (
    <form
      className="flex items-center gap-sm"
      onSubmit={async (e) => {
        e.preventDefault()
        if (reason.trim() === '') return
        setError(null)
        try {
          // Server first, always: a drawer that opened before the audit row existed is
          // exactly the hole this endpoint closes.
          await authorize(reason.trim())
        } catch {
          setError('Could not open the drawer.')
          return
        }
        await pulse()
        setOpen(false)
        setReason('')
      }}
    >
      <Input
        autoFocus
        className="min-h-[48px]"
        placeholder="Reason for opening the drawer…"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
      <Button type="submit" size="lg">
        Open drawer
      </Button>
      <Button type="button" variant="ghost" size="lg" onClick={() => setOpen(false)}>
        Cancel
      </Button>
      {error && <span className="type-body-sm text-error">{error}</span>}
    </form>
  )
}
```

- [ ] **Step 6: Run the test**

Run: `cd frontend/web && npm test -- NoSaleButton`
Expected: PASS, 3 tests.

- [ ] **Step 7: Mount it in the top bar**

In `frontend/web/src/register/Register.tsx`, add to the imports:

```tsx
import { NoSaleButton } from './NoSaleButton'
import { hasHardware, openDrawer } from '../lib/shell'
import { api } from '../lib/api'
```

(Merge with existing imports rather than duplicating.)

Then, immediately after the food-mode `Tabs` toggle block and before the `{user && onShift && (` block, add:

```tsx
        {/* Shell only: in a browser there is no drawer to open, so offering the button
            would be a lie. Supervisor-gated exactly like the RBAC table says. */}
        {onShift && hasHardware() && can('drawer.no_sale') && (
          <NoSaleButton authorize={(reason) => api.drawerNoSale(reason).then(() => undefined)} pulse={openDrawer} />
        )}
```

- [ ] **Step 8: Route printing through the shell**

In `frontend/web/src/register/SaleScreen.tsx`, add to the imports:

```tsx
import { hasHardware, printReceipt } from '../lib/shell'
```

Add this helper immediately above the `ReceiptCard` component definition:

```tsx
/**
 * In the shell, print to the thermal printer; in a browser, the print dialog exactly as
 * before. Printing never blocks a sale — the order is already closed server-side, so a
 * jammed printer is a notice, not a rollback.
 */
async function printNow(receipt: Receipt | null, currency: string): Promise<void> {
  if (!hasHardware() || receipt === null) {
    window.print()
    return
  }
  try {
    await printReceipt(receipt, currency)
  } catch {
    window.print()
  }
}
```

Then replace both `onClick={() => window.print()}` handlers (lines ~468 and ~772) with:

```tsx
onClick={() => void printNow(receipt, CURRENCY)}
```

`CURRENCY` is the module constant `SaleScreen.tsx` already passes to every `MoneyText` (e.g. line 448) — no new value is introduced.

- [ ] **Step 9: Run the full suite**

Run: `cd frontend/web && npm test && npm run typecheck && npm run build`
Expected: PASS — 101 tests, clean typecheck, successful build. The 92 pre-existing tests remain unmodified.

- [ ] **Step 10: Commit**

```bash
git add frontend/web/src/lib/shell.ts frontend/web/src/lib/api.ts \
        frontend/web/src/register/NoSaleButton.tsx frontend/web/src/register/NoSaleButton.test.tsx \
        frontend/web/src/register/Register.tsx frontend/web/src/register/SaleScreen.tsx
git commit -m "Register: print through the shell, supervisor No sale button"
```

---

### Task 9: CI, docs, and the WebKitGTK eyeball

**Files:**
- Modify: `.github/workflows/ci.yml`, `CLAUDE.md`, `docs/01-architecture.md`, `docs/06-roadmap.md`

- [ ] **Step 1: Add the shell job to CI**

In `.github/workflows/ci.yml`, add a fourth job alongside the existing `backend`, `frontend`, and `back-office` jobs, matching their indentation and style:

```yaml
  shell:
    name: Desktop shell
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: System dependencies
        run: |
          sudo apt-get update
          sudo apt-get install -y libwebkit2gtk-4.1-dev libgtk-3-dev librsvg2-dev build-essential
      - uses: dtolnay/rust-toolchain@stable
        with:
          components: rustfmt, clippy
      - name: Format
        run: cd frontend/native/src-tauri && cargo fmt --check
      - name: Lint
        run: cd frontend/native/src-tauri && cargo clippy -- -D warnings
      - name: Test
        run: cd frontend/native/src-tauri && cargo test
```

Bundling is deliberately not built here: it needs the full toolchain and buys nothing until there is something to distribute.

- [ ] **Step 2: Prove both Next outputs build in CI**

In the `frontend:` job (named `Frontend (tsc + build)`) in `.github/workflows/ci.yml`, immediately after its existing build step, add:

```yaml
      - name: Build static export (the shell's bundle)
        run: cd frontend/web && POS_STATIC_EXPORT=1 npm run build
```

- [ ] **Step 3: Run the whole thing locally before trusting CI**

```bash
cd backend && ./vendor/bin/pest
cd ../frontend/web && npm test && npm run typecheck && npm run build && POS_STATIC_EXPORT=1 npm run build
cd ../native/src-tauri && cargo fmt --check && cargo clippy -- -D warnings && cargo test
```

Expected: backend 466, register 101, shell 19, all builds clean.

- [ ] **Step 4: Eyeball the shell in the real webview**

```bash
cd frontend/web && npm run dev          # leave running on :5174
cd frontend/native && npm run dev       # opens the Tauri window
```

The shell renders in **WebKitGTK**, not Chrome, and the reworked UI was only ever eyeballed in Chrome. Walk the register: setup screen → enrolment → PIN → open shift → a cash sale → Print → No sale. Check specifically for the things WebKit renders differently from Chromium: flex/grid gaps, `min-h-dvh`, focus outlines, and the tabular-figures alignment on money. Record what you saw in the task report, and fix any layout break in `frontend/web` — such a fix is shared with the browser app, so re-run the register suite after.

Confirm the mock driver produced files:

```bash
ls ~/.local/share/test.pos.register/print-jobs/
```

Expected: one `.bin` per print and per drawer kick. Inspect one with `xxd` — it should begin `1b 40` and end `1d 56 00`.

- [ ] **Step 5: Update the docs**

In `CLAUDE.md`, replace the `frontend/native/` line in the Layout section with:

```
frontend/native/      Tauri v2 desktop shell — hosts the register SPA and adds thermal
                      printer + cash drawer. Mock driver only; see its README.
```

In `docs/01-architecture.md`, in the `frontend/native/` section, replace "and is empty in v1" with "and now exists" and append a paragraph after the hardware bullet list:

```markdown
Built as of the shell milestone: the SPA is bundled as a static export, API traffic
detours through a Rust `api_request` command (no CORS, and the server address lives in
Rust so the webview never names a host), and receipt JSON becomes ESC/POS bytes in a pure
Rust function. `POST /api/v1/drawer/no-sale` finally gives `drawer.no_sale` a door: the
server authorizes and audits, the shell only pulses. Only the mock driver ships — it
writes the exact bytes to disk — because no printer has been bought yet.
```

In `docs/06-roadmap.md`, in the deferred table, replace the `Desktop shell (frontend/native/)` row with:

```markdown
| Real printer drivers (network / USB / serial) | A printer physically exists. The shell, the `Printer` trait, and the ESC/POS encoder shipped; a driver is the small remaining part. |
```

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/ci.yml CLAUDE.md docs/01-architecture.md docs/06-roadmap.md
git commit -m "Shell: CI, docs, and the record of what shipped"
```

---

## Plan self-review (performed at write time)

- **Spec coverage:** hosting via bundled export + Rust detour (Tasks 2, 3, 4); the one SPA change with error-shape preservation (Task 2); dual build (Tasks 2, 9); hardware seam with the pure encoder and mock driver (Tasks 6, 7); browser printing preserved (Task 8); no-sale endpoint with mandatory reason, shift binding, and audit row (Task 1) plus its UI (Task 8); first-run setup with health validation (Task 5); testing and CI (Tasks 1–9, consolidated in 9); WebKitGTK risk (Task 9 Step 4); the ambiguous `next.config.ts` working-tree change (Task 2, called out explicitly). Every spec section maps to at least one task.
- **Placeholder scan:** no TBDs. Every code step carries complete code; every run step carries an exact command and expected result. Test counts are stated as concrete numbers (466 backend, 101 register, 19 shell) so a drift is visible rather than hand-waved.
- **Type consistency:** `send()` returns `{ status, body }` in Task 2 and is consumed with those exact field names in `request()`. `ShellConfig.server_url` (Task 5) matches Rust's `Config { server_url }` (Task 4) through serde's default snake_case. `api_request`'s argument is named `req` in both the TS `invoke` call (Task 2) and the Rust signature (Task 4). `Receipt` is the TS type in Task 8 and the serde struct in Task 6, both mirroring `ReceiptResource`. `Printer::write` is defined in Task 7 Step 3 and used in Steps 4 and 6 with the same signature. `hasHardware`/`inShell` are one function exported under two names, deliberately, so call sites read as intent.
- **YAGNI check:** no real drivers, no updater, no signing, no offline, no plugin dependencies (reqwest and serde only). The `Printer` trait has exactly one method because that is all a drawer pulse and a receipt need.
