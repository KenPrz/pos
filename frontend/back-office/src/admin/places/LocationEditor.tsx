'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location } from '../../lib/api'

// Intl.supportedValuesOf('timeZone') is the canonical IANA zone list the runtime knows
// about — computed once at module load (it never changes mid-session) rather than per
// render, so every LocationEditor mount reuses the same datalist options.
const TIMEZONES = Intl.supportedValuesOf('timeZone')

/**
 * Name, code (create-only in practice — the server allows a PATCH but the brief calls
 * it required-on-create, so this only enforces the DB's NOT NULL/unique via the same
 * server error path as everything else), timezone, prices_include_tax, receipt
 * header/footer. Field names verified against AdminLocationResource.php.
 */
export function LocationEditor({
  location,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  location: Location | null
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(location?.name ?? '')
  const [code, setCode] = useState(location?.code ?? '')
  const [timezone, setTimezone] = useState(location?.timezone ?? '')
  const [pricesIncludeTax, setPricesIncludeTax] = useState(location?.prices_include_tax ?? false)
  const [receiptHeader, setReceiptHeader] = useState(location?.receipt_header ?? '')
  const [receiptFooter, setReceiptFooter] = useState(location?.receipt_footer ?? '')
  const [isActive, setIsActive] = useState(location?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      location ? api.locations.update(location.id, body) : api.locations.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'locations'] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the location.')
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    if (timezone.trim() === '' || !TIMEZONES.includes(timezone)) {
      setError('Pick a timezone from the list.')
      return
    }

    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (location === null || value !== original) body[key] = value
    }
    put('name', name, location?.name)
    put('code', code, location?.code)
    put('timezone', timezone, location?.timezone)
    put('prices_include_tax', pricesIncludeTax, location?.prices_include_tax)
    put('receipt_header', receiptHeader || null, location?.receipt_header)
    put('receipt_footer', receiptFooter || null, location?.receipt_footer)
    if (location) put('is_active', isActive, location.is_active)

    // Archive-style confirm (brief's global constraint) — same as every other
    // is_active:false transition in this app.
    if (body.is_active === false && !window.confirm(`Deactivate ${name}? Its history stays, but staff can no longer sign in there.`)) {
      return
    }
    save.mutate(body)
  }

  return (
    <section className="form-panel">
      <header className="row">
        <h2>{location ? 'Edit location' : 'New location'}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Back
        </button>
      </header>

      <form onSubmit={submit}>
        <label htmlFor="location-name">
          Name
          <input id="location-name" value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <label htmlFor="location-code">
          Code
          <input id="location-code" value={code} onChange={(e) => setCode(e.target.value)} />
        </label>
        <label htmlFor="location-timezone">
          Timezone
          <input
            id="location-timezone"
            list="location-timezone-options"
            value={timezone}
            onChange={(e) => setTimezone(e.target.value)}
            placeholder="America/Chicago"
          />
          <datalist id="location-timezone-options">
            {TIMEZONES.map((tz) => (
              <option key={tz} value={tz} />
            ))}
          </datalist>
        </label>
        <label htmlFor="location-prices-include-tax">
          Prices include tax
          <input
            id="location-prices-include-tax"
            type="checkbox"
            checked={pricesIncludeTax}
            onChange={(e) => setPricesIncludeTax(e.target.checked)}
          />
        </label>
        <p className="muted">Applies to future orders only — orders already open keep the pricing basis they started with.</p>
        <label htmlFor="location-receipt-header">
          Receipt header
          <input id="location-receipt-header" value={receiptHeader} onChange={(e) => setReceiptHeader(e.target.value)} />
        </label>
        <label htmlFor="location-receipt-footer">
          Receipt footer
          <input id="location-receipt-footer" value={receiptFooter} onChange={(e) => setReceiptFooter(e.target.value)} />
        </label>
        {location && (
          <label htmlFor="location-active">
            Active
            <input id="location-active" type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          </label>
        )}
        <button type="submit" className="btn btn-submit" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save'}
        </button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}
