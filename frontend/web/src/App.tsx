import { useEffect, useState } from 'react'
import { ApiError, api, type Health } from './lib/api'

type State =
  | { status: 'checking' }
  | { status: 'up'; health: Health }
  | { status: 'down'; reason: string }

export default function App() {
  const [state, setState] = useState<State>({ status: 'checking' })

  useEffect(() => {
    let cancelled = false

    api
      .health()
      .then((health) => {
        if (cancelled) return
        setState(
          health.healthy
            ? { status: 'up', health }
            : { status: 'down', reason: health.database.reason ?? 'Database unavailable.' },
        )
      })
      .catch((error: unknown) => {
        if (cancelled) return
        setState({
          status: 'down',
          reason: error instanceof ApiError ? error.message : 'Unknown failure.',
        })
      })

    return () => {
      cancelled = true
    }
  }, [])

  return (
    <main className="shell">
      <header>
        <h1>POS</h1>
        <p className="muted">M0 — skeleton that boots</p>
      </header>

      {state.status === 'checking' && <p className="muted">Checking…</p>}

      {state.status === 'up' && (
        <section className="card ok">
          <h2>System healthy</h2>
          <dl>
            <dt>API</dt>
            <dd>reachable</dd>
            <dt>App version</dt>
            <dd>{state.health.app_version}</dd>
            <dt>Database</dt>
            <dd>{state.health.database.version ?? 'unknown'}</dd>
          </dl>
        </section>
      )}

      {state.status === 'down' && (
        <section className="card bad">
          <h2>System unavailable</h2>
          <p>{state.reason}</p>
          <p className="muted">
            v1 is online-only — the terminal needs the server.
          </p>
        </section>
      )}
    </main>
  )
}
