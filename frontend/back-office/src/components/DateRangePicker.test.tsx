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
