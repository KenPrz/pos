// @vitest-environment jsdom
/**
 * Task 13: blind drawer count (mask expected cash/variance until a count is submitted)
 * and supervisor approve-variance from the close result plate.
 */
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CloseShiftScreen } from './ShiftScreens'
import { api, tokens, type Shift, type ShiftCloseResult, type ZReport } from '../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
})

// Same idiom as FloorScreen.test.tsx: keep everything real (ApiError, tokens, etc.)
// except the endpoints this screen actually calls.
vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      zReport: vi.fn(),
      closeShift: vi.fn(),
      approveVariance: vi.fn(),
    },
  }
})

function makeShift(overrides: Partial<Shift> = {}): Shift {
  return {
    id: 'shift-1',
    register_id: 'register-1',
    opened_by: 'user-1',
    opened_at: new Date('2026-07-18T12:00:00Z').toISOString(),
    opening_float_cents: 20000,
    closed_at: null,
    counted_cash_cents: null,
    expected_cash_cents: null,
    variance_cents: null,
    variance_approved_by: null,
    variance_approved_at: null,
    ...overrides,
  }
}

function makeZReport(expectedCashCents: number): ZReport {
  return {
    shift: makeShift(),
    sales_by_driver: { cash: expectedCashCents },
    refunds_by_driver: {},
    movements: { paid_in: 0, payout: 0, drop: 0 },
    orders_closed: 3,
    orders_voided: 0,
    expected_cash_cents: expectedCashCents,
  }
}

function renderClose(can: (permission: string) => boolean = () => false) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const onClosed = vi.fn()
  render(
    <QueryClientProvider client={client}>
      <CloseShiftScreen shiftId="shift-1" can={can} onClosed={onClosed} onCancel={vi.fn()} onSessionExpired={vi.fn()} />
    </QueryClientProvider>,
  )
  return { onClosed }
}

describe('CloseShiftScreen — blind drawer count', () => {
  it('masks the expected-cash figure before a count is submitted', async () => {
    vi.mocked(api.zReport).mockResolvedValue(makeZReport(12345))
    renderClose()

    expect(await screen.findByText('•••••')).toBeInTheDocument()
    expect(screen.queryByText('$123.45')).not.toBeInTheDocument()
  })

  it('reveals expected cash and variance once the close result returns', async () => {
    vi.mocked(api.zReport).mockResolvedValue(makeZReport(12345))
    vi.mocked(api.closeShift).mockResolvedValue({
      shift: makeShift({ closed_at: new Date().toISOString(), counted_cash_cents: 12345, expected_cash_cents: 12345, variance_cents: 0 }),
      expected_cash_cents: 12345,
      variance_cents: 0,
      requires_approval: false,
    })
    renderClose()

    await screen.findByText('•••••')
    fireEvent.change(screen.getByLabelText(/counted cash/i), { target: { value: '123.45' } })
    fireEvent.click(screen.getByRole('button', { name: 'Close' }))

    expect(await screen.findByText('Drawer reconciled')).toBeInTheDocument()
    expect(screen.getAllByText('$123.45').length).toBeGreaterThan(0)
    expect(screen.queryByText('•••••')).not.toBeInTheDocument()
  })
})

describe('CloseShiftScreen — approve variance', () => {
  const closeResult: ShiftCloseResult = {
    shift: makeShift({ closed_at: new Date().toISOString(), counted_cash_cents: 12000, expected_cash_cents: 12500, variance_cents: -500 }),
    expected_cash_cents: 12500,
    variance_cents: -500,
    requires_approval: true,
  }

  async function closeDrawer() {
    fireEvent.change(await screen.findByLabelText(/counted cash/i), { target: { value: '120.00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Close' }))
    await screen.findByText('Drawer reconciled')
  }

  it('shows the plain approval-needed text and no button without the permission', async () => {
    vi.mocked(api.zReport).mockResolvedValue(makeZReport(12500))
    vi.mocked(api.closeShift).mockResolvedValue(closeResult)
    renderClose(() => false)

    await closeDrawer()

    expect(screen.getByText(/needs supervisor approval/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /approve variance/i })).not.toBeInTheDocument()
  })

  it('lets a supervisor approve, swapping the warning for an approved-by line', async () => {
    tokens.setStaffUser({ id: 'sup-1', name: 'Supervisor Sam', is_admin: false, permissions: ['shift.approve_variance'] })
    vi.mocked(api.zReport).mockResolvedValue(makeZReport(12500))
    vi.mocked(api.closeShift).mockResolvedValue(closeResult)
    vi.mocked(api.approveVariance).mockResolvedValue(
      makeShift({
        closed_at: new Date().toISOString(),
        variance_cents: -500,
        variance_approved_by: 'sup-1',
        variance_approved_at: new Date().toISOString(),
      }),
    )
    renderClose((permission) => permission === 'shift.approve_variance')

    await closeDrawer()

    const approveBtn = await screen.findByRole('button', { name: /approve variance/i })
    fireEvent.click(approveBtn)

    await waitFor(() => expect(api.approveVariance).toHaveBeenCalledWith('shift-1'))
    expect(await screen.findByText(/approved by supervisor sam/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /approve variance/i })).not.toBeInTheDocument()
    expect(screen.queryByText(/needs supervisor approval/i)).not.toBeInTheDocument()
  })
})
