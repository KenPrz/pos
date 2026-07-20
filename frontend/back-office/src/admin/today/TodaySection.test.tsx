// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { TodaySection } from './TodaySection'
import { ApiError, api, type TodayOverview } from '../../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, today: { ...actual.api.today, overview: vi.fn() } },
  }
})

const EMPTY_OVERVIEW: TodayOverview = {
  sales: { rows: [], totals: { orders_closed: 0, gross_cents: 0, refunds_cents: 0, net_cents: 0 }, basis: 'ledger' },
  stock: { rows: [] },
  registers: [{ id: 'reg-1', location_id: 'loc-1', name: 'Front till', mode: 'retail', is_active: true }],
  audit: { rows: [], page: 1, has_more: false },
}

const BUSY_OVERVIEW: TodayOverview = {
  sales: {
    rows: [{ bucket: '2026-07-19', orders_closed: 12, gross_cents: 125000, refunds_cents: 500, net_cents: 124500 }],
    totals: { orders_closed: 12, gross_cents: 125000, refunds_cents: 500, net_cents: 124500 },
    basis: 'ledger',
  },
  stock: { rows: [{ variant_id: 'v-1', sku: 'CFE-01', name: 'Whole milk', qty: '2.000', low: true }] },
  registers: [{ id: 'reg-1', location_id: 'loc-1', name: 'Back till', mode: 'retail', is_active: false }],
  audit: {
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
  },
}

function renderSection(locationId: string | null, onUnauthorized = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <TodaySection locationId={locationId} onUnauthorized={onUnauthorized} />
    </QueryClientProvider>,
  )
}

describe('TodaySection', () => {
  it('shows an empty state instead of fetching when no location is selected', () => {
    renderSection(null)

    expect(screen.getByText('No location selected')).toBeInTheDocument()
    expect(api.today.overview).not.toHaveBeenCalled()
  })

  it('renders the KPI row from the composed overview', async () => {
    vi.mocked(api.today.overview).mockResolvedValue(BUSY_OVERVIEW)
    renderSection('loc-1')

    expect(await screen.findByText('$1,245.00')).toBeInTheDocument()
    expect(api.today.overview).toHaveBeenCalledWith('loc-1')
    expect(screen.getByText('Net sales today')).toBeInTheDocument()
    expect(screen.getByText('Orders closed')).toBeInTheDocument()
    expect(screen.getByText('12')).toBeInTheDocument()
    expect(screen.getByText('Refunds today')).toBeInTheDocument()
    expect(screen.getByText('$5.00')).toBeInTheDocument()
    // "Low stock" is BOTH the KPI label and the StatusPill text in the attention panel
    // once there's a low-stock row, so this asserts the label's presence via count
    // rather than a single (necessarily ambiguous) getByText.
    expect(screen.getAllByText('Low stock').length).toBeGreaterThanOrEqual(1)
  })

  it('lists low-stock rows and inactive registers under Needs attention', async () => {
    vi.mocked(api.today.overview).mockResolvedValue(BUSY_OVERVIEW)
    renderSection('loc-1')

    expect(await screen.findByText('Needs attention')).toBeInTheDocument()
    expect(screen.getByText(/Whole milk/)).toBeInTheDocument()
    expect(screen.getByText('Back till')).toBeInTheDocument()
    // Two "Low stock" texts on screen: the KPI card label and the row's StatusPill.
    expect(screen.getAllByText('Low stock')).toHaveLength(2)
    expect(screen.getByText('Inactive')).toBeInTheDocument()
  })

  it('shows "All clear" when nothing needs attention', async () => {
    vi.mocked(api.today.overview).mockResolvedValue(EMPTY_OVERVIEW)
    renderSection('loc-1')

    expect(await screen.findByText('All clear')).toBeInTheDocument()
  })

  it('renders the recent-activity table from the first audit page', async () => {
    vi.mocked(api.today.overview).mockResolvedValue(BUSY_OVERVIEW)
    renderSection('loc-1')

    expect(await screen.findByText('admin.variant.update')).toBeInTheDocument()
    expect(screen.getByText('Alex Admin')).toBeInTheDocument()
  })

  it('signs out on a 401 from the composed overview', async () => {
    const onUnauthorized = vi.fn()
    vi.mocked(api.today.overview).mockRejectedValue(new ApiError('unauthenticated', 'nope', 401))
    renderSection('loc-1', onUnauthorized)

    await waitFor(() => expect(onUnauthorized).toHaveBeenCalled())
  })
})
