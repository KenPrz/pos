// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ComponentProps } from 'react'
import { VariantEditor } from './VariantEditor'
import { api, type Variant } from '../../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, variants: { ...actual.api.variants, update: vi.fn(), create: vi.fn() } },
  }
})

const VARIANT: Variant = {
  id: 'var-1',
  product_id: 'prod-1',
  name: 'Large',
  sku: 'SKU-1',
  barcode: null,
  price_cents: 450,
  cost_cents: null,
  tax_rate_id: null,
  track_inventory: true,
  is_active: true,
}

function renderEditor(props: Partial<ComponentProps<typeof VariantEditor>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const merged: ComponentProps<typeof VariantEditor> = {
    variant: VARIANT,
    products: [
      { id: 'prod-1', name: 'Latte', description: null, category_id: null, kind: 'goods', is_active: true, modifier_group_ids: [] },
    ],
    taxRates: [],
    onDone: vi.fn(),
    onCancel: vi.fn(),
    onUnauthorized: vi.fn(),
    ...props,
  }
  render(
    <QueryClientProvider client={client}>
      <VariantEditor {...merged} />
    </QueryClientProvider>,
  )
  return merged
}

describe('VariantEditor', () => {
  // Regression: clearing the price field used to fall back to "0" and silently save as
  // $0.00 rather than blocking the save — price_cents is required and never nullable.
  it('rejects a blank price instead of silently saving as $0', () => {
    renderEditor()

    fireEvent.change(screen.getByLabelText(/price/i), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(api.variants.update).not.toHaveBeenCalled()
    expect(screen.getByText(/enter a valid price/i)).toBeInTheDocument()
  })

  it('saves a changed price as cents', async () => {
    vi.mocked(api.variants.update).mockResolvedValue({ ...VARIANT, price_cents: 500 })
    renderEditor()

    fireEvent.change(screen.getByLabelText(/price/i), { target: { value: '5.00' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.variants.update).toHaveBeenCalledWith('var-1', { price_cents: 500 }))
  })

  // Review fix: unchecking Active and hitting Save must not silently archive — the
  // brief's global "archive behind a confirm" constraint, previously unimplemented.
  it('does not save an archive when the confirm is cancelled', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    renderEditor()

    fireEvent.click(screen.getByLabelText(/^active$/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Archive'))
    expect(api.variants.update).not.toHaveBeenCalled()
  })

  it('saves the archive once the confirm is accepted', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    vi.mocked(api.variants.update).mockResolvedValue({ ...VARIANT, is_active: false })
    renderEditor()

    fireEvent.click(screen.getByLabelText(/^active$/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.variants.update).toHaveBeenCalledWith('var-1', { is_active: false }))
  })
})
