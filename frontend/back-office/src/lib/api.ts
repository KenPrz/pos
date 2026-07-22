/**
 * The admin API client. One place that knows the envelope from docs/03-api.md, so no
 * component ever unwraps `data` or branches on an HTTP status by hand. Deliberately a
 * separate module from the register's `src/lib/api.ts` — the back office authenticates
 * with an email+password admin token, never a device or staff token, and the two
 * surfaces should never accidentally share credentials.
 */

import { isoDate } from './date'
import { setCurrency } from './currency'

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
export type AdminSession = { token: string; user: AdminUser; currency: string }

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

// ---------------------------------------------------------------------------
// Users, locations & registers (Task 10) — verified against
// app/Http/Resources/Admin/{AdminUserResource,AdminLocationResource,AdminRegisterResource}.php.
// ---------------------------------------------------------------------------

/**
 * A user's role at one location. `location_name` rides along on every read (joined
 * server-side in RoleAssignments::describe) purely for display — writing a user only
 * ever sends `{ location_id, role }` back (RoleAssignments::sync ignores anything else),
 * so `location_name` is optional on the way out and simply dropped by the caller.
 */
export type RoleAssignment = { location_id: string; location_name: string; role: 'cashier' | 'supervisor' }

/**
 * A direct, per-location permission grant — independent of (and additive to) whatever
 * a user's role already carries at that location. `location_name` rides along on read
 * the same way `RoleAssignment.location_name` does (`PermissionAssignments::describe`);
 * writes only ever need `{ location_id, permission }`.
 */
export type PermissionGrant = { location_id: string; location_name?: string; permission: string }

// Deliberately not named `AdminUser` — that type already means "the signed-in admin"
// (AdminSessionResource, no roles/is_active). This is a managed user row.
export type ManagedUser = {
  id: string
  name: string
  email: string | null
  is_admin: boolean
  is_active: boolean
  roles: RoleAssignment[]
  permissions: PermissionGrant[]
}

// ---------------------------------------------------------------------------
// Role templates & the permission catalog (RBAC v2 Task 10) — verified against
// AdminRoleResource.php and ListPermissionsController.php.
// ---------------------------------------------------------------------------

export type Role = { id: string; name: string; is_system: boolean; permissions: string[]; assigned_users: number }

export type PermissionGroup = { label: string; permissions: string[] }

export type Location = {
  id: string
  code: string
  name: string
  timezone: string
  prices_include_tax: boolean
  receipt_header: string | null
  receipt_footer: string | null
  is_active: boolean
}

export type RegisterActivation = {
  state: 'enrolled' | 'code_pending' | 'code_expired' | 'not_enrolled'
  code_expires_at: string | null
}

export type Register = {
  id: string
  location_id: string
  name: string
  mode: 'retail' | 'food'
  is_active: boolean
  activation: RegisterActivation
}

export type IssuedActivationCode = { activation_code: string; expires_at: string }

// ---------------------------------------------------------------------------
// Reports & audit wire types (Task 11) — verified against
// app/Http/Resources/Admin/{SalesReportResource,StockReportResource}.php and
// app/Actions/Admin/Audit/ListAuditLog.php.
// ---------------------------------------------------------------------------

export type SalesReportParams = {
  location_id: string
  from: string // 'YYYY-MM-DD', inclusive
  to: string // 'YYYY-MM-DD', inclusive
  group_by: 'day' | 'category' | 'user'
}

/**
 * One row shape covers both bases: `day`/`user` (ledger) populate the money fields,
 * `category` (lines) populates `qty_sold`/`line_total_cents`. Which fields are present
 * is determined by the `group_by` the caller chose — see `basis` on `SalesReport`,
 * which names which kind of number this response actually is.
 */
export type SalesReportRow = {
  bucket: string
  orders_closed?: number
  gross_cents?: number
  refunds_cents?: number
  net_cents?: number
  qty_sold?: string
  line_total_cents?: number
}

export type SalesReport = {
  rows: SalesReportRow[]
  totals: Omit<SalesReportRow, 'bucket'>
  // 'ledger': captured payments & refunds actually moved (day/user). 'lines': the sales
  // mix read off order lines, joined to the live catalog (category). The two are not
  // guaranteed to reconcile — never label one as the other.
  basis: 'ledger' | 'lines'
}

export type StockReportParams = { location_id: string; low_only?: boolean }

export type StockReportRow = { variant_id: string; sku: string; name: string; qty: string; low: boolean }

export type StockReport = { rows: StockReportRow[] }

