'use client'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { ApiError, api, type Order, type OpenShiftRegister } from '../lib/api'
import { ActionZone } from '@/components/ActionZone'
import { MoneyText } from '@/components/MoneyText'
import { TileButton } from '@/components/TileButton'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet'

const CURRENCY = 'USD'

// Stable references for the "no data yet" case — `.data ?? []` would otherwise mint a
// new array every render.
const NO_ORDERS: Order[] = []
const NO_TARGETS: OpenShiftRegister[] = []

function ageLabel(openedAt: string | undefined): string {
  if (!openedAt) return '—'
  const mins = Math.max(0, Math.floor((Date.now() - new Date(openedAt).getTime()) / 60_000))
  if (mins < 60) return `${mins}m`
  return `${Math.floor(mins / 60)}h ${mins % 60}m`
}

/**
 * The floor view (M5, Task 12): every open order across the location, not just this
 * register's (api.openOrders() === findOrders({status:'open'}), unscoped). A plain grid
 * of tab tiles — deliberately not a graphical room layout, per the M5 design spec's Out
 * of scope. Register.tsx only mounts this screen while its TABS toggle is active, so
 * the 10s poll runs only while staff are actually looking at it.
 *
 * RESUME and TRANSFER only ever act on cards this register opened (`order.register_id
 * === registerId`): the floor shows every table so staff can see the whole room, but
 * working someone else's tab has to come through THEM handing it off via Transfer —
 * there's no "pull" affordance here, matching the transfer target list itself never
 * offering "me" as a destination.
 */
