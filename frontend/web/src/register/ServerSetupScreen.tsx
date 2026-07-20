'use client'

import { useState } from 'react'
import { Button } from '../components/ui/button'
import { Card, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'

/**
 * Shown once, before enrolment, and only in the desktop shell: a bundled app has no
 * implicit origin, so it must be told which server to talk to. `check` and `save` are
 * injected so this is testable without Tauri.
 */
export function ServerSetupScreen({
  onConnected,
  save,
  check,
}: {
  onConnected: () => void
  save: (url: string) => Promise<void>
  check: (url: string) => Promise<boolean>
}) {
  const [url, setUrl] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  return (
    <main className="flex min-h-dvh items-center justify-center bg-canvas p-lg text-ink">
      <Card className="w-full max-w-[28rem] p-lg">
        <CardTitle>Connect this terminal</CardTitle>
        <p className="type-body-sm mt-sm text-ink-muted">
          The address of the POS server this till talks to.
        </p>
        <form
          className="mt-lg flex flex-col gap-md"
          onSubmit={async (e) => {
            e.preventDefault()
            if (busy || url.trim() === '') return
            setBusy(true)
            setError(null)
            // Validate before saving: a typo caught here is a typo not discovered at the
            // first sale of the morning.
            const reachable = await check(url.trim())
            if (!reachable) {
              setError('Cannot reach that server. Check the address and try again.')
              setBusy(false)
              return
            }
            await save(url.trim())
            setBusy(false)
            onConnected()
          }}
        >
          <label className="type-body-sm flex flex-col gap-xs">
            Server address
            <Input
              autoFocus
              className="min-h-[48px]"
              placeholder="https://pos.example.com"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
            />
          </label>
          {error && <p className="type-body-sm text-error">{error}</p>}
          <div>
            <Button type="submit" size="lg" disabled={busy}>
              {busy ? 'Connecting…' : 'Connect'}
            </Button>
          </div>
        </form>
      </Card>
    </main>
  )
}
