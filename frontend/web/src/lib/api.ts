/**
 * The API client. One place that knows the envelope from docs/03-api.md, so no component
 * ever unwraps `data` or branches on an HTTP status by hand.
 */

/** Success is always `{ data: ... }`; errors are always `{ error: ... }`. Never both. */
export type ApiSuccess<T> = { data: T }

export type ApiErrorBody = {
  error: {
    /** Stable and machine-readable — branch on this, never on `message`. */
    code: string
    /** Human-readable. May change freely; never parse it. */
    message: string
    details: Record<string, unknown>
  }
}

/**
 * A failed request. Carries the stable `code` so callers can branch without touching
 * HTTP status codes or message text.
 */
export class ApiError extends Error {
  // Explicit fields rather than constructor parameter properties: the tsconfig sets
  // `erasableSyntaxOnly`, so type syntax must never emit runtime code.
  readonly code: string
  readonly status: number
  readonly details: Record<string, unknown>

  constructor(code: string, message: string, status: number, details: Record<string, unknown> = {}) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.status = status
    this.details = details
  }
}

export type Health = {
  healthy: boolean
  app_version: string
  database: {
    ok: boolean
    version: string | null
    reason: string | null
  }
}

// ---------------------------------------------------------------------------
// Tokens. The device token is durable (the terminal's identity); the staff token
// is a short session and dies at shift close — the server revokes it, we just
// mirror that by clearing on 401.
// ---------------------------------------------------------------------------

const DEVICE_TOKEN_KEY = 'pos.device_token'
const STAFF_TOKEN_KEY = 'pos.staff_token'

export const tokens = {
  device: () => localStorage.getItem(DEVICE_TOKEN_KEY),
  setDevice: (t: string) => localStorage.setItem(DEVICE_TOKEN_KEY, t),
  clearDevice: () => localStorage.removeItem(DEVICE_TOKEN_KEY),
  staff: () => localStorage.getItem(STAFF_TOKEN_KEY),
  setStaff: (t: string) => localStorage.setItem(STAFF_TOKEN_KEY, t),
  clearStaff: () => localStorage.removeItem(STAFF_TOKEN_KEY),
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', ...(init?.headers as Record<string, string>) }
  const device = tokens.device()
  const staff = tokens.staff()
  if (device) headers.Authorization = `Bearer ${device}`
  if (staff) headers['X-Staff-Token'] = staff

  let response: Response

  try {
    response = await fetch(`/api/v1${path}`, { ...init, headers })
  } catch (cause) {
    // The network never reached us. v1 is online-only (docs/00-overview.md), so this is
    // a real, expected state the UI has to show rather than swallow.
    throw new ApiError('network_unreachable', 'Cannot reach the server.', 0, {
      cause: String(cause),
    })
  }

  const body: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    // 503 from /health is a normal, well-formed response carrying `data`, not an error
    // envelope. Anything else that fails must be one.
    if (isErrorBody(body)) {
      throw new ApiError(body.error.code, body.error.message, response.status, body.error.details)
    }
    if (isSuccessBody<T>(body)) {
      return body.data
    }
    throw new ApiError('unexpected_response', `Unexpected ${response.status} from ${path}.`, response.status)
  }

  if (!isSuccessBody<T>(body)) {
    throw new ApiError('unexpected_response', `Malformed response from ${path}.`, response.status)
  }

  return body.data
}

function isErrorBody(body: unknown): body is ApiErrorBody {
  return typeof body === 'object' && body !== null && 'error' in body
}

function isSuccessBody<T>(body: unknown): body is ApiSuccess<T> {
  return typeof body === 'object' && body !== null && 'data' in body
}

function post<T>(path: string, body: unknown, extra?: Record<string, string>): Promise<T> {
  return request<T>(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...extra },
    body: JSON.stringify(body),
  })
}

// ---------------------------------------------------------------------------
// Wire types — cents are plain integers on the wire (docs/03-api.md); brand them
// with money.ts's cents() at the display edge, not here.
// ---------------------------------------------------------------------------

// Field names verified against StaffSessionResource.php (M2): the resource also emits
// `is_admin` and `permissions` on `user`, which docs/03-api.md's example omits.
export type StaffSession = {
  staff_token: string
  expires_at: string
  user: { id: string; name: string; is_admin: boolean; permissions: string[] }
}

