import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { CalendarDays, Plus, Loader2, AlertTriangle, ListChecks, Clock } from 'lucide-react'

/**
 * Vendor meeting history (Meeting.docx §17).
 *
 * Reads the shared meetings engine scoped to this vendor: rollup totals, a
 * by-type breakdown, and the meeting list. "This becomes very valuable for
 * vendor governance." Read-only here; scheduling opens the meeting create form
 * pre-scoped to the vendor.
 */
const STATUS_COLOR = {
  Scheduled: '#0ea5e9', Delayed: '#f59e0b', Completed: '#10b981', Cancelled: '#94a3b8',
}
const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')

export function VendorMeetingsPanel({ vendorId, api }) {
  const navigate = useNavigate()
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr]         = useState(null)

  useEffect(() => {
    setLoading(true); setErr(null)
    api.vendors.meetingHistory(vendorId)
      .then(setData)
      .catch(() => setErr('Could not load the meeting history.'))
      .finally(() => setLoading(false))
  }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps

  const t = data?.totals

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
          <CalendarDays size={16} style={{ color: '#a78bfa' }} />
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Meeting History</h2>
        </div>
        <button onClick={() => navigate(`/app/tpv/kickoff/new?vendor=${vendorId}`)} style={btnPrimary}>
          <Plus size={13} /> Schedule meeting
        </button>
      </div>

      {loading ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}><Loader2 size={16} className="vm-spin" style={{ color: '#a78bfa' }} /><span style={muted}>Loading…</span></div>
      ) : err ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
          <AlertTriangle size={14} style={{ color: '#ef4444' }} /><span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span>
        </div>
      ) : !data || t.meetings === 0 ? (
        <div style={{ padding: '28px 16px', borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center' }}>
          <CalendarDays size={26} style={{ color: 'var(--text-muted)', opacity: 0.5, marginBottom: 8 }} />
          <p style={{ ...muted, margin: 0, fontSize: 13 }}>No meetings for this vendor yet.</p>
        </div>
      ) : (
        <>
          {/* Rollup totals */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 8, marginBottom: 16 }}>
            <Stat label="Meetings"     value={t.meetings} />
            <Stat label="Completed"    value={t.completed} color="#10b981" />
            <Stat label="Open actions" value={t.open_actions} color={t.open_actions ? '#f59e0b' : undefined} />
            <Stat label="Overdue"      value={t.overdue_actions} color={t.overdue_actions ? '#ef4444' : undefined} />
          </div>

          {/* By type */}
          {data.by_type?.length > 0 && (
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 16 }}>
              {data.by_type.map(b => (
                <span key={b.type} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '5px 11px', borderRadius: 999, background: 'var(--bg-input)', border: '1px solid var(--border)', fontSize: 12 }}>
                  <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{b.label}</span>
                  <span style={{ color: '#a78bfa', fontWeight: 800 }}>{b.count}</span>
                </span>
              ))}
            </div>
          )}

          {/* Meeting list */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {data.meetings.map(m => {
              const color = STATUS_COLOR[m.status] || 'var(--text-muted)'
              return (
                <button key={m.id} onClick={() => navigate(`/app/tpv/kickoff/${m.id}`)}
                  style={{ textAlign: 'left', display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', borderRadius: 10, cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  <div style={{ minWidth: 0, flex: 1 }}>
                    <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.title || m.meeting_type_label}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{m.meeting_type_label} · {m.scheduled_at ? fmtDate(m.scheduled_at) : 'Unscheduled'}</div>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexShrink: 0 }}>
                    {m.open_actions > 0 && <Pill icon={ListChecks} tone="#f59e0b">{m.open_actions}</Pill>}
                    {m.open_issues > 0 && <Pill icon={AlertTriangle} tone="#ef4444">{m.open_issues}</Pill>}
                    <span style={{ padding: '2px 9px', borderRadius: 999, fontSize: 10.5, fontWeight: 800, background: `${color}22`, color }}>{m.status}</span>
                  </div>
                </button>
              )
            })}
          </div>
        </>
      )}
      <style>{`@keyframes vmSpin{to{transform:rotate(360deg)}}.vm-spin{animation:vmSpin .9s linear infinite}`}</style>
    </div>
  )
}

function Stat({ label, value, color }) {
  return (
    <div style={{ padding: '10px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <div style={{ fontSize: 19, fontWeight: 900, color: color || 'var(--text-h)', lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}
const Pill = ({ icon: Icon, tone, children }) => (
  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '2px 7px', borderRadius: 6, background: `${tone}1f`, color: tone, fontSize: 10.5, fontWeight: 800 }}>
    <Icon size={10} /> {children}
  </span>
)
const muted = { color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }
const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 13px', borderRadius: 9, border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: '#fff', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }

export default VendorMeetingsPanel
