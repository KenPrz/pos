'use client'

import { useEffect, useState } from 'react'
import { adminToken, api, type AdminSession, type AdminUser } from '../lib/api'
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

  useEffect(() => {
    // A reload has no in-memory `user` yet — hydrate it from the cache Task 8's review
    // added alongside the token, so the carbon bar shows a name immediately rather than
    // waiting on a real query (there isn't a "who am I" endpoint to ask).
    if (adminToken.get()) {
      setUser(adminToken.user())
      setStage({ name: 'shell' })
    } else {
      setStage({ name: 'login' })
    }
  }, [])

  const logout = async () => {
    await api.logout()
    setUser(null)
    setStage({ name: 'login' })
  }

  // The shared 401 convention (register app idiom): whichever screen's query or mutation
  // hits a 401 calls this, same effect as logout minus the (pointless — the token is
  // already dead) server round trip.
  const handleUnauthorized = () => {
    adminToken.clear()
    adminToken.clearUser()
    setUser(null)
    setStage({ name: 'login' })
  }

  // Booting and login are chrome-less relative to the shell: Shell is the one that
  // owns the full chassis (carbon bar + nav rail), same as the register's Register()
  // owns its single <main className="shell"> for every stage — nesting a second
  // <main> inside Shell's would be wrong, so these two earlier stages get their own.
  if (stage.name === 'booting') {
    return (
      <main className="shell">
        <div className="plate chamfer register-body">
          <p className="muted">Loading…</p>
        </div>
      </main>
    )
  }

  if (stage.name === 'login') {
    return (
      <main className="shell">
        <div className="plate chamfer register-body">
          <LoginScreen
            onLoggedIn={(session: AdminSession) => {
              setUser(session.user)
              setStage({ name: 'shell' })
            }}
          />
        </div>
      </main>
    )
  }

  return <Shell user={user} onLogout={logout} onUnauthorized={handleUnauthorized} />
}
