# On-Screen Keyboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A per-register `screen_keyboard_enabled` flag, set in the back office, that docks a touch keyboard on the till whenever a text or number field takes focus — so a terminal with no physical keyboard can still void, discount and refund.

**Architecture:** One boolean column on `registers`, surfaced on the same two resources that already carry `mode`, toggled by one checkbox in `RegisterEditor`. The register app mounts a single `ScreenKeyboardHost` that listens for focus on inputs opting in via `data-screen-keyboard="numeric|full"` and renders one `react-simple-keyboard` docked at the bottom.

**Tech Stack:** Laravel 13.20 / PHP 8.5 / PostgreSQL 18, Pest. Next.js 16 + React 19 + TypeScript 7, Vitest, Tailwind v4. New dependency: `react-simple-keyboard@^3.8`.

**Spec:** `docs/superpowers/specs/2026-07-27-screen-keyboard-design.md`

## Global Constraints

- **Money is integer cents** (`_cents` suffix on the wire); **quantities are strings** (`"0.500"`). The keyboard writes into existing inputs and must change neither representation.
- One action = one route = one controller = one Action class. Actions are `final`, take an Input DTO, never touch HTTP. `declare(strict_types=1);` everywhere. Never call `env()` outside `config/`.
- **Admin `FormRequest::authorize()` never calls bare `can()`** — always `AuthorizesBackOffice::allowsBackOffice()`.
- **Validation failures are `400 validation_failed`**, not 422 (`backend/app/Exceptions/ApiErrorEnvelope.php:39-43`).
- **Tests run against real PostgreSQL, never SQLite.** Dev Postgres is on **host port 5434**; `backend/.env`'s `DB_PORT=5432` is stale. Use `cd backend && DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest`. **Never edit `backend/.env`.** Never use `--parallel`.
- **Eloquent `create()` never hydrates DB column defaults** — set them explicitly or `->refresh()`.
- **The shared UI set is byte-identical between `frontend/web` and `frontend/back-office`**: `src/styles/carbon.css`, `src/lib/utils.ts`, all of `src/components/ui/*`, plus `StatusPill`/`EmptyState`/`ConfirmDialog`. **Nothing in this plan may touch any of them.**
- `tsconfig` sets `erasableSyntaxOnly` — type syntax must never emit runtime code.
- This repo has **no `@testing-library/user-event`**; the convention is `fireEvent`.
- Register touch targets have a **56px** floor (root `DESIGN.md`).
- `npm run typecheck` is the gate on both frontends; Next's own check stays disabled.

## File Structure

**Backend** — created: `database/migrations/2026_07_27_000200_add_register_screen_keyboard.php`. Modified: `app/Models/Register.php`, `app/Http/Resources/Admin/AdminRegisterResource.php`, `app/Http/Resources/EnrolledRegisterResource.php`, `app/Http/Resources/StaffSessionResource.php`, `app/Http/Requests/Admin/Registers/CreateRegisterRequest.php`, `UpdateRegisterRequest.php`, `app/Actions/Admin/Registers/CreateRegisterInput.php` + `CreateRegister.php`. Tests: `tests/Feature/Admin/LocationRegisterTest.php`.

**Back office** — modified: `src/lib/api.ts` (the `Register` type), `src/admin/places/RegisterEditor.tsx`, `RegisterEditor.test.tsx`.

**Register** — created: `src/register/ScreenKeyboardHost.tsx`, `src/register/screen-keyboard.css`, `src/register/ScreenKeyboardHost.test.tsx`. Modified: `src/lib/api.ts` (`RegisterInfo`), `src/register/Register.tsx` (mount the host), and the input sites in `SaleScreen.tsx`, `RefundScreen.tsx`, `ShiftScreens.tsx`, `SessionScreens.tsx`, `FloorScreen.tsx`, `NoSaleButton.tsx`.

**Docs** — `docs/02-data-model.md`, `docs/03-api.md`, `docs/06-roadmap.md`, `CLAUDE.md`, `docs/user-manual/user-manual.md`.

