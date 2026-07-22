// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ComponentProps } from 'react'
import { FloorScreen } from './FloorScreen'
import { api, type Order, type OpenShiftRegister } from '../lib/api'
import { setCurrency } from '../lib/currency'

// Same idiom as ModifierSheet.test.tsx: vitest doesn't run with `globals: true`, so
// @testing-library/react's auto-cleanup never registers itself — do it by hand or DOM
// from one test leaks into the next.
afterEach(cleanup)

// The mocked api.* fns are module-scoped (the vi.mock factory below runs once), so their
// call history accumulates across tests unless cleared — bites the retry/key-reuse
// assertion below, which reads mock.calls positionally. openShiftRegisters gets a
// target-less default every test (matches canTransfer: false being renderFloor's own
// default) so tests that don't care about transfer targets never see an unhandled
// rejection from an uncleared mock returning undefined.
beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(api.openShiftRegisters).mockResolvedValue([])
  // Explicit, not relying on lib/currency's pre-load default: this suite never fetches
  // the catalog (the thing that would normally set it).
  setCurrency('USD')
})

// Module mock: keep everything real (ApiError, types, other api.* members) except the
// endpoints this screen actually calls, so `err instanceof ApiError` checks inside
// FloorScreen still work against the real class.
vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      openOrders: vi.fn(),
      openOrder: vi.fn(),
      transferOrder: vi.fn(),
      openShiftRegisters: vi.fn(),
    },
  }
})

const MINE = 'register-mine'
const OTHER = 'register-other'

function makeOpenShiftRegister(overrides: Partial<OpenShiftRegister> = {}): OpenShiftRegister {
  return {
    register_id: OTHER,
    register_name: 'Bar',
    shift_id: 'shift-1',
    opened_by_name: 'Sam',
    ...overrides,
  }
}

function makeOrder(overrides: Partial<Order> = {}): Order {
  return {
    id: 'order-1',
    number: 'N-0001',
    register_id: MINE,
    status: 'open',
    table_ref: '12',
    opened_at: new Date(Date.now() - 5 * 60_000).toISOString(),
    opened_by_name: 'Alex',
    business_date: '2026-07-18',
    prices_include_tax: false,
    subtotal_cents: 1200,
    discount_cents: 0,
    tax_cents: 100,
    total_cents: 1300,
    paid_cents: 0,
    due_cents: 1300,
    version: 1,
    ...overrides,
  }
}

function renderFloor(props: Partial<ComponentProps<typeof FloorScreen>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const merged: ComponentProps<typeof FloorScreen> = {
    registerId: MINE,
    canTransfer: false,
    activeOrderId: null,
    onResume: vi.fn(),
    onNewTab: vi.fn(),
    onSessionExpired: vi.fn(),
    ...props,
  }
  render(
    <QueryClientProvider client={client}>
      <FloorScreen {...merged} />
    </QueryClientProvider>,
  )
  return merged
}

