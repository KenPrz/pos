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

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  let response: Response

  try {
    response = await fetch(`/api/v1${path}`, {
      ...init,
      headers: { Accept: 'application/json', ...init?.headers },
    })
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

export const api = {
  health: () => request<Health>('/health'),
}
