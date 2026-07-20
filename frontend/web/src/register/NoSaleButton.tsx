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
  const [busy, setBusy] = useState(false)

  if (!open) {
    return (
      <Button
        type="button"
        variant="ghost"
        className="self-stretch"
        onClick={() => {
          setReason('')
          setError(null)
          setOpen(true)
        }}
      >
        No sale
      </Button>
    )
  }

  return (
    <form
      className="flex min-w-0 flex-1 items-center gap-sm px-sm"
      onSubmit={async (e) => {
        e.preventDefault()
        if (busy || reason.trim() === '') return
        setBusy(true)
        setError(null)
        try {
          // Server first, always: a drawer that opened before the audit row existed is
          // exactly the hole this endpoint closes.
          await authorize(reason.trim())
        } catch {
          setError('Could not open the drawer.')
          setBusy(false)
          return
        }
        try {
          await pulse()
        } catch {
          setError('Could not open the drawer.')
          setBusy(false)
          return
        }
        setBusy(false)
        setOpen(false)
        setReason('')
      }}
    >
      <Input
        autoFocus
        className="min-h-[48px] min-w-0 flex-1"
        placeholder="Reason for opening the drawer…"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
      <Button type="submit" className="min-h-[48px]" disabled={busy}>
        Open drawer
      </Button>
      <Button
        type="button"
        variant="ghost"
        className="min-h-[48px]"
        onClick={() => {
          setReason('')
          setError(null)
          setOpen(false)
        }}
      >
        Cancel
      </Button>
      {error && <span className="type-body-sm text-error">{error}</span>}
    </form>
  )
}
