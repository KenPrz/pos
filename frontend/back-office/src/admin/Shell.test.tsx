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
vi.mock('./settings/SettingsSection', () => ({ SettingsSection: () => <div>Settings stub</div> }))

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
  variance_approval_threshold_cents: null,
  low_stock_threshold: null,
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

// The canonical, ordered admin-tier section list (RBAC v2 Task 6/11 brief) — the
// default for every test that isn't specifically exercising gating, so the pre-Task-11
// tests below keep seeing every nav item without having to name each permission.
const ALL_SECTIONS = [
  'catalog.manage',
  'user.manage',
  'location.manage',
  'register.enroll',
  'audit.view',
  'report.sales.view',
  'report.stock.view',
  'settings.manage',
  'role.manage',
]

function renderShell(overview: TodayOverview, onLogout = vi.fn(), sections: string[] = ALL_SECTIONS) {
  vi.mocked(api.today.overview).mockResolvedValue(overview)
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <Shell
        user={{ id: 'u-1', name: 'Alex Admin', email: 'alex@pos.test', is_admin: true }}
        sections={sections}
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

  // Section gating (RBAC v2 Task 11) — the exact section-permission → sidebar mapping
  // from the brief: a session scoped to only `report.sales.view` should see Today (always
  // visible) plus Reports, and nothing else.
  it('shows only Today and Reports for a session scoped to report.sales.view', () => {
    renderShell(NO_ATTENTION, vi.fn(), ['report.sales.view'])

    expect(screen.getByRole('button', { name: 'Today' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reports' })).toBeInTheDocument()

    for (const label of ['Catalog', 'Users', 'Locations & Registers', 'Audit', 'Settings']) {
      expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    }
  })

  it('shows all seven nav items, including Settings, for the full admin section list', () => {
    renderShell(NO_ATTENTION, vi.fn(), ALL_SECTIONS)

    for (const label of ['Today', 'Catalog', 'Users', 'Locations & Registers', 'Reports', 'Audit', 'Settings']) {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument()
    }
  })

  // A hidden section has no nav item at all — there is nothing to click, so it cannot be
  // navigated to, rather than being reachable-but-rejected.
  it('renders no nav item for a section whose permission is not held, so it cannot be clicked', () => {
    renderShell(NO_ATTENTION, vi.fn(), ['report.sales.view'])

    expect(screen.queryByRole('button', { name: 'Settings' })).not.toBeInTheDocument()
    expect(screen.queryByText('Settings stub')).not.toBeInTheDocument()
  })
})