export function FloorScreen({ registerId, canTransfer, activeOrderId, onResume, onNewTab, onSessionExpired }: {
  registerId: string
  canTransfer: boolean
  // The order currently in progress on the mounted-hidden sale screen, if any — lifted
  // through Register.tsx via SaleScreen's onOrderChange. Resuming a DIFFERENT tab while
  // one is already in progress would strand it, so every other card's resume is
  // disabled until that sale is finished or parked.
  activeOrderId: string | null
  onResume: (order: Order) => void
  onNewTab: (order: Order) => void
  onSessionExpired: () => void
}) {
  const [error, setError] = useState<string | null>(null)
  const [newTabOpen, setNewTabOpen] = useState(false)
  const [tableRefInput, setTableRefInput] = useState('')
  const [transferOpenId, setTransferOpenId] = useState<string | null>(null)
  const queryClient = useQueryClient()

  // Minted once when the NEW TAB pad opens and reused across every submit attempt while
  // it stays open — same idiom as SaleScreen's tender-phase key — so a lost response
  // can't mint a twin order on retry. A fresh key is minted the next time the pad opens.
  const newTabKeyRef = useRef<string | null>(null)

  const fail = (err: unknown, fallback: string) => {
    if (err instanceof ApiError && err.status === 401) return onSessionExpired()
    setError(err instanceof ApiError ? err.message : fallback)
  }

  const openOrders = useQuery({
    queryKey: ['open-orders-floor'],
    queryFn: api.openOrders,
    refetchInterval: 10_000,
  })

  // TRANSFER's destination list (M6 gap fix): the location's other registers with an
  // open shift right now, from a dedicated endpoint rather than inferred from open
  // orders — the old inference missed a register that opened a shift but has no open
  // tabs of its own. Only fetched when this staff member can actually transfer; the
  // cards themselves keep polling openOrders regardless.
  const openShiftRegisters = useQuery({
    queryKey: ['open-shift-registers'],
    queryFn: api.openShiftRegisters,
    enabled: canTransfer,
  })

  // useQuery (react-query v5) dropped the onError callback — watch the settled error the
  // same way Register.tsx's own shift-load effect does.
  useEffect(() => {
    if (openOrders.error instanceof ApiError && openOrders.error.status === 401) onSessionExpired()
  }, [openOrders.error, onSessionExpired])
  useEffect(() => {
    if (openShiftRegisters.error instanceof ApiError && openShiftRegisters.error.status === 401) onSessionExpired()
  }, [openShiftRegisters.error, onSessionExpired])

  const newTab = useMutation({
    mutationFn: (tableRef: string) =>
      api.openOrder({ tableRef: tableRef || undefined, idempotencyKey: newTabKeyRef.current ?? undefined }),
    onSuccess: (order) => {
      newTabKeyRef.current = null
      setNewTabOpen(false)
      setTableRefInput('')
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['open-orders-floor'] })
      onNewTab(order)
    },
    onError: (err) => fail(err, 'Could not open a new tab.'),
  })

  const transfer = useMutation({
    mutationFn: ({ order, targetRegisterId }: { order: Order; targetRegisterId: string }) =>
      api.transferOrder(order, targetRegisterId),
    onSuccess: () => {
      setTransferOpenId(null)
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['open-orders-floor'] })
    },
    onError: (err) => fail(err, 'Transfer failed.'),
  })

  const orders = openOrders.data ?? NO_ORDERS
  const targets = openShiftRegisters.data ?? NO_TARGETS

  // Shared by the pad form's onSubmit (Enter in the table field) and the action zone's
  // Open tab button — one guard path, same doPay/submitPay idiom as SaleScreen.
  const doOpenTab = () => {
    if (newTab.isPending) return
    setError(null)
    newTab.mutate(tableRefInput.trim())
  }

  const submitNewTab = (e: FormEvent) => {
    e.preventDefault()
    doOpenTab()
  }

  return (
    <section className="flex flex-col gap-md pb-[80px]">
      <h2 className="type-headline">Floor</h2>

      {newTabOpen && (
        <form onSubmit={submitNewTab} className="max-w-[24rem]">
          <Input
            autoFocus placeholder="Table (optional)…" maxLength={20}
            value={tableRefInput} onChange={(e) => setTableRefInput(e.target.value)}
            className="h-[56px] text-[18px]"
          />
        </form>
      )}

      {openOrders.isLoading && <p className="type-body-sm text-ink-muted">Loading tabs…</p>}
      {openOrders.isError && <p className="type-body-sm text-error">Could not load the floor.</p>}
      {!openOrders.isLoading && orders.length === 0 && <p className="type-body-sm text-ink-muted">No open tabs.</p>}

      <div className="grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-sm">
        {orders.map((o) => {
          const mine = o.register_id === registerId
          const blockedByActiveOrder = mine && activeOrderId !== null && activeOrderId !== o.id
          const resumeDisabled = !mine || blockedByActiveOrder
          return (
            <div
              className="flex flex-col gap-xxs" key={o.id}
              title={!mine ? 'Open at another register.' : undefined}
            >
              <TileButton
                title={o.table_ref ?? o.number}
                // The tab currently in progress on the (hidden) sale screen — status as
                // a left edge bar, blue = selected indicator per the semantic map.
                edge={activeOrderId === o.id ? 'info' : undefined}
                disabled={resumeDisabled}
                onClick={() => onResume(o)}
                meta={
                  <>
                    <span className="block">{o.opened_by_name ?? 'Unknown'} · {ageLabel(o.opened_at)}</span>
                    <MoneyText cents={o.due_cents} currency={CURRENCY} size="line" className="block text-ink" />
                  </>
                }
              />
              {blockedByActiveOrder && (
                <p className="type-caption text-ink-subtle">Finish or park the current sale to switch tabs.</p>
              )}
              {mine && canTransfer && targets.length > 0 && (
                <>
                  <Button
                    type="button" variant="tertiary" size="lg"
                    onClick={() => setTransferOpenId(transferOpenId === o.id ? null : o.id)}
                  >
                    Transfer
                  </Button>
                  <Sheet open={transferOpenId === o.id} onOpenChange={(open) => { if (!open) setTransferOpenId(null) }}>
                    <SheetContent aria-describedby={undefined}>
                      <SheetTitle>Send to</SheetTitle>
                      <div className="mt-md flex flex-col">
                        {targets.map((t) => (
                          <Button
                            key={t.register_id} type="button" variant="ghost" size="lg"
                            className="justify-start border-b border-hairline text-ink"
                            disabled={transfer.isPending}
                            onClick={() => transfer.mutate({ order: o, targetRegisterId: t.register_id })}
                          >
                            {t.register_name} — {t.opened_by_name}
                          </Button>
                        ))}
                      </div>
                    </SheetContent>
                  </Sheet>
                </>
              )}
            </div>
          )
        })}
      </div>

      {error && <p className="type-body-sm text-error">{error}</p>}

      {/* NEW TAB is the floor's single primary action (spec §register: "NEW TAB as the
          bottom action-zone primary"); while the pad is open the zone becomes its
          Open tab / Cancel pair, same swap idiom as SplitPrompt's GO/Cancel. */}
      <ActionZone>
        {newTabOpen ? (
          <>
            <Button size="xl" type="button" disabled={newTab.isPending} onClick={doOpenTab}>
              {newTab.isPending ? 'Opening…' : 'Open tab'}
            </Button>
            <Button
              size="xl" type="button" variant="ghost"
              onClick={() => { newTabKeyRef.current = null; setNewTabOpen(false) }}
            >
              Cancel
            </Button>
          </>
        ) : (
          <Button
            size="xl" type="button"
            onClick={() => { newTabKeyRef.current = crypto.randomUUID(); setNewTabOpen(true) }}
          >
            New tab
          </Button>
        )}
      </ActionZone>
    </section>
  )
}
