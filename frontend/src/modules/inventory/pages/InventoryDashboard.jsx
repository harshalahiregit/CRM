import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  Package, Warehouse, IndianRupee, AlertTriangle, XCircle, ArrowLeftRight, Boxes, ArrowRight, LifeBuoy,
  FileClock, Lock, User, Users, ShieldCheck, Activity, TrendingUp, PieChart, Layers3, RefreshCw,
} from 'lucide-react'
import { inventoryApi, INV_ACCENT, fmtQty, money } from '@/services/inventoryApi'
import RaiseTicketModal from '../../helpdesk/components/RaiseTicketModal'

/**
 * Inventory command centre — KPI tiles plus live charts, all built on figures the
 * ledger can actually prove.
 *
 * Every query auto-refreshes on a 30-second interval (the same cadence as the
 * Helpdesk dashboard), so a screen left open on a warehouse wall stays current
 * without anyone pressing reload. The whole page is role-aware: the valuation
 * tile and the value-by-category chart are admin-only (the server sends null for
 * money a staff member may not see), and Analytics is scoped to the viewer's own
 * movements for staff, the whole workspace for admins.
 */
const REFRESH_MS = 30_000

// Validated categorical order (dataviz skill — passes CVD on light and dark;
// the previous ad-hoc palette had blue/purple pairs a colourblind viewer could
// not tell apart). Assigned in fixed order, never cycled.
const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7']
// In/out is a two-series comparison; green reads as "in", warm orange as "out".
const IN_COLOR = '#1baf7a'
const OUT_COLOR = '#eb6834'
// ABC is an ordinal rank (A carries the most value), so a single-hue ramp,
// darkest = most important. Each bar is labelled, so identity is never colour-only.
const ABC_COLOR = { A: '#047857', B: '#10B981', C: '#6ee7b7' }

