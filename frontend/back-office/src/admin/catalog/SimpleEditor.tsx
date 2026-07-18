'use client'

import { useState, type FormEvent } from 'react'

/**
 * A declarative field for SimpleEditor — enough kinds to cover categories (name,
 * parent select, sort order) and tax rates (name, percent, active) without either
 * needing a bespoke form. `percent` is the one special case: the wire value is
 * micros (`rate_micros`), but it displays and is typed as a percent — the
 * `rate_micros / 10_000` / `Math.round(pct * 10_000)` conversion from the brief,
 * done once here rather than in every caller.
 */
export type FieldSpec =
  | { key: string; label: string; kind: 'text' }
  | { key: string; label: string; kind: 'number' }
  | { key: string; label: string; kind: 'percent' }
  | { key: string; label: string; kind: 'select'; options: Array<{ value: string; label: string }> }
  | { key: string; label: string; kind: 'checkbox' }

type DisplayValue = string | boolean

function toDisplay(field: FieldSpec, wireValue: unknown): DisplayValue {
  if (field.kind === 'checkbox') return Boolean(wireValue)
  if (field.kind === 'percent') return wireValue == null ? '' : String((wireValue as number) / 10_000)
  if (wireValue === null || wireValue === undefined) return ''
  return String(wireValue)
}

function toWire(field: FieldSpec, display: DisplayValue): unknown {
  if (field.kind === 'checkbox') return Boolean(display)
  if (display === '') return null
  if (field.kind === 'number') return Number(display)
  if (field.kind === 'percent') return Math.round(Number(display) * 10_000)
  return display
}

/**
 * A generic record editor: renders one labeled input per `FieldSpec`, diffs against
 * `initial` on submit, and hands the caller only the changed wire keys — the PATCH
 * discipline every editor in this task follows. `initial === null` means "creating a
 * new row": every non-empty field is sent (there is nothing yet to diff against).
 */
export function SimpleEditor({
  title,
  fields,
  initial,
  saving,
  error,
  onSave,
  onCancel,
}: {
  title: string
  fields: FieldSpec[]
  initial: Record<string, unknown> | null
  saving: boolean
  error?: string | null
  onSave: (body: Record<string, unknown>) => void
  onCancel: () => void
}) {
  const [values, setValues] = useState<Record<string, DisplayValue>>(() =>
    Object.fromEntries(
      fields.map((f) => [
        f.key,
        toDisplay(f, initial ? initial[f.key] : f.kind === 'checkbox' ? true : undefined),
      ]),
    ),
  )

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const body: Record<string, unknown> = {}

    for (const f of fields) {
      const wire = toWire(f, values[f.key])
      if (initial === null) {
        if (wire !== null) body[f.key] = wire
        continue
      }
      const original = initial[f.key] ?? null
      if (wire !== original) body[f.key] = wire
    }

    // Archive behind a confirm (brief's global constraint) — only Tax Rates carry
    // `is_active` through this generic editor (Categories don't), but the check is
    // entity-agnostic: any `is_active: false` in the diff is an archive. UNARCHIVE (the
    // table action) never goes through here, so it needs no confirm. `initial !== null`
    // matters too: archiving is an edit-time concept — creating a new row with Active
    // unchecked isn't "archiving" anything (there's no prior active row to leave), so it
    // must never pop the confirm (a Cancel there would otherwise silently block create).
    if (initial !== null && body.is_active === false) {
      const label = typeof values.name === 'string' && values.name ? values.name : 'this record'
      if (!window.confirm(`Archive ${label}? It leaves the register catalog but stays in history.`)) return
    }

    onSave(body)
  }

  return (
    <section className="form-panel">
      <header className="row">
        <h2>{title}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Back
        </button>
      </header>

      <form onSubmit={submit}>
        {fields.map((f) => (
          <label key={f.key} htmlFor={`field-${f.key}`}>
            {f.label}
            {f.kind === 'select' ? (
              <select
                id={`field-${f.key}`}
                value={values[f.key] as string}
                onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
              >
                <option value="">—</option>
                {f.options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            ) : f.kind === 'checkbox' ? (
              <input
                id={`field-${f.key}`}
                type="checkbox"
                checked={values[f.key] as boolean}
                onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.checked }))}
              />
            ) : (
              <input
                id={`field-${f.key}`}
                type="text"
                inputMode={f.kind === 'number' || f.kind === 'percent' ? 'decimal' : undefined}
                value={values[f.key] as string}
                onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
              />
            )}
          </label>
        ))}
        <button type="submit" className="btn btn-submit" disabled={saving}>
          {saving ? 'Saving…' : 'Save'}
        </button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}
