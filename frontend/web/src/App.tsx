import { useCallback, useEffect, useState } from 'react'
import { ApiError, api, tokens, type Shift } from './lib/api'
import { PinScreen, SetupScreen } from './register/SessionScreens'
import { CloseShiftScreen, OpenShiftScreen } from './register/ShiftScreens'
import { SaleScreen } from './register/SaleScreen'

type Stage =
  | { name: 'setup' }
  | { name: 'pin' }
  | { name: 'loading-shift' }
  | { name: 'open-shift' }
  | { name: 'selling'; shift: Shift }
  | { name: 'closing'; shift: Shift }

// Nav-gold section word on the carbon bar — pure wayfinding, no behavior attached.
const SECTION_LABEL: Record<Stage['name'], string> = {
  setup: 'Enroll Terminal',
  pin: 'Sign In',
  'loading-shift': 'Loading',
  'open-shift': 'Open Shift',
  selling: 'Register',
  closing: 'Close Shift',
}

export default function App() {
  const [stage, setStage] = useState<Stage>(() =>
    !tokens.device() ? { name: 'setup' } : !tokens.staff() ? { name: 'pin' } : { name: 'loading-shift' })

  const loadShift = useCallback(async () => {
    try {
      const current = await api.currentShift()
      setStage({ name: 'selling', shift: current.shift })
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) return setStage({ name: 'open-shift' })
      if (err instanceof ApiError && err.status === 401) {
        tokens.clearStaff()
        return setStage({ name: 'pin' })
      }
      throw err
    }
  }, [])

  useEffect(() => {
    if (stage.name === 'loading-shift') void loadShift()
  }, [stage.name, loadShift])

  const sessionExpired = () => {
    tokens.clearStaff()
    setStage({ name: 'pin' })
  }

  return (
    <main className="shell">
      <header className="carbon-bar">
        <span className="pos-pill">POS</span>
        <span className="carbon-bar-section">{SECTION_LABEL[stage.name]}</span>
      </header>

      <div className="plate chamfer register-body">
        {stage.name === 'setup' && <SetupScreen onDone={() => setStage({ name: 'pin' })} />}
        {stage.name === 'pin' && (
          <PinScreen
            onLoggedIn={() => setStage({ name: 'loading-shift' })}
            onDeviceInvalid={() => setStage({ name: 'setup' })}
          />
        )}
        {stage.name === 'loading-shift' && <p className="muted">Loading…</p>}
        {stage.name === 'open-shift' && (
          <OpenShiftScreen onOpened={(shift) => setStage({ name: 'selling', shift })} onSessionExpired={sessionExpired} />
        )}
        {stage.name === 'selling' && (
          <SaleScreen onCloseShift={() => setStage({ name: 'closing', shift: stage.shift })} onSessionExpired={sessionExpired} />
        )}
        {stage.name === 'closing' && (
          <CloseShiftScreen
            shiftId={stage.shift.id}
            onCancel={() => setStage({ name: 'selling', shift: stage.shift })}
            onClosed={() => {
              tokens.clearStaff()   // the server revoked the session at close
              setStage({ name: 'pin' })
            }}
            onSessionExpired={sessionExpired}
          />
        )}
      </div>
    </main>
  )
}
