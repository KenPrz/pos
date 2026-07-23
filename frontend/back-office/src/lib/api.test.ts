import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, adminToken, api } from './api'
import { getCurrency } from './currency'

// Vitest's default (node) environment has no localStorage — Node itself only gained a
// global one in recent versions, and we don't rely on it being present. adminToken is the
// one thing in api.ts that touches it, so stub a minimal in-memory implementation per
// test rather than pull in a DOM environment for one module's sake. Mirrors the register
// app's src/lib/api.test.ts harness idiom.
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

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

/** A `global.fetch` stub with a concrete signature, so `mock.calls` destructures cleanly. */
function stubFetch(respond: () => Response | Promise<Response>) {
  const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => respond())
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

beforeEach(() => {
  vi.stubGlobal('localStorage', fakeLocalStorage())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('api.login', () => {
  it('stores the token and unwraps { data }', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse({
        data: {
          token: 'admin-token-abc',
          user: { id: 'user-1', name: 'Alex Admin', email: 'alex@example.com', is_admin: true },
          currency: 'PHP',
          sections: ['catalog.manage', 'user.manage'],
          report_location_ids: null,
        },
      }),
    )

    const session = await api.login('alex@example.com', 'hunter2')

    expect(session.token).toBe('admin-token-abc')
    expect(session.user).toEqual({ id: 'user-1', name: 'Alex Admin', email: 'alex@example.com', is_admin: true })
    expect(session.sections).toEqual(['catalog.manage', 'user.manage'])
    expect(session.report_location_ids).toBeNull()
    expect(adminToken.get()).toBe('admin-token-abc')
    // Login is the back office's only source for the server's currency (no catalog fetch
    // here) — it must land in lib/currency's module state, not just ride along unread.
    expect(getCurrency()).toBe('PHP')
    expect(localStorage.getItem('pos.currency')).toBe('PHP')

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toContain('/admin/login')
    expect(init?.method).toBe('POST')
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({ email: 'alex@example.com', password: 'hunter2' })
  })

  it('turns the 401 envelope into an ApiError carrying the stable code', async () => {
    stubFetch(() => jsonResponse({ error: { code: 'invalid_credentials', message: 'Invalid credentials.', details: {} } }, 401))

    await expect(api.login('alex@example.com', 'wrong')).rejects.toBeInstanceOf(ApiError)

    stubFetch(() => jsonResponse({ error: { code: 'invalid_credentials', message: 'Invalid credentials.', details: {} } }, 401))
    await expect(api.login('alex@example.com', 'wrong')).rejects.toMatchObject({
      code: 'invalid_credentials',
      status: 401,
    })
    expect(adminToken.get()).toBeNull()
  })
})

describe('api.logout', () => {
  it('clears the stored token', async () => {
    adminToken.set('admin-token-abc')
    stubFetch(() => new Response(null, { status: 204 }))

    await api.logout()

    expect(adminToken.get()).toBeNull()
  })

  it('clears the token even when the logout request fails', async () => {
    adminToken.set('admin-token-abc')
    stubFetch(() => {
      throw new Error('network down')
    })

    await api.logout()

    expect(adminToken.get()).toBeNull()
  })
})

describe('day api', () => {
  it('exposes get/close/reopen/list as functions', () => {
    expect(typeof api.day.get).toBe('function')
    expect(typeof api.day.close).toBe('function')
    expect(typeof api.day.reopen).toBe('function')
    expect(typeof api.day.list).toBe('function')
  })

  it('builds the status URL with the location in the path and date in the query', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ data: { business_date: '2026-07-23' } }))

    await api.day.get('loc-1', '2026-07-23')

    const [url] = fetchMock.mock.calls[0]
    expect(String(url)).toContain('/admin/locations/loc-1/day?date=2026-07-23')
  })

  it('omits the date query param when no date is given', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ data: { business_date: '2026-07-23' } }))

    await api.day.get('loc-1')

    const [url] = fetchMock.mock.calls[0]
    expect(String(url)).toContain('/admin/locations/loc-1/day')
    expect(String(url)).not.toContain('?date=')
  })

  it('posts the close body to the close endpoint and unwraps { data }', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse({
        data: { id: 'day-1', location_id: 'loc-1', business_date: '2026-07-23', closed_by: 'user-1' },
      }),
    )

    const record = await api.day.close('loc-1', { deposit_cents: 1000, checklist: { cash_drop_confirmed: true } })

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toContain('/admin/locations/loc-1/day/close')
    expect(init?.method).toBe('POST')
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({ deposit_cents: 1000, checklist: { cash_drop_confirmed: true } })
    expect(record.id).toBe('day-1')
  })

  it('posts the reopen body to the reopen endpoint', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse({ data: { id: 'day-1', location_id: 'loc-1', business_date: '2026-07-23', closed_by: 'user-1' } }),
    )

    await api.day.reopen('loc-1', { reason: 'miscounted deposit' })

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toContain('/admin/locations/loc-1/day/reopen')
    expect(init?.method).toBe('POST')
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({ reason: 'miscounted deposit' })
  })

  it('lists business days for a location and unwraps { data }', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse({ data: [{ id: 'day-1', location_id: 'loc-1', business_date: '2026-07-22', closed_by: 'user-1' }] }),
    )

    const days = await api.day.list('loc-1')

    const [url] = fetchMock.mock.calls[0]
    expect(String(url)).toContain('/admin/locations/loc-1/days')
    expect(days).toHaveLength(1)
    expect(days[0].id).toBe('day-1')
  })
})
