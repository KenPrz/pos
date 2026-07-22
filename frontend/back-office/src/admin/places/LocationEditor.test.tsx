// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ComponentProps } from 'react'
import { LocationEditor } from './LocationEditor'
import { api, type Location } from '../../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, locations: { ...actual.api.locations, update: vi.fn(), create: vi.fn() } },
  }
})

const LOCATION: Location = {
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

function renderEditor(props: Partial<ComponentProps<typeof LocationEditor>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const merged: ComponentProps<typeof LocationEditor> = {
    location: LOCATION,
    onDone: vi.fn(),
    onCancel: vi.fn(),
    onUnauthorized: vi.fn(),
    ...props,
  }
  render(
    <QueryClientProvider client={client}>
      <LocationEditor {...merged} />
    </QueryClientProvider>,
  )
  return merged
}

describe('LocationEditor', () => {
  it('saves only the changed field', async () => {
    vi.mocked(api.locations.update).mockResolvedValue({ ...LOCATION, name: 'Downtown Flagship' })
    renderEditor()

    fireEvent.change(screen.getByLabelText(/^name$/i), { target: { value: 'Downtown Flagship' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.locations.update).toHaveBeenCalledWith('loc-1', { name: 'Downtown Flagship' }))
  })

  it('rejects a timezone not on the IANA list instead of silently saving it', () => {
    renderEditor()

    fireEvent.change(screen.getByLabelText(/timezone/i), { target: { value: 'Nowhere/Imaginary' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(api.locations.update).not.toHaveBeenCalled()
    expect(screen.getByText(/pick a timezone from the list/i)).toBeInTheDocument()
  })

  // UI-rework addition (exception #3): the deactivate confirm moved from `window.confirm`
  // to `ConfirmDialog` — added coverage since the source always carried a
  // `window.confirm` call here but no prior test exercised it. Same copy, same
  // cancel-blocks/confirm-proceeds semantics.
  it('cancelling the deactivate ConfirmDialog blocks the save', () => {
    renderEditor()

    fireEvent.click(screen.getByLabelText(/^active$/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(
      screen.getByText('Deactivate Downtown? Its history stays, but staff can no longer sign in there.'),
    ).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(api.locations.update).not.toHaveBeenCalled()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('confirming the deactivate ConfirmDialog proceeds with the save', async () => {
    vi.mocked(api.locations.update).mockResolvedValue({ ...LOCATION, is_active: false })
    renderEditor()

    fireEvent.click(screen.getByLabelText(/^active$/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))
    fireEvent.click(screen.getByRole('button', { name: 'Deactivate' }))

    await waitFor(() => expect(api.locations.update).toHaveBeenCalledWith('loc-1', { is_active: false }))
  })

  // Per-location threshold overrides (RBAC v2 Task 11) — the VariantEditor cost-field
  // optional-numeric pattern: empty is a real "use the config default" choice (null on
  // the wire), a well-formed number saves, and a non-numeric value blocks save entirely
  // rather than silently dropping the override.
  it('saves an empty variance threshold as null', async () => {
    vi.mocked(api.locations.update).mockResolvedValue({ ...LOCATION, variance_approval_threshold_cents: null })
    renderEditor({ location: { ...LOCATION, variance_approval_threshold_cents: 2500 } })

    fireEvent.change(screen.getByLabelText(/variance approval threshold/i), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() =>
      expect(api.locations.update).toHaveBeenCalledWith('loc-1', { variance_approval_threshold_cents: null }),
    )
  })

  it('saves a well-formed variance threshold as integer cents', async () => {
    vi.mocked(api.locations.update).mockResolvedValue({ ...LOCATION, variance_approval_threshold_cents: 5000 })
    renderEditor()

    fireEvent.change(screen.getByLabelText(/variance approval threshold/i), { target: { value: '50.00' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() =>
      expect(api.locations.update).toHaveBeenCalledWith('loc-1', { variance_approval_threshold_cents: 5000 }),
    )
  })

  it('blocks save on a non-numeric variance threshold', () => {
    renderEditor()

    fireEvent.change(screen.getByLabelText(/variance approval threshold/i), { target: { value: 'abc' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(api.locations.update).not.toHaveBeenCalled()
    expect(screen.getByText(/enter a valid threshold/i)).toBeInTheDocument()
  })

  it('saves an empty low-stock threshold as null', async () => {
    vi.mocked(api.locations.update).mockResolvedValue({ ...LOCATION, low_stock_threshold: null })
    renderEditor({ location: { ...LOCATION, low_stock_threshold: '5.000' } })

    fireEvent.change(screen.getByLabelText(/low stock threshold/i), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.locations.update).toHaveBeenCalledWith('loc-1', { low_stock_threshold: null }))
  })

  it('saves a well-formed low-stock threshold as a decimal string, unparsed', async () => {
    vi.mocked(api.locations.update).mockResolvedValue({ ...LOCATION, low_stock_threshold: '5.000' })
    renderEditor()

    fireEvent.change(screen.getByLabelText(/low stock threshold/i), { target: { value: '5.000' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.locations.update).toHaveBeenCalledWith('loc-1', { low_stock_threshold: '5.000' }))
  })

  it('blocks save on a non-numeric low-stock threshold', () => {
    renderEditor()

    fireEvent.change(screen.getByLabelText(/low stock threshold/i), { target: { value: 'low' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(api.locations.update).not.toHaveBeenCalled()
    expect(screen.getByText(/enter a valid threshold/i)).toBeInTheDocument()
  })
})
