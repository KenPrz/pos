import { useState, type FormEvent } from 'react'
import { ApiError, api, tokens, type StaffSession } from '../lib/api'

export function SetupScreen({ onDone }: { onDone: () => void }) {
  const [token, setToken] = useState('')

  return (
    <section className="card">
      <h2>Enroll this terminal</h2>
      <p className="muted">
        Paste a device token — printed by <code>php artisan migrate:fresh --seed</code>, or issued via
        POST /api/v1/registers/enroll.
      </p>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          if (!token.trim()) return
          tokens.setDevice(token.trim())
          onDone()
        }}
      >
        <input value={token} onChange={(e) => setToken(e.target.value)} placeholder="1|xxxxxxxx…" autoFocus />
        <button type="submit">Save</button>
      </form>
    </section>
  )
}

export function PinScreen({ onLoggedIn, onDeviceInvalid }: {
  onLoggedIn: (session: StaffSession) => void
  onDeviceInvalid: () => void
}) {
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      onLoggedIn(await api.staffLogin(pin))
    } catch (err) {
      setPin('')
      // A stale device token (e.g. after a re-seed) would otherwise dead-end here —
      // the setup screen only shows when no token is stored.
      if (err instanceof ApiError && err.code === 'invalid_device_token') {
        tokens.clearDevice()
        onDeviceInvalid()
        return
      }
      setError(err instanceof ApiError ? err.message : 'Login failed.')
    }
  }

  return (
    <section className="card">
      <h2>Enter PIN</h2>
      <form onSubmit={submit}>
        <input
          type="password" inputMode="numeric" autoComplete="off" autoFocus
          value={pin} onChange={(e) => setPin(e.target.value)} placeholder="••••"
        />
        <button type="submit">Clock in</button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}
