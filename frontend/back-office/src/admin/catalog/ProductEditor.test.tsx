// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ComponentProps } from 'react'
import { ProductEditor } from './ProductEditor'
import { api, type Product } from '../../lib/api'

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
      products: { ...actual.api.products, update: vi.fn(), create: vi.fn() },
      setProductModifierGroups: vi.fn(),
    },
  }
})

const PRODUCT: Product = {
  id: 'prod-1',
  name: 'Latte',
  description: 'A milky coffee',
  category_id: 'cat-1',
  kind: 'goods',
  is_active: true,
}

function renderEditor(props: Partial<ComponentProps<typeof ProductEditor>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const merged: ComponentProps<typeof ProductEditor> = {
    product: PRODUCT,
    categories: [{ id: 'cat-1', name: 'Coffee', parent_id: null, sort_order: 0 }],
    modifierGroups: [
      { id: 'grp-1', name: 'Milk', min_select: 0, max_select: 1 },
      { id: 'grp-2', name: 'Size', min_select: 1, max_select: 1 },
    ],
    onDone: vi.fn(),
    onCancel: vi.fn(),
    onUnauthorized: vi.fn(),
    ...props,
  }
  render(
    <QueryClientProvider client={client}>
      <ProductEditor {...merged} />
    </QueryClientProvider>,
  )
  return merged
}

describe('ProductEditor', () => {
  it('save calls api.products.update with only the changed fields', async () => {
    vi.mocked(api.products.update).mockResolvedValue({ ...PRODUCT, name: 'Flat White' })
    renderEditor()

    fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'Flat White' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.products.update).toHaveBeenCalledWith('prod-1', { name: 'Flat White' }))
    expect(api.products.create).not.toHaveBeenCalled()
  })

  it('sends every field when creating a new product', async () => {
    vi.mocked(api.products.create).mockResolvedValue(PRODUCT)
    renderEditor({ product: null })

    fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'Cortado' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() =>
      expect(api.products.create).toHaveBeenCalledWith(expect.objectContaining({ name: 'Cortado', kind: 'goods' })),
    )
  })

  it('calls setProductModifierGroups with the ordered ids clicked, not display order', async () => {
    vi.mocked(api.setProductModifierGroups).mockResolvedValue(PRODUCT)
    renderEditor()

    // Displayed as Milk, Size — click Size first, then Milk, so the attach order is
    // the reverse of display order.
    fireEvent.click(screen.getByRole('checkbox', { name: /size/i }))
    fireEvent.click(screen.getByRole('checkbox', { name: /milk/i }))
    fireEvent.click(screen.getByRole('button', { name: /save modifier groups/i }))

    await waitFor(() => expect(api.setProductModifierGroups).toHaveBeenCalledWith('prod-1', ['grp-2', 'grp-1']))
  })

  it('unchecking a group removes it from the ordered ids sent', async () => {
    vi.mocked(api.setProductModifierGroups).mockResolvedValue(PRODUCT)
    renderEditor()

    fireEvent.click(screen.getByRole('checkbox', { name: /milk/i }))
    fireEvent.click(screen.getByRole('checkbox', { name: /size/i }))
    fireEvent.click(screen.getByRole('checkbox', { name: /milk/i })) // uncheck
    fireEvent.click(screen.getByRole('button', { name: /save modifier groups/i }))

    await waitFor(() => expect(api.setProductModifierGroups).toHaveBeenCalledWith('prod-1', ['grp-2']))
  })
})
