// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { TodaySection } from './TodaySection'
import { ApiError, api, type AuditPage, type Register, type SalesReport, type StockReport } from '../../lib/api'
import { setCurrency } from '../../lib/currency'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
  // Explicit, not relying on lib/currency's pre-load default: this suite never logs in
  // (the thing that would normally set it for real — see api.ts's login).
  setCurrency('USD')
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      reports: { ...actual.api.reports, sales: vi.fn(), stock: vi.fn() },
      registers: { ...actual.api.registers, list: vi.fn() },
      audit: { ...actual.api.audit, list: vi.fn() },
    },
  }
})

const ALL_SECTIONS = ['report.sales.view', 'report.stock.view', 'register.enroll', 'audit.view']

const EMPTY_SALES: SalesReport = {
  rows: [],
  totals: { orders_closed: 0, gross_cents: 0, refunds_cents: 0, net_cents: 0 },
  basis: 'ledger',
}

const BUSY_SALES: SalesReport = {
  rows: [{ bucket: '2026-07-19', orders_closed: 12, gross_cents: 125000, refunds_cents: 500, net_cents: 124500 }],
  totals: { orders_closed: 12, gross_cents: 125000, refunds_cents: 500, net_cents: 124500 },
  basis: 'ledger',
}

const EMPTY_STOCK: StockReport = { rows: [] }
const LOW_STOCK: StockReport = { rows: [{ variant_id: 'v-1', sku: 'CFE-01', name: 'Whole milk', qty: '2.000', low: true }] }

const NO_REGISTERS: Register[] = []
const INACTIVE_REGISTER: Register[] = [
  {
    id: 'reg-1',
    location_id: 'loc-1',
    name: 'Back till',
    mode: 'retail',
    is_active: false,
    activation: { state: 'not_enrolled', code_expires_at: null },
  },
]

const EMPTY_AUDIT: AuditPage = { rows: [], page: 1, has_more: false }
const BUSY_AUDIT: AuditPage = {
  rows: [
    {
      id: 'audit-1',
      created_at: '2026-07-19T09:00:00Z',
      action: 'admin.variant.update',
      entity_type: 'ProductVariant',
      entity_id: 'v-1',
      user_name: 'Alex Admin',
      register_name: null,
      payload: null,
    },
  ],
  page: 1,
  has_more: false,
}

/** Every widget defaults to its "nothing going on" fixture unless a test overrides one. */
function mockDefaults() {
  vi.mocked(api.reports.sales).mockResolvedValue(EMPTY_SALES)
  vi.mocked(api.reports.stock).mockResolvedValue(EMPTY_STOCK)
  vi.mocked(api.registers.list).mockResolvedValue(NO_REGISTERS)
  vi.mocked(api.audit.list).mockResolvedValue(EMPTY_AUDIT)
}

function renderSection(
  locationId: string | null,
  sections: string[] = ALL_SECTIONS,
  onUnauthorized = vi.fn(),
) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <TodaySection locationId={locationId} sections={sections} onUnauthorized={onUnauthorized} />
    </QueryClientProvider>,
  )
}

