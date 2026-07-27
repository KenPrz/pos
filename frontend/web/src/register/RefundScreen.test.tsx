// @vitest-environment jsdom
/**
 * Step 0 regression (Task 16): api.refund's second argument used to be a `driver`
 * literal ('cash') and is now a payment method CODE — codes are uppercase, so the old
 * literal silently sent 'cash' and got 422 payment_method_unknown at runtime. The
 * typechecker can't catch this (the param widened to `string`), so this test pins the
 * actual posted value: the screen must read the location's cash-driver method off the
 * catalog and post ITS code, never a hardcoded literal. The fixture's cash method is
 * deliberately PETTYCASH, not CASH, so a reintroduced 'CASH' literal — or 'cash' — would
 * both fail this test.
 */
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RefundScreen } from './RefundScreen'
import { api, type Catalog, type Order, type Refund } from '../lib/api'
import { setCurrency } from '../lib/currency'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
  setCurrency('USD')
})

// Same idiom as SaleScreen.test.tsx: keep everything real except the endpoints this
// screen actually calls. api.catalog is ungated here too (the refundable-method lookup
// runs unconditionally), so it must be mocked for every case in this file.
vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      findOrders: vi.fn(),
      refund: vi.fn(),
      catalog: vi.fn(),
    },
  }
})

const CATALOG: Catalog = {
  categories: [], products: [], variants: [], modifier_groups: [], modifiers: [], tax_rates: [], discounts: [],
  currency: 'USD',
  payment_methods: [
    // Deliberately NOT 'CASH' — proves the code comes from the catalog, not a literal.
    { id: 'm-pettycash', code: 'PETTYCASH', name: 'Petty cash', group_code: 'CASH', group_name: 'Cash', driver: 'cash', sort_order: 0 },
    { id: 'm-visa', code: 'VISA', name: 'Visa', group_code: 'CARD', group_name: 'Cards', driver: 'external_card', sort_order: 0 },
  ],
}

const order: Order = {
  id: 'order-1',
  number: 'N-0001',
  register_id: 'register-1',
  status: 'closed',
  table_ref: null,
  business_date: '2026-07-18',
  prices_include_tax: false,
  subtotal_cents: 1200,
  discount_cents: 0,
  tax_cents: 100,
  total_cents: 1300,
  paid_cents: 1300,
  due_cents: 0,
  version: 1,
  lines: [
    { id: 'line-1', name: 'Widget', sku: 'W-1', unit_price_cents: 1200, qty: '1.000', tax_cents: 100, line_total_cents: 1300, voided_at: null, prep_state: null },
  ],
}

function renderRefund() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <RefundScreen onDone={vi.fn()} onSessionExpired={vi.fn()} />
    </QueryClientProvider>,
  )
}

async function findOrder() {
  fireEvent.change(screen.getByLabelText(/receipt number/i), { target: { value: 'N-0001' } })
  fireEvent.click(screen.getByRole('button', { name: /find order/i }))
  await screen.findByText(/Order N-0001/)
}

describe('RefundScreen — payment method code', () => {
  it('posts the catalog cash method code, not a hardcoded literal', async () => {
    vi.mocked(api.catalog).mockResolvedValue(CATALOG)
    vi.mocked(api.findOrders).mockResolvedValue([order])
    vi.mocked(api.refund).mockResolvedValue({
      id: 'refund-1', original_order_id: order.id, driver: 'cash',
      payment_method_code: 'PETTYCASH', payment_method_name: 'Petty cash',
      amount_cents: 1300, reason: 'Faulty', business_date: '2026-07-18',
      lines: [{ original_order_line_id: 'line-1', qty: '1.000', amount_cents: 1300, restock: true }],
    } satisfies Refund)

    renderRefund()
    await findOrder()

    fireEvent.change(screen.getByLabelText(/quantity of widget to refund/i), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText(/reason/i), { target: { value: 'Faulty' } })
    // Regression: the button used to hardcode 'Refund cash' — a location whose
    // cash-driver method is PETTYCASH would show a button naming a tender it doesn't
    // use. It must read the resolved method's NAME ("Petty cash"), not a literal.
    expect(screen.queryByRole('button', { name: /^refund cash$/i })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /refund petty cash/i }))

    await screen.findByText(/Refund complete/i)
    expect(api.refund).toHaveBeenCalledWith(
      order.id, 'PETTYCASH', 'Faulty',
      [{ original_order_line_id: 'line-1', qty: '1', restock: true }],
      expect.any(String),
    )
  })

  it('refuses to submit when the location has no cash payment method', async () => {
    vi.mocked(api.catalog).mockResolvedValue({ ...CATALOG, payment_methods: [CATALOG.payment_methods[1]] })
    vi.mocked(api.findOrders).mockResolvedValue([order])

    renderRefund()
    await findOrder()

    fireEvent.change(screen.getByLabelText(/quantity of widget to refund/i), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText(/reason/i), { target: { value: 'Faulty' } })
    fireEvent.click(screen.getByRole('button', { name: /refund cash/i }))

    expect(await screen.findByText(/no cash payment method/i)).toBeInTheDocument()
    expect(api.refund).not.toHaveBeenCalled()
  })

  it('blames the connection, not the configuration, when the catalog fails to load', async () => {
    // Same distinction the tender screen draws: an unreachable catalog is not a
    // misconfigured location, and telling staff otherwise sends them to fix the wrong
    // thing. The refund is correctly refused either way.
    vi.mocked(api.catalog).mockRejectedValue(new Error('network down'))
    vi.mocked(api.findOrders).mockResolvedValue([order])

    renderRefund()
    await findOrder()

    fireEvent.change(screen.getByLabelText(/quantity of widget to refund/i), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText(/reason/i), { target: { value: 'Faulty' } })
    fireEvent.click(screen.getByRole('button', { name: /refund cash/i }))

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument()
    expect(screen.queryByText(/no cash payment method/i)).not.toBeInTheDocument()
    expect(api.refund).not.toHaveBeenCalled()
  })
})
