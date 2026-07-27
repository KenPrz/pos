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
import { setCurrency } from '../lib/currency'

afterEach(cleanup)

// Module scope, above beforeEach: read by the api.catalog mock set up there.
const PAYMENT_METHODS = [
  { id: 'm-cash', code: 'CASH', name: 'Cash', group_code: 'CASH', group_name: 'Cash', driver: 'cash' as const, sort_order: 0 },
  { id: 'm-visa', code: 'VISA', name: 'Visa', group_code: 'CARD', group_name: 'Cards', driver: 'external_card' as const, sort_order: 0 },
  { id: 'm-gcash', code: 'GCASH', name: 'GCash', group_code: 'EWALLET', group_name: 'E-wallets', driver: 'external_card' as const, sort_order: 0 },
]

// Mocked api.* fns are module-scoped (the vi.mock factory below runs once); clear call
// history between tests the same way FloorScreen.test.tsx does.
beforeEach(() => {
  vi.clearAllMocks()
  // Explicit, not relying on lib/currency's pre-load default: api.catalog isn't mocked
  // here (foodMode is off, so MenuGrid — the only thing that calls it — never mounts),
  // so nothing else in this file would set it for real.
  setCurrency('USD')
  // SaleScreen now reads its tender buttons from the catalog, ungated (Task 16) — so
  // this must be mocked for every case in this file, not just the tender ones, or every
  // test fires a real api.catalog(), gets a rejection, resolves methods to [], and
  // renders the no-methods empty state instead of a tender form.
  vi.mocked(api.catalog).mockResolvedValue({
    categories: [], products: [], variants: [], modifier_groups: [], modifiers: [],
    tax_rates: [], discounts: [], payment_methods: PAYMENT_METHODS, currency: 'USD',
  })
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
      catalog: vi.fn(),
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
        payment: { id: 'pay-a', driver: 'cash', payment_method_code: 'CASH', payment_method_name: 'Cash', status: 'captured', amount_cents: 500, tendered_cents: 500, change_cents: 0 },
        order: { ...childA, paid_cents: 500, due_cents: 0, status: 'closed' },
      })
      .mockResolvedValueOnce({
        payment: { id: 'pay-b', driver: 'cash', payment_method_code: 'CASH', payment_method_name: 'Cash', status: 'captured', amount_cents: 500, tendered_cents: 500, change_cents: 0 },
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
    // (Styling-internal hook moved with the UI rework: the strip is a PillStrip now, so
    // the old `.split-chip`/`.settled` class assertions read PillStrip's `data-state`.)
    const chips = document.querySelectorAll('[data-state]')
    expect(chips[0]).toHaveAttribute('data-state', 'settled')
    expect(chips[0]).toHaveTextContent('Paid')
    expect(chips[1]).toHaveAttribute('data-state', 'active')
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

describe('SaleScreen tender methods', () => {
  // Getting to the tender phase is the same click the split cases above use: resume an
  // order, then press the Pay button (labelled `Pay — <amount>`).
  async function enterTender() {
    vi.mocked(api.findOrders).mockResolvedValue([])
    renderSale(order)
    fireEvent.click(await screen.findByRole('button', { name: /^Pay — / }))
  }

  it('renders one tender button per method, under its group name', async () => {
    await enterTender()

    expect(screen.getByRole('button', { name: 'Cash' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Visa' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'GCash' })).toBeInTheDocument()
    // Group headings appear because this location has more than one group.
    expect(screen.getByText('E-wallets')).toBeInTheDocument()
  })

  it('shows the cash field for a cash-driver method and a reference field otherwise', async () => {
    await enterTender()

    // Cash is selected first — it is the first method in the (server-sorted) list.
    expect(screen.getByLabelText(/cash tendered/i)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'GCash' }))
    expect(screen.getByLabelText(/reference/i)).toBeInTheDocument()
    expect(screen.queryByLabelText(/cash tendered/i)).not.toBeInTheDocument()
  })

  it('posts the selected method code, and the outcome screen names the method rather than a generic "Card"', async () => {
    vi.mocked(api.takePayment).mockResolvedValue({
      payment: {
        id: 'p-1', driver: 'external_card', payment_method_code: 'VISA',
        payment_method_name: 'Visa', status: 'captured',
        amount_cents: 1300, tendered_cents: null, change_cents: null,
      },
      order: { ...order, paid_cents: 1300, due_cents: 0, status: 'closed', version: 2 },
    })
    vi.mocked(api.receipt).mockResolvedValue(null as never)

    await enterTender()
    fireEvent.click(screen.getByRole('button', { name: 'Visa' }))
    fireEvent.click(screen.getByRole('button', { name: /take payment/i }))

    await waitFor(() => expect(api.takePayment).toHaveBeenCalledWith(
      expect.anything(), expect.any(Number), 'VISA', expect.any(String), expect.anything(),
    ))

    // Regression: this caption used to hardcode 'Card' for any non-cash tender, so a
    // GCash sale showed "Card" directly above "recorded on GCash". It must name the
    // ACTUAL method, not assume every non-cash payment is a card.
    await screen.findByText('Payment complete — order N-0001')
    expect(screen.getByText('Visa')).toBeInTheDocument()
    expect(screen.queryByText('Card')).not.toBeInTheDocument()
  })

  it('names the back office when the location has no methods', async () => {
    vi.mocked(api.catalog).mockResolvedValue({
      categories: [], products: [], variants: [], modifier_groups: [], modifiers: [],
      tax_rates: [], discounts: [], payment_methods: [], currency: 'USD',
    })

    await enterTender()

    expect(screen.getByText(/no payment methods are set up/i)).toBeInTheDocument()
  })

  it('blames the connection, not the configuration, when the catalog fails to load', async () => {
    // Both cases render an empty method list, but they are different problems: sending a
    // cashier to the back office to fix a configuration that was never wrong wastes a
    // trip. Payment is correctly blocked either way.
    vi.mocked(api.catalog).mockRejectedValue(new Error('network down'))

    await enterTender()

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument()
    expect(screen.queryByText(/no payment methods are set up/i)).not.toBeInTheDocument()
  })
})
