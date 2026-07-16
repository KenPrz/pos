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

  return (
    <main className="shell">
      <header>
        <h1>POS</h1>
        <p className="muted">Register</p>
      </header>

      {stage.name === 'setup' && <SetupScreen onDone={() => setStage({ name: 'pin' })} />}
      {stage.name === 'pin' && <PinScreen onLoggedIn={() => setStage({ name: 'loading-shift' })} />}
      {stage.name === 'loading-shift' && <p className="muted">Loading…</p>}
      {stage.name === 'open-shift' && <OpenShiftScreen onOpened={(shift) => setStage({ name: 'selling', shift })} />}
      {stage.name === 'selling' && (
        <SaleScreen onCloseShift={() => setStage({ name: 'closing', shift: stage.shift })} />
      )}
      {stage.name === 'closing' && (
        <CloseShiftScreen
          shiftId={stage.shift.id}
          onCancel={() => setStage({ name: 'selling', shift: stage.shift })}
          onClosed={() => {
            tokens.clearStaff()   // the server revoked the session at close
            setStage({ name: 'pin' })
          }}
        />
      )}
    </main>
  )
}