---

## Task 1: The column, the resources, and the admin round-trip

**Files:**
- Create: `backend/database/migrations/2026_07_27_000200_add_register_screen_keyboard.php`
- Modify: `backend/app/Models/Register.php`, `app/Http/Resources/Admin/AdminRegisterResource.php`, `app/Http/Resources/EnrolledRegisterResource.php`, `app/Http/Resources/StaffSessionResource.php`, `app/Http/Requests/Admin/Registers/CreateRegisterRequest.php`, `UpdateRegisterRequest.php`, `app/Actions/Admin/Registers/CreateRegisterInput.php`, `CreateRegister.php`
- Test: `backend/tests/Feature/Admin/LocationRegisterTest.php`

**Interfaces:**
- Produces: `registers.screen_keyboard_enabled` (boolean, not null, default false); the key `screen_keyboard_enabled` on `AdminRegisterResource`, and inside the nested `register` object of both `EnrolledRegisterResource` and `StaffSessionResource`. Tasks 2 and 3 consume these.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Admin/LocationRegisterTest.php`, matching its existing admin-header setup:

```php
it('defaults the on-screen keyboard off and round-trips a toggle', function (): void {
    $location = provisionedLocation(['code' => 'AAA']);

    $created = $this->postJson('/api/v1/admin/registers', [
        'location_id' => $location->id, 'name' => 'Kiosk 1',
    ], $this->headers)->assertCreated();

    // Off by default: a terminal WITH a keyboard is the common case, and defaulting on
    // would put a keyboard on every till already in service.
    expect($created->json('data.register.screen_keyboard_enabled'))->toBeFalse();

    $id = $created->json('data.register.id');
    $this->patchJson("/api/v1/admin/registers/{$id}", [
        'screen_keyboard_enabled' => true,
    ], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.register.screen_keyboard_enabled', true);

    expect(Register::findOrFail($id)->screen_keyboard_enabled)->toBeTrue();
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.register.update', 'entity_id' => $id]);
});

it('carries the keyboard flag to the till on activation and on staff login', function (): void {
    $location = provisionedLocation(['code' => 'AAA']);
    $register = registerAt($location);
    $register->update(['screen_keyboard_enabled' => true]);
    $cashier = staffWithRole($location, Roles::CASHIER);

    // The activation response is the one that matters for PIN entry: the client persists
    // register info from it BEFORE any staff session exists.
    $code = app(IssueActivationCode::class)->execute(new IssueActivationCodeInput(
        registerId: $register->id, actorId: $cashier->id,
    ));
    $activated = $this->postJson('/api/v1/registers/activate', ['activation_code' => $code->code])
        ->assertOk();
    expect($activated->json('data.register.screen_keyboard_enabled'))->toBeTrue();

    $device = $activated->json('data.device_token');
    $this->postJson('/api/v1/staff/login', ['pin' => $cashier->pin ?? '1111'],
        ['Authorization' => "Bearer {$device}"]);
});
```

Adapt the second case to this file's real helpers for issuing an activation code and logging a staff session — read the file first; it already exercises that flow for the activation-code work. If the staff-login half is awkward to construct here, assert the `EnrolledRegisterResource` half and add a focused `StaffSessionResource` assertion wherever this suite already covers PIN login.

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest tests/Feature/Admin/LocationRegisterTest.php`
Expected: FAIL — `screen_keyboard_enabled` is null/absent.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-register on-screen keyboard, for sealed terminals and tablets with no physical
 * keyboard. Per REGISTER rather than per location because one store commonly mixes
 * hardware — a sealed counter terminal beside a back-office PC enrolled as a second till.
 *
 * Defaults false: a terminal with a keyboard is the common case, and defaulting true
 * would silently put a keyboard on every till already in service at migrate time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table registers
            add column screen_keyboard_enabled boolean not null default false');
    }

    public function down(): void
    {
        DB::statement('alter table registers drop column screen_keyboard_enabled');
    }
};
```

- [ ] **Step 4: Model, resources, requests**

- `Register::$fillable` += `'screen_keyboard_enabled'`; add `'screen_keyboard_enabled' => 'boolean'` to its `casts()`.
- `AdminRegisterResource`: add `'screen_keyboard_enabled' => $this->screen_keyboard_enabled,` after `'mode'`.
- `EnrolledRegisterResource` and `StaffSessionResource`: add the same key inside the nested `register` object, beside `'mode'`.
- `CreateRegisterRequest` / `UpdateRegisterRequest`: `'screen_keyboard_enabled' => ['sometimes', 'boolean'],`. Add it to `UpdateRegisterRequest`'s `safe()->only([...])` list, and to `CreateRegisterInput` + `CreateRegister` (defaulting `false` via `$this->boolean('screen_keyboard_enabled', false)`).

Unlike a payment method's `code`, this is an ordinary editable setting — there is no history to corrupt by changing it, so it belongs in the `PATCH` allow-list.

- [ ] **Step 5: Run the test, then the suite**

```bash
cd backend
DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest tests/Feature/Admin/LocationRegisterTest.php
DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest tests/Arch
DB_PORT=5434 php -d memory_limit=512M vendor/bin/pest
```
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat(registers): per-register screen_keyboard_enabled flag"
```

