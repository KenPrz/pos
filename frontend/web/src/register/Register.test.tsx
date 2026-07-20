// @vitest-environment jsdom
/**
 * Review fix (Important 3): the `--app-vh` measurement effect must run only in the
 * shell (a real per-browser JS-resize cost otherwise, for a bug browsers don't have —
 * see Register.tsx's comment on the effect), must track `window.innerHeight` live, and
 * must remove its `resize` listener on unmount. This is the one thing wrong with that
 * effect before this fix: it ran unconditionally. Everything else about Register's stage
 * machine already has no test harness (see SaleScreen.test.tsx's own note on why) — this
 * pins down only the effect, via `../lib/shell` and `../lib/transport` mocks that let
 * Register mount without a device, a staff session, or a real Tauri bridge.
 */
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Register } from './Register'
import { inShell } from '../lib/transport'
import { ApiError, api } from '../lib/api'

// @testing-library/react's auto-cleanup never registers itself in this suite (see
// ShiftScreens.test.tsx et al.) — do it by hand or DOM from one test leaks into the next.
afterEach(cleanup)

beforeEach(() => {
  localStorage.clear()
  vi.clearAllMocks()
  document.documentElement.style.removeProperty('--app-vh')
})

// `inShell` gates both the --app-vh effect under test and Register's own `configured`
// state; mocked so each test controls it directly instead of depending on a real
// `__TAURI_INTERNALS__` global.
vi.mock('../lib/transport', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/transport')>()
  return { ...actual, inShell: vi.fn(() => false) }
})

// Register calls getConfig() whenever inShell() is true; mocked so that path never
// reaches a real `@tauri-apps/api/core` invoke() (no IPC bridge exists in jsdom).
vi.mock('../lib/shell', () => ({
  getConfig: vi.fn(async () => null),
  checkServer: vi.fn(async () => false),
  setServerUrl: vi.fn(async () => {}),
  hasHardware: vi.fn(() => false),
  openDrawer: vi.fn(async () => {}),
}))

vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return { ...actual, api: { ...actual.api, currentShift: vi.fn() } }
})

function renderRegister() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <Register />
    </QueryClientProvider>
  )
}

const appVh = () => document.documentElement.style.getPropertyValue('--app-vh')

describe('Register --app-vh effect', () => {
  it('sets --app-vh from window.innerHeight in the shell, tracks resize, and cleans up on unmount', async () => {
    vi.mocked(inShell).mockReturnValue(true)
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: 900 })

    const { unmount } = renderRegister()

    await waitFor(() => expect(appVh()).toBe('900px'))

    Object.defineProperty(window, 'innerHeight', { configurable: true, value: 500 })
    window.dispatchEvent(new Event('resize'))
    expect(appVh()).toBe('500px')

    const removeSpy = vi.spyOn(window, 'removeEventListener')
    unmount()
    expect(removeSpy).toHaveBeenCalledWith('resize', expect.any(Function))
  })

  it('never sets --app-vh outside the shell', async () => {
    vi.mocked(inShell).mockReturnValue(false)
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: 900 })

    renderRegister()

    // Nothing async gates this path (the effect either runs synchronously on mount or
    // not at all), so a microtask tick is enough to prove it never fires — a `waitFor`
    // on a property that's expected to stay empty would just time out instead of
    // proving anything.
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(appVh()).toBe('')
  })
})

describe('device revocation routing', () => {
  it('lands on the disabled screen when a mid-session 401 carries invalid_device_token', async () => {
    localStorage.setItem('pos.device_token', 'dead-token')
    localStorage.setItem('pos.staff_token', 'live-staff-token')
    vi.mocked(api.currentShift).mockRejectedValue(
      new ApiError('invalid_device_token', 'This device is not enrolled.', 401),
    )

    renderRegister()

    expect(
      await screen.findByText('Your activation code has been disabled. Please contact an admin and request a new activation code.'),
    ).toBeInTheDocument()
    expect(localStorage.getItem('pos.device_token')).toBeNull()
    expect(localStorage.getItem('pos.staff_token')).toBeNull()
  })

  it('goes back to the PIN screen when the 401 is a plain staff-session expiry', async () => {
    localStorage.setItem('pos.device_token', 'live-token')
    localStorage.setItem('pos.staff_token', 'dead-staff-token')
    vi.mocked(api.currentShift).mockRejectedValue(
      new ApiError('staff_session_expired', 'No staff session.', 401),
    )

    renderRegister()

    expect(await screen.findByText('Enter PIN')).toBeInTheDocument()
    expect(localStorage.getItem('pos.device_token')).toBe('live-token')
  })
})
