import { useState, useEffect, useCallback } from 'react'
import {
  LayoutGrid, List as ListIcon, CalendarDays, Clock, Video, MapPin, Users, Check, RefreshCw, XCircle,
  ExternalLink, Download, ChevronLeft, ChevronRight, Star,
} from 'lucide-react'
import { companyPortalApi } from '@/services/companyPortalApi'

const unwrap = r => r?.data ?? r
const ST = {
  Scheduled: { c: '#3b82f6', bg: 'rgba(59,130,246,0.12)' },
  Completed: { c: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  Cancelled: { c: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  Rescheduled: { c: '#f59e0b', bg: 'rgba(245,158,11,0.12)' },
}
const ACT = { Confirmed: '#10b981', Reschedule: '#f59e0b', Cancel: '#ef4444' }
const dt = d => d ? new Date(d) : null
const fmtDate = d => d ? new Date(d).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'
const fmtTime = d => d ? new Date(d).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '—'
const today = () => { const t = new Date(); t.setHours(0, 0, 0, 0); return t }

export default function CompanyInterviews({ requestId, showToast }) {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [view, setView] = useState('card')
  const [resched, setResched] = useState(null) // interview being rescheduled
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try { setItems(unwrap(await companyPortalApi.interviews(requestId)) || []) }
    catch (e) { showToast(e.response?.data?.message || 'Failed to load', 'error') }
    finally { setLoading(false) }
  }, [requestId, showToast])
  useEffect(() => { load() }, [load])

  const act = async (iv, action, data = {}) => {
    if (busy) return
    if ((action === 'Cancel') && !window.confirm('Request cancellation of this interview?')) return
    setBusy(true)
    try { await companyPortalApi.interviewAction(requestId, iv.id, { action, ...data }); showToast(`${action} request sent`); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
    finally { setBusy(false) }
  }
  const invite = async (iv) => {
    try {
      const blob = await companyPortalApi.interviewInvite(requestId, iv.id)
      const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `interview-${iv.id}.ics`; a.click(); URL.revokeObjectURL(url)
    } catch { showToast('Invite unavailable', 'error') }
  }

  const buckets = {
    today: items.filter(i => { const d = dt(i.scheduled_at); return d && d.toDateString() === new Date().toDateString() }),
    upcoming: items.filter(i => { const d = dt(i.scheduled_at); return d && d > new Date() && i.status === 'Scheduled' }),
    past: items.filter(i => { const d = dt(i.scheduled_at); return d && d < today() && i.status !== 'Scheduled' }),
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, flexWrap: 'wrap', gap: 10 }}>
        <div style={{ display: 'flex', gap: 14, fontSize: 12, color: 'var(--text-muted)' }}>
          <span>Today <b style={{ color: '#a78bfa' }}>{buckets.today.length}</b></span>
          <span>Upcoming <b style={{ color: '#3b82f6' }}>{buckets.upcoming.length}</b></span>
          <span>Past <b style={{ color: 'var(--text-muted)' }}>{buckets.past.length}</b></span>
        </div>
        <div style={{ display: 'flex', gap: 4 }}>
          {[['card', LayoutGrid], ['list', ListIcon], ['calendar', CalendarDays], ['timeline', Clock]].map(([v, Icon]) => (
            <button key={v} onClick={() => setView(v)} title={v} style={{ padding: 8, borderRadius: 8, cursor: 'pointer', border: '1px solid var(--border)', background: view === v ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)', color: view === v ? '#a78bfa' : 'var(--text-muted)' }}><Icon size={16} /></button>
          ))}
        </div>
      </div>

      {loading ? <div style={{ color: 'var(--text-muted)', padding: 40, textAlign: 'center' }}>Loading…</div> :
        items.length === 0 ? <Empty t="No interviews scheduled yet." /> :
        view === 'calendar' ? <Calendar items={items} /> :
        view === 'timeline' ? <Timeline items={items} /> :
        view === 'list' ? (
          <div className="card-3d" style={{ padding: 0, overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
              <thead><tr style={{ background: 'var(--bg-input)' }}>{['Candidate', 'Round', 'Type', 'Date', 'Time', 'Interviewer', 'Status', 'Action'].map(h => <th key={h} style={{ textAlign: 'left', padding: '10px 12px', fontWeight: 700, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>)}</tr></thead>
              <tbody>{items.map(i => {
                const s = ST[i.status] || {}
                return (
                  <tr key={i.id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '10px 12px', color: 'var(--text-h)', fontWeight: 700 }}>{i.candidate}</td>
                    <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{i.round}</td>
                    <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{i.type}</td>
                    <td style={{ padding: '10px 12px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtDate(i.scheduled_at)}</td>
                    <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{fmtTime(i.scheduled_at)}</td>
                    <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{i.interviewer || '—'}</td>
                    <td style={{ padding: '10px 12px' }}><span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: s.bg, color: s.c }}>{i.status}</span>{i.client_action && <span style={{ fontSize: 9.5, marginLeft: 6, color: ACT[i.client_action] }}>· {i.client_action}</span>}</td>
                    <td style={{ padding: '10px 12px' }}>{i.meet_link && <a href={i.meet_link} target="_blank" rel="noreferrer" style={{ color: '#a78bfa' }}><Video size={14} /></a>}</td>
                  </tr>
                )
              })}</tbody>
            </table>
          </div>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(320px,1fr))', gap: 12 }}>
            {items.map(i => <Card key={i.id} i={i} onAct={act} onResched={() => setResched(i)} onInvite={() => invite(i)} />)}
          </div>
        )}

      {resched && <RescheduleModal iv={resched} onClose={() => setResched(null)} onSubmit={async (data) => { await act(resched, 'Reschedule', data); setResched(null) }} />}
    </div>
  )
}

function Card({ i, onAct, onResched, onInvite }) {
  const s = ST[i.status] || {}
  const canAct = i.status === 'Scheduled'
  return (
    <div className="card-3d" style={{ padding: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
        <div>
          <p style={{ fontWeight: 800, color: 'var(--text-h)', fontSize: 14.5 }}>{i.candidate}</p>
          <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{i.round} · {i.type}</p>
        </div>
        <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, height: 'fit-content', background: s.bg, color: s.c }}>{i.status}</span>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 4, marginTop: 10, fontSize: 12, color: 'var(--text-muted)' }}>
        <span><Clock size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {fmtDate(i.scheduled_at)} · {fmtTime(i.scheduled_at)}</span>
        {i.interviewer && <span><Users size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {i.interviewer}{i.panel?.length ? ` +${i.panel.length}` : ''}</span>}
        {i.venue && <span><MapPin size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {i.venue}</span>}
        {i.rating != null && <span><Star size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {i.rating}/5 · {i.decision || '—'}</span>}
      </div>
      {i.client_action && <p style={{ fontSize: 11, fontWeight: 700, marginTop: 8, color: ACT[i.client_action] }}>You requested: {i.client_action}</p>}
      <div style={{ display: 'flex', gap: 6, marginTop: 12, flexWrap: 'wrap' }}>
        {i.meet_link && <a href={i.meet_link} target="_blank" rel="noreferrer" style={{ ...mini, color: '#a78bfa', textDecoration: 'none' }}><ExternalLink size={11} /> Join</a>}
        <button onClick={onInvite} style={mini}><Download size={11} /> Invite</button>
        {canAct && <button onClick={() => onAct(i, 'Confirmed')} style={{ ...mini, color: '#10b981' }}><Check size={11} /> Confirm</button>}
        {canAct && <button onClick={onResched} style={{ ...mini, color: '#f59e0b' }}><RefreshCw size={11} /> Reschedule</button>}
        {canAct && <button onClick={() => onAct(i, 'Cancel')} style={{ ...mini, color: '#f87171' }}><XCircle size={11} /> Cancel</button>}
      </div>
    </div>
  )
}

