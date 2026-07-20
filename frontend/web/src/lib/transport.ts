import { invoke } from '@tauri-apps/api/core'

/**
 * The one place that knows whether we are a browser tab or the desktop shell.
 *
 * A bundled shell page has origin `tauri://localhost` and no Next rewrite, so the
 * relative `/api/v1` URL that keeps the browser single-origin does not exist there.
 * The shell detours through Rust instead: no CORS, and the server address lives in
 * Rust config so the webview never names a host. See
 * docs/superpowers/specs/2026-07-20-tauri-register-shell-design.md.
 */
export type TransportResponse = { status: number; body: string }

export function inShell(): boolean {
  return typeof window !== 'undefined' && '__TAURI_INTERNALS__' in window
}

export async function send(path: string, init: RequestInit): Promise<TransportResponse> {
  if (!inShell()) {
    const response = await fetch(`/api/v1${path}`, init)
    return { status: response.status, body: await response.text() }
  }

  return invoke<TransportResponse>('api_request', {
    req: {
      path,
      method: (init.method ?? 'GET').toUpperCase(),
      headers: (init.headers ?? {}) as Record<string, string>,
      body: typeof init.body === 'string' ? init.body : null,
    },
  })
}
