'use client'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, api } from '../../lib/api'
import { FieldRow } from '../../components/FieldRow'
import { SectionHeader } from '../../components/SectionHeader'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Input } from '../../components/ui/input'

/**
 * The whole registry (`Settings::REGISTRY`, backend) — fixed and small enough that this
 * is a bespoke three-field form in the `LocationEditor` manual-state shape, not a
 * generic key/value list. Labels are display copy; `key` is the wire key verified
 * against `Settings.php`.
 */
const FIELDS: { key: string; label: string }[] = [
  { key: 'business.name', label: 'Business name' },
  { key: 'business.address', label: 'Business address' },
  { key: 'business.tax_id', label: 'Tax ID' },
]

/**
 * Settings (Task 11, RBAC v2): business identity, database-first with a config
 * fallback. An emptied field PATCHes `null`, which the server reads as "clear the
 * override, fall back to config again" (`UpdateSettings.php`) — never a stored empty
 * string. Gated behind `settings.manage` by Shell before this ever mounts.
 */
export function SettingsSection({ onUnauthorized }: { onUnauthorized: () => void }) {
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['admin', 'settings'], queryFn: api.settings.get })
  // Local editable copy, seeded from the query exactly once it lands — same "manual
  // state initialized from the fetched row" idiom every editor in this app uses, just
  // without a route param to key it (there is only ever one settings screen).
  const [values, setValues] = useState<Record<string, string> | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])

  useEffect(() => {
    if (query.data && values === null) {
      const seeded: Record<string, string> = {}
      for (const setting of query.data) seeded[setting.key] = setting.value ?? ''
      setValues(seeded)
    }
  }, [query.data, values])

  const save = useMutation({
    mutationFn: (changes: Record<string, string | null>) => api.settings.update(changes),
    onSuccess: () => {
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['admin', 'settings'] })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save settings.')
    },
  })

  if (query.isLoading || values === null) return <p className="type-body-sm text-ink-muted">Loading…</p>
  if (query.isError) return <p className="type-body-sm text-error">Could not load settings.</p>

  const settingsByKey = new Map((query.data ?? []).map((s) => [s.key, s]))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    const changes: Record<string, string | null> = {}
    for (const field of FIELDS) {
      const original = settingsByKey.get(field.key)?.value ?? ''
      const current = values[field.key] ?? ''
      if (current !== original) changes[field.key] = current === '' ? null : current
    }
    if (Object.keys(changes).length === 0) return
    save.mutate(changes)
  }

  return (
    <div className="flex flex-col gap-lg">
      <SectionHeader title="Settings" subline="Business identity shown on receipts and reports." />
      <Card>
        <CardTitle className="mb-md">Business identity</CardTitle>
        <form onSubmit={submit} className="flex flex-col gap-md">
          {FIELDS.map((field) => {
            const setting = settingsByKey.get(field.key)
            return (
              <FieldRow key={field.key} label={field.label}>
                <>
                  <Input
                    id={`settings-${field.key}`}
                    value={values[field.key] ?? ''}
                    onChange={(e) => setValues((v) => ({ ...(v ?? {}), [field.key]: e.target.value }))}
                  />
                  {setting?.source === 'config' && (
                    <p className="type-caption mt-xxs text-ink-muted">from config — saving stores an override</p>
                  )}
                </>
              </FieldRow>
            )
          })}
          <div>
            <Button type="submit" variant="primary" disabled={save.isPending}>
              {save.isPending ? 'Saving…' : 'Save'}
            </Button>
          </div>
        </form>
      </Card>
      {error && <p className="type-body-sm text-error">{error}</p>}
    </div>
  )
}