export default function InventoryDashboard() {
  const navigate = useNavigate()
  const [raising, setRaising] = useState(false)

  // All four queries share the 30s refetch. Every hook runs before the loading
  // return below — React counts hooks per render, so a query placed after an
  // early return would crash the page the moment data arrived.
  const { data: s, isLoading, isFetching } = useQuery({
    queryKey: ['inv-summary'], queryFn: inventoryApi.summary, refetchInterval: REFRESH_MS,
  })
  const { data: low = [] } = useQuery({
    queryKey: ['inv-low-stock'], queryFn: inventoryApi.lowStock, refetchInterval: REFRESH_MS,
  })
  const { data: pending = [] } = useQuery({
    queryKey: ['inv-approvals'], queryFn: inventoryApi.pendingApprovals, refetchInterval: REFRESH_MS,
  })
  // 30-day analytics feed the charts. Scoped to the viewer by the server.
  const { data: analytics } = useQuery({
    queryKey: ['inv-analytics-dash'], queryFn: () => inventoryApi.analytics({ days: 30 }), refetchInterval: REFRESH_MS,
  })

  if (isLoading) {
    return (
      <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))' }}>
        {[...Array(8)].map((_, i) => (
          <div key={i} className="rounded-2xl animate-pulse" style={{ height: 96, background: 'var(--bg-card)' }} />
        ))}
      </div>
    )
  }

  const isAdmin = s?.inventory_value != null

  // Stock levels are shared operational truth — identical for everyone. Only the
  // valuation tile is admin-gated (server sends null when you may not see it).
  const tiles = [
    ...(isAdmin
      ? [{ key: 'inventory_value', label: 'Inventory Value', value: money(s.inventory_value), icon: IndianRupee, color: INV_ACCENT, spark: 'value' }]
      : []),
    { key: 'available', label: 'Available Stock', value: fmtQty(s?.available), icon: Boxes, color: '#2a78d6', sub: `${fmtQty(s?.total_quantity)} on hand` },
    { key: 'reserved', label: 'Reserved', value: fmtQty(s?.reserved), icon: Lock, color: '#4a3aa7', sub: 'committed to orders' },
    { key: 'products', label: 'Active Products', value: s?.products ?? 0, icon: Package, color: '#52514e' },
    { key: 'warehouses', label: 'Warehouses', value: s?.warehouses ?? 0, icon: Warehouse, color: '#2a78d6' },
    { key: 'low_stock', label: 'Low Stock', value: s?.low_stock ?? 0, icon: AlertTriangle, color: '#eda100', to: '/app/inventory/products?filter=low' },
    { key: 'out_of_stock', label: 'Out of Stock', value: s?.out_of_stock ?? 0, icon: XCircle, color: '#d03b3b' },
    { key: 'movements_today', label: 'Movements Today', value: s?.movements_today ?? 0, icon: ArrowLeftRight, color: '#1baf7a' },
  ]

  const team = s?.team
  const teamTiles = team ? [
    { key: 't_drafts', label: "Team's unposted drafts", value: team.open_drafts ?? 0, icon: FileClock, color: '#eda100', to: '/app/inventory/analytics' },
    { key: 't_res', label: "Team's reservations", value: team.reservations ?? 0, icon: Lock, color: '#4a3aa7', to: '/app/inventory/traceability' },
    { key: 't_people', label: 'People active today', value: team.active_people ?? 0, icon: Users, color: INV_ACCENT, to: '/app/inventory/analytics' },
  ] : []

  const mine = s?.my
  const myTiles = mine ? [
    { key: 'my_moves', label: 'My movements today', value: mine.movements_today ?? 0, icon: ArrowLeftRight, color: '#1baf7a' },
    { key: 'my_drafts', label: 'My unposted drafts', value: mine.open_drafts ?? 0, icon: FileClock, color: '#eda100', to: '/app/inventory/vouchers/receipt' },
    { key: 'my_res', label: 'My reservations', value: mine.reservations ?? 0, icon: Lock, color: '#4a3aa7', to: '/app/inventory/traceability' },
  ] : []

  const trend = analytics?.movement_trend || []
  const catMix = (analytics?.category_mix || []).filter(r => r.value > 0)
  const abc = analytics?.abc?.counts
  const headline = analytics?.headline || {}

  // Stock health: what's free vs already promised. Two categories, so a single
  // split bar reads it at a glance.
  const available = Number(s?.available || 0)
  const reserved = Number(s?.reserved || 0)
  const healthTotal = available + reserved

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Boxes size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Inventory</h1>

        {/* Live badge — the page refreshes itself every 30s, so this says so
            rather than leaving people wondering if the numbers are stale. */}
        <span className="flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-lg"
          title="These figures refresh automatically every 30 seconds"
          style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
          <RefreshCw size={10} className={isFetching ? 'animate-spin' : ''}
            style={{ color: isFetching ? INV_ACCENT : 'var(--text-muted)' }} />
          Live · every 30s
        </span>

        <button onClick={() => setRaising(true)}
          className="ml-auto flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-bold transition-opacity hover:opacity-90"
          style={{ background: 'var(--color-support-500)', color: '#fff' }}>
          <LifeBuoy size={15} /> Raise Ticket
        </button>
      </header>

      {/* Waiting on you. Sits above the KPIs because it is the only thing on this
          page where somebody else's work is stopped until you act. */}
      {pending.length > 0 && (
        <section className="rounded-2xl p-4 mb-5"
          style={{ background: 'color-mix(in srgb, #eda100 8%, var(--bg-card))', border: '1px solid #eda100' }}>
          <p className="flex items-center gap-1.5 text-xs font-bold mb-2" style={{ color: '#a86a00' }}>
            <ShieldCheck size={14} />
            {pending.length} document{pending.length > 1 ? 's' : ''} waiting for your approval
          </p>
          <div className="flex flex-col gap-1.5">
            {pending.slice(0, 4).map(v => (
              <button key={v.id} onClick={() => navigate(`/app/inventory/vouchers/${v.type}`)}
                className="flex flex-wrap items-center gap-2 text-left text-xs rounded-lg px-2 py-1.5"
                style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                <span className="font-mono font-bold" style={{ color: INV_ACCENT }}>{v.code}</span>
                <span style={{ color: 'var(--text-h)' }}>{v.type_label}</span>
                <span style={{ color: 'var(--text-muted)' }}>· raised by {v.creator?.name || 'someone'}</span>
                <span className="ml-auto font-bold" style={{ color: '#a86a00' }}>Review →</span>
              </button>
            ))}
            {pending.length > 4 && (
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>…and {pending.length - 4} more</p>
            )}
          </div>
        </section>
      )}

      {/* KPI tiles */}
      <div className="grid gap-3 mb-5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(176px,1fr))' }}>
        {tiles.map(t => <KpiTile key={t.key} t={t} navigate={navigate} />)}
      </div>

      {/* ── Charts ─────────────────────────────────────────────────── */}
      <div className="grid gap-4 mb-5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(300px,1fr))' }}>
        <Card title="Stock movement" icon={Activity} hint="Units in (green) vs out (orange), last 30 days."
          action={{ label: 'Analytics', onClick: () => navigate('/app/inventory/analytics') }}>
          <TrendChart data={trend} />
        </Card>

        <Card title="Stock health" icon={Boxes} hint="How much of what you hold is free to sell versus already promised.">
          <HealthBar available={available} reserved={reserved} total={healthTotal} />
          <div className="grid grid-cols-2 gap-2 mt-3">
            <MiniStat label="Low stock" value={s?.low_stock ?? 0} color="#eda100" icon={AlertTriangle}
              onClick={(s?.low_stock ?? 0) > 0 ? () => navigate('/app/inventory/products?filter=low') : null} />
            <MiniStat label="Out of stock" value={s?.out_of_stock ?? 0} color="#d03b3b" icon={XCircle} />
          </div>
        </Card>

        {/* Value by category is a finance view — admins only. Staff get a
            non-financial movement summary in the same slot instead. */}
        {isAdmin ? (
          <Card title="Stock value by category" icon={PieChart} hint="Where the money on your shelves is concentrated.">
            {catMix.length
              ? <StackedShare rows={catMix} />
              : <Empty>No categorised stock value yet.</Empty>}
          </Card>
        ) : (
          <Card title="Movement summary" icon={TrendingUp} hint="Your recorded movements over the last 30 days.">
            <div className="grid grid-cols-2 gap-2">
              <MiniStat label="Received" value={fmtQty(headline.qty_received)} color="#1baf7a" icon={TrendingUp} />
              <MiniStat label="Consumed" value={fmtQty(headline.qty_consumed)} color="#eb6834" icon={ArrowLeftRight} />
            </div>
          </Card>
        )}

        <Card title="ABC classification" icon={Layers3}
          hint="Pareto on consumption value — A items carry most of it and are worth counting most often.">
          {abc && (abc.A + abc.B + abc.C) > 0
            ? <AbcBars counts={abc} turnover={analytics?.turnover} />
            : <Empty>Not enough consumption history yet to rank items.</Empty>}
        </Card>
      </div>

      {/* My work — about the viewer, not the warehouse. */}
      {myTiles.length > 0 && (
        <Section icon={User} label="My work">
          {myTiles.map(t => <KpiTile key={t.key} t={t} navigate={navigate} compact />)}
        </Section>
      )}

      {/* Team pending — admin only. An unposted draft is stock that hasn't moved
          yet; until now only its author could see it. */}
      {teamTiles.length > 0 && (
        <Section icon={Users} label="Team">
          {teamTiles.map(t => <KpiTile key={t.key} t={t} navigate={navigate} compact />)}
        </Section>
      )}

      {/* Reorder worklist — the dashboard's one actionable list. */}
      <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-center gap-2 mb-3">
          <AlertTriangle size={14} style={{ color: '#eda100' }} />
          <h2 className="font-bold text-xs" style={{ color: 'var(--text-h)' }}>Needs reordering</h2>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {low.length} {low.length === 1 ? 'product is' : 'products are'} at or below the reorder point
          </span>
          <button onClick={() => navigate('/app/inventory/products')}
            className="ml-auto flex items-center gap-1 text-[11px] font-bold" style={{ color: INV_ACCENT }}>
            All products <ArrowRight size={11} />
          </button>
        </div>

        {low.length === 0 && (
          <p className="text-xs py-6 text-center" style={{ color: 'var(--text-muted)' }}>
            Nothing to reorder — every product is above its threshold.
          </p>
        )}

        {low.length > 0 && (
          <ul className="space-y-1.5">
            {low.map(p => {
              const threshold = Number(p.reorder_point || p.min_stock) || 1
              const pct = Math.min(100, Math.round((Number(p.on_hand) / threshold) * 100))
              return (
                <li key={p.id}>
                  <button onClick={() => navigate(`/app/inventory/products/${p.id}`)}
                    className="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-left"
                    style={{ background: 'var(--bg-input)' }}>
                    <span className="flex-1 min-w-0">
                      <span className="block text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{p.name}</span>
                      <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{p.sku}</span>
                      {/* How close to empty, at a glance. */}
                      <span className="block mt-1 h-1 rounded-full overflow-hidden" style={{ background: 'var(--bg-card)' }}>
                        <span className="block h-full rounded-full"
                          style={{ width: `${pct}%`, background: Number(p.on_hand) <= 0 ? '#d03b3b' : '#eda100' }} />
                      </span>
                    </span>
                    <span className="text-right shrink-0">
                      <span className="block text-xs font-black tabular-nums" style={{ color: Number(p.on_hand) <= 0 ? '#d03b3b' : '#eda100' }}>{fmtQty(p.on_hand)}</span>
                      <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>
                        reorder at {fmtQty(threshold)}
                      </span>
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
        )}
      </section>

      <RaiseTicketModal open={raising} onClose={() => setRaising(false)} source="inventory"
        defaultSubject="[Inventory] " />
    </div>
  )
}

/* ── Pieces ─────────────────────────────────────────────────────── */

function KpiTile({ t, navigate, compact }) {
  const Icon = t.icon
  const clickable = Boolean(t.to) && t.value > 0 && t.value !== '0'
  return (
    <button disabled={!clickable} onClick={() => clickable && navigate(t.to)}
      className="relative rounded-2xl p-4 text-left transition-all overflow-hidden"
      style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', cursor: clickable ? 'pointer' : 'default' }}>
      {/* A hairline of the tile's colour along the top — enough to give the row
          rhythm without turning the card into a block of colour. */}
      <span className="absolute inset-x-0 top-0 h-[3px]" style={{ background: t.color }} />
      <div className="flex items-center gap-2 mb-2">
        <span className="w-6 h-6 rounded-lg flex items-center justify-center"
          style={{ background: `color-mix(in srgb, ${t.color} 14%, transparent)` }}>
          <Icon size={13} style={{ color: t.color }} />
        </span>
        <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{t.label}</span>
        {clickable && <ArrowRight size={12} className="ml-auto" style={{ color: t.color }} />}
      </div>
      <p className={`${compact ? 'text-xl' : 'text-2xl'} font-black tabular-nums`} style={{ color: 'var(--text-h)' }}>{t.value}</p>
      {t.sub && <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{t.sub}</p>}
    </button>
  )
}

function Card({ title, icon: Icon, hint, action, children }) {
  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-1">
        {Icon && <Icon size={14} style={{ color: INV_ACCENT }} />}
        <h2 className="font-bold text-xs" style={{ color: 'var(--text-h)' }}>{title}</h2>
        {action && (
          <button onClick={action.onClick} className="ml-auto flex items-center gap-1 text-[11px] font-bold" style={{ color: INV_ACCENT }}>
            {action.label} <ArrowRight size={11} />
          </button>
        )}
      </div>
      {hint && <p className="text-[10px] mb-3" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
      {children}
    </section>
  )
}

function Section({ icon: Icon, label, children }) {
  return (
    <section className="mb-5">
      <p className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide mb-2" style={{ color: 'var(--text-muted)' }}>
        <Icon size={12} style={{ color: INV_ACCENT }} /> {label}
      </p>
      <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(176px,1fr))' }}>
        {children}
      </div>
    </section>
  )
}

const Empty = ({ children }) => (
  <p className="text-xs py-6 text-center" style={{ color: 'var(--text-muted)' }}>{children}</p>
)

/** Available vs reserved as one split bar, with a 2px surface gap between fills. */
function HealthBar({ available, reserved, total }) {
  if (total <= 0) return <Empty>Nothing on the shelf yet.</Empty>
  const ap = (available / total) * 100
  return (
    <div>
      <div className="flex items-center rounded-lg overflow-hidden" style={{ height: 12, gap: 2, background: 'var(--bg-input)' }}>
        {available > 0 && <div style={{ width: `${ap}%`, height: '100%', background: IN_COLOR, borderRadius: 4 }} title={`Available: ${fmtQty(available)}`} />}
        {reserved > 0 && <div style={{ width: `${100 - ap}%`, height: '100%', background: '#4a3aa7', borderRadius: 4 }} title={`Reserved: ${fmtQty(reserved)}`} />}
      </div>
      <div className="flex gap-4 mt-2">
        <Legend color={IN_COLOR} label="Available" value={fmtQty(available)} />
        <Legend color="#4a3aa7" label="Reserved" value={fmtQty(reserved)} />
      </div>
    </div>
  )
}

const Legend = ({ color, label, value }) => (
  <span className="flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
    <span style={{ width: 8, height: 8, borderRadius: 2, background: color }} />
    {label}: <strong className="tabular-nums" style={{ color: 'var(--text-h)' }}>{value}</strong>
  </span>
)

function MiniStat({ label, value, color, icon: Icon, onClick }) {
  return (
    <button disabled={!onClick} onClick={() => onClick && onClick()}
      className="flex items-center gap-2 rounded-xl px-3 py-2 text-left w-full"
      style={{ background: 'var(--bg-input)', cursor: onClick ? 'pointer' : 'default' }}>
      <Icon size={14} style={{ color }} />
      <span className="min-w-0">
        <span className="block text-sm font-black tabular-nums leading-tight" style={{ color: 'var(--text-h)' }}>{value}</span>
        <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{label}</span>
      </span>
    </button>
  )
}

/** Daily in/out as opposing bars — dependency-free SVG, rounded data-ends. */
function TrendChart({ data }) {
  if (!data.length) return <Empty>No movements in the last 30 days.</Empty>

  const max = Math.max(1, ...data.map(d => Math.max(d.in, d.out)))
  const w = Math.max(1, 100 / data.length)
  const totalIn = data.reduce((a, d) => a + d.in, 0)
  const totalOut = data.reduce((a, d) => a + d.out, 0)

  return (
    <div>
      <svg viewBox="0 0 100 44" preserveAspectRatio="none" style={{ width: '100%', height: 120 }} role="img" aria-label="Daily stock in and out">
        <line x1="0" y1="22" x2="100" y2="22" stroke="var(--border)" strokeWidth="0.3" />
        {data.map((d, i) => (
          <g key={d.date}>
            {d.in > 0 && <rect x={i * w + w * 0.15} y={22 - (d.in / max) * 20} width={w * 0.7} height={(d.in / max) * 20} rx={Math.min(w * 0.2, 0.8)} fill={IN_COLOR}>
              <title>{d.date}: {fmtQty(d.in)} in</title>
            </rect>}
            {d.out > 0 && <rect x={i * w + w * 0.15} y={22} width={w * 0.7} height={(d.out / max) * 20} rx={Math.min(w * 0.2, 0.8)} fill={OUT_COLOR}>
              <title>{d.date}: {fmtQty(d.out)} out</title>
            </rect>}
          </g>
        ))}
      </svg>
      <div className="flex items-center justify-between mt-1.5">
        <Legend color={IN_COLOR} label="In" value={fmtQty(totalIn)} />
        <Legend color={OUT_COLOR} label="Out" value={fmtQty(totalOut)} />
      </div>
    </div>
  )
}

/** Category value split — validated categorical hues, in fixed order, each labelled. */
function StackedShare({ rows }) {
  const top = rows.slice(0, 7)
  const total = rows.reduce((sm, r) => sm + r.value, 0) || 1
  return (
    <div>
      <div className="flex rounded-lg overflow-hidden mb-2" style={{ height: 12, gap: 2, background: 'var(--bg-input)' }}>
        {top.map((r, i) => (
          <div key={r.name} style={{ width: `${(r.value / total) * 100}%`, background: SERIES[i % SERIES.length], borderRadius: 4 }}
            title={`${r.name}: ${money(r.value)}`} />
        ))}
      </div>
      <ul className="space-y-1">
        {top.map((r, i) => (
          <li key={r.name} className="flex items-center gap-2 text-[11px]">
            <span style={{ width: 8, height: 8, borderRadius: 2, background: SERIES[i % SERIES.length] }} />
            <span className="flex-1 truncate" style={{ color: 'var(--text-body)' }}>{r.name}</span>
            <span className="tabular-nums font-semibold" style={{ color: 'var(--text-h)' }}>{money(r.value)}</span>
            <span className="tabular-nums" style={{ color: 'var(--text-muted)', width: 38, textAlign: 'right' }}>
              {Math.round((r.value / total) * 100)}%
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/** ABC counts as a single-hue ordinal split, each class labelled. */
function AbcBars({ counts, turnover }) {
  const total = (counts.A || 0) + (counts.B || 0) + (counts.C || 0)
  return (
    <div>
      <div className="flex rounded-lg overflow-hidden" style={{ height: 12, gap: 2, background: 'var(--bg-input)' }}>
        {['A', 'B', 'C'].map(k => (counts[k] || 0) > 0 && (
          <div key={k} style={{ width: `${(counts[k] / total) * 100}%`, background: ABC_COLOR[k], borderRadius: 4 }}
            title={`Class ${k}: ${counts[k]} item(s)`} />
        ))}
      </div>
      <div className="flex gap-4 mt-2">
        {['A', 'B', 'C'].map(k => (
          <Legend key={k} color={ABC_COLOR[k]} label={k} value={counts[k] || 0} />
        ))}
      </div>
      {turnover?.fastest?.[0] && (
        <p className="text-[11px] mt-3 pt-3 truncate" style={{ color: 'var(--text-muted)', borderTop: '1px solid var(--border)' }}>
          Fastest mover: <strong style={{ color: 'var(--text-h)' }}>{turnover.fastest[0].name}</strong>
          {' '}<span className="tabular-nums">({turnover.fastest[0].annualised}× / yr)</span>
        </p>
      )}
    </div>
  )
}
