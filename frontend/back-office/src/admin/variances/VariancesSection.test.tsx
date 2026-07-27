// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { VariancesSection } from './VariancesSection'
import { api, type PendingVariance } from '../../lib/api'

const rows: PendingVariance[] = [
  {
    shift_id: 's-1',
    register_id: 'r-1',
    register_name: 'Till 2',
    location_id: 'loc-1',
    location_name: 'Manila Grocery',
    opened_by_name: 'Alice',
    opened_at: '2026-07-27T01:00:00Z',
    closed_at: '2026-07-27T09:14:00Z',
    expected_cash_cents: 10000,
    counted_cash_cents: 8800,
    variance_cents: -1200,
    threshold_cents: 500,
  },
]

function renderSection() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <VariancesSection onUnauthorized={() => {}} />
    </QueryClientProvider>,
  )
}

afterEach(cleanup)

describe('VariancesSection', () => {
  it('lists a pending variance with its register, opener and amount', async () => {
    vi.spyOn(api.variances, 'list').mockResolvedValue(rows)
    renderSection()

    expect(await screen.findByText('Till 2')).toBeInTheDocument()
    expect(screen.getByText('Alice')).toBeInTheDocument()
  })

  it('tells the supervisor to approve from a DIFFERENT register', async () => {
    vi.spyOn(api.variances, 'list').mockResolvedValue(rows)
    renderSection()

    // The whole point of the view: closing a shift signs its own register out, so the
    // listed register is the one place approval 401s.
    expect(await screen.findByText(/any register other than the one listed/i)).toBeInTheDocument()
  })

  it('shows an empty state when nothing is pending', async () => {
    vi.spyOn(api.variances, 'list').mockResolvedValue([])
    renderSection()

    expect(await screen.findByText(/no variances waiting/i)).toBeInTheDocument()
  })
})
