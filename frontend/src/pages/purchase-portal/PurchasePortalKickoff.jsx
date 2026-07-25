import { useState, useEffect, useCallback } from 'react'
import { CalendarDays, CheckCircle2, MapPin, Video } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import { KIT3D_STYLE, InfoBox } from '@/components/ui/kit3d'

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—')

export default function PurchasePortalKickoff() {
  const [meeting, setMeeting] = useState(null)
  const [loading, setL] = useState(true)
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setL(true)
    purchasePortalApi.kickoff.get()
      .then(d => setMeeting(d?.meeting ?? null))
      .catch(() => {})
      .finally(() => setL(false))
  }, [])
  useEffect(() => { load() }, [load])

  const accept = async () => {
    setBusy(true)
    try { await purchasePortalApi.kickoff.accept(); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not acknowledge') }
    finally { setBusy(false) }
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '0 0 16px' }}>Kickoff Meeting</h1>

      {loading ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
      : !meeting ? <InfoBox>No kickoff meeting has been scheduled for you yet. The procurement team will schedule one.</InfoBox>
      : (
        <div className="pr-glass" style={{ padding: 22, borderRadius: 16, maxWidth: 640 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
            <CalendarDays size={18} style={{ color: '#a78bfa' }} />
            <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{meeting.title || 'Kickoff Meeting'}</h2>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 24px', marginBottom: 16 }}>
            {[['Status', meeting.status_label || meeting.status], ['Scheduled', fmt(meeting.scheduled_at)], ['Mode', meeting.mode || '—'], ['Location', meeting.location || '—']].map(([k, v]) => (
              <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 7 }}>
                <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
                <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, textAlign: 'right', textTransform: k === 'Mode' ? 'capitalize' : 'none' }}>{v}</span>
              </div>
            ))}
          </div>

          {meeting.meeting_link && (
            <a href={meeting.meeting_link} target="_blank" rel="noopener noreferrer" style={{ ...linkBtn, marginBottom: 10 }}><Video size={15} /> Join Online Meeting</a>
          )}

          {meeting.mom_available
            ? <InfoBox>Minutes of Meeting (MOM) are available. {meeting.acknowledged_at ? 'You have acknowledged them.' : 'Please review and acknowledge below.'}</InfoBox>
            : <InfoBox>Minutes of Meeting have not been published yet.</InfoBox>}

          {meeting.acknowledged_at ? (
            <div style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'rgba(16,185,129,0.12)', color: '#10b981', fontWeight: 700, fontSize: 13 }}>
              <CheckCircle2 size={15} /> Acknowledged on {fmt(meeting.acknowledged_at)}
            </div>
          ) : meeting.mom_available ? (
            <button onClick={accept} disabled={busy} style={primaryBtn}><CheckCircle2 size={15} /> {busy ? 'Submitting…' : 'Acknowledge MOM'}</button>
          ) : null}
        </div>
      )}
    </div>
  )
}

const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 10, border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 800, color: '#fff', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const linkBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 10, textDecoration: 'none', fontSize: 13, fontWeight: 700, color: 'var(--text-h)', background: 'var(--bg-input)', border: '1px solid var(--border)' }
