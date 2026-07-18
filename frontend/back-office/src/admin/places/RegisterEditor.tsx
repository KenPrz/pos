'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location, type Register } from '../../lib/api'

/**
 * Name, mode (retail/food chips), active toggle, and the REISSUE TOKEN action.
 * `location_id` is a create-time-only decision (UpdateRegisterRequest marks it
 * `prohibited` on PATCH — see the comment there), so the location picker only shows up
 * for a brand-new register; editing one shows its location as plain text.
 *
 * Reissuing revokes every existing token for the register immediately
 * (ReissueDeviceToken.php) — confirmed behind a warning because the till holding the old
 * one goes dark the instant this succeeds — and the fresh token is shown exactly once in
 * a copy-me plate. It lives only in this component's state: never written to the cache,
 * never persisted, gone the moment this editor closes.
 */
export function RegisterEditor({
  register,
  locations,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  register: Register | null
  locations: Location[]
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [locationId, setLocationId] = useState(register?.location_id ?? locations[0]?.id ?? '')
  const [name, setName] = useState(register?.name ?? '')
  const [mode, setMode] = useState<'retail' | 'food'>(register?.mode ?? 'retail')
  const [isActive, setIsActive] = useState(register?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)
  const [reissuedToken, setReissuedToken] = useState<string | null>(null)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin', 'registers'] })
  const fail = (err: unknown, fallback: string) => {
    if (err instanceof ApiError && err.status === 401) return onUnauthorized()
    setError(err instanceof ApiError ? err.message : fallback)
  }

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      register ? api.registers.update(register.id, body) : api.registers.create(body),
    onSuccess: () => {
      invalidate()
      onDone()
    },
    onError: (err) => fail(err, 'Could not save the register.'),
  })

  const reissue = useMutation({
    mutationFn: () => api.registers.reissueToken(register?.id ?? ''),
    onSuccess: (token) => {
      setReissuedToken(token)
      setError(null)
    },
    onError: (err) => fail(err, 'Could not reissue the token.'),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (register === null || value !== original) body[key] = value
    }
    if (register === null) body.location_id = locationId
    put('name', name, register?.name)
    put('mode', mode, register?.mode)
    if (register) put('is_active', isActive, register.is_active)

    // Archive-style confirm (brief's global constraint) — same as every other
    // is_active:false transition in this app.
    if (body.is_active === false && !window.confirm(`Deactivate ${name}? It can no longer clock in a shift.`)) {
      return
    }
    save.mutate(body)
  }

  const requestReissue = () => {
    if (!window.confirm(`Reissue ${register?.name ?? 'this register'}'s token? The current till goes dark immediately.`)) return
    reissue.mutate()
  }

  return (
    <section className="form-panel">
      <header className="row">
        <h2>{register ? 'Edit register' : 'New register'}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Back
        </button>
      </header>

      <form onSubmit={submit}>
        {register ? (
          <p className="muted">Location: {locations.find((l) => l.id === register.location_id)?.name ?? register.location_id}</p>
        ) : (
          <label htmlFor="register-location">
            Location
            <select id="register-location" value={locationId} onChange={(e) => setLocationId(e.target.value)}>
              {locations.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.name}
                </option>
              ))}
            </select>
          </label>
        )}
        <label htmlFor="register-name">
          Name
          <input id="register-name" value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <fieldset>
          <legend>Mode</legend>
          {/* btn-secondary (selected) vs btn-utility (not) — a visual toggle without
              reaching for btn-submit's warm signal color, which DESIGN.md reserves for
              this form's one primary action (Save). */}
          <div className="btn-row">
            <button
              type="button"
              className={`btn btn-chip ${mode === 'retail' ? 'btn-secondary' : 'btn-utility'}`}
              aria-pressed={mode === 'retail'}
              onClick={() => setMode('retail')}
            >
              Retail
            </button>
            <button
              type="button"
              className={`btn btn-chip ${mode === 'food' ? 'btn-secondary' : 'btn-utility'}`}
              aria-pressed={mode === 'food'}
              onClick={() => setMode('food')}
            >
              Food
            </button>
          </div>
        </fieldset>
        {register && (
          <label htmlFor="register-active">
            Active
            <input id="register-active" type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          </label>
        )}
        <button type="submit" className="btn btn-submit" disabled={save.isPending || (register === null && locationId === '')}>
          {save.isPending ? 'Saving…' : 'Save'}
        </button>
      </form>
      {error && <p className="error">{error}</p>}

      {register && (
        <>
          <hr className="dotted-divider" />
          <h3>Device token</h3>
          <p className="muted">Lost or stolen terminal? Reissuing kills the old token immediately and mints a new one.</p>
          <button type="button" className="btn btn-secondary" disabled={reissue.isPending} onClick={requestReissue}>
            {reissue.isPending ? 'Reissuing…' : 'Reissue token'}
          </button>
          {reissuedToken && (
            <div className="plate" style={{ marginTop: 'var(--space-sm)', padding: 'var(--space-sm)' }}>
              <p className="muted">
                New token — copy it now, it will not be shown again:
              </p>
              <code style={{ userSelect: 'all', wordBreak: 'break-all' }}>{reissuedToken}</code>
            </div>
          )}
        </>
      )}
    </section>
  )
}
