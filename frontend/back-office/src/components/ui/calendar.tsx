import * as React from 'react'
import { DayPicker } from 'react-day-picker'
import { cn } from '@/lib/utils'

// Carbon calendar — react-day-picker restyled with the token set: square 40px
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
        // today's blue is scoped to unselected cells: the `selected` modifier lands on
        // the SAME td, and a bare text-primary outranks text-on-primary in the compiled
        // stylesheet — blue number on blue fill, i.e. invisible (aria-selected sits on
        // the td, which is what the not- variant keys off).
        today: 'font-semibold not-aria-selected:text-primary',
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
