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
 * 401s. `ApproveVariance` scopes by location, not by terminal — but no register screen
 * anywhere renders an Approve control for a shift other than its own (the only one that
 * exists is on the close-result plate of the shift that just closed, which is exactly
 * the dead session). So today, approving is an API call made from another still-open
 * register's session, not a UI flow at any till. The standing guidance line below is the
 * feature — without it the queue tells a supervisor which shift needs attention and
 * leaves them no way to find out how approval actually happens.
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
          This list is where to find which shifts still need a supervisor&rsquo;s sign-off. Approval is scoped to
          the location, never to the till that closed it — closing already revoked that till&rsquo;s own sessions.
          Today that means calling the API directly from{' '}
          <strong>another still-open register&rsquo;s session at the same location</strong> — no till screen
          anywhere offers an Approve button for a shift other than its own.
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
          { key: 'threshold', header: 'Threshold', render: (r) => fm(r.threshold_cents) },
        ]}
        rows={rows}
        rowKey={(r) => r.shift_id}
        empty={{ title: 'No variances waiting for approval' }}
      />
    </div>
  )
}
