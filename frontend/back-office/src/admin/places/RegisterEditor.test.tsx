// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ComponentProps } from 'react'
import { RegisterEditor } from './RegisterEditor'
import { api, type Location, type Register } from '../../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      registers: { ...actual.api.registers, update: vi.fn(), create: vi.fn(), reissueToken: vi.fn() },
    },
  }
})

const LOCATIONS: Location[] = [
  { id: 'loc-1', code: 'DT', name: 'Downtown', timezone: 'America/Chicago', prices_include_tax: false, receipt_header: null, receipt_footer: null, is_active: true },
]

const REGISTER: Register = { id: 'reg-1', location_id: 'loc-1', name: 'Front counter', mode: 'retail', is_active: true }

function renderEditor(props: Partial<ComponentProps<typeof RegisterEditor>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const merged: ComponentProps<typeof RegisterEditor> = {
    register: REGISTER,
    locations: LOCATIONS,
    onDone: vi.fn(),
    onCancel: vi.fn(),
    onUnauthorized: vi.fn(),
    ...props,
  }
  render(
    <QueryClientProvider client={client}>
      <RegisterEditor {...merged} />
    </QueryClientProvider>,
  )
  return merged
}

describe('RegisterEditor', () => {
  it('saves only the changed field', async () => {
    vi.mocked(api.registers.update).mockResolvedValue({ ...REGISTER, name: 'Counter 2' })
    renderEditor()

    fireEvent.change(screen.getByLabelText(/^name$/i), { target: { value: 'Counter 2' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.registers.update).toHaveBeenCalledWith('reg-1', { name: 'Counter 2' }))
  })

  it('switches mode via the Retail/Food toggle', async () => {
    vi.mocked(api.registers.update).mockResolvedValue({ ...REGISTER, mode: 'food' })
    renderEditor()

    fireEvent.click(screen.getByRole('button', { name: 'Food' }))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.registers.update).toHaveBeenCalledWith('reg-1', { mode: 'food' }))
  })

  // UI-rework addition (exception #3): the deactivate confirm moved from `window.confirm`
  // to `ConfirmDialog` — added coverage since the source always carried a
  // `window.confirm` call here but no prior test exercised it. Same copy, same
  // cancel-blocks/confirm-proceeds semantics.
  it('cancelling the deactivate ConfirmDialog blocks the save', () => {
    renderEditor()

    fireEvent.click(screen.getByLabelText(/^active$/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(screen.getByText('Deactivate Front counter? It can no longer clock in a shift.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(api.registers.update).not.toHaveBeenCalled()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('confirming the deactivate ConfirmDialog proceeds with the save', async () => {
    vi.mocked(api.registers.update).mockResolvedValue({ ...REGISTER, is_active: false })
    renderEditor()

    fireEvent.click(screen.getByLabelText(/^active$/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))
    fireEvent.click(screen.getByRole('button', { name: 'Deactivate' }))

    await waitFor(() => expect(api.registers.update).toHaveBeenCalledWith('reg-1', { is_active: false }))
  })

  // UI-rework addition (exception #3): the reissue-token warning moved from
  // `window.confirm` to `ConfirmDialog` — added coverage since the source always
  // carried a `window.confirm` call here but no prior test exercised it. Same copy,
  // same cancel-blocks/confirm-proceeds semantics.
  it('cancelling the reissue ConfirmDialog does not rotate the token', () => {
    renderEditor()

    fireEvent.click(screen.getByRole('button', { name: /reissue token/i }))

    expect(
      screen.getByText("Reissue Front counter's token? The current till goes dark immediately."),
    ).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(api.registers.reissueToken).not.toHaveBeenCalled()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('confirming the reissue ConfirmDialog rotates the token and shows it once', async () => {
    vi.mocked(api.registers.reissueToken).mockResolvedValue('brand-new-token-value')
    renderEditor()

    fireEvent.click(screen.getByRole('button', { name: /reissue token/i }))
    fireEvent.click(screen.getByRole('button', { name: 'Reissue' }))

    await waitFor(() => expect(api.registers.reissueToken).toHaveBeenCalledWith('reg-1'))
    expect(await screen.findByText('brand-new-token-value')).toBeInTheDocument()
  })

  it('disables Save on create until a location is chosen', () => {
    renderEditor({ register: null, locations: [] })

    expect(screen.getByRole('button', { name: /^save$/i })).toBeDisabled()
  })
})
