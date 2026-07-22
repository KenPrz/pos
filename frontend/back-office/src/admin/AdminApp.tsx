'use client'

import { useQuery } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { ApiError, adminToken, api, type AdminSession, type AdminUser } from '../lib/api'
import { initCurrencyFromStorage } from '../lib/currency'
import { LoginScreen } from './LoginScreen'
import { Shell } from './Shell'

type Stage = { name: 'booting' } | { name: 'login' } | { name: 'shell' }

// Session-expiry convention (mirrors the register app): whichever screen's query or
// mutation surfaces a 401 calls `handleUnauthorized` below, dropping back to this same
// 'login' stage — see the register's Register.tsx `sessionExpired`/`onSessionExpired`
// wiring for the pattern. Threaded into Shell as `onUnauthorized` (Task 9), which passes
// it to CatalogSection and on into every list query / mutation under it; Tasks 10-11
// wire their own sections the same way.
export function AdminApp() {
  // The token lives in localStorage, which does not exist while Next prerenders this
  // tree — so the machine boots neutral and resolves its real stage after mount.
  const [stage, setStage] = useState<Stage>({ name: 'booting' })
  const [user, setUser] = useState<AdminUser | null>(null)
  // Which back-office sections this session may see (RBAC v2 Task 11) — threaded into
  // Shell, which gates its nav against it. `reportLocationIds` is `null` for an admin
  // (every location) or the union of locations a report permission is held at otherwise;
  // it narrows the location switcher below, same restore-from-storage story as `user`.
  const [sections, setSections] = useState<string[]>([])
  const [reportLocationIds, setReportLocationIds] = useState<string[] | null>(null)
  // The sidebar's location switcher lives here — the ONE place that owns "which
  // location" for the whole shell. Today and Reports/Stock all read it as a prop (the
  // per-screen pickers are gone — the frozen contract's named switcher-relocation
  // exception).
  const [locationId, setLocationId] = useState<string | null>(null)

  useEffect(() => {
    // A reload has no in-memory `user` yet — hydrate it from the cache Task 8's review
    // added alongside the token, so the carbon bar shows a name immediately rather than
    // waiting on a real query (there isn't a "who am I" endpoint to ask). The currency
    // (set by login) needs the same restore: a reload has a token but no fresh login
    // response, so pull it back from localStorage rather than defaulting to 'USD'.
    initCurrencyFromStorage()
    if (adminToken.get()) {
      setUser(adminToken.user())
      setSections(adminToken.sections())
      setReportLocationIds(adminToken.reportLocationIds())
      setStage({ name: 'shell' })
    } else {
      setStage({ name: 'login' })
    }
  }, [])

  const logout = async () => {
    await api.logout()
    setUser(null)
    setSections([])
    setReportLocationIds(null)
    setStage({ name: 'login' })
  }

  // The shared 401 convention (register app idiom): whichever screen's query or mutation
  // hits a 401 calls this, same effect as logout minus the (pointless — the token is
  // already dead) server round trip.
  const handleUnauthorized = useCallback(() => {
    adminToken.clear()
    adminToken.clearUser()
    setUser(null)
    setSections([])
    setReportLocationIds(null)
    setStage({ name: 'login' })
  }, [])

  // Cached the same way PlacesSection fetches locations — same queryKey, so this
  // doesn't cost a second request once a section mounts its own.
  const locations = useQuery({
    queryKey: ['admin', 'locations'],
    queryFn: api.locations.list,
    enabled: stage.name === 'shell',
  })

  useEffect(() => {
    if (locations.error instanceof ApiError && locations.error.status === 401) handleUnauthorized()
  }, [locations.error, handleUnauthorized])

  // `reportLocationIds === null` means admin (every location); otherwise the switcher
  // (which feeds Today and Reports) narrows down to only what this session holds a
  // report permission at — a report-scoped user should never be able to pick a
  // location they can't run a report for in the first place.
  const visibleLocations = useMemo(() => {
    const all = locations.data ?? []
    if (reportLocationIds === null) return all
    return all.filter((l) => reportLocationIds.includes(l.id))
  }, [locations.data, reportLocationIds])

  // Default to the first (visible) location once the list arrives — the switcher never
  // sits empty when there is anything to pick.
  useEffect(() => {
    if (!locationId && visibleLocations.length > 0) {
      setLocationId(visibleLocations[0].id)
    }
  }, [visibleLocations, locationId])

  // Booting and login are chrome-less relative to the shell: Shell is the one that
  // owns the full chassis (the AppSidebar + section body) — nesting a second <main>
  // inside Shell's would be wrong, so these two earlier stages get their own, a
  // centered card on the plain Carbon canvas.
  if (stage.name === 'booting') {
    return (
      <main className="flex min-h-dvh items-center justify-center bg-canvas">
        <p className="type-body-sm text-ink-muted">Loading…</p>
      </main>
    )
  }

  if (stage.name === 'login') {
    return (
      <main className="flex min-h-dvh items-center justify-center bg-canvas p-lg">
        <div className="w-full max-w-[28rem]">
          <LoginScreen
            onLoggedIn={(session: AdminSession) => {
              setUser(session.user)
              setSections(session.sections)
              setReportLocationIds(session.report_location_ids)
              setStage({ name: 'shell' })
            }}
          />
        </div>
      </main>
    )
  }

  const selectedLocation = visibleLocations.find((l) => l.id === locationId) ?? null

  return (
    <Shell
      user={user}
      sections={sections}
      onLogout={logout}
      onUnauthorized={handleUnauthorized}
      location={selectedLocation}
      locations={visibleLocations}
      onLocationChange={setLocationId}
    />
  )
}
