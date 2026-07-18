// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ComponentProps } from 'react'
import { DiscountEditor } from './DiscountEditor'
import { api, type Discount } from '../../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, discounts: { ...actual.api.discounts, update: vi.fn(), create: vi.fn() } },
  }
})

const DISCOUNT: Discount = {
  id: 'disc-1',
  name: 'Staff discount',
  kind: 'fixed',
  percent_micros: null,
  amount_cents: 500,
  scope: 'order',
  requires_supervisor: true,
  is_active: true,
}

function renderEditor(props: Partial<ComponentProps<typeof DiscountEditor>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const merged: ComponentProps<typeof DiscountEditor> = {
    discount: DISCOUNT,
    onDone: vi.fn(),
    onCancel: vi.fn(),
    onUnauthorized: vi.fn(),
    ...props,
  }
  render(
    <QueryClientProvider client={client}>
      <DiscountEditor {...merged} />
    </QueryClientProvider>,
  )
  return merged
}

describe('DiscountEditor', () => {
  // Regression: clearing the amount field on a fixed discount used to fall back to "0"
  // and silently save as $0.00 rather than blocking the save — amount_cents is required
  // for a fixed-kind discount.
  it('rejects a blank amount instead of silently saving as $0', () => {
    renderEditor()

    fireEvent.change(screen.getByLabelText(/amount/i), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(api.discounts.update).not.toHaveBeenCalled()
    expect(screen.getByText(/enter a valid amount/i)).toBeInTheDocument()
  })

  it('saves a changed amount as cents', async () => {
    vi.mocked(api.discounts.update).mockResolvedValue({ ...DISCOUNT, amount_cents: 300 })
    renderEditor()

    fireEvent.change(screen.getByLabelText(/amount/i), { target: { value: '3.00' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.discounts.update).toHaveBeenCalledWith('disc-1', { amount_cents: 300 }))
  })
})
