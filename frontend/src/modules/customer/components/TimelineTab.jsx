import { useState, useEffect, useMemo } from 'react'
import {
  Receipt, CreditCard, FileX, IndianRupee, FileText, ClipboardList, FileSignature,
  RefreshCw, ShoppingCart, Truck, Package, Send, FolderKanban, CheckSquare,
  Activity as ActivityIcon, CalendarDays, StickyNote, LifeBuoy, AlertOctagon,
  Star, Paperclip, Globe, Bell, Circle,
} from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'

/**
 * §5 — the Customer Timeline.
 *
 * Read-only on purpose: every row here is the shadow of a record that lives in
 * the module that owns it, so there is no "add" button. Clicking through goes
 * to the owner, which is the §6 principle applied to a screen instead of a tab.
 */
const ICON = {
  invoice: Receipt, payment: CreditCard, credit_note: FileX, expense: IndianRupee,
  estimate: ClipboardList, proposal: FileText, contract: FileSignature,
  subscription: RefreshCw, purchase_order: ShoppingCart,
  shipment: Truck, package: Package, pre_alert: Send, delivery_note: Truck,
  project: FolderKanban, task: CheckSquare,
  activity: ActivityIcon, meeting: CalendarDays, note: StickyNote,
  ticket: LifeBuoy, complaint: AlertOctagon, feedback: Star,
  file: Paperclip, domain: Globe, reminder: Bell,
}

const CATEGORY_COLOR = {
  finance: '#0d9488', commercial: '#8b5cf6', operations: '#2563eb',
  relationship: '#0ea5e9', service: '#f97316', admin: '#64748b', other: '#6b7280',
}

const LABEL = (t) => t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

const RANGES = [
  { key: '30',  label: 'Last 30 days', days: 30 },
  { key: '90',  label: 'Last 90 days', days: 90 },
  { key: '365', label: 'Last year',    days: 365 },
  { key: 'all', label: 'All time',     days: null },
]

export default function TimelineTab({ id }) {
  const toast = useToast()
  const [data, setData] = useState(null)
  const [range, setRange] = useState('90')
  const [active, setActive] = useState([])          // selected event types

  useEffect(() => {
    setData(null)
    const days = RANGES.find(r => r.key === range)?.days
    const params = {}
    if (days) {
      const d = new Date(); d.setDate(d.getDate() - days)
      params.from = d.toISOString().slice(0, 10)
    }
    if (active.length) params.types = active.join(',')
    customerApi.timeline(id, params).then(setData).catch(e => toast.error(e.message))
  }, [id, range, active])

  // Counts come from the server BEFORE the type filter, so the chips keep
  // showing what exists rather than collapsing to whatever is selected.
  const chips = useMemo(
    () => Object.entries(data?.counts ?? {}).sort((a, b) => b[1] - a[1]),
    [data?.counts],
  )

  const toggle = (t) => setActive(p => p.includes(t) ? p.filter(x => x !== t) : [...p, t])

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding: 16 }}>
        <div className="flex flex-wrap items-center gap-2 mb-3">
          {RANGES.map(r => (
            <button key={r.key} onClick={() => setRange(r.key)}
              className="px-3 py-1.5 rounded-lg text-[11px] font-bold"
              style={{
                background: range === r.key ? 'rgba(124,58,237,0.16)' : 'var(--bg-input)',
                color: range === r.key ? 'var(--accent)' : 'var(--text-muted)',
                border: `1px solid ${range === r.key ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`,
              }}>
              {r.label}
            </button>
          ))}
          {active.length > 0 && (
            <button onClick={() => setActive([])} className="ml-auto text-[11px] font-bold" style={{ color: 'var(--accent)' }}>
              Clear {active.length} filter{active.length > 1 ? 's' : ''}
            </button>
          )}
        </div>
        <div className="flex flex-wrap gap-1.5">
          {chips.length === 0 && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Nothing recorded in this period.</span>}
          {chips.map(([type, n]) => {
            const on = active.includes(type)
            const Icon = ICON[type] || Circle
            return (
              <button key={type} onClick={() => toggle(type)}
                className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold"
                style={{
                  background: on ? 'rgba(124,58,237,0.16)' : 'var(--bg-input)',
                  color: on ? 'var(--accent)' : 'var(--text-muted)',
                  border: `1px solid ${on ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`,
                }}>
                <Icon size={12} /> {LABEL(type)} <span style={{ opacity: 0.65 }}>{n}</span>
              </button>
            )
          })}
        </div>
      </div>

      {data === null ? (
        <div className="card-3d" style={{ padding: 18 }}>
          <div className="skeleton h-24 rounded-lg" style={{ background: 'var(--border)' }} />
        </div>
      ) : data.days.length === 0 ? (
        <div className="card-3d" style={{ padding: 28, textAlign: 'center' }}>
          <p className="text-sm font-bold" style={{ color: 'var(--text-h)', margin: 0 }}>Nothing on the timeline yet</p>
          <p className="text-xs" style={{ color: 'var(--text-muted)', margin: '6px 0 0' }}>
            Activity from every connected module appears here as it happens.
          </p>
        </div>
      ) : (
        <div className="card-3d" style={{ padding: 18 }}>
          {data.days.map(day => (
            <div key={day.date} style={{ marginBottom: 18 }}>
              <p className="label-caps" style={{ color: 'var(--accent)', marginBottom: 8 }}>{day.label}</p>
              <div style={{ borderLeft: '2px solid var(--border)', paddingLeft: 14, marginLeft: 4 }}>
                {day.events.map((e, i) => {
                  const Icon = ICON[e.type] || Circle
                  const colour = CATEGORY_COLOR[e.category] || CATEGORY_COLOR.other
                  return (
                    <div key={`${day.date}-${i}`} className="flex items-start gap-2.5" style={{ padding: '7px 0' }}>
                      <span style={{
                        marginLeft: -25, marginTop: 1, width: 20, height: 20, borderRadius: 6,
                        display: 'grid', placeItems: 'center', flexShrink: 0,
                        background: 'var(--bg-card)', border: `1px solid ${colour}`, color: colour,
                      }}>
                        <Icon size={11} />
                      </span>
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <p className="text-xs" style={{ color: 'var(--text-h)', margin: 0, fontWeight: 600 }}>{e.label}</p>
                        {e.detail && <p className="text-[11px]" style={{ color: 'var(--text-muted)', margin: '1px 0 0' }}>{e.detail}</p>}
                      </div>
                      <span className="text-[11px] whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>
                        {new Date(e.at).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}
                      </span>
                    </div>
                  )
                })}
              </div>
            </div>
          ))}
          {data.total > data.days.reduce((s, d) => s + d.events.length, 0) && (
            <p className="text-[11px] text-center" style={{ color: 'var(--text-muted)' }}>
              Showing the most recent {data.days.reduce((s, d) => s + d.events.length, 0)} of {data.total} events.
            </p>
          )}
        </div>
      )}
    </div>
  )
}
