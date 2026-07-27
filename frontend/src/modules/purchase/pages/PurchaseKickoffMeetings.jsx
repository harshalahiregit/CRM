import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  CalendarDays, Plus, RefreshCw, Clock, CheckCircle2, XCircle, Send,
  Users, AlertTriangle, ClipboardCheck, Pencil, BellRing, Eye, Download, Loader2, Mail, MessageCircle, Smartphone,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import {
  PK_STATUS, pkStatusCfg, PK_MODES, pkModeLabel, fmtDate, fmtDateTime,
} from '@/modules/purchase/kickoffConstants'
import { KIT3D_STYLE, Overlay, ModalFooter, Field, TextInput, SelectInput } from '@/components/ui/kit3d'

/**
 * Purchase kickoff meeting registry — the Purchase-owned scheduling engine's list
 * view. Consumes ONLY purchaseApi.kickoff (/api/purchase/kickoff). Independent of
 * the shared/TPV kickoff engine: no TPV imports, no shared kickoff page/component.
 */
export default function PurchaseKickoffMeetings() {
  const navigate = useNavigate()
  const [data, setData]   = useState([])
  const [stats, setStats] = useState(null)
  const [loading, setLoad] = useState(true)
  const [filter, setFilter] = useState('All')
  const [banner, setBanner] = useState(null)
  const [pdfBusy, setPdfBusy] = useState(null)

  const [editFor, setEditFor]      = useState(null)
  const [attendanceFor, setAttFor] = useState(null)
  const [reminderFor, setRemindFor] = useState(null)

  const load = () => {
    setLoad(true)
    Promise.all([
      purchaseApi.kickoff.list(filter === 'All' ? {} : { status: filter }),
      purchaseApi.kickoff.stats(),
    ]).then(([rows, s]) => { setData(rows?.data ?? rows ?? []); setStats(s); setLoad(false) })
      .catch(() => setLoad(false))
  }
  useEffect(() => { load() }, [filter]) // eslint-disable-line react-hooks/exhaustive-deps

  // View / Download the MOM PDF — generate on demand if none exists yet.
  const handlePdf = async (m, download) => {
    setPdfBusy(`${m.id}:${download ? 'dl' : 'view'}`); setBanner(null)
    try {
      let blob
      try {
        blob = await purchaseApi.kickoff.momBlob(m.id)
      } catch {
        await purchaseApi.kickoff.generateMom(m.id)
        blob = await purchaseApi.kickoff.momBlob(m.id)
      }
      const url = URL.createObjectURL(blob)
      if (download) {
        const a = document.createElement('a')
        a.href = url; a.download = `Purchase-MOM-${m.id}.pdf`
        document.body.appendChild(a); a.click(); a.remove()
      } else {
        window.open(url, '_blank', 'noopener')
      }
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch (e) {
      setBanner(e?.response?.data?.message || 'Could not open the MOM PDF.')
    } finally { setPdfBusy(null) }
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <style>{`@keyframes pkSpin{to{transform:rotate(360deg)}}.pk-spin{animation:pkSpin .9s linear infinite}`}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PRE-ONBOARDING</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Kickoff Meetings</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Schedule, track attendance &amp; minutes, and capture vendor acknowledgement.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => navigate('/app/purchase/kickoff/new')} style={solidBtn}><Plus size={15} /> Schedule meeting</button>
        </div>
      </div>

      {/* KPI strip */}
      {stats && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 18 }}>
          <Kpi label="Scheduled" value={stats.scheduled} icon={CalendarDays} color="#0ea5e9" />
          <Kpi label="Delayed" value={stats.delayed} icon={Clock} color="#f59e0b" danger={stats.delayed > 0} />
          <Kpi label="Completed" value={stats.completed} icon={CheckCircle2} color="#10b981" />
          <Kpi label="Awaiting acknowledgement" value={stats.awaiting_ack} icon={Send} color="#a78bfa" danger={stats.awaiting_ack > 0} />
        </div>
      )}

      {banner && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 14, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
          <AlertTriangle size={15} style={{ color: '#ef4444', flexShrink: 0 }} />
          <span style={{ fontSize: 13, color: 'var(--text-h)', flex: 1 }}>{banner}</span>
          <button onClick={() => setBanner(null)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}><XCircle size={15} /></button>
        </div>
      )}

      {/* Filter chips */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        {['All', PK_STATUS.SCHEDULED, PK_STATUS.DELAYED, PK_STATUS.COMPLETED, PK_STATUS.CANCELLED].map(f => {
          const on = filter === f
          return (
            <button key={f} onClick={() => setFilter(f)}
              style={{ padding: '6px 14px', borderRadius: 999, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
                background: on ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'var(--bg-card)',
                border: on ? 'none' : '1px solid var(--border)',
                color: on ? '#fff' : 'var(--text-muted)',
                boxShadow: on ? '0 6px 16px -6px rgba(124,58,237,.6)' : 'none' }}>
              {f}
            </button>
          )
        })}
      </div>

      {/* Register */}
      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {[1, 2, 3].map(i => <div key={i} className="skeleton" style={{ height: 64, borderRadius: 12, background: 'var(--border)' }} />)}
        </div>
      ) : data.length === 0 ? (
        <EmptyState filter={filter} onNew={() => navigate('/app/purchase/kickoff/new')} />
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 1120, fontSize: 12.5 }}>
              <thead>
                <tr>
                  {['ID', 'Purchase Vendor', 'Participants', 'Meeting Mode', 'Planned Date', 'Status', 'Acknowledgement', 'Response', 'Attendance', 'Meeting Date', 'Created At'].map(h => (
                    <th key={h} style={th}>{h}</th>
                  ))}
                  <th style={{ ...th, textAlign: 'right', position: 'sticky', right: 0, background: 'var(--bg-card)' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {data.map(m => {
                  const cfg = pkStatusCfg(m.status)
                  const busyView = pdfBusy === `${m.id}:view`
                  const busyDl   = pdfBusy === `${m.id}:dl`
                  return (
                    <tr key={m.id} className="pr-li-row" onClick={() => navigate(`/app/purchase/kickoff/${m.id}`)} style={{ cursor: 'pointer', borderTop: '1px solid var(--border)' }}>
                      <td style={td}><span style={{ fontWeight: 800, color: 'var(--text-h)' }}>#{m.id}</span></td>
                      <td style={td}>
                        <div style={{ fontWeight: 700, color: 'var(--text-h)', maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.vendor?.company_name || m.title}</div>
                      </td>
                      <td style={td}><Chip icon={Users}>{m.participants_count ?? 0}</Chip></td>
                      <td style={td}>{pkModeLabel(m.mode)}</td>
                      <td style={td}>{fmtDateTime(m.scheduled_at)}</td>
                      <td style={td}><span style={{ padding: '3px 10px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11, fontWeight: 800, whiteSpace: 'nowrap' }}>{cfg.label}</span></td>
                      <td style={td}>
                        {m.is_acknowledged
                          ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: '#10b981', fontWeight: 700 }}><CheckCircle2 size={13} /> Acknowledged</span>
                          : <span style={{ color: 'var(--text-muted)' }}>Pending</span>}
                      </td>
                      <td style={td}>{m.acknowledged_by_name || '—'}</td>
                      <td style={td}><Attn present={m.attended_count ?? 0} total={m.participants_count ?? 0} /></td>
                      <td style={td}>{m.completed_at ? fmtDate(m.completed_at) : '—'}</td>
                      <td style={td}>{fmtDate(m.created_at)}</td>
                      <td style={{ ...td, textAlign: 'right', position: 'sticky', right: 0, background: 'var(--bg-card)' }} onClick={e => e.stopPropagation()}>
                        <div style={{ display: 'inline-flex', gap: 4 }}>
                          <ActionBtn title="Attendance" icon={ClipboardCheck} color="#0ea5e9" onClick={() => setAttFor(m.id)} />
                          <ActionBtn title="Edit" icon={Pencil} color="#a78bfa" onClick={() => setEditFor(m)} />
                          <ActionBtn title="Reminder" icon={BellRing} color="#f59e0b" onClick={() => setRemindFor(m)} />
                          <ActionBtn title="View PDF" icon={busyView ? Loader2 : Eye} color="#10b981" spin={busyView} onClick={() => handlePdf(m, false)} />
                          <ActionBtn title="Download PDF" icon={busyDl ? Loader2 : Download} color="#7C3AED" spin={busyDl} onClick={() => handlePdf(m, true)} />
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {editFor && <MeetingEditModal meeting={editFor} onClose={() => setEditFor(null)} onDone={() => { setEditFor(null); load() }} />}
      {attendanceFor && <AttendanceModal id={attendanceFor} onClose={() => setAttFor(null)} onDone={() => { setAttFor(null); load() }} />}
      {reminderFor && <ReminderModal m={reminderFor} onClose={() => setRemindFor(null)} />}
    </div>
  )
}

/* ── cells & chips ──────────────────────────────────────────────────────────── */
function Kpi({ label, value, icon: Icon, color, danger }) {
  return (
    <div className="pr-kpi" style={{ padding: 16, outline: danger ? `1.5px solid ${color}66` : 'none' }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f` }}>
        <Icon size={18} style={{ color }} />
      </div>
      <div style={{ fontSize: 24, fontWeight: 900, color: 'var(--text-h)', marginTop: 11, lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}

const Chip = ({ icon: Icon, children }) => (
  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: 'var(--text-h)', fontWeight: 700 }}>
    <Icon size={13} style={{ color: 'var(--text-muted)' }} /> {children}
  </span>
)

const Attn = ({ present, total }) => {
  const all = total > 0 && present === total
  const none = present === 0
  const color = total === 0 ? 'var(--text-muted)' : all ? '#10b981' : none ? '#ef4444' : '#f59e0b'
  return <span style={{ fontWeight: 800, color }}>{present}/{total}</span>
}

function ActionBtn({ title, icon: Icon, color, onClick, spin }) {
  return (
    <button title={title} onClick={onClick}
      style={{ width: 30, height: 30, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer',
        background: 'var(--bg-input)', border: '1px solid var(--border)', color }}>
      <Icon size={15} className={spin ? 'pk-spin' : ''} />
    </button>
  )
}

function EmptyState({ onNew, filter }) {
  return (
    <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center' }}>
      <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}>
        <CalendarDays size={28} style={{ color: '#a78bfa' }} />
      </div>
      <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>
        {filter === 'All' ? 'No kickoff meetings yet' : `No ${filter.toLowerCase()} meetings`}
      </h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>
        Schedule a pre-onboarding meeting with a purchase vendor to get started.
      </p>
      {filter === 'All' && <button onClick={onNew} style={{ ...solidBtn, margin: '0 auto' }}><Plus size={15} /> Schedule meeting</button>}
    </div>
  )
}

/* ── Edit modal (schedule fields; create lives on the full page) ─────────────── */
function MeetingEditModal({ meeting, onClose, onDone }) {
  const [form, setForm] = useState(() => ({
    title: meeting?.title || '',
    scheduled_at: toLocalInput(meeting?.scheduled_at),
    duration_minutes: meeting?.duration_minutes || 60,
    mode: meeting?.mode || 'onsite',
    location: meeting?.location || '',
    agenda: meeting?.agenda || '',
  }))
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = {
        title: form.title || undefined,
        scheduled_at: form.scheduled_at || undefined,
        duration_minutes: Number(form.duration_minutes) || undefined,
        mode: form.mode, location: form.location, agenda: form.agenda,
      }
      const updated = await purchaseApi.kickoff.update(meeting.id, payload)
      onDone(updated?.data ?? updated)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save the meeting.')
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={720}>
      <div style={{ padding: '20px 22px 8px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Edit kickoff meeting</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>A pre-onboarding meeting with the purchase vendor.</p>
      </div>
      <div style={{ padding: '10px 22px', display: 'flex', flexDirection: 'column', gap: 2 }}>
        <Field label="Vendor" full>
          <div style={{ padding: '9px 12px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 13 }}>
            {meeting.vendor?.company_name || '— (not linked)'}
          </div>
        </Field>
        <Field label="Title (optional — defaults to the vendor name)" full>
          <TextInput value={form.title} onChange={set('title')} placeholder="Kickoff — Acme Supplies" />
        </Field>
        <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr', gap: 12 }}>
          <Field label="Date & time"><TextInput type="datetime-local" value={form.scheduled_at} onChange={set('scheduled_at')} /></Field>
          <Field label="Duration (min)"><TextInput type="number" value={form.duration_minutes} onChange={set('duration_minutes')} /></Field>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.6fr', gap: 12 }}>
          <Field label="Mode"><SelectInput value={form.mode} onChange={set('mode')} options={PK_MODES} pairs /></Field>
          <Field label={form.mode === 'online' ? 'Meeting link' : 'Location'}>
            <TextInput value={form.location} onChange={set('location')} placeholder={form.mode === 'online' ? 'https://…' : 'Site office, Gate 1'} />
          </Field>
        </div>
        <Field label="Agenda" full>
          <TextInput value={form.agenda} onChange={set('agenda')} placeholder="Scope walk, commercial terms, document checklist" />
        </Field>
        {err && <ModalError>{err}</ModalError>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel="Save changes" color="#7C3AED" />
    </Overlay>
  )
}

/* ── Attendance modal ───────────────────────────────────────────────────────── */
function AttendanceModal({ id, onClose, onDone }) {
  const [rows, setRows] = useState(null)
  const [title, setTitle] = useState('')
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  useEffect(() => {
    purchaseApi.kickoff.get(id).then(d => {
      const m = d?.data ?? d
      setTitle(m.title)
      setRows((m.participants || []).map(a => ({ id: a.id, name: a.name, role: a.role, organisation: a.organisation, attended: !!a.attended })))
    }).catch(() => setErr('Could not load the participant list.'))
  }, [id])

  const setAttended = (aid, val) => setRows(rs => rs.map(r => (r.id === aid ? { ...r, attended: val } : r)))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      await purchaseApi.kickoff.attendance(id, rows.map(r => ({ id: r.id, attended: r.attended })))
      onDone()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save attendance.')
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={560}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Mark attendance</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{title}</p>
      </div>
      <div style={{ padding: '10px 22px', maxHeight: 420, overflowY: 'auto' }}>
        {rows === null ? (
          <div style={{ padding: 24, textAlign: 'center' }}><Loader2 size={20} className="pk-spin" style={{ color: '#a78bfa' }} /></div>
        ) : rows.length === 0 ? (
          <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No participants are recorded for this meeting. Add participants via the schedule form first.</p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {rows.map(a => (
              <div key={a.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div style={{ width: 34, height: 34, borderRadius: '50%', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', color: '#a78bfa', fontWeight: 800, fontSize: 13 }}>
                  {(a.name || '?').charAt(0).toUpperCase()}
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{a.name}</div>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{[a.role, a.organisation].filter(Boolean).join(' · ') || '—'}</div>
                </div>
                <div style={{ display: 'inline-flex', borderRadius: 9, overflow: 'hidden', border: '1px solid var(--border)' }}>
                  <SegBtn active={a.attended} color="#10b981" icon={CheckCircle2} onClick={() => setAttended(a.id, true)}>Present</SegBtn>
                  <SegBtn active={!a.attended} color="#ef4444" icon={XCircle} onClick={() => setAttended(a.id, false)}>Absent</SegBtn>
                </div>
              </div>
            ))}
          </div>
        )}
        {err && <ModalError>{err}</ModalError>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel="Save attendance" color="#7C3AED"
        disabled={rows === null || rows.length === 0} />
    </Overlay>
  )
}

const SegBtn = ({ active, color, icon: Icon, onClick, children }) => (
  <button onClick={onClick}
    style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '7px 12px', fontSize: 12, fontWeight: 700, cursor: 'pointer', border: 'none',
      background: active ? `${color}22` : 'transparent', color: active ? color : 'var(--text-muted)' }}>
    <Icon size={13} /> {children}
  </button>
)

/* ── Reminder modal ─────────────────────────────────────────────────────────── */
function ReminderModal({ m, onClose }) {
  const [sending, setSending] = useState(false)
  const [result, setResult] = useState(null)
  const [err, setErr] = useState(null)

  const send = async () => {
    setSending(true); setErr(null)
    try {
      const res = await purchaseApi.kickoff.remind(m.id)
      setResult(res?.result ?? res)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not send the reminder.')
    } finally { setSending(false) }
  }

  return (
    <Overlay onClose={onClose} width={480}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Send reminder</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{m.title}</p>
      </div>
      <div style={{ padding: '12px 22px' }}>
        {result ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
            <ChannelRow icon={Mail} label="Email" state="Sent"
              detail={`${result.email?.sent ?? 0} sent${result.email?.skipped ? `, ${result.email.skipped} skipped` : ''}${result.email?.failed ? `, ${result.email.failed} failed` : ''} · ${result.recipients ?? 0} participant(s)`}
              tone="#10b981" />
            <ChannelRow icon={MessageCircle} label="WhatsApp" state={stubLabel(result.whatsapp)} detail={stubDetail(result.whatsapp)} tone="#f59e0b" />
            <ChannelRow icon={Smartphone} label="SMS" state={stubLabel(result.sms)} detail={stubDetail(result.sms)} tone="#f59e0b" />
            <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '4px 0 0', lineHeight: 1.5 }}>
              WhatsApp and SMS are queued through a stub provider — delivery is not confirmed.
            </p>
          </div>
        ) : (
          <>
            <p style={{ fontSize: 13, color: 'var(--text-h)', margin: 0, lineHeight: 1.55 }}>
              Send a reminder to the meeting participants. Email is delivered live; WhatsApp and SMS are queued via a stub provider (not delivered).
            </p>
            {err && <ModalError>{err}</ModalError>}
          </>
        )}
      </div>
      {result ? (
        <div style={{ display: 'flex', justifyContent: 'flex-end', padding: '10px 22px 18px' }}>
          <button onClick={onClose} style={solidBtn}>Done</button>
        </div>
      ) : (
        <ModalFooter onClose={onClose} onConfirm={send} loading={sending} confirmLabel="Send reminder" color="#f59e0b" />
      )}
    </Overlay>
  )
}

const stubLabel  = (s) => (s === 'queued' ? 'Queued' : s === 'skipped' ? 'Skipped' : (s || 'Skipped'))
const stubDetail = (s) => (s === 'queued' ? 'Queued via stub provider (not delivered)' : 'No vendor phone number on file')

function ChannelRow({ icon: Icon, label, state, detail, tone }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 13px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <Icon size={17} style={{ color: tone, flexShrink: 0 }} />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{label}</div>
        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{detail}</div>
      </div>
      <span style={{ padding: '3px 11px', borderRadius: 999, fontSize: 11, fontWeight: 800, background: `${tone}1f`, color: tone }}>{state}</span>
    </div>
  )
}

/* ── bits ───────────────────────────────────────────────────────────────────── */
const ModalError = ({ children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 8 }}>
    <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0 }} />
    <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{children}</span>
  </div>
)

// ISO timestamp → the value a <input type="datetime-local"> expects, in local time.
function toLocalInput(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const p = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

const th = { textAlign: 'left', padding: '11px 14px', fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', whiteSpace: 'nowrap', background: 'var(--bg-card)' }
const td = { padding: '11px 14px', color: 'var(--text-h)', whiteSpace: 'nowrap', verticalAlign: 'middle' }

const solidBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 700, color: '#fff', border: 'none',
  background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)',
}
const ghostBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)',
}
