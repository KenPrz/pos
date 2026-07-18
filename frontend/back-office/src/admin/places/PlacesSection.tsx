'use client'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ApiError, api, type Location, type Register } from '../../lib/api'
import { EntityTable } from '../catalog/EntityTable'
import { LocationEditor } from './LocationEditor'
import { RegisterEditor } from './RegisterEditor'

type Tab = 'locations' | 'registers'

// Same list-query idiom as CatalogSection's useCatalogList (Task 9).
function useAdminList<T>(key: string, queryFn: () => Promise<T[]>, onUnauthorized: () => void) {
  const query = useQuery({ queryKey: ['admin', key], queryFn })
  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])
  return query
}

/**
 * Locations & Registers (Task 10): two tabs over the same tab-rail chrome CatalogSection
 * established, sharing the locations list (registers need it for their own location
 * column and the create-time picker).
 */
export function PlacesSection({ onUnauthorized }: { onUnauthorized: () => void }) {
  const [tab, setTab] = useState<Tab>('locations')

  return (
    <div className="menu-grid">
      <nav className="menu-rail" aria-label="Places tabs">
        <button type="button" className={`menu-rail-tab${tab === 'locations' ? ' active' : ''}`} aria-pressed={tab === 'locations'} onClick={() => setTab('locations')}>
          Locations
        </button>
        <button type="button" className={`menu-rail-tab${tab === 'registers' ? ' active' : ''}`} aria-pressed={tab === 'registers'} onClick={() => setTab('registers')}>
          Registers
        </button>
      </nav>

      <div style={{ flex: 1 }}>
        {tab === 'locations' && <LocationsPanel onUnauthorized={onUnauthorized} />}
        {tab === 'registers' && <RegistersPanel onUnauthorized={onUnauthorized} />}
      </div>
    </div>
  )
}

function LocationsPanel({ onUnauthorized }: { onUnauthorized: () => void }) {
  const locations = useAdminList('locations', api.locations.list, onUnauthorized)
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<Location | 'new' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const reactivate = useMutation({
    mutationFn: (id: string) => api.locations.update(id, { is_active: true }),
    onSuccess: () => {
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['admin', 'locations'] })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not reactivate the location.')
    },
  })

  if (locations.isLoading) return <p className="muted">Loading…</p>
  if (locations.isError) return <p className="error">Could not load locations.</p>

  if (editing !== null) {
    return (
      <LocationEditor
        location={editing === 'new' ? null : editing}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  return (
    <>
      <EntityTable<Location>
        title="Locations"
        columns={[
          { header: 'Code', render: (l) => l.code },
          { header: 'Name', render: (l) => l.name },
          { header: 'Timezone', render: (l) => l.timezone },
          { header: 'Prices include tax', render: (l) => (l.prices_include_tax ? 'Yes' : 'No') },
        ]}
        rows={locations.data ?? []}
        onEdit={(l) => setEditing(l)}
        onNew={() => setEditing('new')}
        onUnarchive={(l) => reactivate.mutate(l.id)}
        newLabel="New location"
        archivedLabel="INACTIVE"
        unarchiveLabel="Reactivate"
        emptyMessage="No locations yet."
      />
      {error && <p className="error">{error}</p>}
    </>
  )
}

function RegistersPanel({ onUnauthorized }: { onUnauthorized: () => void }) {
  const registers = useAdminList('registers', api.registers.list, onUnauthorized)
  const locations = useAdminList('locations', api.locations.list, onUnauthorized)
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<Register | 'new' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const reactivate = useMutation({
    mutationFn: (id: string) => api.registers.update(id, { is_active: true }),
    onSuccess: () => {
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['admin', 'registers'] })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not reactivate the register.')
    },
  })

  if (registers.isLoading || locations.isLoading) return <p className="muted">Loading…</p>
  if (registers.isError) return <p className="error">Could not load registers.</p>

  if (editing !== null) {
    return (
      <RegisterEditor
        register={editing === 'new' ? null : editing}
        locations={locations.data ?? []}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  const locationName = (id: string) => locations.data?.find((l) => l.id === id)?.name ?? '—'

  return (
    <>
      <EntityTable<Register>
        title="Registers"
        columns={[
          { header: 'Name', render: (r) => r.name },
          { header: 'Location', render: (r) => locationName(r.location_id) },
          { header: 'Mode', render: (r) => r.mode },
        ]}
        rows={registers.data ?? []}
        onEdit={(r) => setEditing(r)}
        onNew={() => setEditing('new')}
        onUnarchive={(r) => reactivate.mutate(r.id)}
        newLabel="New register"
        archivedLabel="INACTIVE"
        unarchiveLabel="Reactivate"
        emptyMessage="No registers yet."
      />
      {error && <p className="error">{error}</p>}
    </>
  )
}