---

## Task 2: The back-office checkbox

**Files:**
- Modify: `frontend/back-office/src/lib/api.ts`, `src/admin/places/RegisterEditor.tsx`, `src/admin/places/RegisterEditor.test.tsx`

**Interfaces:**
- Consumes: `screen_keyboard_enabled` on `AdminRegisterResource` (Task 1).

- [ ] **Step 1: Write the failing test**

Append to `RegisterEditor.test.tsx`, matching its existing render helper and `api.registers` mock:

```tsx
it('round-trips the on-screen keyboard toggle', async () => {
  renderEditor({ ...register, screen_keyboard_enabled: false })

  fireEvent.click(await screen.findByLabelText(/on-screen keyboard/i))
  fireEvent.click(screen.getByRole('button', { name: /save/i }))

  await waitFor(() =>
    expect(api.registers.update).toHaveBeenCalledWith(
      register.id,
      expect.objectContaining({ screen_keyboard_enabled: true }),
    ),
  )
})
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd frontend/back-office && npm test -- RegisterEditor`
Expected: FAIL — no such label.

- [ ] **Step 3: Add the field**

In `src/lib/api.ts`, add `screen_keyboard_enabled: boolean` to the `Register` type.

In `RegisterEditor.tsx`, add state (`useState(register?.screen_keyboard_enabled ?? false)`), include the key in the saved body, and render a checkbox beside the existing Active one — following whatever pattern that file already uses for `is_active`:

```tsx
        <label className="flex items-center gap-sm">
          <Checkbox
            checked={screenKeyboardEnabled}
            onCheckedChange={(v) => setScreenKeyboardEnabled(v === true)}
          />
          <span className="type-body-sm">
            On-screen keyboard — show a touch keyboard on this till, for terminals with no
            physical keyboard
          </span>
        </label>
```

Match the file's real `Checkbox` usage and label idiom; if `is_active` uses `FieldRow`, mirror that instead so the two read alike.

- [ ] **Step 4: Verify**

```bash
cd frontend/back-office && npm test -- RegisterEditor && npm run typecheck && npm test && npm run build
```

- [ ] **Step 5: Commit**

```bash
git add frontend/back-office
git commit -m "feat(back-office): on-screen keyboard toggle on the register editor"
```

---

## Task 3: The keyboard host

**Files:**
- Create: `frontend/web/src/register/ScreenKeyboardHost.tsx`, `src/register/screen-keyboard.css`, `src/register/ScreenKeyboardHost.test.tsx`
- Modify: `frontend/web/package.json` (add `react-simple-keyboard`), `src/lib/api.ts` (`RegisterInfo`), `src/register/Register.tsx`