function RescheduleModal({ iv, onClose, onSubmit }) {
  const [date, setDate] = useState('')
  const [time, setTime] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const min = new Date().toISOString().slice(0, 10) // disable past dates
  const submit = async () => {
    if (!date) return
    setBusy(true)
    try { await onSubmit({ preferred_date: date, preferred_time: time, reason }) } finally { setBusy(false) }
  }
  return (
    <div style={overlay}>
      <div className="card-3d" style={{ width: '100%', maxWidth: 420, padding: 22 }}>
        <h3 style={{ margin: '0 0 4px', fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>Request Reschedule</h3>
        <p style={{ margin: '0 0 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>{iv.round} · {iv.candidate}</p>
        <label style={lbl}>Preferred Date *</label>
        <input type="date" min={min} value={date} onChange={e => setDate(e.target.value)} style={inp} />
        <label style={{ ...lbl, marginTop: 12 }}>Preferred Time</label>
        <input type="time" value={time} onChange={e => setTime(e.target.value)} style={inp} />
        <label style={{ ...lbl, marginTop: 12 }}>Reason</label>
        <textarea rows={2} value={reason} onChange={e => setReason(e.target.value)} style={{ ...inp, resize: 'vertical' }} placeholder="Why reschedule?" />
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16 }}>
          <button onClick={onClose} style={{ ...mini, padding: '9px 16px' }}>Cancel</button>
          <button onClick={submit} disabled={busy || !date} style={{ padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer', opacity: (busy || !date) ? 0.6 : 1 }}>{busy ? 'Sending…' : 'Send Request'}</button>
        </div>
      </div>
    </div>
  )
}

function Calendar({ items }) {
  const [cursor, setCursor] = useState(() => { const d = new Date(); d.setDate(1); return d })
  const y = cursor.getFullYear(), m = cursor.getMonth()
  const first = new Date(y, m, 1).getDay()
  const days = new Date(y, m + 1, 0).getDate()
  const byDay = {}
  items.forEach(i => { const d = dt(i.scheduled_at); if (d && d.getFullYear() === y && d.getMonth() === m) (byDay[d.getDate()] ||= []).push(i) })
  const cells = [...Array(first).fill(null), ...Array.from({ length: days }, (_, i) => i + 1)]
  return (
    <div className="card-3d" style={{ padding: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <button onClick={() => setCursor(new Date(y, m - 1, 1))} style={mini}><ChevronLeft size={14} /></button>
        <b style={{ color: 'var(--text-h)' }}>{cursor.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</b>
        <button onClick={() => setCursor(new Date(y, m + 1, 1))} style={mini}><ChevronRight size={14} /></button>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 4 }}>
        {['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].map(d => <div key={d} style={{ textAlign: 'center', fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', padding: 4 }}>{d}</div>)}
        {cells.map((day, i) => (
          <div key={i} style={{ minHeight: 56, borderRadius: 8, padding: 4, background: day ? 'var(--bg-input)' : 'transparent', border: byDay[day] ? '1px solid rgba(124,58,237,0.4)' : '1px solid transparent' }}>
            {day && <><div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600 }}>{day}</div>
              {(byDay[day] || []).slice(0, 2).map(iv => <div key={iv.id} title={`${iv.candidate} · ${iv.round}`} style={{ fontSize: 9, marginTop: 2, padding: '1px 4px', borderRadius: 4, background: 'rgba(124,58,237,0.2)', color: '#c4b5fd', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{fmtTime(iv.scheduled_at)} {iv.candidate}</div>)}</>}
          </div>
        ))}
      </div>
    </div>
  )
}
function Timeline({ items }) {
  const sorted = [...items].sort((a, b) => new Date(b.scheduled_at) - new Date(a.scheduled_at))
  return (
    <div className="card-3d" style={{ padding: 18 }}>
      {sorted.map((i, idx) => (
        <div key={i.id} style={{ display: 'flex', gap: 12, paddingBottom: 14 }}>
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
            <div style={{ width: 10, height: 10, borderRadius: '50%', background: (ST[i.status] || {}).c || '#64748b', marginTop: 4 }} />
            {idx < sorted.length - 1 && <div style={{ width: 2, flex: 1, background: 'var(--border)', marginTop: 4 }} />}
          </div>
          <div>
            <p style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{i.round} · {i.candidate}</p>
            <p style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{fmtDate(i.scheduled_at)} {fmtTime(i.scheduled_at)} · {i.status}{i.client_action ? ` · You: ${i.client_action}` : ''}</p>
          </div>
        </div>
      ))}
    </div>
  )
}

const Empty = ({ t }) => <div className="card-3d" style={{ padding: 30, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>{t}</div>
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.72)', zIndex: 1100, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }
const mini = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }
const lbl = { display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
const inp = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
