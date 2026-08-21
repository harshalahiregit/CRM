import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, CalendarDays, Clock, MapPin, Users, CheckCircle2, XCircle,
  Send, Copy, Upload, AlertTriangle, Loader2, FileText, ShieldCheck, History,
  Sparkles, Eye, Download, Video, ExternalLink, ClipboardCheck, ThumbsUp, Undo2, RotateCcw, ListChecks,
} from 'lucide-react'
import { kickoffApi } from '@/services/kickoffApi'
import { meetingApi } from '@/services/meetingApi'
import {
  KO_STATUS, koStatusCfg, koNextStatuses, koModeLabel, fmtDateTime, fmtDate,
  actStatusCfg, actNextStatuses, issueStatusCfg, issueNextStatuses, ISSUE_TO_INCIDENT_SEVERITY,
  MOM_STATUS_CONFIG, momStatusCfg, MOM_STAGES,
} from '../kickoffConstants'
import { KIT3D_STYLE, Overlay, ModalFooter, Field, TextInput, SelectInput } from '@/components/ui/kit3d'

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
  const [publishBusy, setPublishBusy] = useState(false)   // "Send for acknowledgement" in flight
  const [action, setAction]   = useState(null)   // { to } transition modal
  const [linkData, setLinkData]     = useState(null)    // online meeting link data
  const [genLinkBusy, setGenLinkBusy] = useState(false) // link generation in progress

  const load = () => kickoffApi.get(id).then(d => {
    const mtg = d?.data ?? d
    setM(mtg)
    setLoad(false)
    // Fetch stored online meeting link if applicable
    if (mtg?.mode === 'online' && mtg?.meeting_platform) {
      meetingApi.getLink(id).then(setLinkData).catch(() => {})
    }
  }).catch(() => { setErr('Could not load this meeting.'); setLoad(false) })
  useEffect(() => { load() }, [id])

  const publish = async () => {
    setPublishBusy(true); setErr(null)
    try {
      const res = await kickoffApi.publish(id)
      // Backend returns the token once; compose the link from origin, as the
      // worker badge QR does — the API host is not the browser origin.
      setAckLink(`${window.location.origin}/kickoff/ack/${res.ack_token}`)
      setM(res.meeting)
    } catch (e) { setErr(e?.response?.data?.message || 'Could not publish.') }
    finally { setPublishBusy(false) }
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
            {m.meeting_no && <span style={{ padding: '3px 9px', borderRadius: 7, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', fontSize: 11.5, fontWeight: 800, letterSpacing: '0.02em' }}>{m.meeting_no}</span>}
            <span style={{ padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 12, fontWeight: 800 }}>{cfg.label}</span>
            {m.priority && <span style={{ padding: '3px 9px', borderRadius: 7, fontSize: 11, fontWeight: 800, background: m.priority === 'Urgent' || m.priority === 'High' ? 'rgba(239,68,68,0.14)' : 'rgba(148,163,184,0.15)', color: m.priority === 'Urgent' || m.priority === 'High' ? '#ef4444' : 'var(--text-muted)' }}>{m.priority}</span>}
            {m.confidentiality && m.confidentiality !== 'Public' && <span style={{ padding: '3px 9px', borderRadius: 7, fontSize: 11, fontWeight: 800, background: 'rgba(245,158,11,0.14)', color: '#d97706' }}>{m.confidentiality}</span>}
            {m.is_acknowledged && (
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11.5, fontWeight: 700, color: '#10b981' }}>
                <ShieldCheck size={13} /> Acknowledged by {m.acknowledged_by_name}
              </span>
            )}
          </div>
          {/* All vendors on the meeting, primary first. Falls back to the single
              subject for records saved before multi-vendor existed. */}
          {(m.subject_list?.length ? m.subject_list : (m.subject ? [m.subject] : [])).length > 0 && (
            <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '5px 0 0' }}>
              {(m.subject_list?.length > 1 ? 'Vendors' : (m.subject?.label || 'Vendor'))}:{' '}
              {(m.subject_list?.length ? m.subject_list : [m.subject]).map((s, i) => (
                <span key={s.subject_id ?? s.id ?? i}>
                  {i > 0 && ', '}
                  <strong style={{ color: 'var(--text-h)' }}>{s.name}</strong>
                  {s.is_primary && m.subject_list?.length > 1 && (
                    <span style={{ fontSize: 9, fontWeight: 800, color: '#a78bfa', marginLeft: 4 }}>PRIMARY</span>
                  )}
                  {s.acknowledged && (
                    <span style={{ fontSize: 9, fontWeight: 800, color: '#10b981', marginLeft: 4 }}>ACK</span>
                  )}
                </span>
              ))}
            </p>
          )}
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
              <Detail icon={CalendarDays} label="Type" value={m.meeting_type_label || 'Kickoff Meeting'} />
              <Detail icon={Clock} label="Date & time" value={fmtDateTime(m.scheduled_at)} />
              <Detail icon={Clock} label="Duration" value={m.duration_minutes ? `${m.duration_minutes} min` : '—'} />
              {m.end_at && <Detail icon={Clock} label="End time" value={fmtDateTime(m.end_at)} />}
              <Detail icon={MapPin} label={m.mode === 'online' ? 'Meeting link' : 'Location'} value={m.location || '—'} />
              <Detail icon={CalendarDays} label="Mode" value={koModeLabel(m.mode)} />
              {m.chairperson && <Detail icon={Users} label="Chairperson" value={m.chairperson} />}
              {m.coordinator && <Detail icon={Users} label="Coordinator" value={m.coordinator} />}
              {m.department && <Detail icon={CalendarDays} label="Department" value={m.department} />}
              {m.client_name && <Detail icon={Users} label="Client" value={m.client_name} />}
              {m.work_package && <Detail icon={CalendarDays} label="Work package" value={m.work_package} />}
              {(m.project_label || m.project_id) && <Detail icon={CalendarDays} label="Project" value={m.project_label || `#${m.project_id}`} />}
            </div>
            {m.status === KO_STATUS.DELAYED && m.delay_reason && (
              <div style={{ marginTop: 14, padding: '11px 13px', borderRadius: 11, background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.32)' }}>
                <div style={{ fontSize: 11, fontWeight: 800, color: '#f59e0b', marginBottom: 3 }}>DELAYED · originally {fmtDate(m.original_scheduled_at)}</div>
                <div style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{m.delay_reason}</div>
              </div>
            )}
            {Array.isArray(m.agenda_items) && m.agenda_items.length > 0 && (
              <div style={{ marginTop: 14 }}>
                <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Agenda</div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {m.agenda_items.map((a, i) => (
                    <div key={a.id} style={{ display: 'flex', alignItems: 'baseline', gap: 8, fontSize: 13, color: 'var(--text-h)' }}>
                      <span style={{ fontWeight: 800, color: '#a78bfa', minWidth: 16 }}>{i + 1}.</span>
                      <span style={{ flex: 1 }}>{a.item}</span>
                      {(a.owner_names || a.owner?.name) && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{a.owner_names || a.owner?.name}</span>}
                      {a.duration_minutes ? <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{a.duration_minutes}m</span> : null}
                      {a.priority && <span style={{ fontSize: 10.5, fontWeight: 800, padding: '1px 7px', borderRadius: 6, background: a.priority === 'High' ? 'rgba(239,68,68,0.12)' : a.priority === 'Medium' ? 'rgba(245,158,11,0.12)' : 'rgba(148,163,184,0.15)', color: a.priority === 'High' ? '#ef4444' : a.priority === 'Medium' ? '#d97706' : 'var(--text-muted)' }}>{a.priority}</span>}
                    </div>
                  ))}
                </div>
              </div>
            )}
            {m.agenda && (
              <div style={{ marginTop: 14 }}>
                <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 5 }}>Agenda notes</div>
                <p style={{ fontSize: 13, color: 'var(--text-h)', margin: 0, lineHeight: 1.55, whiteSpace: 'pre-wrap' }}>{m.agenda}</p>
              </div>
            )}
          </div>

          {/* Online meeting link (shown only when mode = 'online') */}
          {m.mode === 'online' && (
            <OnlineMeetingCard
              meeting={m}
              linkData={linkData}
              busy={genLinkBusy}
              onGenerate={async (platform) => {
                setGenLinkBusy(true); setErr(null)
                try {
                  const res = await meetingApi.generateLink(m.id, platform)
                  setLinkData(res.link)
                  setM(res.meeting)
                } catch (e) {
                  setErr(e?.response?.data?.message || 'Could not generate meeting link.')
                } finally { setGenLinkBusy(false) }
              }}
            />
          )}

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

          {/* This subject's whole meeting history + rollup totals */}
          <VendorHistoryCard m={m} onOpen={(mid) => navigate(`/app/tpv/kickoff/${mid}`)} />
        </div>

        {/* Right column */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* MOM approval & distribution workflow */}
          <MomApprovalCard m={m} onChanged={(updated) => { setM(updated); setErr(null) }} onError={setErr} />

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
            ) : !['Approved', 'Distributed'].includes(m.mom_status) ? (
              <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '12px 0 0', lineHeight: 1.5 }}>
                The minutes must be <strong style={{ color: 'var(--text-h)' }}>approved</strong> before they can be sent to the vendor — use the <strong style={{ color: 'var(--text-h)' }}>Minutes approval</strong> card above.
              </p>
            ) : (
              <div style={{ marginTop: 12 }}>
                <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 12px', lineHeight: 1.5 }}>
                  Send the approved minutes to the vendor for acknowledgement — a single-use public link, no login needed.
                </p>
                <button onClick={publish} disabled={publishBusy}
                  style={{ ...solidBtn, width: '100%', justifyContent: 'center', cursor: publishBusy ? 'wait' : 'pointer', opacity: publishBusy ? 0.75 : 1 }}>
                  {publishBusy ? <Loader2 size={15} className="ko-spin" /> : <Send size={15} />}
                  {publishBusy ? 'Sending…' : 'Send for acknowledgement'}
                </button>
              </div>
            )}
          </div>

          {/* Action items — the Action Engine (Meeting.docx §8) */}
          <ActionItemsCard m={m} meetingId={id} onChanged={load} onError={setErr} />

          {/* Decision register (§9) */}
          <DecisionsCard m={m} />

          {/* Issues raised (§10) — track + escalate */}
          <IssuesCard m={m} meetingId={id} onChanged={load} onError={setErr} />

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

