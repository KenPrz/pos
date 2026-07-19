'use client'

import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { ApiError, api, tokens, type StaffSession } from '../lib/api'

export function SetupScreen({ onDone }: { onDone: () => void }) {
  const [token, setToken] = useState('')

  return (
    <Card className="mx-auto mt-xxl w-full max-w-[480px]">
      <CardHeader>
        <CardTitle>Enroll this terminal</CardTitle>
      </CardHeader>
      <CardContent>
        <p className="type-body-sm mb-lg text-ink-muted">
          Paste a device token — printed by <code>php artisan migrate:fresh --seed</code>, or issued via
          POST /api/v1/registers/enroll.
        </p>
        <form
          className="flex flex-col gap-lg"
          onSubmit={(e) => {
            e.preventDefault()
            if (!token.trim()) return
            tokens.setDevice(token.trim())
            onDone()
          }}
        >
          <Input value={token} onChange={(e) => setToken(e.target.value)} placeholder="1|xxxxxxxx…" autoFocus className="min-h-[48px]" />
          <Button type="submit" size="lg" className="w-full">Save</Button>
        </form>
      </CardContent>
    </Card>
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
    <Card className="mx-auto mt-xxl w-full max-w-[360px]">
      <CardHeader>
        <CardTitle>Enter PIN</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="flex flex-col gap-lg">
          <Input
            type="password" inputMode="numeric" autoComplete="off" autoFocus
            value={pin} onChange={(e) => setPin(e.target.value)} placeholder="••••"
            className="h-[56px] text-center text-[24px]"
          />
          <Button type="submit" size="lg" className="w-full">Clock in</Button>
        </form>
        {error && <p className="type-body-sm mt-md text-error">{error}</p>}
      </CardContent>
    </Card>
  )
}
