'use client'

import { useEffect, useState } from 'react'
import { adminToken, api, type AdminSession, type AdminUser } from '../lib/api'
import { LoginScreen } from './LoginScreen'
import { Shell } from './Shell'

type Stage = { name: 'booting' } | { name: 'login' } | { name: 'shell' }

// Session-expiry convention (mirrors the register app): whichever screen's query or
// mutation surfaces a 401 is responsible for clearing the token and dropping back to
// this same 'login' stage — see the register's Register.tsx `sessionExpired`/
// `onSessionExpired` wiring for the pattern Tasks 9-11 extend as they add real,
// authenticated queries under Shell's sections. Login and logout (the only two
// endpoints this task wires up) already satisfy it without extra plumbing: a failed
// login never leaves the 'login' stage in the first place, and logout clears the
// token unconditionally, 401 or not.
export function AdminApp() {
  // The token lives in localStorage, which does not exist while Next prerenders this
  // tree — so the machine boots neutral and resolves its real stage after mount.
  const [stage, setStage] = useState<Stage>({ name: 'booting' })
  const [user, setUser] = useState<AdminUser | null>(null)

  useEffect(() => {
    setStage(adminToken.get() ? { name: 'shell' } : { name: 'login' })
  }, [])

  const logout = async () => {
    await api.logout()
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

  return <Shell user={user} onLogout={logout} />
}
