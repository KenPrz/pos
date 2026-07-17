'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ApiError, api, tokens, type Shift, type StaffSession } from '../lib/api'
import { PinScreen, SetupScreen } from './SessionScreens'
import { CloseShiftScreen, OpenShiftScreen } from './ShiftScreens'
import { SaleScreen } from './SaleScreen'
import { RefundScreen } from './RefundScreen'

type StaffUser = StaffSession['user']

type Stage =
  | { name: 'booting' }
  | { name: 'setup' }
  | { name: 'pin' }
  | { name: 'loading-shift' }
  | { name: 'open-shift' }
  | { name: 'selling'; shift: Shift }
  | { name: 'refunds'; shift: Shift }
  | { name: 'closing'; shift: Shift }

// Nav-gold section word on the carbon bar — pure wayfinding, no behavior attached.
const SECTION_LABEL: Record<Stage['name'], string> = {
  booting: 'Loading',
  setup: 'Enroll Terminal',
  pin: 'Sign In',
  'loading-shift': 'Loading',
  'open-shift': 'Open Shift',
  selling: 'Register',
  refunds: 'Refunds',
  closing: 'Close Shift',
}

export function Register() {
  // Tokens live in localStorage, which does not exist while Next prerenders this tree —
  // so the machine boots neutral and resolves its real stage after mount.
  const [stage, setStage] = useState<Stage>({ name: 'booting' })
  const [user, setUser] = useState<StaffUser | null>(null)

  useEffect(() => {
    setUser(tokens.staffUser())
    setStage(!tokens.device() ? { name: 'setup' } : !tokens.staff() ? { name: 'pin' } : { name: 'loading-shift' })
  }, [])

  const sessionExpired = () => {
    tokens.clearStaff()
    setUser(null)
    setStage({ name: 'pin' })
  }

  // Resolving "is a shift open on this register?" — a real server-state read, so it goes
  // through React Query; the stage machine just reacts to the answer.
  const shiftQuery = useQuery({
    queryKey: ['current-shift'],
    queryFn: () => api.currentShift(),
    enabled: stage.name === 'loading-shift',
  })

  useEffect(() => {
    if (stage.name !== 'loading-shift') return
    if (shiftQuery.data) {
      setStage({ name: 'selling', shift: shiftQuery.data.shift })
      return
    }
    const err = shiftQuery.error
    if (err instanceof ApiError && err.status === 404) setStage({ name: 'open-shift' })
    else if (err instanceof ApiError && err.status === 401) sessionExpired()
  }, [stage.name, shiftQuery.data, shiftQuery.error])

  const clockOut = async () => {
    await api.staffLogout()
    setUser(null)
    setStage({ name: 'pin' })
  }

  const permissions = user?.permissions ?? []
  const can = (permission: string) => user !== null && (user.is_admin || permissions.includes(permission))
  const onShift = stage.name === 'selling' || stage.name === 'refunds'

  return (
    <main className="shell">
      <header className="carbon-bar">
        <span className="pos-pill">POS</span>
        <span className="carbon-bar-section">{SECTION_LABEL[stage.name]}</span>
        {onShift && can('refund.create') && (
          <button
            type="button"
            className="carbon-bar-link"
            onClick={() =>
              setStage(stage.name === 'refunds' ? { name: 'selling', shift: stage.shift } : { name: 'refunds', shift: stage.shift })
            }
          >
            {stage.name === 'refunds' ? 'Register' : 'Refunds'}
          </button>
        )}
        {user && onShift && (
          <span className="carbon-bar-right">
            <span className="carbon-bar-user">{user.name}</span>
            <button type="button" className="btn btn-secondary btn-clockout" onClick={clockOut}>
              Clock out
            </button>
          </span>
        )}
      </header>

      <div className="plate chamfer register-body">
        {(stage.name === 'booting' || stage.name === 'loading-shift') && <p className="muted">Loading…</p>}
        {stage.name === 'setup' && <SetupScreen onDone={() => setStage({ name: 'pin' })} />}
        {stage.name === 'pin' && (
          <PinScreen
            onLoggedIn={(session) => {
              setUser(session.user)
              setStage({ name: 'loading-shift' })
            }}
            onDeviceInvalid={() => setStage({ name: 'setup' })}
          />
        )}
        {stage.name === 'open-shift' && (
          <OpenShiftScreen onOpened={(shift) => setStage({ name: 'selling', shift })} onSessionExpired={sessionExpired} />
        )}
        {stage.name === 'selling' && (
          <SaleScreen
            can={can}
            onCloseShift={() => setStage({ name: 'closing', shift: stage.shift })}
            onSessionExpired={sessionExpired}
          />
        )}
        {stage.name === 'refunds' && (
          <RefundScreen onDone={() => setStage({ name: 'selling', shift: stage.shift })} onSessionExpired={sessionExpired} />
        )}
        {stage.name === 'closing' && (
          <CloseShiftScreen
            shiftId={stage.shift.id}
            onCancel={() => setStage({ name: 'selling', shift: stage.shift })}
            onClosed={() => {
              tokens.clearStaff() // the server revoked the session at close
              setUser(null)
              setStage({ name: 'pin' })
            }}
            onSessionExpired={sessionExpired}
          />
        )}
      </div>
    </main>
  )
}
