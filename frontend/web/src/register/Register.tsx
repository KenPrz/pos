'use client'

import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ApiError, api, tokens, type Order, type Shift, type StaffSession } from '../lib/api'
import { Button } from '@/components/ui/button'
import { inShell } from '../lib/transport'
import { checkServer, getConfig, setServerUrl } from '../lib/shell'
import { PinScreen, SetupScreen } from './SessionScreens'
// Aliased: this module's own SetupScreen (device enrolment, "Enroll Terminal") is a
// different, pre-existing, frozen screen from the shell's server-address setup below —
// same name, different file, both legitimately called "setup".
import { SetupScreen as ShellSetupScreen } from './SetupScreen'
import { CloseShiftScreen, OpenShiftScreen } from './ShiftScreens'
import { SaleScreen } from './SaleScreen'
import { RefundScreen } from './RefundScreen'
import { FloorScreen } from './FloorScreen'

type StaffUser = StaffSession['user']

type Stage =
  | { name: 'booting' }
  | { name: 'setup' }
  | { name: 'pin' }
  | { name: 'loading-shift' }
  | { name: 'open-shift' }
  | { name: 'selling'; shift: Shift }
  | { name: 'floor'; shift: Shift }
  | { name: 'refunds'; shift: Shift }
  | { name: 'closing'; shift: Shift }

// Section word on the top bar — pure wayfinding, no behavior attached.
const SECTION_LABEL: Record<Stage['name'], string> = {
  booting: 'Loading',
  setup: 'Enroll Terminal',
  pin: 'Sign In',
  'loading-shift': 'Loading',
  'open-shift': 'Open Shift',
  selling: 'Register',
  floor: 'Floor',
  refunds: 'Refunds',
  closing: 'Close Shift',
}

