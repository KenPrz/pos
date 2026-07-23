// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AdminApp } from './AdminApp'
import { adminToken, api } from '../lib/api'

let mockPathname = '/'
const routerMock = { push: vi.fn(), replace: vi.fn(), prefetch: vi.fn(), back: vi.fn(), forward: vi.fn() }
vi.mock('next/navigation', () => ({
  usePathname: () => mockPathname,
  useRouter: () => routerMock,
}))

vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, locations: { ...actual.api.locations, list: vi.fn() } },
  }
})

// Vitest's default (node) environment has no localStorage — stub a minimal in-memory
// implementation per test, same as src/lib/api.test.ts.
function fakeLocalStorage(): Storage {
  const store = new Map<string, string>()
  return {
    getItem: (k: string) => store.get(k) ?? null,
    setItem: (k: string, v: string) => void store.set(k, v),
    removeItem: (k: string) => void store.delete(k),
    clear: () => store.clear(),
    key: () => null,
    get length() {
      return store.size
    },
  } as Storage
}

beforeEach(() => {
  vi.stubGlobal('localStorage', fakeLocalStorage())
  mockPathname = '/'
  routerMock.push.mockClear()
  routerMock.replace.mockClear()
  vi.mocked(api.locations.list).mockResolvedValue([])
})

afterEach(() => {
  vi.unstubAllGlobals()
  cleanup()
})

function renderApp() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <AdminApp />
    </QueryClientProvider>,
  )
}

function storeSession(sections: string[] = ['catalog.manage', 'user.manage']) {
  adminToken.set('admin-token-abc')
  adminToken.setUser(
    { id: 'user-1', name: 'Alex Admin', email: 'alex@example.com', is_admin: false },
    sections,
    null,
  )
}

const LOC = (id: string, name: string, code: string) => ({
  id,
  name,
  code,
  timezone: 'Asia/Manila',
  prices_include_tax: true,
  receipt_header: null,
  receipt_footer: null,
  is_active: true,
  variance_approval_threshold_cents: null,
  low_stock_threshold: null,
})

describe('AdminApp', () => {
  it('boots to login screen when no token is stored', () => {
    renderApp()

    expect(screen.getByLabelText(/email/i)).toBeInTheDocument()
  })

  it('boots to shell when a valid token and new-shape user are stored', async () => {
    const sessionUser = { id: 'user-1', name: 'Alex Admin', email: 'alex@example.com', is_admin: true }
    adminToken.set('admin-token-abc')
    adminToken.setUser(sessionUser, ['catalog.manage', 'user.manage'], null)

    renderApp()

    await waitFor(() => {
      expect(screen.queryByLabelText(/email/i)).not.toBeInTheDocument()
    })
    // Shell is rendered: sidebar with "POS" branding and "Back Office" subtitle visible
    expect(screen.getByText('POS')).toBeInTheDocument()
    expect(screen.getByText('Back Office')).toBeInTheDocument()
  })

  it('clears stale pre-sections session and shows login screen', async () => {
    // Simulate a pre-sections stored shape (token present, but user object missing the
    // sections/report_location_ids keys). This is what's in localStorage after upgrading
    // from a version that didn't have those fields.
    const staleUser = { id: 'user-1', name: 'Alex Admin', email: 'alex@example.com', is_admin: true }
    adminToken.set('admin-token-abc')
    localStorage.setItem('pos.admin_user', JSON.stringify(staleUser))

    renderApp()

    await waitFor(() => {
      expect(screen.getByLabelText(/email/i)).toBeInTheDocument()
    })

    // Verify storage was cleared
    expect(adminToken.get()).toBeNull()
    expect(localStorage.getItem('pos.admin_user')).toBeNull()
  })

  it('renders the section named by the URL', async () => {
    mockPathname = '/catalog'
    storeSession(['catalog.manage'])

    renderApp()

    await waitFor(() =>
      expect(screen.getByRole('link', { name: 'Catalog' })).toHaveAttribute('aria-current', 'page'),
    )
    expect(routerMock.replace).not.toHaveBeenCalled()
  })

  it('normalizes an unheld section URL to /', async () => {
    mockPathname = '/settings'
    storeSession(['catalog.manage'])

    renderApp()

    await waitFor(() => expect(routerMock.replace).toHaveBeenCalledWith('/'))
    expect(screen.getByRole('link', { name: 'Today' })).toHaveAttribute('aria-current', 'page')
  })

  it('normalizes unknown slugs to /', async () => {
    mockPathname = '/nope'
    storeSession()

    renderApp()

    await waitFor(() => expect(routerMock.replace).toHaveBeenCalledWith('/'))
  })

  it('flattens sub-paths to the section root until sections define sub-routes', async () => {
    mockPathname = '/reports/stock'
    storeSession(['report.sales.view'])

    renderApp()

    await waitFor(() => expect(routerMock.replace).toHaveBeenCalledWith('/reports'))
  })

  it('leaves a deep link untouched on the login screen', () => {
    mockPathname = '/reports'

    renderApp()

    expect(screen.getByLabelText(/email/i)).toBeInTheDocument()
    expect(routerMock.replace).not.toHaveBeenCalled()
    expect(routerMock.push).not.toHaveBeenCalled()
  })

  it('pushes the section path when the sidebar navigates', async () => {
    storeSession(['catalog.manage'])

    renderApp()

    const link = await screen.findByRole('link', { name: 'Catalog' })
    fireEvent.click(link)

    expect(routerMock.push).toHaveBeenCalledWith('/catalog')
  })

  it('restores the stored location choice when it is still visible', async () => {
    vi.mocked(api.locations.list).mockResolvedValue([LOC('loc-a', 'Alpha', 'A'), LOC('loc-b', 'Beta', 'B')])
    localStorage.setItem('pos.admin_location', 'loc-b')
    storeSession()

    renderApp()

    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Location' })).toHaveTextContent('Beta · B'))
  })

  it('falls back to the first visible location when the stored one is gone', async () => {
    vi.mocked(api.locations.list).mockResolvedValue([LOC('loc-a', 'Alpha', 'A')])
    localStorage.setItem('pos.admin_location', 'loc-gone')
    storeSession()

    renderApp()

    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Location' })).toHaveTextContent('Alpha · A'))
  })
})
