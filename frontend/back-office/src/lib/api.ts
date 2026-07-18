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

export const adminToken = {
  get: () => localStorage.getItem(ADMIN_TOKEN_KEY),
  set: (t: string) => localStorage.setItem(ADMIN_TOKEN_KEY, t),
  clear: () => localStorage.removeItem(ADMIN_TOKEN_KEY),
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

// ---------------------------------------------------------------------------
// Wire types — verified against AdminSessionResource.php.
// ---------------------------------------------------------------------------

export type AdminUser = { id: string; name: string; email: string | null; is_admin: boolean }
export type AdminSession = { token: string; user: AdminUser }

export const api = {
  login: async (email: string, password: string): Promise<AdminSession> => {
    const session = await post<AdminSession>('/admin/login', { email, password })
    adminToken.set(session.token)
    return session
  },
  // Best-effort: the token is cleared locally regardless of whether the server round
  // trip succeeds, same convention as the register's staffLogout.
  logout: async (): Promise<void> => {
    await post('/admin/logout', {}).catch(() => undefined)
    adminToken.clear()
  },

  // Tasks 9-11 extend from here:
  // categories/taxRates/products/variants/modifierGroups/modifiers/discounts:
  //   list<T>(): Promise<T[]>; create(body): Promise<T>; update(id, body): Promise<T>
  // setProductModifierGroups(productId, groupIds: string[])
  // users: list/create/update per Task 4 shapes
  // locations, registers (+ reissueToken(registerId): Promise<string>)
  // salesReport(params): Promise<SalesReport>; stockReport(params)
  // audit(params): Promise<AuditPage>
}
