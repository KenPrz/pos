// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { EndOfDaySection } from './EndOfDaySection'
import { api, type BusinessDayRecord, type BusinessDayStatus } from '../../lib/api'
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
    api: { ...actual.api, day: { ...actual.api.day, get: vi.fn() } },
  }
})

const OPEN_STATUS: BusinessDayStatus = {
  business_date: '2026-07-23',
  closable: false,
  open_shifts: [{ register_id: 'r1', register_name: 'Till 1', shift_id: 's1', opened_by_name: 'Ana' }],
  open_orders_count: 0,
  unapproved_variance_count: 0,
  totals: {
    gross_sales_cents: 0,
    refunds_cents: 0,
    net_sales_cents: 0,
    tax_cents: 0,
    expected_cash_cents: 0,
    counted_cash_cents: 0,
    variance_cents: 0,
    shift_count: 1,
  },
  record: null,
}

const CLOSED_RECORD: BusinessDayRecord = {
  id: 'd1',
  location_id: 'loc1',
  business_date: '2026-07-23',
  closed_by: 'u1',
  closed_at: '2026-07-23T12:00:00Z',
  gross_sales_cents: 100000,
  refunds_cents: 0,
  net_sales_cents: 100000,
  tax_cents: 12000,
  expected_cash_cents: 50000,
  counted_cash_cents: 50000,
  variance_cents: 0,
  shift_count: 1,
  deposit_cents: 5000,
  checklist: { cash_drop_confirmed: true, spoilage_note: null, next_day_note: null },
  note: null,
  reopened_at: null,
  reopened_by: null,
}

const CLOSED_STATUS: BusinessDayStatus = {
  ...OPEN_STATUS,
  closable: true,
  open_shifts: [],
  record: CLOSED_RECORD,
}

function renderSection(locationId: string | null, isAdmin = false, onUnauthorized = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <EndOfDaySection locationId={locationId} isAdmin={isAdmin} onUnauthorized={onUnauthorized} />
    </QueryClientProvider>,
  )
}

describe('EndOfDaySection', () => {
  it('prompts to pick a location when none is selected', () => {
    renderSection(null)

    expect(screen.getByText('Select a location')).toBeInTheDocument()
    expect(api.day.get).not.toHaveBeenCalled()
  })

  it('shows an open-shift blocker and disables Close day', async () => {
    vi.mocked(api.day.get).mockResolvedValue(OPEN_STATUS)
    renderSection('loc1')

    expect(await screen.findByText(/open shift\(s\) — close them first/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /close day/i })).toBeDisabled()
  })

  it('shows "Day closed" and hides the Reopen control for a non-admin', async () => {
    vi.mocked(api.day.get).mockResolvedValue(CLOSED_STATUS)
    renderSection('loc1', false)

    expect(await screen.findByText('Day closed')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /reopen day/i })).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/reason for reopening/i)).not.toBeInTheDocument()
  })

  it('shows the Reopen control for an admin on a closed day', async () => {
    vi.mocked(api.day.get).mockResolvedValue(CLOSED_STATUS)
    renderSection('loc1', true)

    expect(await screen.findByText('Day closed')).toBeInTheDocument()
    expect(screen.getByLabelText(/reason for reopening/i)).toBeInTheDocument()
    // Empty reason keeps the reopen button disabled until a reason is typed.
    expect(screen.getByRole('button', { name: /reopen day/i })).toBeDisabled()
  })
})
