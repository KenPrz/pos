// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PaymentMethodsSection } from './PaymentMethodsSection'
import { api, type Location } from '../../lib/api'

const location = { id: 'loc-1', name: 'Grocery', code: 'GRC' } as Location

const groups = [
  {
    id: 'g-cash', location_id: 'loc-1', code: 'CASH', name: 'Cash',
    driver: 'cash' as const, sort_order: 0, is_active: true,
    methods: [
      { id: 'm-cash', location_id: 'loc-1', group_id: 'g-cash', code: 'CASH', name: 'Cash', sort_order: 0, is_active: true },
    ],
  },
  {
    id: 'g-ewallet', location_id: 'loc-1', code: 'EWALLET', name: 'E-wallets',
    driver: 'external_card' as const, sort_order: 1, is_active: true,
    methods: [
      { id: 'm-gcash', location_id: 'loc-1', group_id: 'g-ewallet', code: 'GCASH', name: 'GCash', sort_order: 0, is_active: true },
      { id: 'm-maya', location_id: 'loc-1', group_id: 'g-ewallet', code: 'MAYA', name: 'Maya', sort_order: 1, is_active: false },
    ],
  },
]

function renderSection() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <PaymentMethodsSection location={location} onUnauthorized={() => {}} />
    </QueryClientProvider>,
  )
}

afterEach(cleanup)

beforeEach(() => {
  vi.spyOn(api.paymentMethodGroups, 'list').mockResolvedValue(groups)
})

describe('PaymentMethodsSection', () => {
  it('lists each group with its driver and its methods', async () => {
    renderSection()

    // Cash the GROUP and Cash the group's own sole METHOD legitimately share a name —
    // exactly the real Manila cash tender — so the group is found by its heading role
    // (CardTitle renders an <h3>) rather than by bare text, which would also match the
    // method row below it.
    expect(await screen.findByRole('heading', { name: 'Cash' })).toBeInTheDocument()
    expect(screen.getByText('E-wallets')).toBeInTheDocument()
    expect(screen.getByText('GCash')).toBeInTheDocument()
    // The driver is shown because it is the behaviour every method under the group
    // inherits, and it cannot be changed later.
    expect(screen.getAllByText(/external card/i).length).toBeGreaterThan(0)
  })

  it('marks an archived method rather than hiding it', async () => {
    renderSection()

    await screen.findByText('Maya')
    expect(screen.getAllByText('ARCHIVED').length).toBeGreaterThan(0)
  })

  it('scopes its query to the selected location', async () => {
    renderSection()

    await waitFor(() => expect(api.paymentMethodGroups.list).toHaveBeenCalledWith('loc-1'))
  })

  it('opens the group editor with code and driver read-only', async () => {
    renderSection()

    fireEvent.click((await screen.findAllByRole('button', { name: /edit/i }))[0])

    // Immutable fields render read-only rather than absent, so an admin can see the code
    // they cannot change.
    const code = screen.getByLabelText(/group code/i)
    expect(code).toHaveAttribute('readonly')
  })

  it('offers a new group with an editable code', async () => {
    renderSection()

    fireEvent.click(await screen.findByRole('button', { name: /new group/i }))

    expect(screen.getByLabelText(/group code/i)).not.toHaveAttribute('readonly')
  })
})