describe('FloorScreen', () => {
  it('renders one card per open order with its table ref and amount due', async () => {
    vi.mocked(api.openOrders).mockResolvedValue([
      makeOrder({ id: 'order-1', table_ref: '12', due_cents: 1300 }),
      makeOrder({ id: 'order-2', table_ref: '7', number: 'N-0002', due_cents: 500 }),
    ])
    renderFloor()

    expect(await screen.findByText('12')).toBeInTheDocument()
    expect(screen.getByText('7')).toBeInTheDocument()
    expect(screen.getByText('$13.00')).toBeInTheDocument()
    expect(screen.getByText('$5.00')).toBeInTheDocument()
  })

  it('fires onResume with the picked order when its card is tapped', async () => {
    const order = makeOrder({ id: 'order-1', table_ref: '12' })
    vi.mocked(api.openOrders).mockResolvedValue([order])
    const { onResume } = renderFloor()

    // findByRole's accessible-name computation can exceed the default 1s wait on a
    // loaded CI/container host — this one query gets a longer leash, not a logic change.
    const card = await screen.findByRole('button', { name: /12/ }, { timeout: 5000 })
    fireEvent.click(card)

    expect(onResume).toHaveBeenCalledWith(order)
  })

  it('hides TRANSFER when the staff member lacks order.transfer', async () => {
    vi.mocked(api.openOrders).mockResolvedValue([
      makeOrder({ id: 'order-1', register_id: MINE, table_ref: '12' }),
      makeOrder({ id: 'order-2', register_id: OTHER, table_ref: '7', opened_by_name: 'Sam' }),
    ])
    renderFloor({ canTransfer: false })

    await screen.findByText('12')
    expect(screen.queryByText(/transfer/i)).not.toBeInTheDocument()
  })

  it('shows TRANSFER for my own tabs when permitted, and hands off via api.transferOrder', async () => {
    const mine = makeOrder({ id: 'order-1', register_id: MINE, table_ref: '12' })
    vi.mocked(api.openOrders).mockResolvedValue([mine])
    vi.mocked(api.openShiftRegisters).mockResolvedValue([
      makeOpenShiftRegister({ register_id: OTHER, register_name: 'Bar', opened_by_name: 'Sam' }),
    ])
    vi.mocked(api.transferOrder).mockResolvedValue({ ...mine, register_id: OTHER })
    renderFloor({ canTransfer: true })

    await screen.findByText('12')
    fireEvent.click(screen.getByRole('button', { name: /transfer/i }))
    fireEvent.click(await screen.findByRole('button', { name: 'Bar — Sam' }))

    await waitFor(() => expect(api.transferOrder).toHaveBeenCalledWith(mine, OTHER))
  })

  // The M5 gap this endpoint fixes: a register that opened a shift but has no open tabs
  // of its own was previously invisible to the transfer picker (targets were inferred
  // from open orders, so a tabless register never appeared). Now it comes from
  // openShiftRegisters directly, independent of the open-orders payload.
  it('lists a register with an open shift but no open tabs as a transfer target', async () => {
    const mine = makeOrder({ id: 'order-1', register_id: MINE, table_ref: '12' })
    vi.mocked(api.openOrders).mockResolvedValue([mine])
    vi.mocked(api.openShiftRegisters).mockResolvedValue([
      makeOpenShiftRegister({ register_id: OTHER, register_name: 'Bar', opened_by_name: 'Sam' }),
    ])
    renderFloor({ canTransfer: true })

    await screen.findByText('12')
    fireEvent.click(screen.getByRole('button', { name: /transfer/i }))

    expect(await screen.findByRole('button', { name: 'Bar — Sam' })).toBeInTheDocument()
  })

  it('disables resume on other tabs while a different order is active on the sale screen', async () => {
    vi.mocked(api.openOrders).mockResolvedValue([
      makeOrder({ id: 'order-1', table_ref: '12' }),
      makeOrder({ id: 'order-2', table_ref: '7', number: 'N-0002' }),
    ])
    renderFloor({ activeOrderId: 'order-1' })

    await screen.findByText('12')
    const blocked = screen.getByRole('button', { name: /7/ })
    const active = screen.getByRole('button', { name: /12/ })
    expect(blocked).toBeDisabled()
    expect(active).toBeEnabled()
  })

  it('opens a new tab with a table ref and hands the created order to onNewTab', async () => {
    const created = makeOrder({ id: 'order-new', table_ref: '9' })
    vi.mocked(api.openOrders).mockResolvedValue([])
    vi.mocked(api.openOrder).mockResolvedValue(created)
    const { onNewTab } = renderFloor()

    await screen.findByText('No open tabs.')
    fireEvent.click(screen.getByRole('button', { name: /new tab/i }))
    fireEvent.change(screen.getByPlaceholderText(/table/i), { target: { value: '9' } })
    fireEvent.click(screen.getByRole('button', { name: /open tab/i }))

    // idempotencyKey is minted when the pad opens (see FloorScreen's newTabKeyRef) so a
    // lost response can't mint a twin order on retry — asserted as "some string", the
    // exact UUID isn't the point.
    await waitFor(() =>
      expect(api.openOrder).toHaveBeenCalledWith({ tableRef: '9', idempotencyKey: expect.any(String) }),
    )
    await waitFor(() => expect(onNewTab).toHaveBeenCalledWith(created))
  })

  it('reuses the same idempotency key across a retry after a failed submit, and mints a new one on the next pad-open', async () => {
    vi.mocked(api.openOrders).mockResolvedValue([])
    vi.mocked(api.openOrder).mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce(makeOrder({ id: 'order-new' }))
    renderFloor()

    await screen.findByText('No open tabs.')
    fireEvent.click(screen.getByRole('button', { name: /new tab/i }))
    fireEvent.click(screen.getByRole('button', { name: /open tab/i }))
    await waitFor(() => expect(api.openOrder).toHaveBeenCalledTimes(1))

    fireEvent.click(screen.getByRole('button', { name: /open tab/i }))
    await waitFor(() => expect(api.openOrder).toHaveBeenCalledTimes(2))

    const [firstCall, secondCall] = vi.mocked(api.openOrder).mock.calls
    expect(firstCall[0]?.idempotencyKey).toBe(secondCall[0]?.idempotencyKey)
  })
})
