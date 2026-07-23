# Back-Office Date Pickers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the back office's five native `<input type="date">` fields with Carbon-styled shadcn-pattern pickers — a single `DatePicker` for End of Day, a `DateRangePicker` for the audit-log and sales-report from/to pairs.

**Architecture:** `react-day-picker` v9 rendered inside a Radix popover, fully restyled with the Carbon token set (no day-picker stylesheet import). Two composed components own the popover and the wire-format boundary; screens keep their existing `"YYYY-MM-DD"` string state and API wiring untouched.

**Tech Stack:** Next.js 16, React 19, react-day-picker v9, @radix-ui/react-popover, Tailwind v4 Carbon tokens, Vitest + Testing Library (jsdom).

**Spec:** `docs/superpowers/specs/2026-07-23-back-office-date-picker-design.md`

## Global Constraints

- All work is inside `frontend/back-office/` — `frontend/web` is untouched (register has no date fields; the byte-identical shared-set rule keeps governing files that exist in both apps).
- Values crossing a component boundary are `"YYYY-MM-DD"` strings; `Date` objects never leak into screen state, query params, or API calls.
- Date parse/format at the boundary is **local-time** — never `toISOString()` on a picked day (UTC shift moves the calendar day).
- Carbon design language: radius 0, no shadows, hairline borders, `bg-surface-1` field fill, `bg-primary` selection — mirror the chrome in `src/components/ui/input.tsx` and `select.tsx`.
- Tests use `fireEvent` (no `user-event` dependency in this repo) and the `// @vitest-environment jsdom` pragma, matching the existing suites.
- Inside `src/components/ui/*` import via the `@/` alias (matches `select.tsx`); elsewhere use relative imports (matches the screens).
- Commit messages carry **no attribution trailers** of any kind.
- Run commands from `frontend/back-office/` unless a path is shown.

---

### Task 1: Dependencies + local-date helpers

**Files:**
- Modify: `frontend/back-office/package.json` (via npm install)
- Modify: `frontend/back-office/src/lib/date.ts`
- Test: `frontend/back-office/src/lib/date.test.ts` (new)

**Interfaces:**
- Produces: `parseIsoDate(s: string): Date | null`, `formatIsoDate(d: Date): string`, `displayDate(s: string): string` — consumed by Tasks 2 and 3.
- Note: the existing `isoDate(d: Date)` in this file is UTC-based and stays untouched (existing call sites depend on it).

- [ ] **Step 1: Install the two new dependencies**

```bash
npm install react-day-picker @radix-ui/react-popover
```

Expected: `package.json` gains `react-day-picker` (^9.x) and `@radix-ui/react-popover` (^1.x) under `dependencies`; lockfile updates.

- [ ] **Step 2: Write the failing tests**

Create `src/lib/date.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { displayDate, formatIsoDate, parseIsoDate } from './date'

describe('parseIsoDate', () => {
  it('parses YYYY-MM-DD as a local calendar day', () => {
    const d = parseIsoDate('2026-07-23')
    expect(d?.getFullYear()).toBe(2026)
    expect(d?.getMonth()).toBe(6)
    expect(d?.getDate()).toBe(23)
  })

  it('rejects malformed and rollover strings', () => {
    expect(parseIsoDate('')).toBeNull()
    expect(parseIsoDate('23/07/2026')).toBeNull()
    expect(parseIsoDate('2026-7-3')).toBeNull()
    expect(parseIsoDate('2026-13-01')).toBeNull()
    expect(parseIsoDate('2026-02-30')).toBeNull()
  })
})

describe('formatIsoDate', () => {
  it('formats the LOCAL calendar day, zero-padded', () => {
    expect(formatIsoDate(new Date(2026, 6, 3))).toBe('2026-07-03')
  })

  it('round-trips with parseIsoDate', () => {
    expect(formatIsoDate(parseIsoDate('2026-01-31') as Date)).toBe('2026-01-31')
  })
})

describe('displayDate', () => {
  it('renders a short human date', () => {
    expect(displayDate('2026-07-23')).toBe('Jul 23, 2026')
  })

  it('is empty for an unparseable value', () => {
    expect(displayDate('')).toBe('')
  })
})
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `npx vitest run src/lib/date.test.ts`
Expected: FAIL — `parseIsoDate` / `formatIsoDate` / `displayDate` are not exported.

- [ ] **Step 4: Implement the helpers**

Append to `src/lib/date.ts` (leave `isoDate` as-is):

```ts
/**
 * Parse `'YYYY-MM-DD'` into a LOCAL-midnight Date, or null if malformed. Deliberately
 * not `new Date(s)` — that parses as UTC midnight, which renders as the previous
 * calendar day anywhere west of Greenwich. The round-trip check rejects rollover
 * ('2026-02-30' must not become March 2nd).
 */