export function Register() {
  // Tokens live in localStorage, which does not exist while Next prerenders this tree —
  // so the machine boots neutral and resolves its real stage after mount.
  const [stage, setStage] = useState<Stage>({ name: 'booting' })
  const [user, setUser] = useState<StaffUser | null>(null)
  // Whether THIS register is food mode — decides the post-shift-resolve landing stage
  // (floor vs. selling) and whether the TABS/REGISTER toggle appears at all. Lives in
  // state, not read from tokens.registerInfo() at render time, for the same SSR reason
  // `stage` does: localStorage doesn't exist while Next prerenders this tree.
  const [foodMode, setFoodMode] = useState(false)
  // In the shell, nothing can be fetched until we know which server to ask. `null` means
  // "still checking", which must not flash the setup screen at a configured till.
  const [configured, setConfigured] = useState<boolean | null>(inShell() ? null : true)

  useEffect(() => {
    if (!inShell()) return
    void getConfig().then((config) => setConfigured(config?.server_url != null))
  }, [])

  // The order in progress on the (mounted-hidden) sale screen, if any — fed by
  // SaleScreen's onOrderChange so the floor screen can disable resuming a DIFFERENT
  // tab out from under an in-progress sale (Task 12).
  const [activeOrder, setActiveOrder] = useState<Order | null>(null)
  // Set by FloorScreen's onResume/onNewTab; handed to SaleScreen as `initialOrder` to
  // seed it, then the stage flips to `selling` so the (already-mounted) sale screen
  // becomes visible.
  const [resumeOrder, setResumeOrder] = useState<Order | null>(null)
  const queryClient = useQueryClient()

  useEffect(() => {
    setUser(tokens.staffUser())
    setFoodMode(tokens.registerInfo()?.mode === 'food')
    setStage(!tokens.device() ? { name: 'setup' } : !tokens.staff() ? { name: 'pin' } : { name: 'loading-shift' })
  }, [])

  // A cached shift outliving the session that fetched it would let the next login
  // skip straight to selling on a closed drawer — evict it whenever a session ends.
  //
  // resumeOrder/activeOrder must be cleared here too: SaleScreen only stays mounted
  // while stage is selling/floor/refunds/closing, so ending the session (stage → 'pin')
  // unmounts it. A stale resumeOrder would otherwise re-seed the NEXT login's freshly
  // mounted SaleScreen with the previous session's order (the seed effect keys on id,
  // and a fresh mount has no prior id to compare against) — and a stale activeOrder
  // would wrongly show that order as "in progress" on the floor, blocking every other
  // card's resume for no reason.
  const endSession = () => {
    queryClient.removeQueries({ queryKey: ['current-shift'] })
    tokens.clearStaff()
    setUser(null)
    setResumeOrder(null)
    setActiveOrder(null)
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
      // Food-mode registers land on the floor after shift resolve; retail is unchanged
      // (straight to selling — M5's floor/tab machinery is food-only).
      setStage(foodMode ? { name: 'floor', shift: shiftQuery.data.shift } : { name: 'selling', shift: shiftQuery.data.shift })
      return
    }
    const err = shiftQuery.error
    if (err instanceof ApiError && err.status === 404) setStage({ name: 'open-shift' })
    else if (err instanceof ApiError && err.status === 401) sessionExpired()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- endSession is identity-stable in behavior; listing it would re-run on every render
  }, [stage.name, shiftQuery.data, shiftQuery.error, foodMode])

  const clockOut = async () => {
    await api.staffLogout()
    endSession()
  }

  const permissions = user?.permissions ?? []
  const can = (permission: string) => user !== null && (user.is_admin || permissions.includes(permission))
  const onShift = stage.name === 'selling' || stage.name === 'refunds' || stage.name === 'floor'

  if (configured === null) return null
  if (!configured) {
    return (
      <ShellSetupScreen onConnected={() => setConfigured(true)} save={setServerUrl} check={checkServer} />
    )
  }

  return (
    <main className="flex min-h-dvh flex-col bg-canvas text-ink">
      {/* Slim Carbon top bar (DESIGN.md top-nav: 48px, canvas, 1px bottom hairline) —
          brand block, section word, stage toggles, staff name + Clock out. Hidden in
          print so a Z-report/receipt page prints without chrome. */}
      <header className="flex h-[48px] shrink-0 items-stretch border-b border-hairline bg-canvas print:hidden">
        <span className="flex items-center bg-ink px-md text-[14px] font-semibold tracking-[0.16px] text-inverse-ink">POS</span>
        <span className="type-body-sm flex items-center px-md text-ink-muted">{SECTION_LABEL[stage.name]}</span>
        {onShift && can('refund.create') && (
          <Button
            type="button"
            variant="ghost"
            className="self-stretch"
            onClick={() =>
              setStage(stage.name === 'refunds' ? { name: 'selling', shift: stage.shift } : { name: 'refunds', shift: stage.shift })
            }
          >
            {stage.name === 'refunds' ? 'Register' : 'Refunds'}
          </Button>
        )}
        {/* Food mode only — mirrors the Refunds toggle idiom above. Retail registers
            never see the floor at all (M5's tab/table machinery is food-only). */}
        {onShift && foodMode && (
          <Button
            type="button"
            variant="ghost"
            className="self-stretch"
            onClick={() =>
              setStage(stage.name === 'floor' ? { name: 'selling', shift: stage.shift } : { name: 'floor', shift: stage.shift })
            }
          >
            {stage.name === 'floor' ? 'Register' : 'Tabs'}
          </Button>
        )}
        {user && onShift && (
          <span className="ml-auto flex items-center gap-md">
            <span className="type-body-sm text-ink-muted">{user.name}</span>
            <Button type="button" variant="ghost" className="self-stretch" onClick={clockOut}>
              Clock out
            </Button>
          </span>
        )}
      </header>

      {/* The content shell: a single grid slot today; Task 8's sale screen renders its
          two panes (cart | context) inside it. Screens that predate the rework still
          draw their own layout in here and keep working untouched. */}
      <div className="mx-auto grid w-full max-w-[1584px] flex-1 grid-cols-1 content-start p-lg">
        {(stage.name === 'booting' || stage.name === 'loading-shift') && (
          <p className="type-body-sm text-ink-muted">Loading…</p>
        )}
        {stage.name === 'setup' && <SetupScreen onDone={() => setStage({ name: 'pin' })} />}
        {stage.name === 'pin' && (
          <PinScreen
            onLoggedIn={(session) => {
              setUser(session.user)
              setFoodMode(session.register.mode === 'food')
              setStage({ name: 'loading-shift' })
            }}
            onDeviceInvalid={() => setStage({ name: 'setup' })}
          />
        )}
        {stage.name === 'open-shift' && (
          <OpenShiftScreen onOpened={(shift) => setStage({ name: 'selling', shift })} onSessionExpired={sessionExpired} />
        )}
        {/* The sale screen stays MOUNTED (hidden) while on Refunds, the Floor, or Close
            Shift: its in-progress order lives in component state, and unmounting it
            would strand an open order server-side with no way back to it from the
            register. */}
        {(stage.name === 'selling' || stage.name === 'floor' || stage.name === 'refunds' || stage.name === 'closing') && (
          <div hidden={stage.name !== 'selling'}>
            <SaleScreen
              can={can}
              registerId={stage.shift.register_id}
              initialOrder={resumeOrder ?? undefined}
              onOrderChange={setActiveOrder}
              onCloseShift={() => setStage({ name: 'closing', shift: stage.shift })}
              onSessionExpired={sessionExpired}
            />
          </div>
        )}
        {stage.name === 'floor' && (
          <FloorScreen
            registerId={stage.shift.register_id}
            canTransfer={can('order.transfer')}
            activeOrderId={activeOrder?.id ?? null}
            onResume={(order) => {
              setResumeOrder(order)
              setStage({ name: 'selling', shift: stage.shift })
            }}
            onNewTab={(order) => {
              setResumeOrder(order)
              setStage({ name: 'selling', shift: stage.shift })
            }}
            onSessionExpired={sessionExpired}
          />
        )}
        {stage.name === 'refunds' && (
          <RefundScreen onDone={() => setStage({ name: 'selling', shift: stage.shift })} onSessionExpired={sessionExpired} />
        )}
        {stage.name === 'closing' && (
          <CloseShiftScreen
            shiftId={stage.shift.id}
            can={can}
            onCancel={() => setStage({ name: 'selling', shift: stage.shift })}
            onClosed={() => endSession()} // the server revoked the session at close
            onSessionExpired={sessionExpired}
          />
        )}
      </div>
    </main>
  )
}
