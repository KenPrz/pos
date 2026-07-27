'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { ApiError, api } from '../../lib/api'
import { DataTable } from '../../components/DataTable'
import { Card, CardTitle } from '../../components/ui/card'
import { getCurrency } from '../../lib/currency'
import { cents, formatMoney } from '../../lib/money'

// display only; the server owns all arithmetic
const fm = (n: number) => formatMoney(cents(n), getCurrency())

/**
 * The pending-variances queue (Task 2): closed shifts whose drawer variance is over
 * threshold and not yet signed off, scoped server-side to everywhere the caller holds
 * shift.approve_variance. Read-only — approval itself stays a register action
 * (POST /shifts/{shift}/approve-variance).
 *
 * Deliberately carries no link to the offending register: `CloseShift` revokes every
 * staff session bound to the register that just closed, so approving from THAT till
 * 401s. `ApproveVariance` scopes by location, so any OTHER till at the same location
 * works. The standing guidance line below is the feature — without it the queue tells a
 * supervisor where to walk and lets them find the dead end themselves.
 */
export function VariancesSection({ onUnauthorized }: { onUnauthorized: () => void }) {
  const variances = useQuery({
    queryKey: ['admin', 'variances'],
    queryFn: () => api.variances.list(),
  })

  useEffect(() => {
    if (variances.error instanceof ApiError && variances.error.status === 401) onUnauthorized()
  }, [variances.error, onUnauthorized])

  if (variances.isLoading) return <p className="type-body-sm text-ink-muted">Loading…</p>
  if (variances.isError) return <p className="type-body-sm text-error">Could not load variances.</p>

  const rows = variances.data ?? []

  return (
    <div className="flex flex-col gap-lg">
      <CardTitle>Variances</CardTitle>

      <Card>
        <p className="type-body-sm text-ink">
          Variances are approved at a till. Sign in at <strong>any register other than the one listed</strong> —
          closing a shift signs its own register out, so approval must come from another terminal at the same
          location.
        </p>
      </Card>

      <DataTable
        columns={[
          { key: 'register', header: 'Register', render: (r) => r.register_name },
          { key: 'location', header: 'Location', render: (r) => r.location_name },
          { key: 'opened_by', header: 'Opened by', render: (r) => r.opened_by_name },
          { key: 'closed_at', header: 'Closed', render: (r) => r.closed_at },
          { key: 'expected', header: 'Expected', render: (r) => fm(r.expected_cash_cents) },
          { key: 'counted', header: 'Counted', render: (r) => fm(r.counted_cash_cents) },
          { key: 'variance', header: 'Variance', render: (r) => fm(r.variance_cents) },
        ]}
        rows={rows}
        rowKey={(r) => r.shift_id}
        empty={{ title: 'No variances waiting for approval' }}
      />
    </div>
  )
}
