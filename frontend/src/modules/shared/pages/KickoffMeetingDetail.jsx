import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, CalendarDays, Clock, MapPin, Users, CheckCircle2, XCircle,
  Send, Copy, Upload, AlertTriangle, Loader2, FileText, ShieldCheck, History,
} from 'lucide-react'
import { kickoffApi } from '@/services/kickoffApi'
import {
  KO_STATUS, koStatusCfg, koNextStatuses, koModeLabel, fmtDateTime, fmtDate,
} from '../kickoffConstants'
import { KIT3D_STYLE, Overlay, ModalFooter, Field, TextInput } from '@/components/ui/kit3d'

/**
 * Kickoff meeting detail — schedule, attendee registry, status transitions,
 * minutes upload, and the vendor-acknowledgement link.
 */
export default function KickoffMeetingDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [m, setM]         = useState(null)
  const [loading, setLoad] = useState(true)
  const [err, setErr]     = useState(null)
  const [ackLink, setAckLink] = useState(null)
  const [action, setAction]   = useState(null)   // { to } transition modal

  const load = () => kickoffApi.get(id).then(d => { setM(d?.data ?? d); setLoad(false) }).catch(() => { setErr('Could not load this meeting.'); setLoad(false) })
  useEffect(() => { load() }, [id])

  const publish = async () => {
    try {
      const res = await kickoffApi.publish(id)
      // Backend returns the token once; compose the link from origin, as the
      // worker badge QR does — the API host is not the browser origin.
      setAckLink(`${window.location.origin}/kickoff/ack/${res.ack_token}`)
      setM(res.meeting)
    } catch (e) { setErr(e?.response?.data?.message || 'Could not publish.') }
  }

  if (loading) return <div style={{ padding: 24 }}><style>{KIT3D_STYLE}</style><div className="skeleton" style={{ height: 44, width: 280, borderRadius: 12, background: 'var(--border)' }} /></div>
  if (err && !m) return <div style={{ padding: 24, color: 'var(--text-muted)' }}><style>{KIT3D_STYLE}</style>{err}</div>

  const cfg = koStatusCfg(m.status)
  const nexts = koNextStatuses(m.status)

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18 }}>
        <button onClick={() => navigate('/app/tpv/kickoff')} style={{ width: 34, height: 34, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 3, flexShrink: 0 }}>
          <ArrowLeft size={16} />
        </button>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <h1 style={{ color: 'var(--text-h)', fontSize: 23, fontWeight: 900, margin: 0, letterSpacing: '-0.02em' }}>{m.title}</h1>
            <span style={{ padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 12, fontWeight: 800 }}>{cfg.label}</span>
            {m.is_acknowledged && (
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11.5, fontWeight: 700, color: '#10b981' }}>
                <ShieldCheck size={13} /> Acknowledged by {m.acknowledged_by_name}
              </span>
            )}
          </div>
          {m.subject?.name && <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '5px 0 0' }}>{m.subject.label}: <strong style={{ color: 'var(--text-h)' }}>{m.subject.name}</strong></p>}
        </div>
        {/* Transition actions the server will actually accept */}
        <div style={{ display: 'flex', gap: 8, flexShrink: 0, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          {nexts.map(to => {
            const tc = koStatusCfg(to)
            return (
              <button key={to} onClick={() => setAction({ to })}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '8px 13px', borderRadius: 10, cursor: 'pointer', fontSize: 12.5, fontWeight: 700,
                  background: to === KO_STATUS.COMPLETED ? 'linear-gradient(145deg,#34d399,#10b981)' : 'var(--bg-card)',
                  border: to === KO_STATUS.COMPLETED ? 'none' : `1px solid ${tc.color}55`,
                  color: to === KO_STATUS.COMPLETED ? '#fff' : tc.color,
                  boxShadow: to === KO_STATUS.COMPLETED ? '0 8px 20px -6px #10b98188' : 'none' }}>
                {to === KO_STATUS.COMPLETED && <CheckCircle2 size={14} />}
                {actionLabel(m.status, to)}
              </button>
            )
          })}
        </div>
      </div>

      {err && <Banner tone="#ef4444" icon={AlertTriangle}>{err}</Banner>}

      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }}>
        {/* Left column */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Schedule */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={CalendarDays}>Schedule</SectionTitle>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginTop: 12 }}>
              <Detail icon={Clock} label="Date & time" value={fmtDateTime(m.scheduled_at)} />
              <Detail icon={Clock} label="Duration" value={m.duration_minutes ? `${m.duration_minutes} min` : '—'} />
              <Detail icon={MapPin} label={m.mode === 'online' ? 'Meeting link' : 'Location'} value={m.location || '—'} />
              <Detail icon={CalendarDays} label="Mode" value={koModeLabel(m.mode)} />
            </div>
            {m.status === KO_STATUS.DELAYED && m.delay_reason && (
              <div style={{ marginTop: 14, padding: '11px 13px', borderRadius: 11, background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.32)' }}>
                <div style={{ fontSize: 11, fontWeight: 800, color: '#f59e0b', marginBottom: 3 }}>DELAYED · originally {fmtDate(m.original_scheduled_at)}</div>
                <div style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{m.delay_reason}</div>
              </div>
            )}
            {m.agenda && (
              <div style={{ marginTop: 14 }}>
                <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 5 }}>Agenda</div>
                <p style={{ fontSize: 13, color: 'var(--text-h)', margin: 0, lineHeight: 1.55, whiteSpace: 'pre-wrap' }}>{m.agenda}</p>
              </div>
            )}
          </div>

          {/* Attendees */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={Users}>Attendees <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {(m.attendees || []).length}</span></SectionTitle>
            {(m.attendees || []).length === 0 ? (
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No attendees recorded.</p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 12 }}>
                {m.attendees.map(a => (
                  <div key={a.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div style={{ width: 34, height: 34, borderRadius: '50%', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', color: '#a78bfa', fontWeight: 800, fontSize: 13 }}>
                      {(a.name || '?').charAt(0).toUpperCase()}
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{a.name}
                        {a.vendor_contact_id && <span title="Linked to vendor master" style={{ marginLeft: 6, fontSize: 10, fontWeight: 700, color: '#10b981' }}>● linked</span>}
                      </div>
                      <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{[a.role, a.organisation].filter(Boolean).join(' · ') || '—'}</div>
                    </div>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, color: a.attended ? '#10b981' : 'var(--text-muted)' }}>
                      {a.attended ? <><CheckCircle2 size={13} /> Attended</> : <><XCircle size={13} /> Absent</>}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Minutes */}
          {m.minutes && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <SectionTitle icon={FileText}>Minutes</SectionTitle>
              <p style={{ fontSize: 13, color: 'var(--text-h)', margin: '12px 0 0', lineHeight: 1.55, whiteSpace: 'pre-wrap' }}>{m.minutes}</p>
            </div>
          )}
        </div>

        {/* Right column */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Acknowledgement */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={ShieldCheck}>Vendor acknowledgement</SectionTitle>
            {m.is_acknowledged ? (
              <div style={{ marginTop: 12, padding: '14px', borderRadius: 12, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.32)', textAlign: 'center' }}>
                <CheckCircle2 size={26} style={{ color: '#10b981' }} />
                <div style={{ fontSize: 13.5, fontWeight: 800, color: '#10b981', marginTop: 6 }}>Acknowledged</div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>by {m.acknowledged_by_name} · {fmtDateTime(m.acknowledged_at)}</div>
              </div>
            ) : m.status !== KO_STATUS.COMPLETED ? (
              <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '12px 0 0', lineHeight: 1.5 }}>
                Complete the meeting first — the vendor acknowledges the minutes once they're finalised.
              </p>
            ) : ackLink ? (
              <div style={{ marginTop: 12 }}>
                <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 8px', lineHeight: 1.5 }}>
                  Send this single-use link to the vendor's signatory. It works without a login.
                </p>
                <div style={{ display: 'flex', gap: 7 }}>
                  <input readOnly value={ackLink} style={{ flex: 1, padding: '9px 11px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 11.5 }} />
                  <button onClick={() => navigator.clipboard?.writeText(ackLink)} style={{ padding: '0 12px', borderRadius: 9, cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}><Copy size={14} /></button>
                </div>
              </div>
            ) : (
              <div style={{ marginTop: 12 }}>
                <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 12px', lineHeight: 1.5 }}>
                  Generate a public link the vendor can use to acknowledge the minutes.
                </p>
                <button onClick={publish} style={{ ...solidBtn, width: '100%', justifyContent: 'center' }}><Send size={15} /> Send for acknowledgement</button>
              </div>
            )}
          </div>

          {/* Minutes document */}
          <MomCard m={m} onUploaded={setM} onError={setErr} />

          {/* Audit trail */}
          {(m.audit_logs || []).length > 0 && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <SectionTitle icon={History}>Activity</SectionTitle>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12, marginTop: 12 }}>
                {m.audit_logs.map(a => (
                  <div key={a.id} style={{ display: 'flex', gap: 10 }}>
                    <div style={{ width: 8, height: 8, borderRadius: '50%', background: '#a78bfa', marginTop: 5, flexShrink: 0 }} />
                    <div style={{ minWidth: 0 }}>
                      <div style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.4 }}>{a.comment}</div>
                      <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 1 }}>{fmtDateTime(a.created_at)}</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {action && <TransitionModal m={m} to={action.to} onClose={() => setAction(null)} onDone={(updated) => { setAction(null); setM(updated); setErr(null) }} />}
    </div>
  )
}

/* ── MoM upload card ──────────────────────────────────────────────────────── */
function MomCard({ m, onUploaded, onError }) {
  const [busy, setBusy] = useState(false)

  const pick = async (e) => {
    const f = e.target.files?.[0]
    if (!f) return
    setBusy(true); onError(null)
    try {
      const updated = await kickoffApi.uploadMom(m.id, f)
      onUploaded(updated)
    } catch (err) {
      onError(err?.response?.data?.message || 'Could not upload the document.')
    } finally { setBusy(false); e.target.value = '' }
  }

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <SectionTitle icon={FileText}>Minutes document</SectionTitle>
      {m.mom_path ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12, padding: '11px 13px', borderRadius: 11, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.3)' }}>
          <FileText size={16} style={{ color: '#10b981', flexShrink: 0 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-h)', flex: 1 }}>Document uploaded</span>
          <label style={{ fontSize: 11.5, fontWeight: 700, color: '#a78bfa', cursor: 'pointer' }}>
            Replace<input type="file" accept=".pdf,.doc,.docx" onChange={pick} style={{ display: 'none' }} />
          </label>
        </div>
      ) : (
        <label style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 7, marginTop: 12, padding: '20px', borderRadius: 12, cursor: 'pointer',
          background: 'linear-gradient(150deg, rgba(124,58,237,.1), rgba(124,58,237,.03))', border: '1.5px dashed rgba(124,58,237,.4)' }}>
          {busy ? <Loader2 size={22} style={{ color: '#a78bfa' }} className="ko-spin" /> : <Upload size={22} style={{ color: '#a78bfa' }} />}
          <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{busy ? 'Uploading…' : 'Upload minutes (PDF/DOC)'}</span>
          <input type="file" accept=".pdf,.doc,.docx" onChange={pick} disabled={busy} style={{ display: 'none' }} />
        </label>
      )}
      <style>{`@keyframes koSpin{to{transform:rotate(360deg)}}.ko-spin{animation:koSpin .9s linear infinite}`}</style>
    </div>
  )
}

