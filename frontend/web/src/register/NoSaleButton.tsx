'use client'

import { useState } from 'react'
import { Button } from '../components/ui/button'
import { Input } from '../components/ui/input'

/**
 * Opening the drawer with no sale attached. The server authorizes and audits it; this
 * component only asks. `authorize` and `pulse` are injected so it tests without Tauri.
 */
export function NoSaleButton({
  authorize,
  pulse,
}: {
  authorize: (reason: string) => Promise<void>
  pulse: () => Promise<void>
}) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  if (!open) {
    return (
      <Button type="button" variant="ghost" className="self-stretch" onClick={() => setOpen(true)}>
        No sale
      </Button>
    )
  }

  return (
    <form
      className="flex items-center gap-sm"
      onSubmit={async (e) => {
        e.preventDefault()
        if (reason.trim() === '') return
        setError(null)
        try {
          // Server first, always: a drawer that opened before the audit row existed is
          // exactly the hole this endpoint closes.
          await authorize(reason.trim())
        } catch {
          setError('Could not open the drawer.')
          return
        }
        await pulse()
        setOpen(false)
        setReason('')
      }}
    >
      <Input
        autoFocus
        className="min-h-[48px]"
        placeholder="Reason for opening the drawer…"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
      <Button type="submit" size="lg">
        Open drawer
      </Button>
      <Button type="button" variant="ghost" size="lg" onClick={() => setOpen(false)}>
        Cancel
      </Button>
      {error && <span className="type-body-sm text-error">{error}</span>}
    </form>
  )
}
