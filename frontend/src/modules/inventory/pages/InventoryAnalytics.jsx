import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  Activity, TrendingUp, Layers3, Skull, Target, Gauge, User, Building2, PieChart,
  Users, FileClock, Lock, ArrowLeft,
} from 'lucide-react'
import { inventoryApi, INV_ACCENT, fmtQty, money } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

/**
 * Inventory Analytics — deliberately a different page from the KPI dashboard.
 *
 * The dashboard answers "what is on the shelf right now". This answers "how is
 * the stock BEHAVING": which items earn their space (ABC), which are
 * forecastable (XYZ), how fast they turn, what's dead, and how trustworthy the
 * numbers are (accuracy).
 *
 * It is viewer-aware, like the Helpdesk analytics: an admin sees the whole
 * tenant, a staff member sees the same maths over their own recorded activity.
 * The page says which, rather than letting one be mistaken for the other.
 */

const ABC_COLOR = { A: '#10B981', B: '#3b82f6', C: '#94a3b8' }
const XYZ_COLOR = { X: '#10B981', Y: '#f59e0b', Z: '#ef4444' }

export default function InventoryAnalytics() {
  const { user } = useAuth()
  const [days, setDays] = useState(90)
  const [warehouse, setWarehouse] = useState('')
  // Admin drill-in: when set, the whole page is scoped to that one person.
  const [focus, setFocus] = useState('')

  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list })
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['inv-analytics', days, warehouse, focus],
    queryFn: () => inventoryApi.analytics({
      days,
      ...(warehouse ? { warehouse_id: warehouse } : {}),
      ...(focus ? { actor_id: focus } : {}),
    }),
    // Auto-refresh every 30s, like the other dashboards.
    refetchInterval: 30_000,
  })

  const mine = data?.scope === 'mine'
  const person = data?.scope === 'person'
  const h = data?.headline || {}

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Activity size={17} style={{ color: INV_ACCENT }} />
        </span>
        <div>
          <h1 className="text-lg font-bold leading-tight" style={{ color: 'var(--text-h)' }}>Inventory Analytics</h1>
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            How stock behaves — not just what's on the shelf.
          </p>
        </div>

        {/* Scope badge — the page never lets "mine" be mistaken for "everyone". */}
        {data && (
          <span className="flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-lg"
            style={{
              background: (mine || person) ? 'var(--bg-input)' : `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)`,
              color: (mine || person) ? 'var(--text-muted)' : INV_ACCENT,
            }}>
            {mine
              ? <><User size={11} /> My activity</>
              : person
                ? <><User size={11} /> {data.scope_user}</>
                : <><Building2 size={11} /> Whole workspace</>}
          </span>
        )}
        {person && (
          <button onClick={() => setFocus('')}
            className="flex items-center gap-1 text-[11px] font-bold px-2 py-1 rounded-lg"
            style={{ border: '1px solid var(--border)', color: INV_ACCENT }}>
            <ArrowLeft size={11} /> Whole workspace
          </button>
        )}

        <div className="ml-auto flex items-center gap-2">
          <div style={{ width: 150 }}>
            <Select size="sm" value={warehouse} onChange={setWarehouse} placeholder="All warehouses"
              options={[{ value: '', label: 'All warehouses' }, ...warehouses.map(w => ({ value: String(w.id), label: w.name }))]} />
          </div>
          <div style={{ width: 130 }}>
            <Select size="sm" value={String(days)} onChange={v => setDays(Number(v))}
              options={[30, 60, 90, 180, 365].map(d => ({ value: String(d), label: `Last ${d} days` }))} />
          </div>
        </div>
      </header>

      {mine && (
        <p className="text-[11px] px-3 py-2 rounded-xl mb-4" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
          You're seeing analytics for movements <strong>you</strong> recorded. Admins see the whole workspace.
        </p>
      )}

      {isError && <p className="text-xs py-8 text-center" style={{ color: 'var(--color-danger-500)' }}>{error?.message}</p>}
      {isLoading && (
        <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))' }}>
          {[...Array(6)].map((_, i) => <div key={i} className="rounded-2xl animate-pulse" style={{ height: 88, background: 'var(--bg-card)' }} />)}
        </div>
      )}

      {data && !isLoading && (
        <>
          {/* Headline tiles */}
          <div className="grid gap-3 mb-5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(168px,1fr))' }}>
            <Tile label="Items moved" value={h.items_touched ?? 0} icon={Layers3} color={INV_ACCENT} />
            <Tile label="Received" value={fmtQty(h.qty_received)} icon={TrendingUp} color="#3b82f6" />
            <Tile label="Consumed" value={fmtQty(h.qty_consumed)} icon={TrendingUp} color="#f59e0b" />
            <Tile label="Movements" value={h.movements ?? 0} icon={Activity} color="#8b5cf6" />
            <Tile label="Closing value" value={money(h.closing_value)} icon={PieChart} color="#10B981" />
            <Tile label="Days of inventory" value={h.days_of_inventory ?? '—'} icon={Gauge} color="#0ea5e9"
              hint="How long current stock lasts at this window's burn rate" />
            {!mine && h.reserved_value != null && (
              <Tile label="Reserved value" value={money(h.reserved_value)} icon={Target} color="#ec4899" />
            )}
            <Tile label="Stock accuracy" value={`${data.accuracy?.accuracy_pct ?? 100}%`} icon={Target}
              color={(data.accuracy?.accuracy_pct ?? 100) >= 95 ? '#10B981' : '#ef4444'}
              hint={`${data.accuracy?.corrections ?? 0} correction(s) in ${data.accuracy?.movements ?? 0} movements`} />
          </div>

          {/* Team activity — admin only. Answers "who did what", and each row
              drills this whole page into that person. */}
          {data.team && (
            <Card title="Team activity" icon={Users}
              hint={`${data.team.totals.people_active} of ${data.team.totals.people} people moved stock in this window - ${data.team.totals.open_drafts} unposted draft(s) across the team`}
              className="mb-4">
              <div className="overflow-x-auto">
                <table className="w-full text-[11px]" style={{ minWidth: 660 }}>
                  <thead>
                    <tr style={{ borderBottom: '1px solid var(--border)' }}>
                      {['Person', 'Moves', 'In', 'Out', 'Docs', 'Drafts', 'Reserved', 'Accuracy', 'Last active'].map((h, i) => (
                        <th key={h} className={`px-2 py-1.5 font-bold ${i > 0 && i < 8 ? 'text-right' : 'text-left'}`}
                          style={{ color: 'var(--text-muted)' }}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {data.team.rows.map(r => (
                      <tr key={r.user_id} onClick={() => setFocus(String(r.user_id))} className="cursor-pointer"
                        style={{ borderBottom: '1px solid var(--border)', opacity: r.movements ? 1 : 0.55 }}
                        onMouseEnter={e => { e.currentTarget.style.background = 'var(--bg-input)' }}
                        onMouseLeave={e => { e.currentTarget.style.background = 'transparent' }}>
                        <td className="px-2 py-1.5">
                          <span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{r.name}</span>
                          <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{r.role}</span>
                        </td>
                        <td className="px-2 py-1.5 text-right tabular-nums font-bold"
                          style={{ color: r.movements ? 'var(--text-h)' : 'var(--text-muted)' }}>{r.movements}</td>
                        <td className="px-2 py-1.5 text-right tabular-nums" style={{ color: '#10B981' }}>{r.qty_in ? fmtQty(r.qty_in) : '-'}</td>
                        <td className="px-2 py-1.5 text-right tabular-nums" style={{ color: '#f59e0b' }}>{r.qty_out ? fmtQty(r.qty_out) : '-'}</td>
                        <td className="px-2 py-1.5 text-right tabular-nums">{r.posted_docs || '-'}</td>
                        <td className="px-2 py-1.5 text-right tabular-nums">
                          {r.open_drafts
                            ? <span className="inline-flex items-center gap-1 font-bold" style={{ color: '#f59e0b' }}><FileClock size={10} />{r.open_drafts}</span>
                            : '-'}
                        </td>
                        <td className="px-2 py-1.5 text-right tabular-nums">
                          {r.reservations
                            ? <span className="inline-flex items-center gap-1" style={{ color: '#8b5cf6' }}><Lock size={10} />{r.reservations}</span>
                            : '-'}
                        </td>
                        <td className="px-2 py-1.5 text-right tabular-nums"
                          style={{ color: r.accuracy_pct == null ? 'var(--text-muted)' : r.accuracy_pct >= 95 ? '#10B981' : '#ef4444' }}>
                          {r.accuracy_pct == null ? '-' : `${r.accuracy_pct}%`}
                        </td>
                        <td className="px-2 py-1.5" style={{ color: 'var(--text-muted)' }}>
                          {r.last_active ? String(r.last_active).slice(0, 10) : 'never'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="text-[10px] mt-2" style={{ color: 'var(--text-muted)' }}>
                Click a row to scope this whole page to that person.
              </p>
            </Card>
          )}

          {/* Movement trend */}
          <Card title="Movement trend" icon={Activity} className="mb-4">
            <TrendChart data={data.movement_trend || []} />
          </Card>

          <div className="grid gap-4 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(320px,1fr))' }}>
            {/* ABC */}
            <Card title="ABC — value concentration" icon={Layers3}
              hint="Share of consumption value. A = the ~20% of items that carry ~80% of the value.">
              <Bars counts={data.abc?.counts} colors={ABC_COLOR} />
              <MiniTable
                head={['Item', 'Value', 'Cum %', 'Class']}
                rows={(data.abc?.rows || []).slice(0, 8).map(r => [
                  <ItemCell key="i" id={r.product_id} sku={r.sku} name={r.name} />,
                  money(r.value), `${r.cumulative_share}%`,
                  <Pill key="c" text={r.class} color={ABC_COLOR[r.class]} />,
                ])}
                empty="No consumption in this window."
              />
            </Card>

            {/* XYZ */}
            <Card title="XYZ — demand steadiness" icon={Gauge}
              hint="Coefficient of variation of weekly demand. X is steady and forecastable; Z is erratic.">
              <Bars counts={data.xyz?.counts} colors={XYZ_COLOR} />
              <MiniTable
                head={['Item', 'CV', 'Weekly avg', 'Class']}
                rows={(data.xyz?.rows || []).slice(0, 8).map(r => [
                  <ItemCell key="i" id={r.product_id} sku={r.sku} name={r.name} />,
                  r.cv, fmtQty(r.weekly_avg),
                  <Pill key="c" text={r.class} color={XYZ_COLOR[r.class]} />,
                ])}
                empty="Not enough demand history yet."
              />
            </Card>
          </div>

          <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(320px,1fr))' }}>
            {/* Turnover */}
            <Card title="Turnover" icon={TrendingUp} hint="Consumption ÷ average stock held, annualised.">
              <p className="text-[10px] font-bold uppercase tracking-wide mb-1.5" style={{ color: 'var(--text-muted)' }}>Fastest moving</p>
              <MiniTable
                head={['Item', 'Turns', 'Annualised']}
                rows={(data.turnover?.fastest || []).slice(0, 5).map(r => [
                  <ItemCell key="i" id={r.product_id} sku={r.sku} name={r.name} />,
                  `${r.turnover}×`, `${r.annualised}×`,
                ])}
                empty="No turnover yet."
              />
              {(data.turnover?.slowest || []).length > 0 && (
                <>
                  <p className="text-[10px] font-bold uppercase tracking-wide mt-3 mb-1.5" style={{ color: 'var(--text-muted)' }}>Slowest moving</p>
                  <MiniTable
                    head={['Item', 'Turns', 'Annualised']}
                    rows={data.turnover.slowest.slice(0, 5).map(r => [
                      <ItemCell key="i" id={r.product_id} sku={r.sku} name={r.name} />,
                      `${r.turnover}×`, `${r.annualised}×`,
                    ])}
                  />
                </>
              )}
            </Card>

            {/* Dead stock */}
            <Card title="Dead stock" icon={Skull}
              hint="On the shelf but nothing issued in this window — capital sitting still.">
              <div className="flex items-center gap-3 mb-3 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
                <span className="text-2xl font-black tabular-nums" style={{ color: '#ef4444' }}>{data.dead_stock?.count ?? 0}</span>
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                  items · <strong style={{ color: 'var(--text-h)' }}>{money(data.dead_stock?.value)}</strong> tied up
                </span>
              </div>
              <MiniTable
                head={['Item', 'Qty', 'Value']}
                rows={(data.dead_stock?.rows || []).slice(0, 8).map(r => [
                  <ItemCell key="i" id={r.product_id} sku={r.sku} name={r.name} />,
                  fmtQty(r.quantity), money(r.value),
                ])}
                empty="Nothing is sitting idle — everything moved."
              />
            </Card>

            {/* Category mix (admin only — a staff member's slice isn't a business mix) */}
            {!mine && (data.category_mix || []).length > 0 && (
              <Card title="Stock value by category" icon={PieChart}>
                <StackedShare rows={data.category_mix} />
              </Card>
            )}
          </div>
        </>
      )}
    </div>
  )
}

/* ── Bits ───────────────────────────────────────────────────────── */

function Tile({ label, value, icon: Icon, color, hint }) {
  return (
    <div className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }} title={hint || ''}>
      <div className="flex items-center gap-2 mb-2">
        <Icon size={14} style={{ color }} />
        <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</span>
      </div>
      <p className="text-2xl font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{value}</p>
    </div>
  )
}

function Card({ title, icon: Icon, hint, children, className = '' }) {
  return (
    <section className={`rounded-2xl p-4 ${className}`} style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-1">
        {Icon && <Icon size={14} style={{ color: INV_ACCENT }} />}
        <h2 className="font-bold text-xs" style={{ color: 'var(--text-h)' }}>{title}</h2>
      </div>
      {hint && <p className="text-[10px] mb-3" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
      {children}
    </section>
  )
}

/** Class counts as a proportional bar — the shape of the split at a glance. */
function Bars({ counts = {}, colors }) {
  const total = Object.values(counts).reduce((a, b) => a + b, 0)
  if (!total) return null

  return (
    <div className="mb-3">
      <div className="flex rounded-lg overflow-hidden" style={{ height: 8 }}>
        {Object.entries(counts).map(([k, v]) => v > 0 && (
          <div key={k} style={{ width: `${(v / total) * 100}%`, background: colors[k] }} title={`${k}: ${v}`} />
        ))}
      </div>
      <div className="flex gap-3 mt-1.5">
        {Object.entries(counts).map(([k, v]) => (
          <span key={k} className="flex items-center gap-1 text-[10px]" style={{ color: 'var(--text-muted)' }}>
            <span style={{ width: 7, height: 7, borderRadius: 2, background: colors[k], display: 'inline-block' }} />
            {k}: <strong style={{ color: 'var(--text-h)' }}>{v}</strong>
          </span>
        ))}
      </div>
    </div>
  )
}

/** Daily in/out as opposing bars — dependency-free SVG. */
function TrendChart({ data }) {
  if (!data.length) return <p className="text-xs py-6 text-center" style={{ color: 'var(--text-muted)' }}>No movements in this window.</p>

  const max = Math.max(1, ...data.map(d => Math.max(d.in, d.out)))
  const w = Math.max(1, 100 / data.length)

  return (
    <div>
      <svg viewBox="0 0 100 44" preserveAspectRatio="none" style={{ width: '100%', height: 120 }}>
        <line x1="0" y1="22" x2="100" y2="22" stroke="var(--border)" strokeWidth="0.3" />
        {data.map((d, i) => (
          <g key={d.date}>
            {d.in > 0 && <rect x={i * w + w * 0.15} y={22 - (d.in / max) * 20} width={w * 0.7} height={(d.in / max) * 20} fill="#10B981" />}
            {d.out > 0 && <rect x={i * w + w * 0.15} y={22} width={w * 0.7} height={(d.out / max) * 20} fill="#f59e0b" />}
          </g>
        ))}
      </svg>
      <div className="flex items-center justify-between mt-1">
        <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{data[0]?.date}</span>
        <span className="flex gap-3 text-[10px]">
          <span style={{ color: '#10B981' }}>■ In</span>
          <span style={{ color: '#f59e0b' }}>■ Out</span>
        </span>
        <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{data[data.length - 1]?.date}</span>
      </div>
    </div>
  )
}

function StackedShare({ rows }) {
  const total = rows.reduce((s, r) => s + r.value, 0) || 1
  const palette = ['#10B981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ec4899', '#0ea5e9', '#94a3b8']

  return (
    <div>
      <div className="flex rounded-lg overflow-hidden mb-2" style={{ height: 10 }}>
        {rows.map((r, i) => (
          <div key={r.name} style={{ width: `${(r.value / total) * 100}%`, background: palette[i % palette.length] }} title={`${r.name}: ${money(r.value)}`} />
        ))}
      </div>
      <ul className="space-y-1">
        {rows.slice(0, 7).map((r, i) => (
          <li key={r.name} className="flex items-center gap-2 text-[11px]">
            <span style={{ width: 8, height: 8, borderRadius: 2, background: palette[i % palette.length] }} />
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

function MiniTable({ head, rows, empty }) {
  if (!rows?.length) return empty ? <p className="text-[11px] py-3" style={{ color: 'var(--text-muted)' }}>{empty}</p> : null

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-[11px]">
        <thead>
          <tr style={{ borderBottom: '1px solid var(--border)' }}>
            {head.map((h, i) => (
              <th key={h} className={`px-1.5 py-1.5 font-bold ${i > 0 ? 'text-right' : 'text-left'}`} style={{ color: 'var(--text-muted)' }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
              {r.map((c, j) => (
                <td key={j} className={`px-1.5 py-1.5 ${j > 0 ? 'text-right tabular-nums' : ''}`} style={{ color: 'var(--text-body)' }}>{c}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

const ItemCell = ({ id, sku, name }) => (
  <Link to={`/app/inventory/products/${id}`} className="hover:underline">
    <span className="block font-semibold truncate" style={{ color: 'var(--text-h)', maxWidth: 150 }}>{name}</span>
    <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{sku}</span>
  </Link>
)

const Pill = ({ text, color }) => (
  <span className="text-[9px] font-bold px-1.5 py-0.5 rounded-full"
    style={{ background: `color-mix(in srgb, ${color} 16%, transparent)`, color }}>{text}</span>
)