export type AuditParams = {
  entity_type?: string
  entity_id?: string
  user_id?: string
  action?: string
  from?: string // 'YYYY-MM-DD', inclusive
  to?: string // 'YYYY-MM-DD', inclusive
  page?: number
}

export type AuditLogEntry = {
  id: string
  created_at: string
  action: string
  entity_type: string
  entity_id: string
  user_name: string | null
  register_name: string | null
  payload: unknown
}

export type AuditPage = { rows: AuditLogEntry[]; page: number; has_more: boolean }

// ---------------------------------------------------------------------------
// Today landing (Task 2, back-office UI rework) — zero new backend. The server has no
// single "today" endpoint and none is being added: this composes four EXISTING calls
// client-side (the design spec's frozen contract lists the Today landing's LABELS as
// one of exactly three permitted exceptions, never a new route).
// ---------------------------------------------------------------------------

export type TodayOverview = {
  /** `group_by: 'day'`, today..today, at the given location — ledger basis. */
  sales: SalesReport
  /** `low_only: true` at the given location. */
  stock: StockReport
  /** Every register (all locations) — callers filter to their own location's rows. */
  registers: Register[]
  /** First page only, unfiltered — the shell's "recent activity" glance, not a report. */
  audit: AuditPage
}

/**
 * Build a query string from a flat params object, dropping `undefined`/empty values —
 * every report/audit filter is optional-by-omission, never sent as the literal string
 * `"undefined"`.
 */
function qs(params: Record<string, string | number | boolean | undefined>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === '') continue
    search.set(key, String(value))
  }
  const s = search.toString()
  return s ? `?${s}` : ''
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
    // The back office's entry point for the server's currency — it has no catalog fetch
    // of its own (unlike the register). setCurrency also persists it, so a restored
    // session (stored token, no fresh login) still knows it — see lib/currency.ts.
    setCurrency(session.currency)
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

  users: catalogEntity<ManagedUser>('users', 'user'),
  roles: {
    ...catalogEntity<Role>('roles', 'role'),
    // Deletes a custom template outright (not an archive — `role_templates` has no
    // `is_active` column). 422 `role_template_in_use` if it's still assigned anywhere;
    // 422 `role_template_is_system` for `cashier`/`supervisor` (UI never offers this
    // for a system template in the first place, but the server is the real gate).
    deleteRole: (id: string): Promise<void> => post<void>(`/admin/roles/${id}/delete`, {}),
    // Static catalog data grouped for the role editor and the user permission-grant
    // picker alike — not a catalogEntity endpoint, there's nothing to create/update.
    permissionGroups: (): Promise<PermissionGroup[]> =>
      request<{ groups: PermissionGroup[] }>('/admin/permissions').then((r) => r.groups),
  },
  locations: catalogEntity<Location>('locations', 'location'),
  registers: {
    ...catalogEntity<Register>('registers', 'register'),
    // Issues (or reissues) the register's one-time activation code. The server revokes
    // the register's device token AND its staff sessions in the same transaction — the
    // old till goes dark the instant this succeeds (IssueActivationCode.php) and shows
    // its lockout screen until the new code is typed in. The code comes back exactly
    // once; raw device tokens never cross the API anymore.
    issueActivationCode: (registerId: string): Promise<IssuedActivationCode> =>
      post<IssuedActivationCode>(`/admin/registers/${registerId}/activation-code`, {}),
  },

  // ---------------------------------------------------------------------------
  // Reports & audit (Task 11) — verified against SalesReportResource.php,
  // StockReportResource.php and Actions/Admin/Audit/ListAuditLog.php.
  // ---------------------------------------------------------------------------
  reports: {
    sales: (params: SalesReportParams): Promise<SalesReport> => request<SalesReport>(`/admin/reports/sales${qs(params)}`),
    stock: (params: StockReportParams): Promise<StockReport> => request<StockReport>(`/admin/reports/stock${qs(params)}`),
  },
  audit: {
    list: (params: AuditParams): Promise<AuditPage> => request<AuditPage>(`/admin/audit${qs(params)}`),
  },

  today: {
    // Same `isoDate` SalesReportView's `defaultRange` uses — one shared helper
    // (`lib/date.ts`) rather than two copies of `toISOString().slice(0, 10)`.
    overview: (locationId: string): Promise<TodayOverview> => {
      const date = isoDate(new Date())
      return Promise.all([
        api.reports.sales({ location_id: locationId, from: date, to: date, group_by: 'day' }),
        api.reports.stock({ location_id: locationId, low_only: true }),
        api.registers.list(),
        api.audit.list({ page: 1 }),
      ]).then(([sales, stock, registers, audit]) => ({ sales, stock, registers, audit }))
    },
  },
}
