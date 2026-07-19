// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SalesReportView } from './SalesReportView'
import { api, type SalesReport } from '../../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, reports: { ...actual.api.reports, sales: vi.fn() } },
  }
})

const DAY_REPORT: SalesReport = {
  basis: 'ledger',
  rows: [
    { bucket: '2026-07-01', orders_closed: 3, gross_cents: 1000, refunds_cents: 100, net_cents: 900 },
    { bucket: '2026-07-02', orders_closed: 5, gross_cents: 2000, refunds_cents: 200, net_cents: 1800 },
  ],
  totals: { orders_closed: 8, gross_cents: 3000, refunds_cents: 300, net_cents: 2700 },
}

const CATEGORY_REPORT: SalesReport = {
  basis: 'lines',
  rows: [{ bucket: 'Coffee', qty_sold: '12.000', line_total_cents: 4800 }],
  totals: { qty_sold: '12.000', line_total_cents: 4800 },
}

// `locationId` now arrives from the sidebar's location switcher (the frozen contract's
// named switcher-relocation exception) — the per-screen location select is gone, so the
// render helper passes the prop and returns a rerender hook for the prop-change test.
function renderView(locationId: string | null = 'loc-1') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const onUnauthorized = vi.fn()
  const view = (id: string | null) => (
    <QueryClientProvider client={client}>
      <SalesReportView locationId={id} onUnauthorized={onUnauthorized} />
    </QueryClientProvider>
  )
  const utils = render(view(locationId))
  return { rerenderWith: (id: string | null) => utils.rerender(view(id)) }
}

describe('SalesReportView', () => {
  it('refetches with the right params when the group-by tab changes', async () => {
    vi.mocked(api.reports.sales).mockResolvedValueOnce(DAY_REPORT).mockResolvedValueOnce(CATEGORY_REPORT)
    renderView()

    await waitFor(() => expect(api.reports.sales).toHaveBeenCalledTimes(1))
    const firstCall = vi.mocked(api.reports.sales).mock.calls[0][0]
    expect(firstCall).toMatchObject({ location_id: 'loc-1', group_by: 'day' })

    fireEvent.mouseDown(screen.getByRole('tab', { name: /^category$/i }))
    fireEvent.click(screen.getByRole('tab', { name: /^category$/i }))

    await waitFor(() => expect(api.reports.sales).toHaveBeenCalledTimes(2))
    const secondCall = vi.mocked(api.reports.sales).mock.calls[1][0]
    expect(secondCall).toMatchObject({
      location_id: 'loc-1',
      group_by: 'category',
      from: firstCall.from,
      to: firstCall.to,
    })
  })

  it('refetches with the new location when the sidebar switcher changes locationId', async () => {
    vi.mocked(api.reports.sales).mockResolvedValue(DAY_REPORT)
    const { rerenderWith } = renderView('loc-1')

    await waitFor(() => expect(api.reports.sales).toHaveBeenCalledTimes(1))
    expect(vi.mocked(api.reports.sales).mock.calls[0][0]).toMatchObject({ location_id: 'loc-1' })

    rerenderWith('loc-2')

    await waitFor(() => expect(api.reports.sales).toHaveBeenCalledTimes(2))
    expect(vi.mocked(api.reports.sales).mock.calls[1][0]).toMatchObject({ location_id: 'loc-2' })
  })

  it('sums the mocked rows in the totals row', async () => {
    vi.mocked(api.reports.sales).mockResolvedValue(DAY_REPORT)
    renderView()

    await waitFor(() => expect(screen.getByText('$30.00')).toBeInTheDocument())
    expect(screen.getByText('$3.00')).toBeInTheDocument()
    expect(screen.getByText('$27.00')).toBeInTheDocument()
  })

  it('labels the ledger basis so it is never conflated with the line-based mix', async () => {
    vi.mocked(api.reports.sales).mockResolvedValue(DAY_REPORT)
    renderView()

    await waitFor(() => expect(screen.getByText(/ledger/i)).toBeInTheDocument())
  })
})
