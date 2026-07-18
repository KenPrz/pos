// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuditSection } from './AuditSection'
import { api, type AuditPage } from '../../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
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

describe('AuditSection', () => {
  it('offers the complete entity-type set, including OrderDiscount and Location', async () => {
    vi.mocked(api.audit.list).mockResolvedValue(PAGE_1)
    renderSection()
    await waitFor(() => expect(api.audit.list).toHaveBeenCalledTimes(1))

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

    fireEvent.change(screen.getByLabelText(/entity type/i), { target: { value: 'Order' } })
    fireEvent.click(screen.getByRole('button', { name: /^filter$/i }))

    expect(screen.queryByText('admin.location.update')).not.toBeInTheDocument()
  })
})