/* ── Action items — the Action Engine ─────────────────────────────────────────
 * Each MOM item is a trackable action with a lifecycle. This card lists them and
 * lets an owner progress one (status + verification note + evidence file) without
 * re-editing the whole meeting — the state survives meeting edits (upsert). */
function ActionItemsCard({ m, meetingId, onChanged, onError }) {
  const items = m.mom_items || []
  const [openId, setOpenId] = useState(null)
  const [taskBusy, setTaskBusy] = useState(null)

  if (items.length === 0) return null

  // §8 — push an action into the Task module as a real, trackable Task.
  const createTask = async (item) => {
    setTaskBusy(item.id); onError(null)
    try { onChanged(await kickoffApi.pushActionTask(meetingId, item.id)) }
    catch (e) { onError(e?.response?.data?.message || 'Could not create the task.') }
    finally { setTaskBusy(null) }
  }

  const openCount = items.filter(i => i.is_open).length
  const overdue   = items.filter(i => i.is_overdue).length

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
        <SectionTitle icon={CheckCircle2}>Action Items</SectionTitle>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
          {openCount} open{overdue > 0 && <span style={{ color: '#ef4444', fontWeight: 700 }}> · {overdue} overdue</span>}
        </span>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 10 }}>
        {items.map(item => {
          const c = actStatusCfg(item.status)
          const owner = item.responsible_names || item.responsible?.name || item.responsible_org
          return (
            <div key={item.id} style={{ padding: '12px 14px', borderRadius: 12, background: 'var(--bg-input)', border: `1px solid ${item.is_overdue ? 'rgba(239,68,68,0.4)' : 'var(--border)'}` }}>
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10 }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 3 }}>
                    {item.action_ref && <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)' }}>{item.action_ref}</span>}
                    <span style={{ fontSize: 10, fontWeight: 800, padding: '1px 7px', borderRadius: 6, background: c.bg, color: c.color }}>{c.label}</span>
                    {item.priority && <span style={{ fontSize: 10, fontWeight: 800, color: item.priority === 'High' ? '#ef4444' : item.priority === 'Medium' ? '#d97706' : 'var(--text-muted)' }}>{item.priority}</span>}
                    {item.target_date && <span style={{ fontSize: 10.5, color: item.is_overdue ? '#ef4444' : 'var(--text-muted)' }}>due {fmtDate(item.target_date)}</span>}
                    {item.agenda_item?.item && <span title="Agenda item" style={{ fontSize: 10, fontWeight: 700, padding: '1px 7px', borderRadius: 6, background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>▸ {item.agenda_item.item}</span>}
                    {item.depends_on && <span title="Blocked until this action is done" style={{ fontSize: 10, fontWeight: 700, padding: '1px 7px', borderRadius: 6, background: 'rgba(245,158,11,0.12)', color: '#d97706' }}>⛓ depends on {item.depends_on.action_ref || 'another action'}</span>}
                    {item.task && (
                      <a href={`/app/tasks/${item.task.id}`} title={`Linked task · ${item.task.status}`}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10, fontWeight: 700, padding: '1px 7px', borderRadius: 6, background: 'rgba(16,185,129,0.12)', color: '#10b981', textDecoration: 'none' }}>
                        <ListChecks size={10} /> Task: {String(item.task.status).replace(/_/g, ' ')}
                      </a>
                    )}
                  </div>
                  <div style={{ fontSize: 13, color: 'var(--text-h)', lineHeight: 1.5 }} dangerouslySetInnerHTML={{ __html: item.description }} />
                  {owner && <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>Owner: {owner}</div>}
                  {item.verification_note && <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3, fontStyle: 'italic' }}>“{item.verification_note}”</div>}
                  {item.verified_at && <div style={{ fontSize: 11, color: '#10b981', marginTop: 3 }}>Verified by {item.verifier?.name || '—'} · {fmtDate(item.verified_at)}</div>}
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0 }}>
                  <button onClick={() => setOpenId(openId === item.id ? null : item.id)}
                    style={{ padding: '6px 11px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: '#a78bfa' }}>
                    {openId === item.id ? 'Close' : 'Update'}
                  </button>
                  {!item.task_id && (
                    <button onClick={() => createTask(item)} disabled={taskBusy === item.id} title="Create a trackable Task from this action"
                      style={{ padding: '6px 11px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: taskBusy === item.id ? 'wait' : 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: '#10b981', display: 'inline-flex', alignItems: 'center', gap: 5, justifyContent: 'center' }}>
                      <ListChecks size={12} /> {taskBusy === item.id ? '…' : 'Task'}
                    </button>
                  )}
                </div>
              </div>
              {openId === item.id && (
                <ActionProgressForm item={item} meetingId={meetingId}
                  onDone={() => { setOpenId(null); onChanged() }} onError={onError} />
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

/* Inline form to progress one action: status move + verification note + evidence. */
function ActionProgressForm({ item, meetingId, onDone, onError }) {
  const [status, setStatus] = useState('')
  const [remark, setRemark] = useState('')
  const [file, setFile]     = useState(null)
  const [busy, setBusy]     = useState(false)
  const nexts = actNextStatuses(item.status)

  const save = async () => {
    setBusy(true)
    try {
      await kickoffApi.progressAction(meetingId, item.id, {
        status: status || undefined,
        note: remark || undefined,
        evidence: file || undefined,
      })
      onDone()
    } catch (e) { onError(e?.response?.data?.message || 'Could not update the action.'); setBusy(false) }
  }

  const viewEvidence = async () => {
    try {
      const blob = await kickoffApi.actionEvidenceBlob(meetingId, item.id)
      window.open(URL.createObjectURL(blob), '_blank')
    } catch { onError('Could not open the evidence file.') }
  }

  return (
    <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--border)', display: 'grid', gap: 10 }}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
        <Field label="Move to">
          <SelectInput value={status} onChange={e => setStatus(e.target.value)} pairs
            options={[['', 'Keep current'], ...nexts.map(s => [s, actStatusCfg(s).label])]} />
        </Field>
        <Field label="Evidence (optional)">
          <input type="file" onChange={e => setFile(e.target.files?.[0] || null)}
            style={{ fontSize: 12, color: 'var(--text-muted)' }} accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" />
        </Field>
      </div>
      <Field label="Verification note / remark">
        <textarea value={remark} onChange={e => setRemark(e.target.value)} rows={2}
          placeholder="What was done / verified…"
          style={{ width: '100%', padding: '8px 11px', borderRadius: 8, fontSize: 13, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', resize: 'vertical', fontFamily: 'inherit' }} />
      </Field>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <button onClick={save} disabled={busy || (!status && !remark && !file)}
          style={{ padding: '8px 16px', borderRadius: 8, border: 'none', fontWeight: 700, fontSize: 12.5, cursor: busy ? 'wait' : 'pointer', background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', opacity: (!status && !remark && !file) ? 0.5 : 1 }}>
          {busy ? 'Saving…' : 'Save update'}
        </button>
        {item.evidence_path && (
          <button onClick={viewEvidence} style={{ padding: '8px 12px', borderRadius: 8, fontSize: 12, fontWeight: 700, cursor: 'pointer', background: 'transparent', border: '1px solid var(--border)', color: '#a78bfa', display: 'inline-flex', alignItems: 'center', gap: 5 }}>
            <Eye size={13} /> Evidence
          </button>
        )}
      </div>
    </div>
  )
}

/* ── Decision register (§9) ───────────────────────────────────────────────── */
function DecisionsCard({ m }) {
  const rows = m.decisions || []
  if (rows.length === 0) return null
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <SectionTitle icon={ShieldCheck}>Decisions</SectionTitle>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 10 }}>
        {rows.map(d => (
          <div key={d.id} style={{ padding: '11px 13px', borderRadius: 11, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 3, flexWrap: 'wrap' }}>
              {d.decision_ref && <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)' }}>{d.decision_ref}</span>}
              <span style={{ fontSize: 10, fontWeight: 800, color: d.status === 'Active' ? '#10b981' : '#94a3b8' }}>{d.status}</span>
              {d.effective_date && <span style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>effective {fmtDate(d.effective_date)}</span>}
              {d.agenda_item?.item && <span title="Agenda item" style={{ fontSize: 10, fontWeight: 700, padding: '1px 7px', borderRadius: 6, background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>▸ {d.agenda_item.item}</span>}
            </div>
            <div style={{ fontSize: 13, color: 'var(--text-h)', lineHeight: 1.5 }}>{d.decision}</div>
            <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>
              {(d.decided_by_names || d.decided_by?.name) && <>By {d.decided_by_names || d.decided_by?.name}</>}
              {d.impact && <> · Impact: {d.impact}</>}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

/* ── Issues raised (§10) — track lifecycle + escalate to an Incident ────────── */
function IssuesCard({ m, meetingId, onChanged, onError }) {
  const rows = m.issues || []
  if (rows.length === 0) return null
  const open = rows.filter(i => i.is_open).length
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
        <SectionTitle icon={AlertTriangle}>Issues Raised</SectionTitle>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{open} open</span>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 6 }}>
        {rows.map(it => <IssueRow key={it.id} it={it} meetingId={meetingId} onChanged={onChanged} onError={onError} />)}
      </div>
    </div>
  )
}

function IssueRow({ it, meetingId, onChanged, onError }) {
  const [open, setOpen]     = useState(false)
  const [status, setStatus] = useState('')
  const [busy, setBusy]     = useState(false)
  // Convert-to-incident controls.
  const [conv, setConv]     = useState(false)
  const [sev, setSev]       = useState(ISSUE_TO_INCIDENT_SEVERITY[it.severity] || 'Moderate')
  const c = issueStatusCfg(it.status)
  const owner = it.owner_names || it.owner?.name

  const progress = async () => {
    setBusy(true)
    try { await kickoffApi.progressIssue(meetingId, it.id, { status: status || undefined }); setOpen(false); onChanged() }
    catch (e) { onError(e?.response?.data?.message || 'Could not update the issue.'); setBusy(false) }
  }
  const convert = async () => {
    setBusy(true)
    try { await kickoffApi.convertIssue(meetingId, it.id, { severity: sev }); setConv(false); onChanged() }
    catch (e) { onError(e?.response?.data?.message || 'Could not escalate the issue.'); setBusy(false) }
  }

  return (
    <div style={{ padding: '12px 14px', borderRadius: 12, background: 'var(--bg-input)', border: `1px solid ${it.is_overdue ? 'rgba(239,68,68,0.4)' : 'var(--border)'}` }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 3 }}>
            {it.issue_ref && <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)' }}>{it.issue_ref}</span>}
            <span style={{ fontSize: 10, fontWeight: 800, padding: '1px 7px', borderRadius: 6, background: c.bg, color: c.color }}>{c.label}</span>
            {it.severity && <span style={{ fontSize: 10, fontWeight: 800, color: it.severity === 'Critical' || it.severity === 'High' ? '#ef4444' : 'var(--text-muted)' }}>{it.severity}</span>}
            {it.category && <span style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{it.category}</span>}
            {it.due_date && <span style={{ fontSize: 10.5, color: it.is_overdue ? '#ef4444' : 'var(--text-muted)' }}>due {fmtDate(it.due_date)}</span>}
            {it.converted_to && <span style={{ fontSize: 10, fontWeight: 800, color: '#ef4444' }}>→ {it.converted_to} {it.converted_ref}</span>}
          </div>
          <div style={{ fontSize: 13, color: 'var(--text-h)', fontWeight: 600 }}>{it.title}</div>
          {it.description && <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{it.description}</div>}
          {owner && <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>Owner: {owner}</div>}
        </div>
        <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
          <button onClick={() => { setOpen(!open); setConv(false) }} style={{ padding: '6px 11px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: '#a78bfa' }}>{open ? 'Close' : 'Update'}</button>
          {!it.converted_to && <button onClick={() => { setConv(!conv); setOpen(false) }} style={{ padding: '6px 11px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: 'pointer', background: 'rgba(239,68,68,0.06)', border: '1px solid rgba(239,68,68,0.3)', color: '#ef4444' }}>Escalate</button>}
        </div>
      </div>

      {open && (
        <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--border)', display: 'flex', gap: 10, alignItems: 'flex-end' }}>
          <Field label="Move to">
            <SelectInput value={status} onChange={e => setStatus(e.target.value)} pairs
              options={[['', 'Keep current'], ...issueNextStatuses(it.status).map(s => [s, issueStatusCfg(s).label])]} />
          </Field>
          <button onClick={progress} disabled={busy || !status} style={{ padding: '8px 16px', borderRadius: 8, border: 'none', fontWeight: 700, fontSize: 12.5, cursor: 'pointer', background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', opacity: !status ? 0.5 : 1 }}>{busy ? 'Saving…' : 'Save'}</button>
        </div>
      )}

      {conv && (
        <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--border)' }}>
          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 8px' }}>Raise this issue as an HSSE incident against the vendor. Serious/Fatal will suspend the vendor.</p>
          <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end' }}>
            <Field label="Incident severity">
              <SelectInput value={sev} onChange={e => setSev(e.target.value)} pairs options={[['Minor', 'Minor'], ['Moderate', 'Moderate'], ['Serious', 'Serious'], ['Fatal', 'Fatal']]} />
            </Field>
            <button onClick={convert} disabled={busy} style={{ padding: '8px 16px', borderRadius: 8, border: 'none', fontWeight: 700, fontSize: 12.5, cursor: 'pointer', background: 'linear-gradient(135deg,#ef4444,#dc2626)', color: '#fff' }}>{busy ? 'Raising…' : 'Raise incident'}</button>
          </div>
        </div>
      )}
    </div>
  )
}

/* ── MoM document card ────────────────────────────────────────────────────────
 * Two ways to attach minutes: generate a PDF from the meeting's own data, or
 * upload a document manually. Either fills the single MoM slot; regenerating or
 * re-uploading replaces the previous file. View/Download serve whatever is there. */
function MomCard({ m, onUploaded, onError }) {
  const [busy, setBusy] = useState(null) // 'upload' | 'gen' | 'view' | 'dl'

  const pick = async (e) => {
    const f = e.target.files?.[0]
    if (!f) return
    setBusy('upload'); onError(null)
    try {
      onUploaded(await kickoffApi.uploadMom(m.id, f))
    } catch (err) {
      onError(err?.response?.data?.message || 'Could not upload the document.')
    } finally { setBusy(null); e.target.value = '' }
  }

  const generate = async () => {
    setBusy('gen'); onError(null)
    try {
      onUploaded(await kickoffApi.generateMom(m.id))
    } catch (err) {
      onError(err?.response?.data?.message || 'Could not generate the MOM PDF.')
    } finally { setBusy(null) }
  }

  const openPdf = async (download) => {
    setBusy(download ? 'dl' : 'view'); onError(null)
    try {
      if (!m.mom_path) onUploaded(await kickoffApi.generateMom(m.id))
      const blob = await kickoffApi.momBlob(m.id)
      const url = URL.createObjectURL(blob)
      if (download) {
        const a = document.createElement('a')
        a.href = url; a.download = `MOM-${m.id}.pdf`
        document.body.appendChild(a); a.click(); a.remove()
      } else {
        window.open(url, '_blank', 'noopener')
      }
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch (err) {
      onError(err?.response?.data?.message || 'Could not open the MOM PDF.')
    } finally { setBusy(null) }
  }

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <SectionTitle icon={FileText}>Minutes document</SectionTitle>

      {m.mom_path ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12, padding: '11px 13px', borderRadius: 11, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.3)' }}>
          <FileText size={16} style={{ color: '#10b981', flexShrink: 0 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-h)', flex: 1 }}>MOM document ready</span>
          <label style={{ fontSize: 11.5, fontWeight: 700, color: '#a78bfa', cursor: 'pointer' }}>
            Replace<input type="file" accept=".pdf,.doc,.docx" onChange={pick} style={{ display: 'none' }} />
          </label>
        </div>
      ) : (
        <label style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 7, marginTop: 12, padding: '18px', borderRadius: 12, cursor: 'pointer',
          background: 'linear-gradient(150deg, rgba(124,58,237,.1), rgba(124,58,237,.03))', border: '1.5px dashed rgba(124,58,237,.4)' }}>
          {busy === 'upload' ? <Loader2 size={22} style={{ color: '#a78bfa' }} className="ko-spin" /> : <Upload size={22} style={{ color: '#a78bfa' }} />}
          <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{busy === 'upload' ? 'Uploading…' : 'Upload minutes (PDF/DOC)'}</span>
          <input type="file" accept=".pdf,.doc,.docx" onChange={pick} disabled={busy === 'upload'} style={{ display: 'none' }} />
        </label>
      )}

      {/* Generate / View / Download */}
      <div style={{ display: 'grid', gridTemplateColumns: m.mom_path ? '1fr 1fr' : '1fr', gap: 8, marginTop: 10 }}>
        <MomBtn onClick={generate} busy={busy === 'gen'} icon={Sparkles} tone="#7C3AED">{m.mom_path ? 'Regenerate PDF' : 'Generate PDF'}</MomBtn>
        {m.mom_path && <MomBtn onClick={() => openPdf(false)} busy={busy === 'view'} icon={Eye} tone="#10b981">View PDF</MomBtn>}
      </div>
      {m.mom_path && (
        <MomBtn onClick={() => openPdf(true)} busy={busy === 'dl'} icon={Download} tone="#0ea5e9" full>Download PDF</MomBtn>
      )}

      <style>{`@keyframes koSpin{to{transform:rotate(360deg)}}.ko-spin{animation:koSpin .9s linear infinite}`}</style>
    </div>
  )
}

function MomBtn({ onClick, busy, icon: Icon, tone, full, children }) {
  return (
    <button onClick={onClick} disabled={busy}
      style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6, padding: '9px 12px', borderRadius: 10, cursor: busy ? 'wait' : 'pointer',
        width: full ? '100%' : undefined, marginTop: full ? 8 : 0,
        fontSize: 12.5, fontWeight: 700, color: tone, background: `${tone}14`, border: `1px solid ${tone}44` }}>
      {busy ? <Loader2 size={14} className="ko-spin" /> : <Icon size={14} />} {children}
    </button>
  )
}

/* ── MOM approval & distribution (Meeting.docx — approve before distribute) ────
 * The minutes move Draft → Pending Approval → Approved → Distributed. The author
 * submits; an approver approves or returns with a reason; distribution is the
 * vendor-acknowledgement send (in the card below), which the server refuses until
 * the minutes are Approved. This card owns the approval steps + the audit stamps. */
function MomApprovalCard({ m, onChanged, onError }) {
  const [busy, setBusy]           = useState(null)   // submit|approve|return|revise
  const [returning, setReturning] = useState(false)
  const [note, setNote]           = useState('')

  const status    = m.mom_status || 'Draft'
  const cfg       = momStatusCfg(status)
  const completed = m.status === KO_STATUS.COMPLETED
  const curIdx    = MOM_STAGES.indexOf(status)
  // Both approval levels share the approve/return controls; only the label differs.
  const isPending    = status === 'Pending_Approval' || status === 'Pending_Chairperson'
  const approveLabel = status === 'Pending_Approval' ? 'Approve (Organizer)' : 'Approve (Chairperson)'

  const run = async (fn, key) => {
    setBusy(key); onError(null)
    try { onChanged(await fn()) }
    catch (e) { onError(e?.response?.data?.message || 'Could not update the minutes.') }
    finally { setBusy(null) }
  }
  const submit  = () => run(() => kickoffApi.momSubmit(m.id), 'submit')
  const approve = () => run(() => kickoffApi.momDecide(m.id, { decision: 'approve' }), 'approve')
  const revise  = () => run(() => kickoffApi.momRevise(m.id), 'revise')
  const doReturn = () => run(async () => {
    const r = await kickoffApi.momDecide(m.id, { decision: 'return', note })
    setReturning(false); setNote('')
    return r?.data ?? r
  }, 'return')

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, marginBottom: 6 }}>
        <SectionTitle icon={ClipboardCheck}>Minutes approval</SectionTitle>
        <span style={{ padding: '3px 10px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800 }}>{cfg.label}</span>
      </div>

      {/* Pipeline stepper */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 4, margin: '10px 0 14px' }}>
        {MOM_STAGES.map((st, i) => {
          const done = i <= curIdx
          const c = momStatusCfg(st)
          return (
            <div key={st} style={{ display: 'flex', alignItems: 'center', flex: i < MOM_STAGES.length - 1 ? 1 : '0 0 auto' }}>
              <div title={MOM_STATUS_CONFIG[st].label} style={{ width: 9, height: 9, borderRadius: '50%', flexShrink: 0, background: done ? c.color : 'var(--border)' }} />
              {i < MOM_STAGES.length - 1 && <div style={{ flex: 1, height: 2, background: i < curIdx ? momStatusCfg(MOM_STAGES[i + 1]).color : 'var(--border)' }} />}
            </div>
          )
        })}
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 9.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.02em', marginBottom: 12 }}>
        <span>Draft</span><span>Organizer</span><span>Chairperson</span><span>Approved</span><span>Distributed</span>
      </div>

      {/* Contextual actions */}
      {status === 'Draft' && (
        completed ? (
          <MomBtn onClick={submit} busy={busy === 'submit'} icon={Send} tone="#7C3AED" full>Submit for approval</MomBtn>
        ) : (
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5 }}>
            Complete the meeting first — minutes are submitted for approval once the meeting has taken place.
          </p>
        )
      )}

      {isPending && !returning && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
          <MomBtn onClick={approve} busy={busy === 'approve'} icon={ThumbsUp} tone="#10b981">{approveLabel}</MomBtn>
          <MomBtn onClick={() => setReturning(true)} busy={false} icon={Undo2} tone="#f59e0b">Return</MomBtn>
        </div>
      )}
      {isPending && returning && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          <textarea value={note} onChange={e => setNote(e.target.value)} rows={3}
            placeholder="What needs to change? (required)"
            style={{ width: '100%', padding: '8px 11px', borderRadius: 8, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', resize: 'vertical', fontFamily: 'inherit' }} />
          <div style={{ display: 'flex', gap: 8 }}>
            <button onClick={doReturn} disabled={busy === 'return' || !note.trim()}
              style={{ flex: 1, padding: '8px 12px', borderRadius: 8, border: 'none', fontWeight: 700, fontSize: 12.5, cursor: note.trim() ? 'pointer' : 'not-allowed', background: 'linear-gradient(135deg,#f59e0b,#d97706)', color: '#fff', opacity: note.trim() ? 1 : 0.5 }}>
              {busy === 'return' ? 'Returning…' : 'Return for revision'}
            </button>
            <button onClick={() => { setReturning(false); setNote('') }}
              style={{ padding: '8px 12px', borderRadius: 8, fontSize: 12.5, fontWeight: 700, cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              Cancel
            </button>
          </div>
        </div>
      )}

      {status === 'Approved' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 12px', borderRadius: 10, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.3)' }}>
            <CheckCircle2 size={15} style={{ color: '#10b981', flexShrink: 0 }} />
            <span style={{ fontSize: 12, color: 'var(--text-h)' }}>Approved — send to the vendor from the acknowledgement card below.</span>
          </div>
          <MomBtn onClick={revise} busy={busy === 'revise'} icon={RotateCcw} tone="#94a3b8" full>Reopen for revision</MomBtn>
        </div>
      )}

      {status === 'Distributed' && (
        <MomBtn onClick={revise} busy={busy === 'revise'} icon={RotateCcw} tone="#94a3b8" full>Reopen for revision</MomBtn>
      )}

      {/* Audit stamps */}
      {(m.mom_submitted_at || m.mom_organizer_approved_at || m.mom_approved_at || m.mom_distributed_at || m.mom_viewed_at || m.mom_approval_note) && (
        <div style={{ marginTop: 14, paddingTop: 12, borderTop: '1px solid var(--border)', display: 'flex', flexDirection: 'column', gap: 6 }}>
          {m.mom_submitted_at && <MomStamp label="Submitted" who={m.mom_submitter?.name} when={m.mom_submitted_at} />}
          {m.mom_organizer_approved_at && <MomStamp label="Organizer approved" who={m.mom_organizer_approver?.name} when={m.mom_organizer_approved_at} />}
          {m.mom_approved_at && <MomStamp label="Chairperson approved" who={m.mom_approver?.name} when={m.mom_approved_at} />}
          {m.mom_distributed_at && <MomStamp label="Distributed" who={m.mom_distributor?.name} when={m.mom_distributed_at} />}
          {m.mom_viewed_at && <MomStamp label="Viewed by vendor" who={null} when={m.mom_viewed_at} />}
          {m.mom_approval_note && (
            <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2, lineHeight: 1.45 }}>
              <span style={{ fontWeight: 700 }}>Note:</span> {m.mom_approval_note}
            </div>
          )}
        </div>
      )}

      <style>{`@keyframes koSpin{to{transform:rotate(360deg)}}.ko-spin{animation:koSpin .9s linear infinite}`}</style>
    </div>
  )
}

function MomStamp({ label, who, when }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, fontSize: 11.5 }}>
      <span style={{ color: 'var(--text-muted)' }}>{label}{who ? ` · ${who}` : ''}</span>
      <span style={{ color: 'var(--text-h)', fontWeight: 600, textAlign: 'right' }}>{fmtDateTime(when)}</span>
    </div>
  )
}

