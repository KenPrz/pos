'use client'

import { useState, type FormEvent } from 'react'
import { FieldRow } from '../components/FieldRow'
import { Button } from '../components/ui/button'
import { Card, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
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
    <Card>
      <CardTitle className="mb-lg">Sign in</CardTitle>
      <form onSubmit={submit} className="flex flex-col gap-md">
        <FieldRow label="Email">
          <Input
            id="admin-email"
            type="email"
            autoComplete="username"
            autoFocus
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </FieldRow>
        <FieldRow label="Password">
          <Input
            id="admin-password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </FieldRow>
        <div>
          <Button type="submit" variant="primary" disabled={submitting}>
            Sign in
          </Button>
        </div>
      </form>
      {error && <p className="type-body-sm mt-md text-error">{error}</p>}
    </Card>
  )
}
