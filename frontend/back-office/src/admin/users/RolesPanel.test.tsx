// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RolesPanel } from './RolesPanel'
import { ApiError, api, type PermissionGroup, type Role } from '../../lib/api'

afterEach(cleanup)

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      roles: {
        ...actual.api.roles,
        list: vi.fn(),
        create: vi.fn(),
        update: vi.fn(),
        deleteRole: vi.fn(),
        permissionGroups: vi.fn(),
      },
    },
  }
})

const ROLES: Role[] = [
  { id: 'role-1', name: 'cashier', is_system: true, permissions: ['order.open', 'payment.take'], assigned_users: 4 },
  { id: 'role-2', name: 'shift-lead', is_system: false, permissions: ['order.open'], assigned_users: 0 },
]

const GROUPS: PermissionGroup[] = [
  { label: 'Orders', permissions: ['order.open', 'order.void'] },
  { label: 'Payments & refunds', permissions: ['payment.take'] },
]

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(api.roles.list).mockResolvedValue(ROLES)
  vi.mocked(api.roles.permissionGroups).mockResolvedValue(GROUPS)
})

function renderPanel(onUnauthorized = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <RolesPanel onUnauthorized={onUnauthorized} />
    </QueryClientProvider>,
  )
}

async function openEditor(index: number) {
  fireEvent.click((await screen.findAllByRole('button', { name: /^edit$/i }))[index])
}

describe('RolesPanel', () => {
  it('renders one row per role template with its name and assigned-user count', async () => {
    renderPanel()

    expect(await screen.findByText('cashier')).toBeInTheDocument()
    expect(screen.getByText('shift-lead')).toBeInTheDocument()
    expect(screen.getByText('4')).toBeInTheDocument()
  })

  it('opens RoleEditor when Edit is clicked', async () => {
    renderPanel()
    await openEditor(0)

    expect(await screen.findByRole('heading', { name: /edit role/i })).toBeInTheDocument()
  })

  it('shows a disabled name input and no Delete button for a system template', async () => {
    renderPanel()
    await openEditor(0) // cashier — is_system

    expect(await screen.findByLabelText(/^name$/i)).toBeDisabled()
    expect(screen.queryByRole('button', { name: /^delete$/i })).not.toBeInTheDocument()
  })

  it("routes a custom template's delete through ConfirmDialog and calls api.deleteRole", async () => {
    vi.mocked(api.roles.deleteRole).mockResolvedValue(undefined)
    renderPanel()
    await openEditor(1) // shift-lead — custom

    fireEvent.click(await screen.findByRole('button', { name: /^delete$/i }))

    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText('Delete this role? It must be unassigned everywhere.')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: /^delete$/i }))

    await waitFor(() => expect(api.roles.deleteRole).toHaveBeenCalledWith('role-2'))
  })

  it('surfaces the server role_template_in_use message verbatim on a failed delete', async () => {
    const message = 'Unassign this role everywhere first.'
    vi.mocked(api.roles.deleteRole).mockRejectedValue(
      new ApiError('role_template_in_use', message, 422, { assigned_users: 3 }),
    )
    renderPanel()
    await openEditor(1)

    fireEvent.click(await screen.findByRole('button', { name: /^delete$/i }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: /^delete$/i }))

    expect(await screen.findByText(message)).toBeInTheDocument()
  })

  it('groups permission checkboxes under their group labels', async () => {
    renderPanel()
    await openEditor(1)

    expect(await screen.findByText('Orders')).toBeInTheDocument()
    expect(screen.getByText('Payments & refunds')).toBeInTheDocument()
    expect(screen.getByLabelText('order.open')).toBeInTheDocument()
    expect(screen.getByLabelText('order.void')).toBeInTheDocument()
    expect(screen.getByLabelText('payment.take')).toBeInTheDocument()
  })

  it('saves only the changed permission set for an existing role', async () => {
    vi.mocked(api.roles.update).mockResolvedValue({ ...ROLES[1], permissions: ['order.open', 'order.void'] })
    renderPanel()
    await openEditor(1) // shift-lead starts with just order.open checked

    fireEvent.click(await screen.findByLabelText('order.void'))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() =>
      expect(api.roles.update).toHaveBeenCalledWith('role-2', { permissions: ['order.open', 'order.void'] }),
    )
  })

  it('creates a new role with its name and selected permissions', async () => {
    vi.mocked(api.roles.create).mockResolvedValue({
      id: 'role-3',
      name: 'greeter',
      is_system: false,
      permissions: ['order.open'],
      assigned_users: 0,
    })
    renderPanel()

    fireEvent.click(await screen.findByRole('button', { name: /^new role$/i }))
    fireEvent.change(screen.getByLabelText(/^name$/i), { target: { value: 'greeter' } })
    fireEvent.click(await screen.findByLabelText('order.open'))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.roles.create).toHaveBeenCalledWith({ name: 'greeter', permissions: ['order.open'] }))
  })
})