/* ── Subject meeting history + rollup (Meeting.docx — the per-vendor record) ────
 * Every meeting this subject has had, newest first, each with its own open-action
 * and open-issue counts, plus rollup totals across them. The current meeting is
 * marked; the rest link across. */
function VendorHistoryCard({ m, onOpen }) {
  const subject = m.subject
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr]         = useState(null)

  useEffect(() => {
    if (!subject?.type || !subject?.id) return
    setLoading(true); setErr(null)
    kickoffApi.history({ subject_type: subject.type, subject_id: subject.id })
      .then(setData).catch(() => setErr('Could not load the meeting history.')).finally(() => setLoading(false))
  }, [subject?.type, subject?.id])

  if (!subject?.id) return null

  const t = data?.totals
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <SectionTitle icon={History}>{subject.name || subject.label || 'Subject'} — meeting history</SectionTitle>

      {loading ? (
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '12px 0 0' }}>Loading…</p>
      ) : err ? (
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '12px 0 0' }}>{err}</p>
      ) : !data ? null : (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 8, margin: '12px 0 14px' }}>
            <HStat label="Meetings"     value={t.meetings} />
            <HStat label="Completed"    value={t.completed} color="#10b981" />
            <HStat label="Open actions" value={t.open_actions} color={t.open_actions ? '#f59e0b' : undefined} />
            <HStat label="Open issues"  value={t.open_issues} color={t.open_issues ? '#ef4444' : undefined} />
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {data.meetings.map(row => {
              const cur = row.id === m.id
              const cfg = koStatusCfg(row.status)
              return (
                <button key={row.id} onClick={() => !cur && onOpen(row.id)} disabled={cur}
                  style={{ textAlign: 'left', display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', borderRadius: 10,
                    cursor: cur ? 'default' : 'pointer', background: cur ? 'rgba(124,58,237,0.08)' : 'var(--bg-input)', border: `1px solid ${cur ? '#7C3AED' : 'var(--border)'}` }}>
                  <div style={{ minWidth: 0, flex: 1 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                      <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 230 }}>{row.title || row.meeting_type_label}</span>
                      {cur && <span style={{ fontSize: 9.5, fontWeight: 800, color: '#a78bfa' }}>THIS MEETING</span>}
                    </div>
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{row.meeting_type_label} · {row.scheduled_at ? fmtDateTime(row.scheduled_at) : 'Unscheduled'}</div>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexShrink: 0 }}>
                    {row.open_actions > 0 && <HPill tone="#f59e0b">{row.open_actions} act</HPill>}
                    {row.open_issues > 0 && <HPill tone="#ef4444">{row.open_issues} iss</HPill>}
                    <span style={{ padding: '2px 9px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 10.5, fontWeight: 800, whiteSpace: 'nowrap' }}>{cfg.label}</span>
                  </div>
                </button>
              )
            })}
          </div>
        </>
      )}
    </div>
  )
}

