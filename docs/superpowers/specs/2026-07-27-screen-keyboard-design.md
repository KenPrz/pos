# On-screen keyboard — design

A per-register setting, `registers.screen_keyboard_enabled`, that puts a touch keyboard on
the till for terminals with no physical keyboard. Configured in the back office alongside
the register's mode; read by the register app, which docks a keyboard at the bottom of the
screen whenever a text or number field takes focus.

Nothing about the back office changes for the operator beyond one checkbox, and nothing
about the till changes at all when the flag is off.

## Why this exists

The register app assumes a keyboard. Cash tendered, PINs, barcodes typed when a scan
fails, table refs, card references and — critically — the *reasons* attached to voids,
discounts and refunds are all free typing. On a sealed all-in-one terminal or a tablet in
a stand there is nothing to type with, and the supervisor-gated, audited actions are
exactly the ones that become unreachable. A store in that position can sell but cannot
correct a mistake.

This is a per-register setting rather than per-location because a single store commonly
mixes hardware: a sealed counter terminal beside a back-office PC that enrolled as a
second till.

## Constraints inherited from the codebase

- **`registers.mode` is the precedent.** A column on `registers`, surfaced on the two
  resources that describe a register to its client, editable in the back office's
  `RegisterEditor`. This setting follows the same path exactly, and deviating from it
  would be the surprise.
- **One design language, two surfaces.** The root `DESIGN.md` governs both frontends. The
  shared set (`carbon.css`, `lib/utils.ts`, all of `components/ui/*`,
  `StatusPill`/`EmptyState`/`ConfirmDialog`) is **byte-identical** between them — this
  work must not touch any of it.
- **Money is integer cents; quantities are strings on the wire.** The keyboard writes into
  existing inputs and changes neither.
- **Admin `FormRequest::authorize()` never calls bare `can()`** — it goes through
  `AuthorizesBackOffice::allowsBackOffice()`.
- **Archive, never delete**, and admin `PATCH` applies only the keys it is sent.
- `tsconfig` sets `erasableSyntaxOnly`; type syntax must never emit runtime code.
- The register's touch targets have a 56px floor (`DESIGN.md`); keys inherit it.

## Data model

```sql
alter table registers add column screen_keyboard_enabled boolean not null default false;
```

Default `false`. A terminal *with* a keyboard is the common case, and defaulting true
would put a keyboard on every till already in service — a silent behaviour change for
every existing store on migrate.

No CHECK, no index: it is a plain boolean read only by the register that owns it.

## How the flag reaches the till

The same two resources that already carry `mode`:

- `EnrolledRegisterResource` — the activation response. This is the one that matters for
  PIN entry, because `api.activateRegister` already persists the register info
  (`tokens.setRegisterInfo`) *before* any staff session exists.
- `StaffSessionResource` — the PIN-login response, which refreshes it.

`RegisterInfo` becomes `{ id, name, mode, screen_keyboard_enabled }`.

The consequence worth stating plainly: **the activation and server-setup screens cannot
show the keyboard**, because at that point the client has no device token and no register
to read a flag from. First setup of a keyboard-less terminal needs a keyboard attached
once, or the code typed on a phone and pasted. This is documented in the manual rather
than left as a discovery.

## The interaction model — one host, not sixteen keyboards

A single `<ScreenKeyboardHost>` mounts once at the register app root. It listens for
`focusin` on inputs that opt in via a data attribute, and renders one `react-simple-keyboard`
docked to the bottom of the viewport:

```tsx
<Input data-screen-keyboard="numeric" ... />   // cash tendered, PIN, qty, float, counted cash
<Input data-screen-keyboard="full" ... />      // barcode, reference, table ref, reasons
```

Tapping a field raises the keyboard; `Enter` or its **Done** key dismisses it. When the
flag is off the host renders nothing and the attributes are inert.

**Why a host rather than per-field wiring.** The alternative is threading `onKeyPress`
through all sixteen input sites: sixteen files touched, sixteen chances to miss one, and
every input added later silently opts out. The host costs exactly one sharp technique —
writing into a controlled React input from outside requires the native value setter plus a
dispatched `input` event, or React's `onChange` never fires — confined to one helper with
the reason written down beside it.

```ts
// React installs its own value setter on the input instance; assigning `el.value`
// directly updates the DOM but leaves React's internal tracker unaware, so the
// subsequent `input` event is swallowed as a no-op. Going through the prototype's
// setter is what makes React see a real change.
function setNativeValue(el: HTMLInputElement, value: string): void
```

### Layout assignment

| Layout | Fields |
| --- | --- |
| `numeric` | cash tendered, PIN, line quantity, opening float, counted cash |
| `full` | barcode, card/e-wallet reference, table ref, void reason, discount reason, refund reason, no-sale reason |

`numeric` is digits, decimal point and backspace only — a cash field that can accept
letters is a validation problem the till does not need. `full` is QWERTY with a shift and
a numeric row.

### Placement

Fixed to the bottom of the viewport, above the register's own action zone, with the
focused screen padded by the keyboard's height while it is open so the active field is
never covered. It is `position: fixed` rather than in flow so it does not reflow the cart.

## Styling

`react-simple-keyboard` ships structural CSS and a default theme that looks nothing like
this codebase. The structural stylesheet is imported; the theme is overridden in the
register app only, using Carbon tokens (`--color-surface-1`, `--color-hairline`,
`--color-ink`, the `type-body-sm` scale) and a 56px key floor to match the till's existing
touch targets.

The override lives in a register-scoped stylesheet, **not** in `carbon.css` — that file is
byte-identical with the back office, which has no keyboard and never will.

This is the first third-party UI component in either frontend, which cuts against
`DESIGN.md`'s hand-styled-on-Tailwind convention. It is a deliberate exception: a
correct, accessible on-screen keyboard is a large amount of fiddly work (shift state, key
repeat, layout switching, touch targets) with no product value in rewriting.

## Back office

One checkbox in `RegisterEditor`, beside Mode and Active:

> **On-screen keyboard** — Show a touch keyboard on this till. For terminals with no
> physical keyboard.

`AdminRegisterResource` exposes the field; `CreateRegisterRequest` and
`UpdateRegisterRequest` accept it as `['sometimes', 'boolean']`. It is an ordinary
editable setting — unlike a payment method's `code`, there is no history to corrupt by
changing it.

## Testing

**Backend.** The column defaults false; both resources expose it; a `PATCH` toggles it and
audits; the value round-trips to a staff session.

**Register.** The host renders nothing when the flag is off and mounts when it is on;
focusing a `numeric` field shows the numeric layout and a `full` field the QWERTY one;
tapping a key updates the target input's value *and* fires its `onChange` (the assertion
that would catch a broken `setNativeValue`); Done dismisses.

**Back office.** The checkbox round-trips through the editor.

## What this deliberately does not do

- No keyboard on the activation or server-setup screens (no register known yet).
- No per-field override — the flag is per register, not per input.
- No handwriting, no autocomplete, no layout localisation. If a store needs a non-QWERTY
  layout, that is a real request and a later one.
