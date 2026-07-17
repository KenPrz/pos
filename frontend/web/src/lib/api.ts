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
const STAFF_USER_KEY = 'pos.staff_user'

export const tokens = {
  device: () => localStorage.getItem(DEVICE_TOKEN_KEY),
  setDevice: (t: string) => localStorage.setItem(DEVICE_TOKEN_KEY, t),
  clearDevice: () => localStorage.removeItem(DEVICE_TOKEN_KEY),
  staff: () => localStorage.getItem(STAFF_TOKEN_KEY),
  setStaff: (t: string) => localStorage.setItem(STAFF_TOKEN_KEY, t),
  clearStaff: () => {
    localStorage.removeItem(STAFF_TOKEN_KEY)
    localStorage.removeItem(STAFF_USER_KEY)
  },
  // The signed-in user rides alongside the staff token so a page reload keeps the
  // name and permission list without a re-login. Same lifetime as the token.
  setStaffUser: (u: StaffSession['user']) => localStorage.setItem(STAFF_USER_KEY, JSON.stringify(u)),
  staffUser: (): StaffSession['user'] | null => {
    const raw = localStorage.getItem(STAFF_USER_KEY)
    if (!raw) return null
    try {
      return JSON.parse(raw) as StaffSession['user']
    } catch {
      return null
    }
  },
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

// DELETE endpoints here all take a JSON body (a reason, mostly) alongside If-Match —
// this project's void/discount-removal routes are DELETE-with-body, not bodyless.
function del<T>(path: string, body: unknown, extra?: Record<string, string>): Promise<T> {
  return request<T>(path, {
    method: 'DELETE',
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

/** An applied discount row — its id is what removal takes. */
export type OrderDiscount = {
  id: string
  discount_id: string | null
  order_line_id: string | null
  name: string
  amount_cents: number
  reason: string | null
}

export type Order = {
  id: string
  number: string
  register_id: string
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
  discounts?: OrderDiscount[]
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

// Verified against CatalogResource.php / GetCatalog.php: discounts is a global (not
// location-scoped) catalog. Only `discounts` is consumed by the register today, so the
// rest of the payload is left loosely typed rather than modeled field-by-field here.
export type Discount = {
  id: string
  name: string
  kind: 'percent' | 'fixed'
  percent_micros: number | null
  amount_cents: number | null
  scope: 'order' | 'line'
  requires_supervisor: boolean
}

export type Catalog = {
  categories: unknown[]
  products: unknown[]
  variants: unknown[]
  modifier_groups: unknown[]
  modifiers: unknown[]
  tax_rates: unknown[]
  discounts: Discount[]
}

// Verified against RefundResource.php.
export type RefundLine = {
  original_order_line_id: string
  qty: string
  amount_cents: number
  restock: boolean
}

export type Refund = {
  id: string
  original_order_id: string
  driver: string
  amount_cents: number
  reason: string
  business_date: string
  lines: RefundLine[]
}

// Verified against ZReportResource.php / GetZReport.php: sales_by_driver and
// refunds_by_driver are `driver => cents` maps (only drivers with activity are present);
// movements always has all three kinds, zero-filled.
export type ZReport = {
  shift: Shift
  sales_by_driver: Record<string, number>
  refunds_by_driver: Record<string, number>
  movements: { paid_in: number; payout: number; drop: number }
  orders_closed: number
  orders_voided: number
  expected_cash_cents: number
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
    tokens.setStaffUser(session.user)
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
  catalog: () => request<Catalog>('/catalog'),

  // The key matters here too: a lost response on the implicit first-scan open would
  // otherwise mint a second, invisible empty order on rescan — and open orders block
  // shift close. Callers reuse the scan attempt's key.
  openOrder: (idempotencyKey?: string) =>
    post<{ order: Order }>('/orders', {}, idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}).then((r) => r.order),
  // Closes a zero-total order (full comp / abandoned empty) without a tender.
  settleOrder: (order: Order) =>
    post<{ order: Order }>(`/orders/${order.id}/settle`, {}, { 'If-Match': String(order.version) }).then((r) => r.order),
  getOrder: (id: string) => request<{ order: Order }>(`/orders/${id}`).then((r) => r.order),
  // A targeted lookup (receipt number for refunds, an open tab), not a browse — mirrors
  // ListOrdersRequest, which requires at least one of the two.
  findOrders: (params: { number?: string; status?: 'open' | 'closed' | 'voided' }) => {
    const qs = new URLSearchParams()
    if (params.number) qs.set('number', params.number)
    if (params.status) qs.set('status', params.status)
    return request<{ orders: Order[] }>(`/orders?${qs.toString()}`).then((r) => r.orders)
  },
  // idempotencyKey is optional: retail's implicit "open order on first scan" path
  // doesn't need one, but a caller retrying a specific scan submission should pass one.
  addLine: (order: Order, variantId: string, qty = '1', idempotencyKey?: string) =>
    post<{ order: Order; line: OrderLine }>(
      `/orders/${order.id}/lines`,
      { variant_id: variantId, qty },
      { 'If-Match': String(order.version), ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}) },
    ).then((r) => r.order),
  voidLine: (order: Order, lineId: string, reason: string) =>
    del<{ order: Order }>(
      `/orders/${order.id}/lines/${lineId}`,
      { reason },
      { 'If-Match': String(order.version) },
    ).then((r) => r.order),
  // POST, not DELETE — VoidOrderController is mounted on /orders/{order}/void.
  voidOrder: (order: Order, reason: string) =>
    post<{ order: Order }>(
      `/orders/${order.id}/void`,
      { reason },
      { 'If-Match': String(order.version) },
    ).then((r) => r.order),
  applyDiscount: (order: Order, discountId: string, reason: string, orderLineId?: string) =>
    post<{ order: Order }>(
      `/orders/${order.id}/discounts`,
      { discount_id: discountId, order_line_id: orderLineId ?? null, reason },
      { 'If-Match': String(order.version) },
    ).then((r) => r.order),
  removeDiscount: (order: Order, orderDiscountId: string) =>
    del<{ order: Order }>(
      `/orders/${order.id}/discounts/${orderDiscountId}`,
      {},
      { 'If-Match': String(order.version) },
    ).then((r) => r.order),
  // idempotencyKey is minted once by the caller (when the tender phase is entered) and
  // reused across retries — see closeShift's note. tenderedCents/reference are options
  // because they're driver-specific: cash tenders (and gets a computed change_cents);
  // external_card supplies a reference instead, and tenders nothing (TakePaymentRequest
  // treats tendered_cents as absent when null, not literally zero).
  takePayment: (
    order: Order,
    amountCents: number,
    driver: 'cash' | 'external_card',
    idempotencyKey: string,
    options?: { tenderedCents?: number; reference?: string },
  ) =>
    post<PaymentOutcome>(
      `/orders/${order.id}/payments`,
      {
        driver,
        amount_cents: amountCents,
        tendered_cents: options?.tenderedCents ?? null,
        reference: options?.reference ?? null,
      },
      { 'If-Match': String(order.version), 'Idempotency-Key': idempotencyKey },
    ),

  receipt: (orderId: string) => request<Receipt>(`/orders/${orderId}/receipt`),

  // original_order_id + lines derive the amount server-side from the original lines'
  // frozen price/tax snapshot (RefundOrder.php) — the client only chooses qty/restock.
  refund: (
    originalOrderId: string,
    driver: 'cash',
    reason: string,
    lines: Array<{ original_order_line_id: string; qty: string; restock: boolean }>,
    idempotencyKey: string,
  ) =>
    post<{ refund: Refund }>(
      '/refunds',
      { original_order_id: originalOrderId, driver, reason, lines },
      { 'Idempotency-Key': idempotencyKey },
    ).then((r) => r.refund),

  zReport: (shiftId: string) => request<ZReport>(`/reports/z?shift_id=${encodeURIComponent(shiftId)}`),
}
