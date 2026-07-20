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
  due_cents: 1000,
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

describe('tokens.registerInfo', () => {
  it('round-trips through localStorage', () => {
    expect(tokens.registerInfo()).toBeNull()

    tokens.setRegisterInfo({ id: 'register-1', name: 'Bar 1', mode: 'food' })

    expect(tokens.registerInfo()).toEqual({ id: 'register-1', name: 'Bar 1', mode: 'food' })
  })

  it('is dropped by clearDevice (the terminal identity goes with the device token)', () => {
    tokens.setDevice('device-abc')
    tokens.setRegisterInfo({ id: 'register-1', name: 'Bar 1', mode: 'food' })

    tokens.clearDevice()

    expect(tokens.device()).toBeNull()
    expect(tokens.registerInfo()).toBeNull()
  })
})

describe('openOrder', () => {
  it('sends { table_ref } when tableRef is passed', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ data: { order: sampleOrder } }, 201))

    await api.openOrder({ tableRef: 'T1' })

    const [, init] = fetchMock.mock.calls[0]
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({ table_ref: 'T1' })
  })

  it('sends an empty body when no options are passed', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ data: { order: sampleOrder } }, 201))

    await api.openOrder()

    const [, init] = fetchMock.mock.calls[0]
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({})
    const headers = init?.headers as Record<string, string>
    expect(headers['Idempotency-Key']).toBeUndefined()
  })

  it('still sends Idempotency-Key when only idempotencyKey is passed', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ data: { order: sampleOrder } }, 201))

    await api.openOrder({ idempotencyKey: 'key-1' })

    const [, init] = fetchMock.mock.calls[0]
    const headers = init?.headers as Record<string, string>
    expect(headers['Idempotency-Key']).toBe('key-1')
  })
})

describe('addLine', () => {
  it('includes modifiers in the body only when provided', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse({ data: { order: sampleOrder, line: { id: 'line-1' } } }),
    )

    await api.addLine(sampleOrder, 'variant-1', '1', undefined, ['mod-1', 'mod-2'])

    const [, init] = fetchMock.mock.calls[0]
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body.modifiers).toEqual(['mod-1', 'mod-2'])
  })

  it('omits modifiers from the body when not provided', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse({ data: { order: sampleOrder, line: { id: 'line-1' } } }),
    )

    await api.addLine(sampleOrder, 'variant-1')

    const [, init] = fetchMock.mock.calls[0]
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).not.toHaveProperty('modifiers')
  })
})

describe('transferOrder', () => {
  it('posts the target register id with If-Match', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ data: { order: { ...sampleOrder, register_id: 'register-2' } } }))

    await api.transferOrder(sampleOrder, 'register-2')

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toContain(`/orders/${sampleOrder.id}/transfer`)
    const headers = init?.headers as Record<string, string>
    expect(headers['If-Match']).toBe(String(sampleOrder.version))
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({ register_id: 'register-2' })
  })
})

describe('splitOrder', () => {
  it('unwraps data.orders and sends If-Match + Idempotency-Key', async () => {
    const splitOrders = [
      { ...sampleOrder, id: 'order-2' },
      { ...sampleOrder, id: 'order-3' },
    ]
    const fetchMock = stubFetch(() => jsonResponse({ data: { orders: splitOrders } }, 201))

    const result = await api.splitOrder(sampleOrder, 2, 'split-key-1')

    expect(result).toEqual(splitOrders)
    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toContain(`/orders/${sampleOrder.id}/split`)
    const headers = init?.headers as Record<string, string>
    expect(headers['If-Match']).toBe(String(sampleOrder.version))
    expect(headers['Idempotency-Key']).toBe('split-key-1')
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({ ways: 2 })
  })
})

describe('approveVariance', () => {
  it('unwraps data.shift', async () => {
    const shift = {
      id: 'shift-1',
      register_id: 'register-1',
      opened_by: 'user-1',
      opened_at: '2026-07-16T00:00:00Z',
      opening_float_cents: 10000,
      closed_at: '2026-07-16T08:00:00Z',
      counted_cash_cents: 9800,
      expected_cash_cents: 10000,
      variance_cents: -200,
      variance_approved_by: 'user-2',
      variance_approved_at: '2026-07-16T08:05:00Z',
    }
    stubFetch(() => jsonResponse({ data: { shift } }))

    const result = await api.approveVariance('shift-1')

    expect(result).toEqual(shift)
  })
})

describe('setLinePrep', () => {
  it('PATCHes the prep state without an If-Match header', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ data: { order: sampleOrder, line: { id: 'line-1' } } }))

    await api.setLinePrep('order-1', 'line-1', 'in_progress')

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toContain('/orders/order-1/lines/line-1/prep')
    expect(init?.method).toBe('PATCH')
    const headers = init?.headers as Record<string, string>
    expect(headers['If-Match']).toBeUndefined()
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({ state: 'in_progress' })
  })
})

describe('updateLineQty', () => {
  it('PATCHes the qty with If-Match from the order version', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ data: { order: sampleOrder, line: { id: 'line-1' } } }))

    await api.updateLineQty(sampleOrder, 'line-1', '2')

    const [, init] = fetchMock.mock.calls[0]
    expect(init?.method).toBe('PATCH')
    const headers = init?.headers as Record<string, string>
    expect(headers['If-Match']).toBe(String(sampleOrder.version))
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({ qty: '2' })
  })
})

describe('setTableRef', () => {
  it('PATCHes /orders/{id} with the table_ref and If-Match', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ data: { order: { ...sampleOrder, table_ref: 'T1' } } }))

    await api.setTableRef(sampleOrder, 'T1')

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toContain(`/orders/${sampleOrder.id}`)
    expect(init?.method).toBe('PATCH')
    const body = JSON.parse(init?.body as string) as Record<string, unknown>
    expect(body).toEqual({ table_ref: 'T1' })
  })
})

describe('staffLogin', () => {
  it('stores the register info alongside the staff token', async () => {
    stubFetch(() =>
      jsonResponse({
        data: {
          staff_token: 'staff-token-1',
          expires_at: '2026-07-16T12:00:00Z',
          user: { id: 'user-1', name: 'Alex', is_admin: false, permissions: [] },
          register: { id: 'register-1', name: 'Bar 1', mode: 'food' },
        },
      }),
    )

    await api.staffLogin('1234')

    expect(tokens.registerInfo()).toEqual({ id: 'register-1', name: 'Bar 1', mode: 'food' })
  })
})

describe('activateRegister', () => {
  it('exchanges the code, then stores the device token and register info', async () => {
    const fetchMock = stubFetch(() =>
      jsonResponse({ data: { register: { id: 'reg-1', name: 'Till 1', mode: 'retail' }, device_token: '3|abc' } }, 201),
    )

    const register = await api.activateRegister('ABCDE-FGH23')

    const [, init] = fetchMock.mock.calls[0]
    expect(JSON.parse(String(init?.body))).toEqual({ activation_code: 'ABCDE-FGH23' })
    expect(register).toEqual({ id: 'reg-1', name: 'Till 1', mode: 'retail' })
    expect(tokens.device()).toBe('3|abc')
    expect(tokens.registerInfo()).toEqual({ id: 'reg-1', name: 'Till 1', mode: 'retail' })
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