export type Shift = {
  id: string
  register_id: string
  opened_by: string
  opened_at: string
  opening_float_cents: number
  closed_at: string | null
  counted_cash_cents: number | null
  expected_cash_cents: number | null
  variance_cents: number | null
}

export type SalesSummary = { orders_closed: number; total_cents: number; cash_cents: number }
export type CurrentShift = { shift: Shift; expected_cash_cents: number; sales_summary: SalesSummary }
export type ShiftCloseResult = { shift: Shift; expected_cash_cents: number; variance_cents: number; requires_approval: boolean }

export type OrderLine = {
  id: string
  name: string
  sku: string
  unit_price_cents: number
  qty: string
  tax_cents: number
  line_total_cents: number
  voided_at: string | null
}

export type Order = {
  id: string
  number: string
  status: 'open' | 'closed' | 'voided'
  table_ref: string | null
  business_date: string
  prices_include_tax: boolean
  subtotal_cents: number
  discount_cents: number
  tax_cents: number
  total_cents: number
  paid_cents: number
  version: number
  lines?: OrderLine[]
}

export type LookedUpVariant = {
  variant: {
    id: string
    product_id: string
    name: string
    sku: string
    barcode: string | null
    price_cents: number
    track_inventory: boolean
  }
}

export type PaymentOutcome = {
  payment: { id: string; driver: string; status: string; amount_cents: number; tendered_cents: number | null; change_cents: number | null }
  order: Order
}

export type Receipt = {
  business: { name: string; address: string | null; tax_id: string | null }
  location: { name: string; header: string | null; footer: string | null }
  order: { number: string; business_date: string; opened_at: string; closed_at: string | null; table_ref: string | null; cashier: string; prices_include_tax: boolean }
  lines: Array<{ name: string; sku: string; qty: string; unit_price_cents: number; line_total_cents: number; tax_cents: number }>
  totals: { subtotal_cents: number; discount_cents: number; tax_cents: number; total_cents: number; paid_cents: number }
  payments: Array<{ driver: string; amount_cents: number; tendered_cents: number | null; change_cents: number | null }>
  currency: string
}

export const api = {
  health: () => request<Health>('/health'),

  staffLogin: async (pin: string): Promise<StaffSession> => {
    const session = await post<StaffSession>('/staff/login', { pin })
    tokens.setStaff(session.staff_token)
    return session
  },
  staffLogout: async (): Promise<void> => {
    await post('/staff/logout', {}).catch(() => undefined) // best-effort; local state clears regardless
    tokens.clearStaff()
  },

  currentShift: () => request<CurrentShift>('/shifts/current'),
  openShift: (openingFloatCents: number) =>
    post<{ shift: Shift }>('/shifts/open', { opening_float_cents: openingFloatCents }).then((r) => r.shift),
  // idempotencyKey is minted once by the caller and reused across retries of the SAME
  // close attempt — minting it here, per call, would defeat the point: a lost-response
  // retry would look like a brand-new close to the server.
  closeShift: (shiftId: string, countedCashCents: number, idempotencyKey: string) =>
    post<ShiftCloseResult>(`/shifts/${shiftId}/close`, { counted_cash_cents: countedCashCents }, { 'Idempotency-Key': idempotencyKey }),

  lookupBarcode: (barcode: string) => request<LookedUpVariant>(`/catalog/lookup?barcode=${encodeURIComponent(barcode)}`),

  openOrder: () => post<{ order: Order }>('/orders', {}).then((r) => r.order),
  // idempotencyKey is optional: retail's implicit "open order on first scan" path
  // doesn't need one, but a caller retrying a specific scan submission should pass one.
  addLine: (order: Order, variantId: string, qty = '1', idempotencyKey?: string) =>
    post<{ order: Order; line: OrderLine }>(
      `/orders/${order.id}/lines`,
      { variant_id: variantId, qty },
      { 'If-Match': String(order.version), ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}) },
    ).then((r) => r.order),
  // idempotencyKey is minted once by the caller (when the tender phase is entered) and
  // reused across retries — see closeShift's note.
  takePayment: (order: Order, amountCents: number, tenderedCents: number, idempotencyKey: string) =>
    post<PaymentOutcome>(
      `/orders/${order.id}/payments`,
      { driver: 'cash', amount_cents: amountCents, tendered_cents: tenderedCents },
      { 'If-Match': String(order.version), 'Idempotency-Key': idempotencyKey },
    ),

  receipt: (orderId: string) => request<Receipt>(`/orders/${orderId}/receipt`),
}
