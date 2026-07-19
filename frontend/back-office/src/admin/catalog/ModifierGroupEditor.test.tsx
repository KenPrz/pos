// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ComponentProps } from 'react'
import { ModifierGroupEditor } from './ModifierGroupEditor'
import { api, type ModifierGroup } from '../../lib/api'

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
      modifierGroups: { ...actual.api.modifierGroups, update: vi.fn(), create: vi.fn() },
      modifiers: { ...actual.api.modifiers, update: vi.fn(), create: vi.fn() },
    },
  }
})

const GROUP: ModifierGroup = { id: 'grp-1', name: 'Milk', min_select: 1, max_select: 2 }

function renderEditor(props: Partial<ComponentProps<typeof ModifierGroupEditor>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const merged: ComponentProps<typeof ModifierGroupEditor> = {
    group: GROUP,
    modifiers: [],
    onDone: vi.fn(),
    onCancel: vi.fn(),
    onUnauthorized: vi.fn(),
    ...props,
  }
  render(
    <QueryClientProvider client={client}>
      <ModifierGroupEditor {...merged} />
    </QueryClientProvider>,
  )
  return merged
}

describe('ModifierGroupEditor', () => {
  // Review fix: `Number('')` is 0, not an error — clearing min select used to silently
  // save the group with min_select: 0 instead of failing validation.
  it('rejects a blank min select instead of silently saving as 0', () => {
    renderEditor()

    fireEvent.change(screen.getByLabelText(/min select/i), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(api.modifierGroups.update).not.toHaveBeenCalled()
    expect(screen.getByText(/enter a min select value/i)).toBeInTheDocument()
  })

  it('rejects a non-numeric min select', () => {
    renderEditor()

    fireEvent.change(screen.getByLabelText(/min select/i), { target: { value: 'abc' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(api.modifierGroups.update).not.toHaveBeenCalled()
    expect(screen.getByText(/enter a valid min select value/i)).toBeInTheDocument()
  })

  // UI-rework addition: the nested `ModifierForm`'s own archive-behind-a-confirm
  // constraint now goes through `ConfirmDialog` — added coverage since the source
  // always carried a `window.confirm` call here but no prior test exercised it.
  describe('nested ModifierForm archive confirm', () => {
    const MODIFIER = { id: 'mod-1', group_id: 'grp-1', name: 'Extra shot', price_delta_cents: 75, position: 0, is_active: true }

    // The group's own form and the nested ModifierForm both have a "Save" button (same
    // frozen label, two forms) — the group's renders first in DOM order, so index 1 is
    // always the modifier form's.
    const modifierSaveButton = () => screen.getAllByRole('button', { name: /^save$/i })[1]

    it('cancelling the archive ConfirmDialog blocks the save', () => {
      renderEditor({ modifiers: [MODIFIER] })

      fireEvent.click(screen.getByRole('button', { name: /^edit$/i }))
      fireEvent.click(screen.getByLabelText(/^active$/i))
      fireEvent.click(modifierSaveButton())

      const dialog = screen.getByRole('dialog')
      expect(within(dialog).getByText('Archive Extra shot? It leaves the register catalog but stays in history.')).toBeInTheDocument()

      fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))

      expect(api.modifiers.update).not.toHaveBeenCalled()
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })

    it('confirming the archive ConfirmDialog proceeds with the save', async () => {
      vi.mocked(api.modifiers.update).mockResolvedValue({ ...MODIFIER, is_active: false })
      renderEditor({ modifiers: [MODIFIER] })

      fireEvent.click(screen.getByRole('button', { name: /^edit$/i }))
      fireEvent.click(screen.getByLabelText(/^active$/i))
      fireEvent.click(modifierSaveButton())

      const dialog = screen.getByRole('dialog')
      fireEvent.click(within(dialog).getByRole('button', { name: 'Archive' }))

      await waitFor(() => expect(api.modifiers.update).toHaveBeenCalledWith('mod-1', { is_active: false }))
    })
  })
})
