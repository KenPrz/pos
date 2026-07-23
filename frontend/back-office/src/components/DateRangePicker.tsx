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
    // react-day-picker answers the FIRST click with a complete same-day range
    // ({from: d, to: d}), not a half-open one — so "both ends present" alone
    // can't mean "done". A same-day range on a fresh draft is a selection
    // starting; anything after that is the completing click.
    const firstClick = draft?.from === undefined
    setDraft(r)
    if (!r?.from || !r?.to) return
    if (firstClick && r.from.getTime() === r.to.getTime()) return
    onChange({ from: formatIsoDate(r.from), to: formatIsoDate(r.to) })
    setOpen(false)
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
