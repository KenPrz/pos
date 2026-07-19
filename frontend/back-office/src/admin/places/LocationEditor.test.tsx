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
})
