'use client'

import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { ApiError } from '../lib/api'

const DISABLED_MESSAGE =
  'Your activation code has been disabled. Please contact an admin and request a new activation code.'

/**
 * The terminal's enrollment form: exchanges a one-time activation code (issued in the
 * back office) for this device's long-lived token. `disabled` renders the lockout
 * variant shown when the server revoked this terminal's token mid-life. `activate` is
 * injected (same pattern as ServerSetupScreen) so tests need no network.
 */
export function ActivationScreen({
  disabled = false,
  activate,
  onActivated,
}: {
  disabled?: boolean
  activate: (code: string) => Promise<unknown>
  onActivated: () => void
}) {
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (busy || code.trim() === '') return
    setBusy(true)
    setError(null)
    try {
      await activate(code.trim())
      onActivated()
    } catch (err) {
      setBusy(false)
      setError(err instanceof ApiError ? err.message : 'Activation failed.')
    }
  }

  return (
    <Card className="mx-auto mt-xxl w-full max-w-[480px]">
      <CardHeader>
        <CardTitle>{disabled ? 'Terminal disabled' : 'Activate this terminal'}</CardTitle>
      </CardHeader>
      <CardContent>
        {disabled ? (
          <p className="type-body-sm mb-lg text-error">{DISABLED_MESSAGE}</p>
        ) : (
          <p className="type-body-sm mb-lg text-ink-muted">
            Enter the activation code issued for this terminal in the back office.
          </p>
        )}
        <form className="flex flex-col gap-lg" onSubmit={submit}>
          <label className="type-body-sm flex flex-col gap-xs">
            Activation code
            <Input
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="XXXXX-XXXXX"
              autoFocus
              autoComplete="off"
              className="min-h-[48px] uppercase"
            />
          </label>
          {error && <p className="type-body-sm text-error">{error}</p>}
          <Button type="submit" size="lg" className="w-full" disabled={busy}>
            {busy ? 'Activating…' : 'Activate'}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
