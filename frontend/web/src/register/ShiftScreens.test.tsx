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
import { setCurrency } from '../lib/currency'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
  // Explicit, not relying on lib/currency's pre-load default: this suite never fetches
  // the catalog (the thing that would normally set it), so pin it the way a real till
  // would have it set by the time it's showing these amounts.
  setCurrency('USD')
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

// Accepts either the plain expected-cash number the earlier tests pass, or a partial
// ZReport override (Task 20's group-rollup tests need more than one method/group active).
function makeZReport(overrides: number | Partial<ZReport> = {}): ZReport {
  const expectedCashCents = typeof overrides === 'number' ? overrides : (overrides.expected_cash_cents ?? 0)
  const base: ZReport = {
    shift: makeShift(),
    sales_by_method: { CASH: expectedCashCents },
    sales_by_group: { CASH: expectedCashCents },
    refunds_by_method: {},
    refunds_by_group: {},
    movements: { paid_in: 0, payout: 0, drop: 0 },
    orders_closed: 3,
    orders_voided: 0,
    orders_split: 2,
    expected_cash_cents: expectedCashCents,
  }
  return typeof overrides === 'number' ? base : { ...base, ...overrides }
}

// The Z-report panel only renders once the drawer is closed (the close revokes the
// register's staff sessions, so it's fetched beforehand — see ZReportPanel's own doc
// comment). This helper drives a full close so the group-rollup rows in the panel are
// actually on screen, rather than duplicating that flow in every rollup test.
async function renderZReport(report: ZReport) {
  vi.mocked(api.zReport).mockResolvedValue(report)
  vi.mocked(api.closeShift).mockResolvedValue({
    shift: makeShift({
      closed_at: new Date().toISOString(),
      counted_cash_cents: report.expected_cash_cents,
      expected_cash_cents: report.expected_cash_cents,
      variance_cents: 0,
    }),
    expected_cash_cents: report.expected_cash_cents,
    variance_cents: 0,
    requires_approval: false,
  })
  renderClose()
  fireEvent.change(await screen.findByLabelText(/counted cash/i), { target: { value: '0.00' } })
  fireEvent.click(screen.getByRole('button', { name: 'Close' }))
  await screen.findByText('Drawer reconciled')
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
    expect((await screen.findByText('Orders split')).nextSibling).toHaveTextContent('2')
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

  it('lets a supervisor approve, swapping the warning for an approved-by line driven by the response', async () => {
    // Session name and response fields are deliberately made to differ from each other
    // (a fixed, distinctive timestamp the session couldn't know) so a test that only
    // checked the session-derived name couldn't mask a bug in what actually gates the
    // line — the approved_at TEXT asserted below only exists in the mocked response.
    tokens.setStaffUser({ id: 'sup-1', name: 'Supervisor Sam', is_admin: false, permissions: ['shift.approve_variance'] })
    const approvedAt = '2026-07-18T15:30:00.000Z'
    vi.mocked(api.zReport).mockResolvedValue(makeZReport(12500))
    vi.mocked(api.closeShift).mockResolvedValue(closeResult)
    vi.mocked(api.approveVariance).mockResolvedValue(
      makeShift({
        closed_at: new Date().toISOString(),
        variance_cents: -500,
        variance_approved_by: 'sup-1',
        variance_approved_at: approvedAt,
      }),
    )
    renderClose((permission) => permission === 'shift.approve_variance')

    await closeDrawer()

    const approveBtn = await screen.findByRole('button', { name: /approve variance/i })
    fireEvent.click(approveBtn)

    await waitFor(() => expect(api.approveVariance).toHaveBeenCalledWith('shift-1'))
    // The timestamp text only exists if it was read from the mocked response, not from
    // anything the session/tokens mock could have supplied.
    expect(await screen.findByText(new RegExp(new Date(approvedAt).toLocaleTimeString()))).toBeInTheDocument()
    expect(screen.getByText(/approved by supervisor sam/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /approve variance/i })).not.toBeInTheDocument()
    expect(screen.queryByText(/needs supervisor approval/i)).not.toBeInTheDocument()
  })

  it('does not swap to the approved-by line unless the response itself carries variance_approved_at', async () => {
    // Guards against gating on "got any response back" instead of the authoritative
    // field — a response that (hypothetically) omitted variance_approved_at must leave
    // the M4 warning/button in place, not silently treat the click as having succeeded.
    vi.mocked(api.zReport).mockResolvedValue(makeZReport(12500))
    vi.mocked(api.closeShift).mockResolvedValue(closeResult)
    vi.mocked(api.approveVariance).mockResolvedValue(
      makeShift({ closed_at: new Date().toISOString(), variance_cents: -500, variance_approved_by: null, variance_approved_at: null }),
    )
    renderClose(() => true)

    await closeDrawer()
    fireEvent.click(await screen.findByRole('button', { name: /approve variance/i }))

    await waitFor(() => expect(api.approveVariance).toHaveBeenCalledWith('shift-1'))
    expect(screen.queryByText(/approved by/i)).not.toBeInTheDocument()
    expect(screen.getByText(/needs supervisor approval/i)).toBeInTheDocument()
  })
})

describe('CloseShiftScreen — Z-report group rollup', () => {
  it('rolls the drawer up by group when the location has more than one', async () => {
    // CARD and EWALLET share the external_card driver — the group rollup is the only place
    // a supervisor sees them apart, which is the whole reason groups exist.
    await renderZReport(makeZReport({
      sales_by_method: { CASH: 1000, VISA: 2000, GCASH: 3000 },
      sales_by_group: { CASH: 1000, CARD: 2000, EWALLET: 3000 },
    }))

    expect(await screen.findByText('Sales by group — EWALLET')).toBeInTheDocument()
    expect(screen.getByText('Sales by group — CARD')).toBeInTheDocument()
  })

  it('omits the group rollup when there is only one group', async () => {
    await renderZReport(makeZReport({
      sales_by_method: { CASH: 1000 },
      sales_by_group: { CASH: 1000 },
    }))

    expect(await screen.findByText('Sales — CASH')).toBeInTheDocument()
    expect(screen.queryByText(/Sales by group/)).not.toBeInTheDocument()
  })
})
