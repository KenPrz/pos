import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api, tokens, type Order } from './api'

// Vitest's default (node) environment has no localStorage — Node itself only gained a
// global one in recent versions, and we don't rely on it being present. tokens.ts is the
// one thing in api.ts that touches it, so stub a minimal in-memory implementation per
// test rather than pull in a DOM environment for one module's sake.
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

const sampleOrder: Order = {
  id: 'order-1',
  number: 'N-0001',
  register_id: 'register-1',
  status: 'open',
  table_ref: null,
  business_date: '2026-07-16',
  prices_include_tax: false,
  subtotal_cents: 1000,
  discount_cents: 0,
  tax_cents: 0,
  total_cents: 1000,
  paid_cents: 0,
  version: 3,
}

beforeEach(() => {
  vi.stubGlobal('localStorage', fakeLocalStorage())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('request headers', () => {
  it('carries Bearer (device) and X-Staff-Token (staff) when both are set', async () => {
    tokens.setDevice('device-token-abc')
    tokens.setStaff('staff-token-xyz')
    const fetchMock = stubFetch(() =>
      jsonResponse({ data: { healthy: true, app_version: '1.0', database: { ok: true, version: '18', reason: null } } }),
    )

    await api.health()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [, init] = fetchMock.mock.calls[0]
    const headers = init?.headers as Record<string, string>
    expect(headers.Authorization).toBe('Bearer device-token-abc')
    expect(headers['X-Staff-Token']).toBe('staff-token-xyz')
  })

  it('omits X-Staff-Token when no staff session is set', async () => {
    tokens.setDevice('device-only')
    const fetchMock = stubFetch(() =>
      jsonResponse({ data: { healthy: true, app_version: '1.0', database: { ok: true, version: '18', reason: null } } }),
    )

    await api.health()

    const [, init] = fetchMock.mock.calls[0]
    const headers = init?.headers as Record<string, string>
    expect(headers['X-Staff-Token']).toBeUndefined()
  })
})

describe('takePayment', () => {
  it('passes the given Idempotency-Key through verbatim and If-Match from the order version', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse(
        {
          data: {
            payment: { id: 'pay-1', driver: 'cash', status: 'captured', amount_cents: 1000, tendered_cents: 1000, change_cents: 0 },
            order: { ...sampleOrder, status: 'closed', paid_cents: 1000, version: 4 },
          },
        },
        201,
      ),
    )

    await api.takePayment(sampleOrder, 1000, 'cash', 'retry-key-123', { tenderedCents: 1000 })

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toContain(`/orders/${sampleOrder.id}/payments`)
    const headers = init?.headers as Record<string, string>
    expect(headers['Idempotency-Key']).toBe('retry-key-123')
    expect(headers['If-Match']).toBe(String(sampleOrder.version))
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body.driver).toBe('cash')
    expect(body.tendered_cents).toBe(1000)
  })

  it('sends a reference and no tendered_cents for external_card', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse(
        {
          data: {
            payment: { id: 'pay-2', driver: 'external_card', status: 'captured', amount_cents: 1000, tendered_cents: null, change_cents: null },
            order: { ...sampleOrder, status: 'closed', paid_cents: 1000, version: 4 },
          },
        },
        201,
      ),
    )

    await api.takePayment(sampleOrder, 1000, 'external_card', 'retry-key-456', { reference: 'auth-001122' })

    const [, init] = fetchMock.mock.calls[0]
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body.driver).toBe('external_card')
    expect(body.tendered_cents).toBeNull()
    expect(body.reference).toBe('auth-001122')
  })
})

describe('error envelope', () => {
  it('turns a non-2xx error envelope into an ApiError carrying the stable code', async () => {
    stubFetch(() => jsonResponse({ error: { code: 'order_version_conflict', message: 'Someone else changed this order.', details: {} } }, 409))

    await expect(api.getOrder('order-1')).rejects.toBeInstanceOf(ApiError)

    stubFetch(() => jsonResponse({ error: { code: 'order_version_conflict', message: 'Someone else changed this order.', details: {} } }, 409))
    await expect(api.getOrder('order-1')).rejects.toMatchObject({
      code: 'order_version_conflict',
      status: 409,
    })
  })

  it('never lets message text stand in for the code', async () => {
    stubFetch(() => jsonResponse({ error: { code: 'insufficient_permission', message: 'Wording that could change any time.', details: {} } }, 403))

    try {
      await api.voidOrder(sampleOrder, 'test reason')
      expect.unreachable('expected voidOrder to throw')
    } catch (err) {
      expect(err).toBeInstanceOf(ApiError)
      expect((err as ApiError).code).toBe('insufficient_permission')
    }
  })
})
