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

  it('never leaves an unscoped text-primary on the selected cell (blue-on-blue regression)', () => {
    // The selected day is ALSO today here — the two modifiers stack on one <td>, and
    // an unscoped text-primary (from `today`) outranks text-on-primary in the compiled
    // stylesheet, rendering the number blue-on-blue. `today`'s text color must be
    // scoped to unselected cells (not-aria-selected:), never a bare text-primary token.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 6, 23))
    try {
      render(<DatePicker value="2026-07-23" onChange={vi.fn()} aria-label="Business date" />)
      openPicker()

      const selectedCell = document.querySelector('td[aria-selected="true"]')
      expect(selectedCell).not.toBeNull()
      expect(selectedCell?.classList.contains('text-on-primary')).toBe(true)
      expect(selectedCell?.classList.contains('text-primary')).toBe(false)
    } finally {
      vi.useRealTimers()
    }
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
