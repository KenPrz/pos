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
            if (!d) return // re-click on the selected day deselects — keep the value
            onChange(formatIsoDate(d))
            setOpen(false)
          }}
        />
      </PopoverContent>
    </Popover>
  )
}
