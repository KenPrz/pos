// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ComponentProps } from 'react'
import { UserEditor } from './UserEditor'
import { ApiError, api, type Location, type ManagedUser } from '../../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, users: { ...actual.api.users, update: vi.fn(), create: vi.fn() } },
  }
})

const LOCATIONS: Location[] = [
  { id: 'loc-1', code: 'DT', name: 'Downtown', timezone: 'America/Chicago', prices_include_tax: false, receipt_header: null, receipt_footer: null, is_active: true },
  { id: 'loc-2', code: 'UP', name: 'Uptown', timezone: 'America/Chicago', prices_include_tax: false, receipt_header: null, receipt_footer: null, is_active: true },
]

const USER: ManagedUser = {
  id: 'user-1',
  name: 'Alex Cashier',
  email: 'alex@example.com',
  is_admin: false,
  is_active: true,
  roles: [{ location_id: 'loc-1', location_name: 'Downtown', role: 'cashier' }],
}

function renderEditor(props: Partial<ComponentProps<typeof UserEditor>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const merged: ComponentProps<typeof UserEditor> = {
    user: USER,
    locations: LOCATIONS,
    onDone: vi.fn(),
    onCancel: vi.fn(),
    onUnauthorized: vi.fn(),
    ...props,
  }
  render(
    <QueryClientProvider client={client}>
      <UserEditor {...merged} />
    </QueryClientProvider>,
  )
  return merged
}

describe('UserEditor', () => {
  it('submits only the changed field, leaving email/roles/admin untouched', async () => {
    vi.mocked(api.users.update).mockResolvedValue({ ...USER, name: 'Alexandra Cashier' })
    renderEditor()

    fireEvent.change(screen.getByLabelText(/^name$/i), { target: { value: 'Alexandra Cashier' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.users.update).toHaveBeenCalledWith('user-1', { name: 'Alexandra Cashier' }))
  })

  it('renders the server message on a 422 self-lockout instead of a generic fallback', async () => {
    const message = 'You cannot remove your own admin access or deactivate your own account.'
    vi.mocked(api.users.update).mockRejectedValue(new ApiError('self_lockout', message, 422, { user_id: 'user-1' }))
    renderEditor()

    fireEvent.click(screen.getByLabelText(/admin/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(await screen.findByText(message)).toBeInTheDocument()
  })

  // UI-rework rewrite (DOM-shape coupling, exception noted in the task report): the
  // native `<select>` "add location"/"add role" pickers became Radix `Select` (the
  // vocabulary component, same as every other dropdown migrated in Task 3) — so opening
  // and choosing an option replaces the old `fireEvent.change` with the
  // open-trigger/click-option interaction Radix Select needs. Behavior/label assertions
  // are unchanged: same full replacement role set, same "Add" button.
  it('sends the full replacement role set when a role row is added', async () => {
    vi.mocked(api.users.update).mockResolvedValue(USER)
    renderEditor()

    fireEvent.click(screen.getByLabelText(/add location/i))
    fireEvent.click(await screen.findByRole('option', { name: 'Uptown' }))
    fireEvent.click(screen.getByLabelText(/add role/i))
    fireEvent.click(await screen.findByRole('option', { name: 'Supervisor' }))
    fireEvent.click(screen.getByRole('button', { name: /^add$/i }))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() =>
      expect(api.users.update).toHaveBeenCalledWith('user-1', {
        roles: [
          { location_id: 'loc-1', role: 'cashier' },
          { location_id: 'loc-2', role: 'supervisor' },
        ],
      }),
    )
  })

  it('blocks create with neither email nor PIN, mirroring the server 400 as client-side UX', () => {
    renderEditor({ user: null })

    fireEvent.change(screen.getByLabelText(/^name$/i), { target: { value: 'New Hire' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(api.users.create).not.toHaveBeenCalled()
    expect(screen.getByText(/email or a pin/i)).toBeInTheDocument()
  })

  // UI-rework rewrite (exception #3): the deactivate confirm moved from `window.confirm`
  // to `ConfirmDialog`, same copy, same cancel-blocks/confirm-proceeds semantics.
  it('cancelling the deactivate ConfirmDialog blocks the save', () => {
    renderEditor()

    fireEvent.click(screen.getByLabelText(/^active$/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(
      screen.getByText('Deactivate Alex Cashier? They keep their history but can no longer sign in.'),
    ).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(api.users.update).not.toHaveBeenCalled()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('confirming the deactivate ConfirmDialog proceeds with the save', async () => {
    vi.mocked(api.users.update).mockResolvedValue({ ...USER, is_active: false })
    renderEditor()

    fireEvent.click(screen.getByLabelText(/^active$/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))
    fireEvent.click(screen.getByRole('button', { name: 'Deactivate' }))

    await waitFor(() => expect(api.users.update).toHaveBeenCalledWith('user-1', { is_active: false }))
  })
})