**Interfaces:**
- Consumes: `RegisterInfo.screen_keyboard_enabled` (Task 1).
- Produces: `<ScreenKeyboardHost />`, and the `data-screen-keyboard="numeric|full"` contract Task 4 applies to inputs.

- [ ] **Step 1: Add the dependency**

```bash
cd frontend/web && npm install react-simple-keyboard@^3.8
```
Commit the `package.json` and `package-lock.json` changes with the rest of this task.

- [ ] **Step 2: Extend `RegisterInfo`**

In `frontend/web/src/lib/api.ts`:

```ts
export type RegisterInfo = {
  id: string
  name: string
  mode: 'retail' | 'food'
  // Per-register, set in the back office. Drives ScreenKeyboardHost; false on any
  // register that never opted in, including ones enrolled before this shipped.
  screen_keyboard_enabled: boolean
}
```

`tokens.setRegisterInfo` already persists whatever the activation and staff-login responses carry, so no storage change is needed — but check its stored-shape validation (it nulls out malformed stored objects) and make the new key tolerated when absent, so a session persisted before this change does not log the terminal out. Default it to `false` on read.

- [ ] **Step 3: Write the failing test**

Create `frontend/web/src/register/ScreenKeyboardHost.test.tsx`:

```tsx
// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useState } from 'react'
import { ScreenKeyboardHost } from './ScreenKeyboardHost'

afterEach(cleanup)

function Harness({ enabled, layout }: { enabled: boolean; layout: 'numeric' | 'full' }) {
  const [value, setValue] = useState('')
  return (
    <>
      <label>
        Amount
        <input data-screen-keyboard={layout} value={value} onChange={(e) => setValue(e.target.value)} />
      </label>
      <ScreenKeyboardHost enabled={enabled} />
    </>
  )
}

describe('ScreenKeyboardHost', () => {
  it('renders nothing when the register has the keyboard off', () => {
    render(<Harness enabled={false} layout="numeric" />)
    fireEvent.focusIn(screen.getByLabelText('Amount'))
    expect(screen.queryByRole('button', { name: '7' })).not.toBeInTheDocument()
  })

  it('raises a numeric keyboard on focus and types into the field', () => {
    render(<Harness enabled layout="numeric" />)
    const input = screen.getByLabelText('Amount') as HTMLInputElement
    fireEvent.focusIn(input)

    fireEvent.click(screen.getByRole('button', { name: '5' }))
    fireEvent.click(screen.getByRole('button', { name: '0' }))

    // The assertion that catches a broken setNativeValue: React's own state must have
    // advanced, not just the DOM node's value.
    expect(input.value).toBe('50')
  })

  it('shows letters only for a full-layout field', () => {
    render(<Harness enabled layout="full" />)
    fireEvent.focusIn(screen.getByLabelText('Amount'))
    expect(screen.getByRole('button', { name: 'q' })).toBeInTheDocument()
  })

  it('dismisses on Done', () => {
    render(<Harness enabled layout="numeric" />)
    fireEvent.focusIn(screen.getByLabelText('Amount'))
    fireEvent.click(screen.getByRole('button', { name: /done/i }))
    expect(screen.queryByRole('button', { name: '7' })).not.toBeInTheDocument()
  })
})
```

The button accessible names depend on how `react-simple-keyboard` renders keys — read its DOM in a scratch run and adjust the selectors to what it actually produces (it renders `<div>`s with `data-skbtn` by default, so you may need `getByText` or a `buttonTheme`/`display` mapping). **Adjust the test to the library's real output; do not weaken what it asserts.**

- [ ] **Step 4: Run it to confirm it fails**

Run: `cd frontend/web && npm test -- ScreenKeyboardHost`
Expected: FAIL — module not found.

- [ ] **Step 5: Write the host**

Create `frontend/web/src/register/ScreenKeyboardHost.tsx`. The shape:

```tsx
'use client'

import { useEffect, useRef, useState } from 'react'
import Keyboard from 'react-simple-keyboard'
import 'react-simple-keyboard/build/css/index.css'
import './screen-keyboard.css'

type Layout = 'numeric' | 'full'

const LAYOUTS: Record<Layout, { default: string[]; shift?: string[] }> = {
  // Digits, decimal point, backspace. A cash field that accepts letters is a validation
  // problem the till does not need.
  numeric: { default: ['1 2 3', '4 5 6', '7 8 9', '. 0 {bksp}'] },
  full: {
    default: ['1 2 3 4 5 6 7 8 9 0', 'q w e r t y u i o p', 'a s d f g h j k l', '{shift} z x c v b n m {bksp}', '{space}'],
    shift: ['1 2 3 4 5 6 7 8 9 0', 'Q W E R T Y U I O P', 'A S D F G H J K L', '{shift} Z X C V B N M {bksp}', '{space}'],
  },
}

/**
 * React installs its own value setter on each input instance and tracks the last value it
 * saw. Assigning `el.value` directly updates the DOM but leaves that tracker stale, so the
 * `input` event we dispatch afterwards is swallowed as a no-op and onChange never fires.
 * Going through the PROTOTYPE's setter is what makes React see a real change.
 */
function setNativeValue(el: HTMLInputElement, value: string): void {
  const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set
  setter?.call(el, value)
  el.dispatchEvent(new Event('input', { bubbles: true }))
}

/**
 * One keyboard for the whole register app, raised by focus on any input that opts in with
 * `data-screen-keyboard="numeric|full"`.
 *
 * A host rather than per-field wiring: threading onKeyPress through every input would be
 * sixteen files touched, sixteen chances to miss one, and every input added later would
 * silently opt out.
 */
export function ScreenKeyboardHost({ enabled }: { enabled: boolean }) {
  const [target, setTarget] = useState<HTMLInputElement | null>(null)
  const [layout, setLayout] = useState<Layout>('numeric')
  const [shifted, setShifted] = useState(false)
  const targetRef = useRef<HTMLInputElement | null>(null)
  targetRef.current = target

  useEffect(() => {
    if (!enabled) return
    const onFocusIn = (e: FocusEvent) => {
      const el = e.target
      if (!(el instanceof HTMLInputElement)) return
      const want = el.dataset.screenKeyboard
      if (want !== 'numeric' && want !== 'full') return setTarget(null)
      setLayout(want)
      setShifted(false)
      setTarget(el)
    }
    document.addEventListener('focusin', onFocusIn)
    return () => document.removeEventListener('focusin', onFocusIn)
  }, [enabled])

  if (!enabled || target === null) return null

  const press = (button: string) => {
    const el = targetRef.current
    if (el === null) return
    if (button === '{shift}') return setShifted((s) => !s)
    if (button === '{done}') return setTarget(null)
    if (button === '{bksp}') return setNativeValue(el, el.value.slice(0, -1))
    setNativeValue(el, el.value + (button === '{space}' ? ' ' : button))
  }

  return (
    <div className="screen-keyboard" role="group" aria-label="On-screen keyboard">
      <Keyboard
        layoutName={shifted ? 'shift' : 'default'}
        layout={{
          default: [...LAYOUTS[layout].default, '{done}'],
          shift: [...(LAYOUTS[layout].shift ?? LAYOUTS[layout].default), '{done}'],
        }}
        display={{ '{bksp}': '⌫', '{shift}': '⇧', '{space}': 'space', '{done}': 'Done' }}
        onKeyPress={press}
      />
    </div>
  )
}
```

Treat this as the intended shape, not gospel — check `react-simple-keyboard`'s current API (`onKeyPress` vs `onChange`, the `layout` prop's expected form) against the installed version and adjust. Keep the comments explaining *why*.

- [ ] **Step 6: Style it**

Create `frontend/web/src/register/screen-keyboard.css` overriding the library's theme with Carbon tokens — surface, hairline borders, ink text — and a **56px** minimum key height to match the till's touch floor. Fix the container to the bottom of the viewport above the action zone.

**Do not put any of this in `src/styles/carbon.css`** — that file is byte-identical with the back office, which has no keyboard.

- [ ] **Step 7: Mount it**

