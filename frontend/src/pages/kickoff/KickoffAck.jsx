import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import {
  CalendarCheck, Loader2, CheckCircle2, XCircle, Users, Clock, FileText, ShieldCheck,
} from 'lucide-react'
import { kickoffAckApi } from '@/services/kickoffAckApi'
import { KIT3D_STYLE, inputStyle } from '@/components/ui/kit3d'
import { fmtDateTime } from '@/modules/shared/kickoffConstants'

/**
 * Public kickoff-minutes acknowledgement — phone-first.
 *
 * No auth: the 48-char token in the URL is the credential. Opened by a vendor's
 * signatory who has no CRM login. Mirrors the checklist fill page's chrome.
 */
export default function KickoffAck() {
  const { token } = useParams()
  const [meeting, setMeeting] = useState(null)
  const [loading, setLoad] = useState(true)
  const [loadErr, setLoadErr] = useState(null)
  const [name, setName] = useState('')
  const [comment, setComment] = useState('')
  const [submitting, setSub] = useState(false)
  const [pdfBusy, setPdfBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [done, setDone] = useState(false)
  const [signedName, setSignedName] = useState('')

  useEffect(() => {
    kickoffAckApi.show(token)
      .then(m => { setMeeting(m); setLoad(false); if (m.acknowledged_at) { setDone(true); setSignedName(m.acknowledged_by_name || '') } })
      .catch(e => { setLoadErr(e?.response?.data?.message || 'This link is not valid.'); setLoad(false) })
  }, [token])

  // Open the actual minutes document (PDF) so the vendor reviews what they sign.
  // Read-only and repeatable — this never burns the token, unlike acknowledge().
  const viewPdf = async () => {
    setPdfBusy(true); setErr(null)
    try {
      const blob = await kickoffAckApi.mom(token)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not open the minutes document.')
    } finally { setPdfBusy(false) }
  }

  const submit = async () => {
    if (!name.trim()) { setErr('Please enter your name.'); return }
    setSub(true); setErr(null)
    try {
      await kickoffAckApi.acknowledge(token, name.trim(), comment.trim() || undefined)
      setSignedName(name.trim())
      setDone(true)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not acknowledge. Please try again.')
      setSub(false)
    }
  }

  if (loading) return <Shell><Centered><Loader2 size={30} className="ka-spin" style={{ color: '#a78bfa' }} /><p style={muted}>Loading…</p></Centered></Shell>

  if (loadErr) return (
    <Shell><Centered>
      <Disc color="#ef4444" icon={XCircle} />
      <h1 style={h1}>Link not valid</h1>
      <p style={{ ...muted, maxWidth: 300 }}>{loadErr}</p>
    </Centered></Shell>
  )

  if (done) return (
    <Shell><Centered>
      <Disc color="#10b981" icon={CheckCircle2} />
      <h1 style={h1}>Acknowledged</h1>
      <p style={{ ...muted, maxWidth: 320 }}>
        Thank you{signedName ? `, ${signedName}` : ''}. Your acknowledgement of these minutes has been recorded.
      </p>
      <div className="pr-glass" style={{ marginTop: 18, padding: '12px 16px', borderRadius: 14 }}>
        <p style={{ ...muted, fontSize: 12, margin: 0 }}>You can close this page.</p>
      </div>
    </Centered></Shell>
  )

  return (
    <Shell>
      <div style={{ padding: '18px 14px 40px', maxWidth: 560, margin: '0 auto' }}>
        {/* Header */}
        <div className="pr-glass" style={{ padding: 18, marginBottom: 14 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 11 }}>
            <span style={{ width: 42, height: 42, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -4px #7C3AED88, inset 0 1px 0 rgba(255,255,255,.35)' }}>
              <CalendarCheck size={20} color="#fff" />
            </span>
            <div style={{ minWidth: 0 }}>
              <p className="label-caps" style={{ margin: 0, fontSize: 10, fontWeight: 800, letterSpacing: '0.08em', color: '#a78bfa' }}>KICKOFF MINUTES</p>
              <h1 style={{ margin: '2px 0 0', fontSize: 17, fontWeight: 900, color: 'var(--text-h)', lineHeight: 1.25 }}>{meeting.title}</h1>
            </div>
          </div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 12 }}>
            {meeting.subject?.name && <Chip>{meeting.subject.label}: <strong>{meeting.subject.name}</strong></Chip>}
            {meeting.completed_at && <Chip><Clock size={11} /> {fmtDateTime(meeting.completed_at)}</Chip>}
          </div>
        </div>

        {/* Agenda / minutes */}
        {meeting.agenda && (
          <div className="pr-glass" style={{ padding: 18, marginBottom: 14 }}>
            <SectionTitle icon={FileText}>Agenda</SectionTitle>
            <p style={{ fontSize: 13.5, color: 'var(--text-h)', margin: '10px 0 0', lineHeight: 1.55, whiteSpace: 'pre-wrap' }}>{meeting.agenda}</p>
          </div>
        )}
        {meeting.minutes && (
          <div className="pr-glass" style={{ padding: 18, marginBottom: 14 }}>
            <SectionTitle icon={FileText}>Minutes of meeting</SectionTitle>
            <p style={{ fontSize: 13.5, color: 'var(--text-h)', margin: '10px 0 0', lineHeight: 1.6, whiteSpace: 'pre-wrap' }}>{meeting.minutes}</p>
          </div>
        )}

        {/* Attendees */}
        {(meeting.attendees || []).length > 0 && (
          <div className="pr-glass" style={{ padding: 18, marginBottom: 14 }}>
            <SectionTitle icon={Users}>Attendees</SectionTitle>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 7, marginTop: 10 }}>
              {meeting.attendees.map((a, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13 }}>
                  <span style={{ width: 6, height: 6, borderRadius: '50%', background: a.attended ? '#10b981' : 'var(--border)', flexShrink: 0 }} />
                  <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{a.name}</span>
                  <span style={{ color: 'var(--text-muted)', fontSize: 11.5 }}>{[a.role, a.organisation].filter(Boolean).join(' · ')}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Acknowledge */}
        <div className="pr-glass" style={{ padding: 18 }}>
          <SectionTitle icon={ShieldCheck}>Acknowledge these minutes</SectionTitle>
          <p style={{ ...muted, fontSize: 12.5, margin: '8px 0 14px', lineHeight: 1.5 }}>
            By entering your name and confirming, you acknowledge that these minutes reflect what was agreed at the meeting.
          </p>

          {/* Review the actual signed document before acknowledging. */}
          {meeting.has_document && (
            <button onClick={viewPdf} disabled={pdfBusy}
              style={{ width: '100%', height: 46, borderRadius: 12, cursor: pdfBusy ? 'wait' : 'pointer', fontSize: 13.5, fontWeight: 700, marginBottom: 12,
                display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8,
                color: '#a78bfa', background: 'rgba(124,58,237,0.08)', border: '1px solid rgba(124,58,237,0.35)' }}>
              {pdfBusy ? <Loader2 size={16} className="ka-spin" /> : <FileText size={16} />}
              {pdfBusy ? 'Opening…' : 'View the minutes document (PDF)'}
            </button>
          )}

          <input value={name} onChange={e => setName(e.target.value)} placeholder="Your full name"
            style={{ ...inputStyle, height: 48, fontSize: 15, marginBottom: 12 }} />
          <textarea value={comment} onChange={e => setComment(e.target.value)} rows={2}
            placeholder="Add a remark (optional) — e.g. agreed with one correction"
            style={{ ...inputStyle, minHeight: 64, fontSize: 14, marginBottom: 12, resize: 'vertical', fontFamily: 'inherit', lineHeight: 1.5, padding: '10px 12px' }} />
          {err && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 12px', borderRadius: 10, marginBottom: 12, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
              <XCircle size={14} style={{ color: '#ef4444', flexShrink: 0 }} />
              <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span>
            </div>
          )}
          <button onClick={submit} disabled={submitting}
            style={{ width: '100%', height: 52, borderRadius: 13, cursor: 'pointer', fontSize: 15, fontWeight: 800, color: '#fff', border: 'none',
              display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8,
              background: 'linear-gradient(145deg,#34d399,#10b981)', boxShadow: '0 10px 24px -6px rgba(16,185,129,.6), inset 0 1px 0 rgba(255,255,255,.3)',
              opacity: submitting ? 0.7 : 1 }}>
            {submitting ? <Loader2 size={17} className="ka-spin" /> : <ShieldCheck size={17} />}
            {submitting ? 'Recording…' : 'I acknowledge the minutes'}
          </button>
        </div>
      </div>
    </Shell>
  )
}

/* chrome */
const muted = { color: 'var(--text-muted)', fontSize: 13, margin: '8px 0 0' }
const h1 = { fontSize: 22, fontWeight: 900, color: 'var(--text-h)', margin: '14px 0 6px' }

const SectionTitle = ({ icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
    <Icon size={15} style={{ color: '#a78bfa' }} />
    <h2 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{children}</h2>
  </div>
)

function Shell({ children }) {
  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <style>{`@keyframes kaSpin{to{transform:rotate(360deg)}}.ka-spin{animation:kaSpin .9s linear infinite}`}</style>
      {children}
    </div>
  )
}
const Centered = ({ children }) => (
  <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: 24, textAlign: 'center' }}>{children}</div>
)
const Disc = ({ color, icon: Icon }) => (
  <div style={{ width: 84, height: 84, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1a`, border: `2px solid ${color}55`, boxShadow: `0 16px 40px -12px ${color}88` }}>
    <Icon size={40} style={{ color }} />
  </div>
)
const Chip = ({ children }) => (
  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 10px', borderRadius: 999, background: 'var(--bg-input)', border: '1px solid var(--border)', fontSize: 11, color: 'var(--text-muted)' }}>{children}</span>
)
