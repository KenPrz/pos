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

  it('shows the threshold so a supervisor can tell why a row qualifies', async () => {
    vi.spyOn(api.variances, 'list').mockResolvedValue(rows)
    renderSection()

    expect(await screen.findByText('Threshold')).toBeInTheDocument()
    // threshold_cents: 500, formatted through the same money helper as every other column.
    expect(screen.getByText('$5.00')).toBeInTheDocument()
  })

  it('tells the supervisor to approve from a DIFFERENT, still-open register', async () => {
    vi.spyOn(api.variances, 'list').mockResolvedValue(rows)
    renderSection()

    // The instruction: closing a shift revokes its own sessions, so approval has to come
    // from another still-open register at the same location.
    expect(
      await screen.findByText(/another still-open register.s session at the same location/i),
    ).toBeInTheDocument()
  })

  it('explains WHY: no till screen offers approval for a shift other than its own', async () => {
    vi.spyOn(api.variances, 'list').mockResolvedValue(rows)
    renderSection()

    // The reason the instruction above matters at all — without this clause the
    // guidance reads as a UI flow that doesn't exist. Deleting it should fail this test.
    expect(
      await screen.findByText(/no till screen anywhere offers an approve button for a shift other than its own/i),
    ).toBeInTheDocument()
  })

  it('shows an empty state when nothing is pending', async () => {
    vi.spyOn(api.variances, 'list').mockResolvedValue([])
    renderSection()

    expect(await screen.findByText(/no variances waiting/i)).toBeInTheDocument()
  })
})