/* ── Transition modal ─────────────────────────────────────────────────────── */
function TransitionModal({ m, to, onClose, onDone }) {
  const [form, setForm] = useState({ delay_reason: '', scheduled_at: '', minutes: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const tc = koStatusCfg(to)

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = { status: to }
      if (to === KO_STATUS.DELAYED) { payload.delay_reason = form.delay_reason; if (form.scheduled_at) payload.scheduled_at = form.scheduled_at }
      if (to === KO_STATUS.SCHEDULED && form.scheduled_at) payload.scheduled_at = form.scheduled_at
      if (to === KO_STATUS.COMPLETED && form.minutes) payload.minutes = form.minutes
      const updated = await kickoffApi.transition(m.id, payload)
      onDone(updated?.data ?? updated)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not update the meeting.')
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={460}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>{actionLabel(m.status, to)}</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Move this meeting to <span style={{ color: tc.color, fontWeight: 700 }}>{tc.label}</span>.</p>
      </div>
      <div style={{ padding: '10px 22px' }}>
        {to === KO_STATUS.DELAYED && (
          <>
            <Field label="Why is it delayed? (required)" full>
              <TextInput value={form.delay_reason} onChange={e => setForm(f => ({ ...f, delay_reason: e.target.value }))} placeholder="Vendor representative travelling" />
            </Field>
            <Field label="New date & time (optional)" full>
              <TextInput type="datetime-local" value={form.scheduled_at} onChange={e => setForm(f => ({ ...f, scheduled_at: e.target.value }))} />
            </Field>
          </>
        )}
        {to === KO_STATUS.SCHEDULED && (
          <Field label="New date & time" full>
            <TextInput type="datetime-local" value={form.scheduled_at} onChange={e => setForm(f => ({ ...f, scheduled_at: e.target.value }))} />
          </Field>
        )}
        {to === KO_STATUS.COMPLETED && (
          <Field label="Minutes (optional — a document can be attached after)" full>
            <textarea rows={4} value={form.minutes} onChange={e => setForm(f => ({ ...f, minutes: e.target.value }))}
              placeholder="Key decisions, actions, and owners…"
              style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
          </Field>
        )}
        {to === KO_STATUS.CANCELLED && (
          <p style={{ fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.5 }}>This meeting will be marked cancelled. You can reopen it later if it goes ahead.</p>
        )}
        {err && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 8 }}>
            <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0 }} />
            <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span>
          </div>
        )}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel={actionLabel(m.status, to)} color={tc.color === '#94a3b8' ? '#7C3AED' : tc.color} />
    </Overlay>
  )
}

/* ── bits ─────────────────────────────────────────────────────────────────── */
function actionLabel(from, to) {
  if (to === KO_STATUS.COMPLETED) return 'Mark completed'
  if (to === KO_STATUS.DELAYED) return 'Mark delayed'
  if (to === KO_STATUS.CANCELLED) return 'Cancel meeting'
  if (to === KO_STATUS.SCHEDULED) return from === KO_STATUS.CANCELLED ? 'Reopen' : 'Reschedule'
  return to
}

const SectionTitle = ({ icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
    <Icon size={15} style={{ color: '#a78bfa' }} />
    <h2 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{children}</h2>
  </div>
)

const Detail = ({ icon: Icon, label, value }) => (
  <div>
    <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 3 }}>
      <Icon size={11} /> {label}
    </div>
    <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-h)' }}>{value}</div>
  </div>
)

const Banner = ({ tone, icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 16, background: `${tone}12`, border: `1px solid ${tone}55` }}>
    <Icon size={15} style={{ color: tone, flexShrink: 0 }} />
    <span style={{ fontSize: 13, color: 'var(--text-h)' }}>{children}</span>
  </div>
)

const solidBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 16px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 700, color: '#fff', border: 'none',
  background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)',
}
