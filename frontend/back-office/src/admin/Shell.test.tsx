// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Shell } from './Shell'
import { api, type TodayOverview } from '../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

// The other five sections have their own deep query chains (Task 3-5 territory) —
// stubbed out here so this file only exercises the shell/nav rebuild itself.
vi.mock('./catalog/CatalogSection', () => ({ CatalogSection: () => <div>Catalog stub</div> }))
vi.mock('./users/UsersSection', () => ({ UsersSection: () => <div>Users stub</div> }))
vi.mock('./places/PlacesSection', () => ({ PlacesSection: () => <div>Places stub</div> }))
vi.mock('./reports/ReportsSection', () => ({ ReportsSection: () => <div>Reports stub</div> }))
vi.mock('./audit/AuditSection', () => ({ AuditSection: () => <div>Audit stub</div> }))

vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, today: { ...actual.api.today, overview: vi.fn() } },
  }
})

const LOCATION = {
  id: 'loc-1',
  code: 'DT',
  name: 'Downtown',
  timezone: 'America/Chicago',
  prices_include_tax: false,
  receipt_header: null,
  receipt_footer: null,
  is_active: true,
}

const NO_ATTENTION: TodayOverview = {
  sales: { rows: [], totals: { orders_closed: 0, gross_cents: 0, refunds_cents: 0, net_cents: 0 }, basis: 'ledger' },
  stock: { rows: [] },
  registers: [],
  audit: { rows: [], page: 1, has_more: false },
}

const LOW_STOCK: TodayOverview = {
  ...NO_ATTENTION,
  stock: {
    rows: [
      { variant_id: 'v-1', sku: 'CFE-01', name: 'Whole milk', qty: '1.000', low: true },
      { variant_id: 'v-2', sku: 'CFE-02', name: 'Oat milk', qty: '0.000', low: true },
    ],
  },
}

function renderShell(overview: TodayOverview, onLogout = vi.fn()) {
  vi.mocked(api.today.overview).mockResolvedValue(overview)
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <Shell
        user={{ id: 'u-1', name: 'Alex Admin', email: 'alex@pos.test', is_admin: true }}
        onLogout={onLogout}
        onUnauthorized={vi.fn()}
        location={LOCATION}
        locations={[LOCATION]}
        onLocationChange={vi.fn()}
      />
    </QueryClientProvider>,
  )
}

describe('Shell', () => {
  it('renders the five existing section labels byte-identical, plus Today', () => {
    renderShell(NO_ATTENTION)

    for (const label of ['Today', 'Catalog', 'Users', 'Locations & Registers', 'Reports', 'Audit']) {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument()
    }
  })

  it('groups the rail under sentence-case Operations/Insights eyebrows', () => {
    renderShell(NO_ATTENTION)

    expect(screen.getByText('Operations')).toBeInTheDocument()
    expect(screen.getByText('Insights')).toBeInTheDocument()
  })

  it('defaults to Today as the active, rendered section', () => {
    renderShell(NO_ATTENTION)

    expect(screen.getByRole('button', { name: 'Today' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByText("What's happening at this location right now.")).toBeInTheDocument()
  })

  it('navigates to a section on click', () => {
    renderShell(NO_ATTENTION)

    fireEvent.click(screen.getByRole('button', { name: 'Catalog' }))

    expect(screen.getByText('Catalog stub')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Catalog' })).toHaveAttribute('aria-current', 'page')
  })

  it('fires onLogout when Sign out is pressed', () => {
    const onLogout = vi.fn()
    renderShell(NO_ATTENTION, onLogout)

    fireEvent.click(screen.getByRole('button', { name: 'Sign out' }))

    expect(onLogout).toHaveBeenCalledTimes(1)
  })

  it('shows no count badge on Today when nothing is low on stock', () => {
    renderShell(NO_ATTENTION)

    const todayItem = screen.getByRole('button', { name: 'Today' }).closest('li') as HTMLElement
    expect(within(todayItem).queryByText(/^\d+$/)).not.toBeInTheDocument()
  })

  it('badges Today with the low-stock count once the composed overview has rows', async () => {
    renderShell(LOW_STOCK)

    const todayItem = screen.getByRole('button', { name: 'Today' }).closest('li') as HTMLElement
    expect(await within(todayItem).findByText('2')).toBeInTheDocument()
  })
})