describe('TodaySection', () => {
  it('shows an empty state instead of fetching when no location is selected', () => {
    mockDefaults()
    renderSection(null)

    expect(screen.getByText('No location selected')).toBeInTheDocument()
    expect(api.reports.sales).not.toHaveBeenCalled()
    expect(api.reports.stock).not.toHaveBeenCalled()
    expect(api.audit.list).not.toHaveBeenCalled()
  })

  it('renders the KPI row, needs-attention panel, and recent activity for a full-permission session', async () => {
    vi.mocked(api.reports.sales).mockResolvedValue(BUSY_SALES)
    vi.mocked(api.reports.stock).mockResolvedValue(LOW_STOCK)
    vi.mocked(api.registers.list).mockResolvedValue(INACTIVE_REGISTER)
    vi.mocked(api.audit.list).mockResolvedValue(BUSY_AUDIT)
    renderSection('loc-1')

    expect(await screen.findByText('$1,245.00')).toBeInTheDocument()
    expect(screen.getByText('Net sales today')).toBeInTheDocument()
    expect(screen.getByText('Orders closed')).toBeInTheDocument()
    expect(screen.getByText('12')).toBeInTheDocument()
    expect(screen.getByText('Refunds today')).toBeInTheDocument()
    expect(screen.getByText('$5.00')).toBeInTheDocument()

    expect(screen.getByText('Needs attention')).toBeInTheDocument()
    expect(screen.getByText(/Whole milk/)).toBeInTheDocument()
    expect(screen.getByText('Back till')).toBeInTheDocument()
    expect(screen.getByText('Inactive')).toBeInTheDocument()

    expect(screen.getByText('admin.variant.update')).toBeInTheDocument()
    expect(screen.getByText('Alex Admin')).toBeInTheDocument()
  })

  it('shows "All clear" when nothing needs attention', async () => {
    mockDefaults()
    renderSection('loc-1')

    expect(await screen.findByText('All clear')).toBeInTheDocument()
  })

  // RBAC v2 Task 11 — the coordinator's binding requirement: a partially-permissioned
  // session must see only the widgets it holds a permission for, with NO request fired
  // (let alone a 403 surfaced) for the ones it doesn't.
  it('a session scoped to only report.sales.view shows sales tiles and nothing else, with no error surfaced', async () => {
    vi.mocked(api.reports.sales).mockResolvedValue(BUSY_SALES)
    renderSection('loc-1', ['report.sales.view'])

    expect(await screen.findByText('Net sales today')).toBeInTheDocument()
    expect(screen.getByText('Orders closed')).toBeInTheDocument()
    expect(screen.getByText('Refunds today')).toBeInTheDocument()

    // No stock tile, no needs-attention panel, no recent-activity strip.
    expect(screen.queryByText('Low stock')).not.toBeInTheDocument()
    expect(screen.queryByText('Needs attention')).not.toBeInTheDocument()
    expect(screen.queryByText(/recent activity/i)).not.toBeInTheDocument()

    // The widgets this session isn't entitled to never even fired a request.
    expect(api.reports.stock).not.toHaveBeenCalled()
    expect(api.registers.list).not.toHaveBeenCalled()
    expect(api.audit.list).not.toHaveBeenCalled()

    // No 403/error text anywhere on the page.
    expect(screen.queryByText(/could not load/i)).not.toBeInTheDocument()
  })

  it('a session scoped to only report.stock.view shows the low-stock tile and needs-attention rows, no sales tiles', async () => {
    vi.mocked(api.reports.stock).mockResolvedValue(LOW_STOCK)
    renderSection('loc-1', ['report.stock.view'])

    // "Low stock" is both the KPI label and the StatusPill text once there's a row.
    expect(await screen.findAllByText('Low stock')).toHaveLength(2)
    expect(screen.getByText('Needs attention')).toBeInTheDocument()
    expect(screen.getByText(/Whole milk/)).toBeInTheDocument()

    expect(screen.queryByText('Net sales today')).not.toBeInTheDocument()
    expect(screen.queryByText(/recent activity/i)).not.toBeInTheDocument()

    expect(api.reports.sales).not.toHaveBeenCalled()
    expect(api.registers.list).not.toHaveBeenCalled()
    expect(api.audit.list).not.toHaveBeenCalled()
  })

  it('a session scoped to only audit.view shows the recent-activity strip and nothing else', async () => {
    vi.mocked(api.audit.list).mockResolvedValue(BUSY_AUDIT)
    renderSection('loc-1', ['audit.view'])

    expect(await screen.findByText('admin.variant.update')).toBeInTheDocument()

    expect(screen.queryByText('Net sales today')).not.toBeInTheDocument()
    expect(screen.queryByText('Low stock')).not.toBeInTheDocument()
    expect(screen.queryByText('Needs attention')).not.toBeInTheDocument()

    expect(api.reports.sales).not.toHaveBeenCalled()
    expect(api.reports.stock).not.toHaveBeenCalled()
    expect(api.registers.list).not.toHaveBeenCalled()
  })

  it('a session holding none of the four reporting permissions gets a "nothing to show" empty state', () => {
    renderSection('loc-1', [])

    expect(screen.getByText('Nothing to show')).toBeInTheDocument()
    expect(api.reports.sales).not.toHaveBeenCalled()
    expect(api.reports.stock).not.toHaveBeenCalled()
    expect(api.registers.list).not.toHaveBeenCalled()
    expect(api.audit.list).not.toHaveBeenCalled()
  })

  // A genuine (non-permission) failure on one permitted widget must not blank out a
  // sibling widget that succeeded — the isolation cuts both ways.
  it('a genuine error on one widget surfaces inline without blocking a sibling widget that succeeded', async () => {
    vi.mocked(api.reports.sales).mockRejectedValue(new ApiError('server_error', 'boom', 500))
    vi.mocked(api.reports.stock).mockResolvedValue(LOW_STOCK)
    renderSection('loc-1', ['report.sales.view', 'report.stock.view'])

    expect(await screen.findByText(/could not load today's sales/i)).toBeInTheDocument()
    // "Low stock" is both the KPI label and the StatusPill text once there's a row.
    expect(screen.getAllByText('Low stock')).toHaveLength(2)
    expect(screen.getByText(/Whole milk/)).toBeInTheDocument()
  })

  it('signs out on a 401 from any permitted widget', async () => {
    const onUnauthorized = vi.fn()
    mockDefaults()
    vi.mocked(api.audit.list).mockRejectedValue(new ApiError('unauthenticated', 'nope', 401))
    renderSection('loc-1', ALL_SECTIONS, onUnauthorized)

    await waitFor(() => expect(onUnauthorized).toHaveBeenCalled())
  })
})