export function parseIsoDate(s: string): Date | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s)
  if (!m) return null
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
  const date = new Date(y, mo - 1, d)
  return date.getFullYear() === y && date.getMonth() === mo - 1 && date.getDate() === d ? date : null
}

/** `'YYYY-MM-DD'` from a Date's LOCAL calendar day — the counterpart of parseIsoDate. */
export function formatIsoDate(d: Date): string {
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${mm}-${dd}`
}

const DISPLAY_FORMAT = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' })

/** `'Jul 23, 2026'` for a wire date, `''` if it doesn't parse — picker trigger labels. */
export function displayDate(s: string): string {
  const d = parseIsoDate(s)
  return d ? DISPLAY_FORMAT.format(d) : ''
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npx vitest run src/lib/date.test.ts`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json src/lib/date.ts src/lib/date.test.ts
git commit -m "feat(back-office): local-date helpers + calendar deps"
```

---

### Task 2: Popover + Calendar primitives, DatePicker

**Files:**
- Create: `frontend/back-office/src/components/ui/popover.tsx`
- Create: `frontend/back-office/src/components/ui/calendar.tsx`
- Create: `frontend/back-office/src/components/DatePicker.tsx`
- Test: `frontend/back-office/src/components/DatePicker.test.tsx` (new)

**Interfaces:**
- Consumes: `parseIsoDate` / `formatIsoDate` / `displayDate` from Task 1.
- Produces:
  - `Popover`, `PopoverTrigger`, `PopoverContent` (radix wrappers)
  - `Calendar` — `(props: React.ComponentProps<typeof DayPicker>) => JSX`, Carbon-styled
  - `DatePicker` — `{ value: string; onChange: (value: string) => void; max?: string; id?: string; 'aria-label'?: string }`

- [ ] **Step 1: Write the failing DatePicker tests**

Create `src/components/DatePicker.test.tsx`. Notes baked into these tests: react-day-picker v9 gives each day button an accessible name like `"Wednesday, July 15th, 2026"` (with a `"Today, "` prefix or `", selected"` suffix sometimes attached — match with a loose regex), the month table has `role="grid"`, and a day disabled via the `disabled` matcher renders a disabled button.

```tsx
// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DatePicker } from './DatePicker'

afterEach(cleanup)

const openPicker = () => fireEvent.click(screen.getByRole('button', { name: /business date/i }))

describe('DatePicker', () => {
  it('shows the formatted value on the trigger, placeholder when empty', () => {
    const { rerender } = render(<DatePicker value="2026-07-10" onChange={vi.fn()} aria-label="Business date" />)
    expect(screen.getByRole('button', { name: /business date/i })).toHaveTextContent('Jul 10, 2026')

    rerender(<DatePicker value="" onChange={vi.fn()} aria-label="Business date" />)
    expect(screen.getByRole('button', { name: /business date/i })).toHaveTextContent('Select date')
  })

  it('opens a calendar on the value month and emits the picked day as YYYY-MM-DD', () => {
    const onChange = vi.fn()
    render(<DatePicker value="2026-07-10" onChange={onChange} aria-label="Business date" />)

    openPicker()
    expect(screen.getByRole('grid')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /july 15th, 2026/i }))
    expect(onChange).toHaveBeenCalledExactlyOnceWith('2026-07-15')
    // Picking closes the popover.
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
  })

  it('disables days after max', () => {
    const onChange = vi.fn()
    render(<DatePicker value="2026-07-10" max="2026-07-20" onChange={onChange} aria-label="Business date" />)

    openPicker()
    expect(screen.getByRole('button', { name: /july 25th, 2026/i })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: /july 25th, 2026/i }))
    expect(onChange).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/components/DatePicker.test.tsx`
Expected: FAIL — cannot resolve `./DatePicker`.

- [ ] **Step 3: Create the popover primitive**

Create `src/components/ui/popover.tsx`:

```tsx
import * as React from 'react'
import * as PopoverPrimitive from '@radix-ui/react-popover'
import { cn } from '@/lib/utils'

// Carbon popover — the same flat sheet as SelectContent: hairline border, canvas
// fill, radius 0, no shadow.
const Popover = PopoverPrimitive.Root
const PopoverTrigger = PopoverPrimitive.Trigger

const PopoverContent = React.forwardRef<
  React.ComponentRef<typeof PopoverPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof PopoverPrimitive.Content>
>(({ className, align = 'start', sideOffset = 4, ...props }, ref) => (
  <PopoverPrimitive.Portal>
    <PopoverPrimitive.Content
      ref={ref}
      align={align}
      sideOffset={sideOffset}
      className={cn('z-50 rounded-none border border-hairline bg-canvas p-md outline-none', className)}
      {...props}
    />
  </PopoverPrimitive.Portal>
))
PopoverContent.displayName = PopoverPrimitive.Content.displayName

export { Popover, PopoverTrigger, PopoverContent }
```

- [ ] **Step 4: Create the calendar primitive**

Create `src/components/ui/calendar.tsx`. This is react-day-picker v9's `classNames` API (element keys like `month_grid`/`day_button`, modifier keys like `selected`/`range_middle`); no day-picker stylesheet is imported, so these classes are the entire look. If typecheck rejects a key, check the installed `ClassNames` type in `node_modules/react-day-picker/dist/cjs/types/shared.d.ts` — the key set, not the styling, is the contract.

```tsx
import * as React from 'react'
import { DayPicker } from 'react-day-picker'
import { cn } from '@/lib/utils'

// Carbon calendar — react-day-picker v9 restyled with the token set: square 40px
// cells, surface-1 hover, primary selection, hairline-flat chrome. Screens never
// mount this directly; DatePicker/DateRangePicker own the popover and the
// 'YYYY-MM-DD' wire-format boundary.
//
// range_middle uses `!` because the `selected` modifier class lands on the same
// cell — without it, which bg wins depends on CSS order, not intent.
type CalendarProps = React.ComponentProps<typeof DayPicker>

function Calendar({ className, classNames, ...props }: CalendarProps) {
  return (
    <DayPicker
      className={cn('text-ink', className)}
      classNames={{
        months: 'relative flex gap-lg',
        month: 'flex flex-col gap-xs',
        month_caption: 'flex h-[40px] items-center justify-center',
        caption_label: 'type-body-sm font-medium text-ink',
        nav: 'absolute inset-x-0 top-0 z-10 flex h-[40px] items-center justify-between',
        button_previous:
          'flex size-[32px] items-center justify-center text-ink outline-none hover:bg-surface-1 ' +
          'focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary ' +
          'disabled:pointer-events-none disabled:opacity-50',
        button_next:
          'flex size-[32px] items-center justify-center text-ink outline-none hover:bg-surface-1 ' +
          'focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary ' +
          'disabled:pointer-events-none disabled:opacity-50',
        chevron: 'size-[16px] fill-current',
        month_grid: 'border-collapse',
        weekday: 'w-[40px] py-xs text-center type-caption font-normal text-ink-muted',
        day: 'p-0 text-center',
        day_button:
          'size-[40px] cursor-pointer p-0 text-[14px] leading-none outline-none hover:bg-surface-1 ' +
          'focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary ' +
          'disabled:cursor-default disabled:hover:bg-transparent',
        today: 'font-semibold text-primary',
        selected: 'bg-primary text-on-primary',
        range_start: 'bg-primary text-on-primary',
        range_end: 'bg-primary text-on-primary',
        range_middle: 'bg-surface-2! text-ink!',
        outside: 'text-ink-subtle',
        disabled: 'text-ink-subtle opacity-50',
        hidden: 'invisible',
        ...classNames,
      }}
      {...props}
    />
  )
}

export { Calendar }
```

- [ ] **Step 5: Create DatePicker**

Create `src/components/DatePicker.tsx`:

```tsx
'use client'

import { useState } from 'react'
import { cn } from '../lib/utils'
import { displayDate, formatIsoDate, parseIsoDate } from '../lib/date'
import { Calendar } from './ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from './ui/popover'

export interface DatePickerProps {
  /** `'YYYY-MM-DD'` — the wire format; `''` means unset. */
  value: string
  onChange: (value: string) => void
  /** `'YYYY-MM-DD'` — days after this are unclickable. */
  max?: string
  id?: string
  'aria-label'?: string
}

// Same field chrome as Input/SelectTrigger (surface-1 fill, bottom hairline,
// primary underline while open), opening a Carbon Calendar in a popover. Strings
// in, strings out — Date objects stop at this boundary.
export function DatePicker({ value, onChange, max, id, 'aria-label': ariaLabel }: DatePickerProps) {
  const [open, setOpen] = useState(false)
  const selected = parseIsoDate(value) ?? undefined
  const maxDate = max ? (parseIsoDate(max) ?? undefined) : undefined

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger
        id={id}
        aria-label={ariaLabel}
        className={cn(
          'flex w-full items-center justify-between gap-xs rounded-none border-0 border-b border-hairline',
          'bg-surface-1 px-md py-[11px] text-left text-[16px] leading-[1.5] tracking-[0.16px] text-ink',
          'outline-none data-[state=open]:border-b-2 data-[state=open]:border-primary',
          'disabled:pointer-events-none disabled:opacity-50'
        )}
      >
        <span className={selected ? undefined : 'text-ink-subtle'}>
          {selected ? displayDate(value) : 'Select date'}
        </span>
        <span aria-hidden className="text-ink-subtle">
          ▾
        </span>
      </PopoverTrigger>
      <PopoverContent>
        <Calendar
          mode="single"
          selected={selected}
          defaultMonth={selected ?? maxDate}
          disabled={maxDate ? { after: maxDate } : undefined}
          onSelect={(d) => {
            if (!d) return // v9 re-click on the selected day deselects — keep the value
            onChange(formatIsoDate(d))
            setOpen(false)
          }}
        />
      </PopoverContent>
    </Popover>
  )
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `npx vitest run src/components/DatePicker.test.tsx`
Expected: PASS (3 tests). If the day-button name regex misses, `screen.debug()` the open grid once to read v9's actual `aria-label` wording and adjust the regexes — the format, not the behavior, is the only degree of freedom here.

- [ ] **Step 7: Commit**

```bash
git add src/components/ui/popover.tsx src/components/ui/calendar.tsx src/components/DatePicker.tsx src/components/DatePicker.test.tsx
git commit -m "feat(back-office): Carbon popover + calendar primitives and DatePicker"
```

---

### Task 3: DateRangePicker

**Files:**
- Create: `frontend/back-office/src/components/DateRangePicker.tsx`
- Test: `frontend/back-office/src/components/DateRangePicker.test.tsx` (new)

**Interfaces:**
- Consumes: `Calendar`, `Popover*` (Task 2); date helpers (Task 1); `DateRange` type from `react-day-picker`.
- Produces: `DateRangePicker` — `{ from: string; to: string; onChange: (range: { from: string; to: string }) => void; id?: string; 'aria-label'?: string }`. Emits **only complete** ranges, with `from <= to` always.

- [ ] **Step 1: Write the failing tests**

Create `src/components/DateRangePicker.test.tsx`:

```tsx
// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DateRangePicker } from './DateRangePicker'

afterEach(cleanup)

const openPicker = () => fireEvent.click(screen.getByRole('button', { name: /date range/i }))

describe('DateRangePicker', () => {
  it('shows the formatted range on the trigger, placeholder when empty', () => {
    const { rerender } = render(
      <DateRangePicker from="2026-07-01" to="2026-07-05" onChange={vi.fn()} aria-label="Date range" />,
    )
    expect(screen.getByRole('button', { name: /date range/i })).toHaveTextContent('Jul 1, 2026 – Jul 5, 2026')

    rerender(<DateRangePicker from="" to="" onChange={vi.fn()} aria-label="Date range" />)
    expect(screen.getByRole('button', { name: /date range/i })).toHaveTextContent('Select range')
  })

  it('shows two months and emits only once both ends are picked', () => {
    const onChange = vi.fn()
    render(<DateRangePicker from="2026-07-01" to="2026-07-05" onChange={onChange} aria-label="Date range" />)

    openPicker()
    expect(screen.getAllByRole('grid')).toHaveLength(2)

    fireEvent.click(screen.getByRole('button', { name: /july 10th, 2026/i }))
    expect(onChange).not.toHaveBeenCalled()
    expect(screen.getAllByRole('grid')).toHaveLength(2) // still open

    fireEvent.click(screen.getByRole('button', { name: /july 15th, 2026/i }))
    expect(onChange).toHaveBeenCalledExactlyOnceWith({ from: '2026-07-10', to: '2026-07-15' })
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
  })

  it('orders a backwards pick so from <= to', () => {
    const onChange = vi.fn()
    render(<DateRangePicker from="2026-07-01" to="2026-07-05" onChange={onChange} aria-label="Date range" />)

    openPicker()
    fireEvent.click(screen.getByRole('button', { name: /july 15th, 2026/i }))
    fireEvent.click(screen.getByRole('button', { name: /july 10th, 2026/i }))
    expect(onChange).toHaveBeenCalledExactlyOnceWith({ from: '2026-07-10', to: '2026-07-15' })
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/components/DateRangePicker.test.tsx`
Expected: FAIL — cannot resolve `./DateRangePicker`.

- [ ] **Step 3: Implement DateRangePicker**

Create `src/components/DateRangePicker.tsx`:

```tsx
'use client'

import { useState } from 'react'
import type { DateRange } from 'react-day-picker'
import { cn } from '../lib/utils'
import { displayDate, formatIsoDate, parseIsoDate } from '../lib/date'
import { Calendar } from './ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from './ui/popover'

export interface DateRangePickerProps {
  /** `'YYYY-MM-DD'` bounds — the wire format; `''` means unset. */
  from: string
  to: string
  /** Called only with a COMPLETE range, `from <= to` guaranteed. */
  onChange: (range: { from: string; to: string }) => void
  id?: string
  'aria-label'?: string
}

// Range flavor of DatePicker: same trigger chrome, a two-month calendar in range
// mode. The draft selection restarts empty on every open — react-day-picker's
// click-with-existing-range behavior (extend vs. restart) is version-defined, and
// a fresh draft makes it deterministically two clicks; the current value still
// shows on the trigger. onChange fires once, when the second click completes the
// range (day-picker orders the pair itself).
export function DateRangePicker({ from, to, onChange, id, 'aria-label': ariaLabel }: DateRangePickerProps) {
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState<DateRange | undefined>(undefined)
  const hasValue = parseIsoDate(from) !== null && parseIsoDate(to) !== null

  const handleOpenChange = (next: boolean) => {
    setOpen(next)
    if (next) setDraft(undefined)
  }

  const handleSelect = (r: DateRange | undefined) => {
    setDraft(r)
    if (r?.from && r?.to) {
      onChange({ from: formatIsoDate(r.from), to: formatIsoDate(r.to) })
      setOpen(false)
    }
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger
        id={id}
        aria-label={ariaLabel}
        className={cn(
          'flex w-full items-center justify-between gap-xs rounded-none border-0 border-b border-hairline',
          'bg-surface-1 px-md py-[11px] text-left text-[16px] leading-[1.5] tracking-[0.16px] text-ink',
          'outline-none data-[state=open]:border-b-2 data-[state=open]:border-primary',
          'disabled:pointer-events-none disabled:opacity-50'
        )}
      >
        <span className={hasValue ? undefined : 'text-ink-subtle'}>
          {hasValue ? `${displayDate(from)} – ${displayDate(to)}` : 'Select range'}
        </span>
        <span aria-hidden className="text-ink-subtle">
          ▾
        </span>
      </PopoverTrigger>
      <PopoverContent>
        <Calendar
          mode="range"
          numberOfMonths={2}
          selected={draft}
          defaultMonth={parseIsoDate(from) ?? undefined}
          onSelect={handleSelect}
        />
      </PopoverContent>
    </Popover>
  )
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/components/DateRangePicker.test.tsx`
Expected: PASS (3 tests). If the second test fails because onChange fired on the FIRST click, the installed v9 sets `to` on a single click — fix by only emitting when `r.from` and `r.to` are different days OR the draft already had a `from` (check `journal` of actual behavior with `screen.debug()`); the contract (emit once, complete, ordered) is what the tests pin.

- [ ] **Step 5: Commit**

```bash
git add src/components/DateRangePicker.tsx src/components/DateRangePicker.test.tsx
git commit -m "feat(back-office): DateRangePicker"
```

---

### Task 4: End of Day uses DatePicker

**Files:**
- Modify: `frontend/back-office/src/admin/day/EndOfDaySection.tsx:150-157` (the header `Input[type=date]`)
- Test: `frontend/back-office/src/admin/day/EndOfDaySection.test.tsx`

**Interfaces:**
- Consumes: `DatePicker` from Task 2. `setDate` is `Dispatch<SetStateAction<string | null>>` — it accepts `(value: string) => void` assignment directly.

- [ ] **Step 1: Write the failing test**

Append to the `describe` block in `EndOfDaySection.test.tsx` (fixtures already pin `business_date`/`location_today` to `2026-07-23`):

```tsx
  it('refetches for the picked business date and disables days after location_today', async () => {
    vi.mocked(api.day.get).mockResolvedValue(OPEN_STATUS)
    renderSection('loc1')

    await screen.findByText(/open shift\(s\) — close them first/i)
    fireEvent.click(screen.getByRole('button', { name: /business date/i }))

    // location_today is 2026-07-23 — later days are unclickable.
    expect(screen.getByRole('button', { name: /july 25th, 2026/i })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: /july 20th, 2026/i }))
    expect(await screen.findByRole('button', { name: /business date/i })).toHaveTextContent('Jul 20, 2026')
    expect(vi.mocked(api.day.get)).toHaveBeenLastCalledWith('loc1', '2026-07-20')
  })
```

- [ ] **Step 2: Run tests to verify the new one fails**

Run: `npx vitest run src/admin/day/EndOfDaySection.test.tsx`
Expected: the new test FAILS (no button named "Business date" — it's still a native input); the 5 existing tests PASS.

- [ ] **Step 3: Swap the input for DatePicker**

In `EndOfDaySection.tsx`, replace the `SectionHeader` `action` (lines 149–157):

```tsx
        action={
          <div className="w-[200px]">
            <DatePicker
              value={pickerDate}
              max={status?.location_today}
              aria-label="Business date"
              onChange={setDate}
            />
          </div>
        }
```

Add the import (`Input` stays — the reopen-reason field still uses it):

```tsx
import { DatePicker } from '../../components/DatePicker'
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/admin/day/EndOfDaySection.test.tsx`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/admin/day/EndOfDaySection.tsx src/admin/day/EndOfDaySection.test.tsx
git commit -m "feat(back-office): End of Day business date via DatePicker"
```

---

### Task 5: Audit log uses DateRangePicker

**Files:**
- Modify: `frontend/back-office/src/admin/audit/AuditSection.tsx:153-172` (the two From/To `FieldRow`s)
- Test: `frontend/back-office/src/admin/audit/AuditSection.test.tsx`

**Interfaces:**
- Consumes: `DateRangePicker` from Task 3. Writes `filters.from`/`filters.to` (staged `Filters` state — applied only on the Filter button, unchanged).

- [ ] **Step 1: Write the failing test**

The audit filters start empty, so the calendar opens on the *real* current month — pin the clock (Date only, so `waitFor` still works) for determinism. Append to the `describe` block in `AuditSection.test.tsx`:

```tsx
  it('applies a picked date range through the Filter button', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 6, 23))
    try {
      vi.mocked(api.audit.list).mockResolvedValue({ rows: [], has_more: false } as AuditPage)
      renderSection()

      await waitFor(() => expect(api.audit.list).toHaveBeenCalledTimes(1))

      fireEvent.click(screen.getByRole('button', { name: /date range/i }))
      fireEvent.click(screen.getByRole('button', { name: /july 10th, 2026/i }))
      fireEvent.click(screen.getByRole('button', { name: /july 15th, 2026/i }))
      expect(screen.getByRole('button', { name: /date range/i })).toHaveTextContent('Jul 10, 2026 – Jul 15, 2026')

      fireEvent.click(screen.getByRole('button', { name: /^filter$/i }))
      await waitFor(() =>
        expect(api.audit.list).toHaveBeenLastCalledWith(
          expect.objectContaining({ from: '2026-07-10', to: '2026-07-15' }),
        ),
      )
    } finally {
      vi.useRealTimers()
    }
  })
