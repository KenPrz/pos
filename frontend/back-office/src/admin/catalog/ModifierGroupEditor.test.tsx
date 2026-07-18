// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
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
})
