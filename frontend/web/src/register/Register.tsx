'use client'

import { useQuery, useQueryClient } from '@tanstack/react-query'
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
  const queryClient = useQueryClient()

  useEffect(() => {
    setUser(tokens.staffUser())
    setStage(!tokens.device() ? { name: 'setup' } : !tokens.staff() ? { name: 'pin' } : { name: 'loading-shift' })
  }, [])

  // A cached shift outliving the session that fetched it would let the next login
  // skip straight to selling on a closed drawer — evict it whenever a session ends.
  const endSession = () => {
    queryClient.removeQueries({ queryKey: ['current-shift'] })
    tokens.clearStaff()
    setUser(null)
    setStage({ name: 'pin' })
  }

  const sessionExpired = endSession

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
    // eslint-disable-next-line react-hooks/exhaustive-deps -- endSession is identity-stable in behavior; listing it would re-run on every render
  }, [stage.name, shiftQuery.data, shiftQuery.error])

  const clockOut = async () => {
    await api.staffLogout()
    endSession()
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
        {/* The sale screen stays MOUNTED (hidden) while on Refunds or Close Shift: its
            in-progress order lives in component state, and unmounting it would strand
            an open order server-side with no way back to it from the register. */}
        {(stage.name === 'selling' || stage.name === 'refunds' || stage.name === 'closing') && (
          <div hidden={stage.name !== 'selling'}>
            <SaleScreen
              can={can}
              registerId={stage.shift.register_id}
              onCloseShift={() => setStage({ name: 'closing', shift: stage.shift })}
              onSessionExpired={sessionExpired}
            />
          </div>
        )}
        {stage.name === 'refunds' && (
          <RefundScreen onDone={() => setStage({ name: 'selling', shift: stage.shift })} onSessionExpired={sessionExpired} />
        )}
        {stage.name === 'closing' && (
          <CloseShiftScreen
            shiftId={stage.shift.id}
            onCancel={() => setStage({ name: 'selling', shift: stage.shift })}
            onClosed={() => endSession()} // the server revoked the session at close
            onSessionExpired={sessionExpired}
          />
        )}
      </div>
    </main>
  )
}
