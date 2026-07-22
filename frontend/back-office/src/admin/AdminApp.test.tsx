// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AdminApp } from './AdminApp'
import { adminToken } from '../lib/api'

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
})