```

(`renderSection()` and the `AuditPage` import already exist in this file — reuse them as-is.)

- [ ] **Step 2: Run tests to verify the new one fails**

Run: `npx vitest run src/admin/audit/AuditSection.test.tsx`
Expected: the new test FAILS (no "Date range" button); existing tests PASS.

- [ ] **Step 3: Swap the two date FieldRows**

In `AuditSection.tsx`, replace the two `w-[180px]` From/To blocks (lines 153–172) with:

```tsx
        <div className="w-[280px]">
          <FieldRow label="Date range">
            <DateRangePicker
              id="audit-range"
              aria-label="Date range"
              from={filters.from}
              to={filters.to}
              onChange={(r) => setFilters((f) => ({ ...f, from: r.from, to: r.to }))}
            />
          </FieldRow>
        </div>
```

Imports: add `import { DateRangePicker } from '../../components/DateRangePicker'`; remove the `Input` import **only if** no other field in the file uses it (entity id / user id / action still do — so it stays).

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/admin/audit/AuditSection.test.tsx`
Expected: PASS (all existing + 1 new).

- [ ] **Step 5: Commit**

```bash
git add src/admin/audit/AuditSection.tsx src/admin/audit/AuditSection.test.tsx
git commit -m "feat(back-office): audit log date range via DateRangePicker"
```

