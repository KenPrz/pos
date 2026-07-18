'use client'

import { useState, type FormEvent } from 'react'
import { ApiError, api, type AdminSession } from '../lib/api'

export function LoginScreen({ onLoggedIn }: { onLoggedIn: (session: AdminSession) => void }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      const session = await api.login(email, password)
      onLoggedIn(session)
    } catch (err) {
      setPassword('')
      setError(err instanceof ApiError ? err.message : 'Login failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="form-panel">
      <h2>Sign in</h2>
      <form onSubmit={submit}>
        <label htmlFor="admin-email">
          Email
          <input
            id="admin-email"
            type="email"
            autoComplete="username"
            autoFocus
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </label>
        <label htmlFor="admin-password">
          Password
          <input
            id="admin-password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </label>
        <button type="submit" className="btn btn-submit" disabled={submitting}>
          Sign in
        </button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}