function HStat({ label, value, color }) {
  return (
    <div style={{ padding: '10px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <div style={{ fontSize: 19, fontWeight: 900, color: color || 'var(--text-h)', lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}
function HPill({ tone, children }) {
  return <span style={{ padding: '2px 7px', borderRadius: 6, background: `${tone}1f`, color: tone, fontSize: 10, fontWeight: 800, whiteSpace: 'nowrap' }}>{children}</span>
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

/* ── Online Meeting card ──────────────────────────────────────────────────────
 * Shown below the Schedule card when mode = 'online'.
 * States:
 *   1. linkData present  → show link, Copy + Open buttons, passcode/ID
 *   2. linkData absent   → show "Generate Meeting Link" button
 *   3. busy              → spinner
 */
const PLATFORM_LABELS = {
  google_meet: 'Google Meet',
  zoom:        'Zoom',
  teams:       'Microsoft Teams',
  stub:        'Generic Link',
}
const PLATFORM_COLORS = {
  google_meet: '#4285F4',
  zoom:        '#2D8CFF',
  teams:       '#6264A7',
  stub:        '#a78bfa',
}

function OnlineMeetingCard({ meeting, linkData, busy, onGenerate }) {
  const [copied, setCopied] = useState(false)

  const copy = () => {
    if (!linkData?.link) return
    navigator.clipboard?.writeText(linkData.link)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  const platform    = linkData?.platform ?? meeting.meeting_platform ?? 'stub'
  const color       = PLATFORM_COLORS[platform] ?? '#a78bfa'
  const platformLbl = PLATFORM_LABELS[platform]  ?? platform

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
        <Video size={15} style={{ color: '#a78bfa' }} />
        <h2 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>Online Meeting</h2>
        <span style={{ marginLeft: 'auto', padding: '3px 10px', borderRadius: 999, fontSize: 11, fontWeight: 800, background: `${color}18`, color, border: `1px solid ${color}44` }}>
          {platformLbl}
        </span>
      </div>

      {busy ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '14px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <Loader2 size={16} className="ko-spin" style={{ color: '#a78bfa' }} />
          <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>Generating meeting link…</span>
        </div>
      ) : linkData?.link ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {/* Link row */}
          <div style={{ display: 'flex', gap: 7 }}>
            <input
              readOnly
              value={linkData.link}
              style={{ flex: 1, padding: '9px 11px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 12, fontFamily: 'monospace', minWidth: 0 }}
            />
            <button
              onClick={copy}
              title="Copy link"
              style={{ padding: '0 12px', borderRadius: 9, cursor: 'pointer', background: copied ? 'rgba(16,185,129,0.1)' : 'var(--bg-card)', border: `1px solid ${copied ? 'rgba(16,185,129,0.4)' : 'var(--border)'}`, color: copied ? '#10b981' : 'var(--text-muted)', display: 'flex', alignItems: 'center', gap: 5, fontSize: 12, fontWeight: 700, transition: 'all .15s ease' }}
            >
              <Copy size={13} /> {copied ? 'Copied!' : 'Copy'}
            </button>
            <a
              href={linkData.link}
              target="_blank"
              rel="noopener noreferrer"
              style={{ padding: '0 12px', borderRadius: 9, cursor: 'pointer', background: `${color}14`, border: `1px solid ${color}44`, color, display: 'flex', alignItems: 'center', gap: 5, fontSize: 12, fontWeight: 700, textDecoration: 'none' }}
            >
              <ExternalLink size={13} /> Open
            </a>
          </div>

          {/* Meta row */}
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            {linkData.id && (
              <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                ID: <strong style={{ color: 'var(--text-h)', fontFamily: 'monospace' }}>{linkData.id}</strong>
              </span>
            )}
            {linkData.passcode && (
              <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                Passcode: <strong style={{ color: 'var(--text-h)', fontFamily: 'monospace' }}>{linkData.passcode}</strong>
              </span>
            )}
          </div>

          {/* Regenerate */}
          <button
            onClick={() => onGenerate(platform)}
            style={{ alignSelf: 'flex-start', display: 'inline-flex', alignItems: 'center', gap: 5, padding: '7px 13px', borderRadius: 9, cursor: 'pointer', fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }}
          >
            <Video size={12} /> Regenerate link
          </button>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5 }}>
            No meeting link has been generated yet. Click below to create one.
          </p>
          <button
            onClick={() => onGenerate(platform)}
            style={{ ...solidBtn, alignSelf: 'flex-start' }}
          >
            <Video size={15} /> Generate Meeting Link
          </button>
        </div>
      )}
    </div>
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
