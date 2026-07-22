// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SplitPrompt, SplitStrip } from './SplitStrip'
import type { Order } from '../lib/api'
import { setCurrency } from '../lib/currency'

afterEach(cleanup)

beforeEach(() => {
  // Explicit, not relying on lib/currency's pre-load default: this suite renders
  // SplitStrip standalone, with no catalog fetch to set it for real.
  setCurrency('USD')
})

function makeOrder(overrides: Partial<Order> = {}): Order {
  return {
    id: 'order-1',
    number: 'N-0001',
    register_id: 'register-1',
    status: 'open',
    table_ref: null,
    business_date: '2026-07-18',
    prices_include_tax: false,
    subtotal_cents: 1000,
    discount_cents: 0,
    tax_cents: 0,
    total_cents: 1000,
    paid_cents: 0,
    due_cents: 1000,
    version: 1,
    ...overrides,
  }
}

describe('SplitStrip', () => {
  it('renders one chip per child with its number and due, active child highlighted', () => {
    const orders = [
      makeOrder({ id: 'a', number: 'N-0001', due_cents: 500 }),
      makeOrder({ id: 'b', number: 'N-0002', due_cents: 500 }),
    ]
    render(<SplitStrip orders={orders} activeIx={1} />)

    expect(screen.getByText('Check 1')).toBeInTheDocument()
    expect(screen.getByText('Check 2')).toBeInTheDocument()
    expect(screen.getAllByText('$5.00')).toHaveLength(2)

    // Styling-internal hook moved with the UI rework: the strip is a PillStrip now, so
    // the old `.split-chip`/`.active` class assertions read PillStrip's `data-state`.
    const chips = document.querySelectorAll('[data-state]')
    expect(chips[0]).toHaveAttribute('data-state', 'pending')
    expect(chips[1]).toHaveAttribute('data-state', 'active')
  })

  it('shows a settled child as paid instead of its (zero) due amount', () => {
    const orders = [makeOrder({ id: 'a', due_cents: 0 }), makeOrder({ id: 'b', due_cents: 500 })]
    render(<SplitStrip orders={orders} activeIx={1} />)

    expect(screen.getByText('Paid')).toBeInTheDocument()
    expect(screen.getByText('$5.00')).toBeInTheDocument()
  })
})

describe('SplitPrompt', () => {
  it('shows the even-split preview and steps ways via onWaysChange', () => {
    const onWaysChange = vi.fn()
    render(
      <SplitPrompt ways={3} totalCents={1000} onWaysChange={onWaysChange} onConfirm={vi.fn()} onCancel={vi.fn()} pending={false} />,
    )

    expect(screen.getByText('3')).toBeInTheDocument()
    // 1000 split 3 ways: earliest absorbs the remainder — 334 / 333 / 333 (allocate()).
    expect(screen.getByText('$3.34 · $3.33 · $3.33')).toBeInTheDocument()

    fireEvent.click(screen.getByText('+'))
    expect(onWaysChange).toHaveBeenCalledWith(4)

    fireEvent.click(screen.getByText('−'))
    expect(onWaysChange).toHaveBeenCalledWith(2)
  })

  it('disables − at the floor of 2 and + at the ceiling of 10', () => {
    const { rerender } = render(
      <SplitPrompt ways={2} totalCents={1000} onWaysChange={vi.fn()} onConfirm={vi.fn()} onCancel={vi.fn()} pending={false} />,
    )
    expect(screen.getByText('−')).toBeDisabled()
    expect(screen.getByText('+')).toBeEnabled()

    rerender(<SplitPrompt ways={10} totalCents={1000} onWaysChange={vi.fn()} onConfirm={vi.fn()} onCancel={vi.fn()} pending={false} />)
    expect(screen.getByText('+')).toBeDisabled()
    expect(screen.getByText('−')).toBeEnabled()
  })

  it('calls onConfirm when GO is clicked and shows a pending label while splitting', () => {
    const onConfirm = vi.fn()
    const { rerender } = render(
      <SplitPrompt ways={2} totalCents={1000} onWaysChange={vi.fn()} onConfirm={onConfirm} onCancel={vi.fn()} pending={false} />,
    )
    fireEvent.click(screen.getByRole('button', { name: 'GO' }))
    expect(onConfirm).toHaveBeenCalled()

    rerender(<SplitPrompt ways={2} totalCents={1000} onWaysChange={vi.fn()} onConfirm={onConfirm} onCancel={vi.fn()} pending={true} />)
    expect(screen.getByRole('button', { name: /splitting/i })).toBeDisabled()
  })

  it('calls onCancel when Cancel is clicked', () => {
    const onCancel = vi.fn()
    render(
      <SplitPrompt ways={2} totalCents={1000} onWaysChange={vi.fn()} onConfirm={vi.fn()} onCancel={onCancel} pending={false} />,
    )
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(onCancel).toHaveBeenCalled()
  })

  it('disables Cancel while the split request is in flight, so it cannot be dismissed out from under an already-irreversible submit', () => {
    const onCancel = vi.fn()
    render(
      <SplitPrompt ways={2} totalCents={1000} onWaysChange={vi.fn()} onConfirm={vi.fn()} onCancel={onCancel} pending={true} />,
    )
    const cancelBtn = screen.getByRole('button', { name: 'Cancel' })
    expect(cancelBtn).toBeDisabled()
    fireEvent.click(cancelBtn)
    expect(onCancel).not.toHaveBeenCalled()
  })
})