In `src/register/Register.tsx`, render `<ScreenKeyboardHost enabled={tokens.registerInfo()?.screen_keyboard_enabled ?? false} />` once at the app root. Follow the file's existing pattern for reading register info into state rather than at render time (it does this deliberately for SSR).

- [ ] **Step 8: Verify**

```bash
cd frontend/web && npm test -- ScreenKeyboardHost && npm run typecheck && npm test && npm run build
```

- [ ] **Step 9: Commit**

```bash
git add frontend/web
git commit -m "feat(register): on-screen keyboard host driven by the register flag"
```

---

## Task 4: Opt the inputs in

**Files:**
- Modify: `frontend/web/src/register/SaleScreen.tsx`, `RefundScreen.tsx`, `ShiftScreens.tsx`, `SessionScreens.tsx`, `FloorScreen.tsx`, `NoSaleButton.tsx`

**Interfaces:**
- Consumes: the `data-screen-keyboard` contract from Task 3.

- [ ] **Step 1: Enumerate the inputs**

Run: `grep -rn "<Input" frontend/web/src/register/*.tsx`

Every hit gets an attribute **except** `ActivationScreen.tsx` and `ServerSetupScreen.tsx` — at that point the client has no register and therefore no flag to read, which is a documented limitation, not an oversight.

- [ ] **Step 2: Apply the attributes**

| Layout | Fields |
| --- | --- |
| `numeric` | cash tendered (`SaleScreen`), PIN (`SessionScreens`), line quantity (`RefundScreen`), opening float and counted cash (`ShiftScreens`) |
| `full` | barcode (`SaleScreen`), card/e-wallet reference (`SaleScreen`), table ref (`FloorScreen`), void reason and discount reason (`SaleScreen`), refund reason and receipt lookup (`RefundScreen`), no-sale reason (`NoSaleButton`) |

Add `data-screen-keyboard="numeric"` or `="full"` to each `<Input>`. Nothing else about those files changes — no state, no handlers.

- [ ] **Step 3: Verify nothing regressed**

```bash
cd frontend/web && npm test && npm run typecheck && npm run build
```
Expected: all green, unchanged counts apart from Task 3's new file. If a test breaks, the attribute is not the cause — investigate rather than adjust the test.

- [ ] **Step 4: Commit**

```bash
git add frontend/web/src/register
git commit -m "feat(register): opt every till input into the on-screen keyboard"
```

---

## Task 5: Docs and manual

**Files:**
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/06-roadmap.md`, `CLAUDE.md`, `docs/user-manual/user-manual.md`

- [ ] **Step 1: `docs/02-data-model.md`** — add `screen_keyboard_enabled` to the `registers` table block with a comment saying why it defaults false and why it is per-register rather than per-location.

- [ ] **Step 2: `docs/03-api.md`** — add the field to the documented `registers` admin shape, and note that it appears on the activation and staff-login responses so the till can read it before any staff session exists.

- [ ] **Step 3: `docs/06-roadmap.md` and `CLAUDE.md`** — a Status/record entry in the shape of the neighbouring ones: what shipped, the one decision worth remembering (a host listening on `focusin` rather than per-field wiring, and why), the documented limitation (activation and server-setup screens still need a physical keyboard), and the final suite counts.

- [ ] **Step 4: `docs/user-manual/user-manual.md`** — a short passage in the registers chapter: what the toggle does, that it is per till, and the setup caveat. Add a Revision History row. Do **not** rebuild the PDF here; note it for Step 5.

- [ ] **Step 5: Rebuild the manual**

Run: `make manual`
(Only if a full stack is available. If it is not, say so plainly rather than claiming a build you did not see — CI rebuilds it on push under `docs/user-manual/**`.)

- [ ] **Step 6: Commit**

```bash
git add docs CLAUDE.md
git commit -m "docs: per-register on-screen keyboard"
```

---

## Final verification

- [ ] `make test` — all three suites
- [ ] `make e2e` — the flag is inert for the scripts, but they must stay green
- [ ] `make build` — all three images
