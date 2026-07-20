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
      registers: { ...actual.api.registers, update: vi.fn(), create: vi.fn(), issueActivationCode: vi.fn() },
    },
  }
})

const LOCATIONS: Location[] = [
  { id: 'loc-1', code: 'DT', name: 'Downtown', timezone: 'America/Chicago', prices_include_tax: false, receipt_header: null, receipt_footer: null, is_active: true },
]

const REGISTER: Register = {
  id: 'reg-1',
  location_id: 'loc-1',
  name: 'Front counter',
  mode: 'retail',
  is_active: true,
  activation: { state: 'enrolled', code_expires_at: null },
}

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
  it('cancelling the issue ConfirmDialog leaves the code un-issued', () => {
    renderEditor()

    fireEvent.click(screen.getByRole('button', { name: /issue activation code/i }))
    fireEvent.click(screen.getByRole('button', { name: /^cancel$/i }))

    expect(api.registers.issueActivationCode).not.toHaveBeenCalled()
  })

  it('issues a code on confirm and shows it exactly once', async () => {
    vi.mocked(api.registers.issueActivationCode).mockResolvedValue({
      activation_code: 'ABCDE-FGH23',
      expires_at: '2026-07-27T12:00:00+00:00',
    })
    renderEditor()

    fireEvent.click(screen.getByRole('button', { name: /issue activation code/i }))
    fireEvent.click(screen.getByRole('button', { name: /^issue code$/i }))

    await waitFor(() => expect(api.registers.issueActivationCode).toHaveBeenCalledWith('reg-1'))
    expect(await screen.findByText('ABCDE-FGH23')).toBeInTheDocument()
  })

  it('shows the activation state pill', () => {
    renderEditor({ register: { ...REGISTER, activation: { state: 'code_pending', code_expires_at: '2026-07-27T12:00:00+00:00' } } })

    expect(screen.getByText(/code pending/i)).toBeInTheDocument()
  })

  // The `register` prop is a snapshot from PlacesSection's `editing` state — it never
  // refetches under an already-open editor, so the pill has to be driven off the issue
  // mutation's own result rather than off `register.activation` alone.
  it('updates the activation pill immediately after issuing a code, without waiting for a refetch', async () => {
    vi.mocked(api.registers.issueActivationCode).mockResolvedValue({
      activation_code: 'ABCDE-FGH23',
      expires_at: '2026-07-27T12:00:00+00:00',
    })
    renderEditor({ register: { ...REGISTER, activation: { state: 'not_enrolled', code_expires_at: null } } })

    fireEvent.click(screen.getByRole('button', { name: /issue activation code/i }))
    fireEvent.click(screen.getByRole('button', { name: /^issue code$/i }))

    expect(await screen.findByText('Code pending — expires 2026-07-27')).toBeInTheDocument()
  })

  it('disables Save on create until a location is chosen', () => {
    renderEditor({ register: null, locations: [] })

    expect(screen.getByRole('button', { name: /^save$/i })).toBeDisabled()
  })
})