---

### Task 6: Sales report uses DateRangePicker

**Files:**
- Modify: `frontend/back-office/src/admin/reports/SalesReportView.tsx:118-129` (the two From/To `FieldRow`s)
- Test: `frontend/back-office/src/admin/reports/SalesReportView.test.tsx`

**Interfaces:**
- Consumes: `DateRangePicker` from Task 3. Writes the existing `from`/`to` state (query key members — a pick refetches immediately, same as typing in the old inputs did).

- [ ] **Step 1: Write the failing test**

`defaultRange()` reads the real clock, so pin it the same way as Task 5. Append to the `describe` block in `SalesReportView.test.tsx`:

```tsx
  it('refetches when a new date range is picked', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 6, 23))
    try {
      vi.mocked(api.reports.sales).mockResolvedValue(DAY_REPORT)
      renderView()

      await waitFor(() => expect(api.reports.sales).toHaveBeenCalledTimes(1))
      expect(vi.mocked(api.reports.sales).mock.calls[0][0]).toMatchObject({
        from: '2026-07-17',
        to: '2026-07-23',
      })

      fireEvent.click(screen.getByRole('button', { name: /date range/i }))
      fireEvent.click(screen.getByRole('button', { name: /july 10th, 2026/i }))
      fireEvent.click(screen.getByRole('button', { name: /july 15th, 2026/i }))

      await waitFor(() => expect(api.reports.sales).toHaveBeenCalledTimes(2))
      expect(vi.mocked(api.reports.sales).mock.calls[1][0]).toMatchObject({
        from: '2026-07-10',
        to: '2026-07-15',
      })
    } finally {
      vi.useRealTimers()
    }
  })
```

