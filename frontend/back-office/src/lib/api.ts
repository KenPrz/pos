/**
 * The admin API client. One place that knows the envelope from docs/03-api.md, so no
 * component ever unwraps `data` or branches on an HTTP status by hand. Deliberately a
 * separate module from the register's `src/lib/api.ts` — the back office authenticates
 * with an email+password admin token, never a device or staff token, and the two
 * surfaces should never accidentally share credentials.
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

// ---------------------------------------------------------------------------
// The admin token — a Sanctum PAT minted by POST /admin/login, sent as a Bearer
// header. No device or staff token here (AdminSessionResource.php): the back office is
// location-less and authenticates the person, not a till.
// ---------------------------------------------------------------------------

const ADMIN_TOKEN_KEY = 'pos.admin_token'
const ADMIN_USER_KEY = 'pos.admin_user'

export const adminToken = {
  get: () => localStorage.getItem(ADMIN_TOKEN_KEY),
  set: (t: string) => localStorage.setItem(ADMIN_TOKEN_KEY, t),
  clear: () => localStorage.removeItem(ADMIN_TOKEN_KEY),
  // The signed-in user rides alongside the token (Task 8 review) so a page reload can
  // show a name in the carbon bar without waiting on a real query — same idiom as the
  // register app's tokens.setStaffUser/staffUser.
  setUser: (u: AdminUser) => localStorage.setItem(ADMIN_USER_KEY, JSON.stringify(u)),
  user: (): AdminUser | null => {
    const raw = localStorage.getItem(ADMIN_USER_KEY)
    if (!raw) return null
    try {
      return JSON.parse(raw) as AdminUser
    } catch {
      return null
    }
  },
  clearUser: () => localStorage.removeItem(ADMIN_USER_KEY),
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', ...(init?.headers as Record<string, string>) }
  const token = adminToken.get()
  if (token) headers.Authorization = `Bearer ${token}`

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

  // 204 No Content (logout) has no body to parse.
  const body: unknown = response.status === 204 ? null : await response.json().catch(() => null)

  if (!response.ok) {
    if (isErrorBody(body)) {
      throw new ApiError(body.error.code, body.error.message, response.status, body.error.details)
    }
    throw new ApiError('unexpected_response', `Unexpected ${response.status} from ${path}.`, response.status)
  }

  if (response.status === 204) return undefined as T

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

// PATCH: partial update, changed keys only — see docs/03-api.md and every editor's
// PATCH-discipline note (Task 9).
function patch<T>(path: string, body: unknown): Promise<T> {
  return request<T>(path, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
}

// PUT: full-set replace. Only the product<->modifier-group attach endpoint uses this.
function put<T>(path: string, body: unknown): Promise<T> {
  return request<T>(path, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
}

// ---------------------------------------------------------------------------
// Wire types — verified against AdminSessionResource.php.
// ---------------------------------------------------------------------------

export type AdminUser = { id: string; name: string; email: string | null; is_admin: boolean }
export type AdminSession = { token: string; user: AdminUser }

// ---------------------------------------------------------------------------
// Catalog wire types — verified against app/Http/Resources/Admin/*.php (Task 9).
// Field names match the models exactly; note the asymmetry the resources actually
// carry: categories and modifier groups have NO `is_active` (they don't archive —
// EntityTable's badge simply never renders for those two entities), while products,
// variants, modifiers, discounts and tax rates all do.
// ---------------------------------------------------------------------------

export type Category = { id: string; name: string; parent_id: string | null; sort_order: number }

export type TaxRate = { id: string; name: string; rate_micros: number; is_active: boolean }

export type Product = {
  id: string
  name: string
  description: string | null
  category_id: string | null
  kind: 'goods' | 'service'
  is_active: boolean
  // Ordered ids of the product's attached modifier groups, always present (Task 9 gap
  // fix) — AdminProductResource computes this from the loaded relation when eager-loaded
  // (ListProducts does) or a one-off query otherwise (create/update), so every response
  // that returns a Product carries it, unlike the older `modifier_groups` (name+position)
  // field it replaces for the client's purposes.
  modifier_group_ids: string[]
}

export type Variant = {
  id: string
  product_id: string
  name: string
  sku: string
  barcode: string | null
  price_cents: number
  cost_cents: number | null
  tax_rate_id: string | null
  track_inventory: boolean
  is_active: boolean
}

export type ModifierGroup = { id: string; name: string; min_select: number; max_select: number | null }

export type Modifier = {
  id: string
  group_id: string
  name: string
  price_delta_cents: number
  position: number
  is_active: boolean
}

export type Discount = {
  id: string
  name: string
  kind: 'percent' | 'fixed'
  percent_micros: number | null
  amount_cents: number | null
  scope: 'order' | 'line'
  requires_supervisor: boolean
  is_active: boolean
}

/**
 * One list+create+update trio per catalog entity. Every list endpoint wraps its rows
 * as `{ items: [...] }`; every create/update wraps the single row under the entity's
 * own singular key (`{ product: ... }`, `{ tax_rate: ... }`, etc — verified per-resource
 * against the Admin\Catalog controllers, not guessed from the plural route). `update`
 * takes a partial body on purpose — callers send only the fields that changed.
 */
function catalogEntity<T>(path: string, key: string) {
  return {
    list: (): Promise<T[]> => request<{ items: T[] }>(`/admin/${path}`).then((r) => r.items),
    create: (body: Record<string, unknown>): Promise<T> =>
      post<Record<string, T>>(`/admin/${path}`, body).then((r) => r[key]),
    update: (id: string, body: Record<string, unknown>): Promise<T> =>
      patch<Record<string, T>>(`/admin/${path}/${id}`, body).then((r) => r[key]),
  }
}

export const api = {
  login: async (email: string, password: string): Promise<AdminSession> => {
    const session = await post<AdminSession>('/admin/login', { email, password })
    adminToken.set(session.token)
    adminToken.setUser(session.user)
    return session
  },
  // Best-effort: the token is cleared locally regardless of whether the server round
  // trip succeeds, same convention as the register's staffLogout.
  logout: async (): Promise<void> => {
    await post('/admin/logout', {}).catch(() => undefined)
    adminToken.clear()
    adminToken.clearUser()
  },

  categories: catalogEntity<Category>('categories', 'category'),
  taxRates: catalogEntity<TaxRate>('tax-rates', 'tax_rate'),
  products: catalogEntity<Product>('products', 'product'),
  variants: catalogEntity<Variant>('variants', 'variant'),
  modifierGroups: catalogEntity<ModifierGroup>('modifier-groups', 'modifier_group'),
  modifiers: catalogEntity<Modifier>('modifiers', 'modifier'),
  discounts: catalogEntity<Discount>('discounts', 'discount'),

  // Full-set replace, ordered — position is the array index (SetProductModifierGroups.php).
  // Duplicates 400 server-side (`distinct` rule), so this never de-dupes client-side.
  setProductModifierGroups: (productId: string, groupIds: string[]): Promise<Product> =>
    put<{ product: Product }>(`/admin/products/${productId}/modifier-groups`, { group_ids: groupIds }).then(
      (r) => r.product,
    ),

  // Tasks 10-11 extend from here:
  // users: list/create/update per Task 4 shapes
  // locations, registers (+ reissueToken(registerId): Promise<string>)
  // salesReport(params): Promise<SalesReport>; stockReport(params)
  // audit(params): Promise<AuditPage>
}
