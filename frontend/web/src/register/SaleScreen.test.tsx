// @vitest-environment jsdom
/**
 * Regression coverage for the review fix: Register.tsx's endSession() now clears
 * `resumeOrder` before the next login remounts SaleScreen (see Register.tsx's endSession
 * comment). Register.tsx itself has no test harness to drive that remount through —
 * PinScreen, ShiftScreens, and the whole stage machine would all need mocking for what's
 * really a one-line state clear. Instead, this pins down the actual mechanism the bug
 * lived in: SaleScreen's `initialOrder`-seeding effect runs on mount. A fresh mount with
 * `initialOrder` still undefined (the POST-FIX state, once Register clears it) must show
 * no order; a fresh mount WITH one (the pre-fix, stale-resumeOrder state) does seed —
 * proving the fix works by bracketing both sides of it.
 */
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SaleScreen } from './SaleScreen'
import { api, type Order } from '../lib/api'

afterEach(cleanup)

// Mocked api.* fns are module-scoped (the vi.mock factory below runs once); clear call
// history between tests the same way FloorScreen.test.tsx does.
beforeEach(() => {
  vi.clearAllMocks()
})

// Same idiom as FloorScreen.test.tsx: keep everything real except the endpoints
// SaleScreen's recovery query and the split flow call.
vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      findOrders: vi.fn(),
      splitOrder: vi.fn(),
      takePayment: vi.fn(),
      receipt: vi.fn(),
    },
  }
})

const order: Order = {
  id: 'order-1',
  number: 'N-0001',
  register_id: 'register-1',
  status: 'open',
  table_ref: '12',
  business_date: '2026-07-18',
  prices_include_tax: false,
  subtotal_cents: 1200,
  discount_cents: 0,
  tax_cents: 100,
  total_cents: 1300,
  paid_cents: 0,
  due_cents: 1300,
  version: 1,
}

function renderSale(initialOrder?: Order) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <SaleScreen
        can={() => false}
        registerId="register-1"
        initialOrder={initialOrder}
        onCloseShift={vi.fn()}
        onSessionExpired={vi.fn()}
      />
    </QueryClientProvider>,
  )
}

describe('SaleScreen resume seeding', () => {
  it('does not seed a stale order on a fresh mount when initialOrder is undefined (post-endSession state)', async () => {
    vi.mocked(api.findOrders).mockResolvedValue([])
    renderSale(undefined)

    expect(await screen.findByText('New sale')).toBeInTheDocument()
    expect(screen.queryByText(/^Order /)).not.toBeInTheDocument()
  })

  it('seeds the order on mount when a resumed initialOrder IS provided', async () => {
    vi.mocked(api.findOrders).mockResolvedValue([])
    renderSale(order)

    expect(await screen.findByText(`Order ${order.number}`)).toBeInTheDocument()
  })
})

describe('SaleScreen split flow', () => {
  it('splits into checks, advances through each child on payment, and lands on a combined done plate', async () => {
    vi.mocked(api.findOrders).mockResolvedValue([])
    const parent: Order = { ...order, id: 'parent-1', number: 'N-0001', total_cents: 1000, due_cents: 1000, paid_cents: 0, version: 1 }
    const childA: Order = { ...order, id: 'child-a', number: 'N-0002', total_cents: 500, due_cents: 500, paid_cents: 0, version: 0 }
    const childB: Order = { ...order, id: 'child-b', number: 'N-0003', total_cents: 500, due_cents: 500, paid_cents: 0, version: 0 }
    vi.mocked(api.splitOrder).mockResolvedValue([childA, childB])
    vi.mocked(api.takePayment)
      .mockResolvedValueOnce({
        payment: { id: 'pay-a', driver: 'cash', status: 'captured', amount_cents: 500, tendered_cents: 500, change_cents: 0 },
        order: { ...childA, paid_cents: 500, due_cents: 0, status: 'closed' },
      })
      .mockResolvedValueOnce({
        payment: { id: 'pay-b', driver: 'cash', status: 'captured', amount_cents: 500, tendered_cents: 500, change_cents: 0 },
        order: { ...childB, paid_cents: 500, due_cents: 0, status: 'closed' },
      })
    vi.mocked(api.receipt).mockRejectedValue(new Error('receipt unavailable in this test'))

    renderSale(parent)
    await screen.findByText('Order N-0001')

    fireEvent.click(screen.getByText(/Pay —/))
    fireEvent.click(screen.getByRole('button', { name: 'Split bill' }))
    fireEvent.click(screen.getByRole('button', { name: 'GO' }))

    await waitFor(() => expect(api.splitOrder).toHaveBeenCalledWith(parent, 2, expect.any(String)))
    await screen.findByText('Check 1')
    expect(screen.getByText('Check 2')).toBeInTheDocument()
    await screen.findByText('Order N-0002')

    fireEvent.change(screen.getByLabelText(/cash tendered/i), { target: { value: '5.00' } })
    fireEvent.click(screen.getByRole('button', { name: /take payment/i }))

    await waitFor(() => expect(screen.getByText('Order N-0003')).toBeInTheDocument())
    expect(api.takePayment).toHaveBeenCalledTimes(1)

    // The settled-chip regression this task fixed: check 1's chip must flip to "Paid"
    // once its payment closes it, not keep showing its stale pre-payment due forever.
    const chips = document.querySelectorAll('.split-chip')
    expect(chips[0]).toHaveClass('settled')
    expect(chips[0]).toHaveTextContent('Paid')
    expect(chips[1]).toHaveClass('active')
    expect(chips[1]).toHaveTextContent('$5.00')

    fireEvent.change(screen.getByLabelText(/cash tendered/i), { target: { value: '5.00' } })
    fireEvent.click(screen.getByRole('button', { name: /take payment/i }))

    expect(await screen.findByText(/All checks settled — 2 checks/)).toBeInTheDocument()
    expect(api.takePayment).toHaveBeenCalledTimes(2)
    expect(screen.getByText('Check 1 — order N-0002')).toBeInTheDocument()
    expect(screen.getByText('Check 2 — order N-0003')).toBeInTheDocument()
  })

  it('hides the SPLIT control once a child is being tendered (no re-splitting a check)', async () => {
    vi.mocked(api.findOrders).mockResolvedValue([])
    const parent: Order = { ...order, id: 'parent-1', number: 'N-0001', total_cents: 1000, due_cents: 1000, paid_cents: 0, version: 1 }
    const childA: Order = { ...order, id: 'child-a', number: 'N-0002', total_cents: 500, due_cents: 500, paid_cents: 0, version: 0 }
    const childB: Order = { ...order, id: 'child-b', number: 'N-0003', total_cents: 500, due_cents: 500, paid_cents: 0, version: 0 }
    vi.mocked(api.splitOrder).mockResolvedValue([childA, childB])

    renderSale(parent)
    await screen.findByText('Order N-0001')

    fireEvent.click(screen.getByText(/Pay —/))
    fireEvent.click(screen.getByRole('button', { name: 'Split bill' }))
    fireEvent.click(screen.getByRole('button', { name: 'GO' }))

    await screen.findByText('Order N-0002')
    expect(screen.queryByRole('button', { name: 'Split bill' })).not.toBeInTheDocument()
  })
})