- [ ] **Step 2: Run tests to verify the new one fails**

Run: `npx vitest run src/admin/reports/SalesReportView.test.tsx`
Expected: the new test FAILS; the 4 existing tests PASS.

- [ ] **Step 3: Swap the two date FieldRows**

In `SalesReportView.tsx`, replace lines 118–129 (the whole `flex flex-wrap items-end gap-md` div's two children) with:

```tsx
      <div className="flex flex-wrap items-end gap-md">
        <div className="w-[280px]">
          <FieldRow label="Date range">
            <DateRangePicker
              id="sales-range"
              aria-label="Date range"
              from={from}
              to={to}
              onChange={(r) => {
                setFrom(r.from)
                setTo(r.to)
              }}
            />
          </FieldRow>
        </div>
      </div>
```

Imports: add `import { DateRangePicker } from '../../components/DateRangePicker'`; remove the now-unused `Input` import (nothing else in this file uses it).

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/admin/reports/SalesReportView.test.tsx`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/admin/reports/SalesReportView.tsx src/admin/reports/SalesReportView.test.tsx
git commit -m "feat(back-office): sales report date range via DateRangePicker"
```

---

### Task 7: Full gate

**Files:** none new — verification only.

- [ ] **Step 1: Full back-office suite**

Run: `npm test`
Expected: PASS, ≥ 187 tests (177 existing + ~10 new), 0 failures.

- [ ] **Step 2: Typecheck and build**

Run: `npm run typecheck && npm run build`
Expected: tsgo exits 0; `next build` completes without the mid-build self-heal warning.

- [ ] **Step 3: Lint**

Run: `npm run lint`
Expected: 0 errors (oxlint).

- [ ] **Step 4: Eyeball it in the running stack** (skip if `make dev` isn't up)

With `make dev` up and seeded: open http://127.0.0.1:5175, log in with the seeded admin, and check Reports → Sales (range picker), Audit log (range picker + Filter), End of Day (single picker capped at today). Confirm the popover is a flat hairline sheet, selection is primary-blue, and picking days round-trips into the tables.

- [ ] **Step 5: Commit anything outstanding**

Nothing should be left; if a fix-up was needed during the gate, commit it with a `fix(back-office):` message describing the actual fix.
