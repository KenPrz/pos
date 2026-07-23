# Back-office date pickers (shadcn-style calendar)

**Date:** 2026-07-23
**Status:** Approved

## Problem

The back office renders five date fields as native `<input type="date">`, so the
picker is whatever the browser ships — visually foreign to the Carbon design
language and inconsistent across platforms. The fields:

- `src/admin/day/EndOfDaySection.tsx` — business date (single, capped at the
  location's "today")
- `src/admin/audit/AuditSection.tsx` — From / To filter pair
- `src/admin/reports/SalesReportView.tsx` — From / To filter pair

## Decision

Adopt the shadcn/ui calendar pattern: `react-day-picker` v9 rendered inside a
Radix popover, restyled with Carbon tokens. From/To pairs become a single
range picker; End of Day keeps a single-date picker.

Approaches rejected: hand-rolled month grid (reimplements month math, keyboard
nav, and ARIA for no win) and restyling the native input (still the browser's
picker — doesn't meet the ask).

## Scope

Back office only. The register app has zero date fields, so `frontend/web` is
untouched. The CLAUDE.md byte-identical rule keeps governing files that exist
in both apps; `calendar.tsx`/`popover.tsx` join the shared set the day the
register needs them.

## New dependencies (`frontend/back-office` only)

- `react-day-picker@^9`
- `@radix-ui/react-popover`

## Components

- `src/components/ui/popover.tsx` — thin Radix wrapper. Panel styled like
  `SelectContent`: flat, `border-hairline`, `bg-canvas`, radius 0, no shadow,
  portal + `z-50`.
- `src/components/ui/calendar.tsx` — `DayPicker` restyled with Carbon tokens:
  IBM Plex type scale, square cells, `bg-surface-1` hover,
  `bg-primary`/`text-ink-inverse` selected, `text-ink-subtle` outside days,
  disabled days at reduced opacity and non-interactive.
- `src/components/DatePicker.tsx` — composed single-date control. Button
  trigger with `Input` chrome (`bg-surface-1`, bottom hairline, radius 0,
  focus/open underline in `border-primary`) showing the formatted date, or a
  muted placeholder when empty. Opens `Calendar` in a `Popover`; picking a day
  closes it.
- `src/components/DateRangePicker.tsx` — same trigger chrome showing
  "from – to"; opens a two-month `Calendar` in range mode. Picking the first
  day starts the range, the second completes it and closes the popover.

### Value contract

Both components take and emit **`"YYYY-MM-DD"` strings** (`''`/absent for
unset) — the same wire shape the screens already keep in state and pass to the
API. All date math converts at the component boundary; no `Date` objects leak
into screen state, query params, or API calls. Parsing/formatting is local-time
(never `toISOString`, which shifts across UTC).

Props:

- `DatePicker`: `value: string`, `onChange(value: string)`, optional
  `max: string` (days after it are disabled), `aria-label`/`id` passthrough.
- `DateRangePicker`: `from: string`, `to: string`,
  `onChange(range: { from: string; to: string })`, `aria-label`/`id`
  passthrough.

## Screen changes

- **End of Day:** header `Input[type=date]` → `DatePicker` with
  `max={status.location_today}`; same `pickerDate` fallback logic and
  "Business date" aria-label.
- **Audit log:** From + To inputs collapse into one `DateRangePicker` labelled
  "Date range" (~`w-[280px]`); writes `filters.from`/`filters.to` exactly as
  before; the Filter button still applies.
- **Sales report:** same collapse; `from`/`to` state and query wiring
  unchanged.

## Error handling

- Range picker: selecting a second day before the first swaps them so
  `from <= to` always holds (react-day-picker range mode does this natively).
- Empty state: placeholder text, empty-string emission — matches how the
  screens treat blank filters today.
- `max`-violating days are unclickable rather than validated after the fact.

## Testing

- Component tests (`DatePicker.test.tsx`, `DateRangePicker.test.tsx`): opens
  on click, renders the grid, picking emits `YYYY-MM-DD`, respects `max`,
  range completes and closes, empty → placeholder.
- Update the three screens' existing tests to drive the new pickers instead of
  `fireEvent.change` on native date inputs.
- Gate: `npm test`, `npm run typecheck`, `npm run build` in
  `frontend/back-office`.
