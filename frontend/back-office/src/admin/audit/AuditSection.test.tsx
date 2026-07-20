// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuditSection } from './AuditSection'
import { api, type AuditPage } from '../../lib/api'

afterEach(cleanup)

// jsdom implements none of the layout machinery Radix Select's popper touches —
// stubbed here (same as any Radix-in-jsdom suite) so the entity-type dropdown can
// actually open in these tests.
class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.stubGlobal('ResizeObserver', ResizeObserverStub)
  window.HTMLElement.prototype.scrollIntoView = vi.fn()
  window.HTMLElement.prototype.hasPointerCapture = vi.fn()
  window.HTMLElement.prototype.releasePointerCapture = vi.fn()
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, audit: { ...actual.api.audit, list: vi.fn() } },
  }
})

const PAGE_1: AuditPage = {
  page: 1,
  has_more: false,
  rows: [
    {
      id: 'audit-1',
      created_at: '2026-07-01T12:00:00Z',
      action: 'admin.location.update',
      entity_type: 'Location',
      entity_id: 'loc-1',
      user_name: 'Alex Admin',
      register_name: null,
      payload: { changed: ['name'] },
    },
  ],
}

function renderSection() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <AuditSection onUnauthorized={vi.fn()} />
    </QueryClientProvider>,
  )
}

/** Open the entity-type dropdown (Radix renders its options only while open). */
function openEntityTypeSelect() {
  fireEvent.keyDown(screen.getByRole('combobox', { name: /entity type/i }), { key: 'ArrowDown' })
}

describe('AuditSection', () => {
  it('offers the complete entity-type set, including OrderDiscount and Location', async () => {
    vi.mocked(api.audit.list).mockResolvedValue(PAGE_1)
    renderSection()
    await waitFor(() => expect(api.audit.list).toHaveBeenCalledTimes(1))

    openEntityTypeSelect()

    expect(screen.getByRole('option', { name: 'OrderDiscount' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Location' })).toBeInTheDocument()
  })

  it('clears the table immediately on FILTER, before the new response lands', async () => {
    // A response that never resolves stands in for "still in flight" — the row from the
    // first (resolved) load must be gone the instant FILTER is clicked, not once this
    // second request eventually settles.
    vi.mocked(api.audit.list).mockResolvedValueOnce(PAGE_1).mockReturnValueOnce(new Promise(() => {}))
    renderSection()

    await waitFor(() => expect(screen.getByText('admin.location.update')).toBeInTheDocument())

    openEntityTypeSelect()
    fireEvent.keyDown(screen.getByRole('option', { name: 'Order' }), { key: 'Enter' })
    fireEvent.click(screen.getByRole('button', { name: /^filter$/i }))

    expect(screen.queryByText('admin.location.update')).not.toBeInTheDocument()
  })
})
