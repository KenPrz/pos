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
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { SaleScreen } from './SaleScreen'
import { api, type Order } from '../lib/api'

afterEach(cleanup)

// Same idiom as FloorScreen.test.tsx: keep everything real except the one endpoint
// SaleScreen's recovery query calls on every mount.
vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      findOrders: vi.fn(),
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
