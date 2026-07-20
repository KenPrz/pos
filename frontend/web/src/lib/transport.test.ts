// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { invoke } from '@tauri-apps/api/core'
import { inShell, send } from './transport'

vi.mock('@tauri-apps/api/core', () => ({
  invoke: vi.fn(),
}))

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('inShell', () => {
  it('is false in a plain browser', () => {
    expect(inShell()).toBe(false)
  })

  it('is true when Tauri has injected its internals', () => {
    vi.stubGlobal('__TAURI_INTERNALS__', {})
    expect(inShell()).toBe(true)
  })
})

describe('send', () => {
  it('uses relative fetch in the browser, preserving the /api/v1 prefix', async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response('{"data":1}', { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const result = await send('/health', { method: 'GET' })

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/health')
    expect(result).toEqual({ status: 200, body: '{"data":1}' })
  })

  it('propagates a non-2xx status rather than throwing', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{"error":{}}', { status: 422 })))

    await expect(send('/orders', { method: 'POST' })).resolves.toEqual({
      status: 422,
      body: '{"error":{}}',
    })
  })

  it('detours through invoke("api_request") in the shell, upper-casing method and nulling a non-string body', async () => {
    vi.stubGlobal('__TAURI_INTERNALS__', {})
    vi.mocked(invoke).mockResolvedValue({ status: 200, body: '{"data":1}' })

    const result = await send('/health', { method: 'get', body: { not: 'a string' } as unknown as BodyInit })

    expect(invoke).toHaveBeenCalledWith('api_request', {
      req: {
        path: '/health',
        method: 'GET',
        headers: {},
        body: null,
      },
    })
    expect(result).toEqual({ status: 200, body: '{"data":1}' })
  })
})
