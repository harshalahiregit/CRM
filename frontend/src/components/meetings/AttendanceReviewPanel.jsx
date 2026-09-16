import { useState, useEffect, useCallback } from 'react'
import { ShieldCheck, AlertTriangle, Loader2, Save, Clock, Smartphone } from 'lucide-react'

/**
 * The organiser's review of who actually attended.
 *
 * Marking attendance in the CRM is a CLAIM — it is also how the person got the
 * joining link at all — and the CRM cannot watch a call held on Google Meet,
 * Zoom or Teams. So the organiser decides, and this screen exists to let them
 * decide with the evidence in front of them.
 *
 * ── Why two columns and not one ─────────────────────────────────────────
 * The left side is what the person's own actions say: did they mark attendance,
 * when, and from what device. The right side is the organiser's verdict. They
 * are side by side and never merged, because the sentence this whole feature
 * exists to make writable is "punched CRM attendance but did not join the
 * call" — and that is only writable if the claim survives being contradicted.
 * A single column would have destroyed the evidence at the moment of judging it.
 *
 * Disagreement is called out rather than left for someone to notice.
 *
 * ── One panel, both engines ─────────────────────────────────────────────
 * `api` is the resolved meeting client (shared or Purchase), so the two
 * engines cannot drift into two different review screens.
 */

const VERDICTS = [
  ['Fully_Present', 'Fully Present', '#10b981'],
  ['Partial_Absent', 'Partial Absent', '#f59e0b'],
  ['Complete_Absent', 'Complete Absent', '#ef4444'],
]

const clock = (iso) => (iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—')
/** A datetime-local value from an ISO string, in the reader's own zone. */
const toLocalInput = (iso) => {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

export default function AttendanceReviewPanel({ api, meetingId }) {
  const [rows, setRows] = useState(null)
  const [mayReview, setMayReview] = useState(false)
  const [draft, setDraft] = useState({})      // id → { verdict, verdict_from, verdict_to, verdict_note }
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [flash, setFlash] = useState('')

  const load = useCallback(() => {
    setError('')
    api.attendanceRegister(meetingId)
      .then(d => {
        setRows(d?.register ?? [])
        setMayReview(!!d?.may_review)
        // Seed the form from what is already decided, so opening the screen and
        // saving does not quietly wipe an earlier review.
        setDraft(Object.fromEntries((d?.register ?? []).map(r => [r.id, {
          verdict: r.verdict ?? '',
          verdict_from: toLocalInput(r.verdict_from),
          verdict_to: toLocalInput(r.verdict_to),
          verdict_note: r.verdict_note ?? '',
        }])))
      })
      .catch(e => { setRows([]); setError(e?.response?.data?.message || 'Could not load the attendance register.') })
  }, [api, meetingId])

  useEffect(() => { load() }, [load])

  const set = (id, patch) => setDraft(d => ({ ...d, [id]: { ...d[id], ...patch } }))

  const save = () => {
    setBusy(true); setError(''); setFlash('')
    const payload = rows.map(r => ({
      id: r.id,
      verdict: draft[r.id]?.verdict || null,
      verdict_from: draft[r.id]?.verdict_from || null,
      verdict_to: draft[r.id]?.verdict_to || null,
      verdict_note: draft[r.id]?.verdict_note || null,
    }))

    api.reviewAttendance(meetingId, payload)
      .then(d => {
        setRows(d?.register ?? [])
        const c = d?.counts || {}
        setFlash(`Saved — ${c.present || 0} fully present, ${c.partial || 0} partial, ${c.absent || 0} absent`
          + (c.contradicted ? `, ${c.contradicted} contradicting a CRM attendance mark` : ''))
      })
      // The server refuses a partial verdict with no window, and says why. That
      // message is more useful than anything this screen could invent.
      .catch(e => setError(e?.response?.data?.message || 'Could not save the review.'))
      .finally(() => setBusy(false))
  }

  if (rows === null) {
    return (
      <div className="pr-glass" style={{ padding: 20, display: 'flex', alignItems: 'center', gap: 9 }}>
        <Loader2 size={15} className="ko-spin" style={{ color: '#a78bfa' }} />
        <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>Loading the attendance register…</span>
      </div>
    )
  }
  if (!rows.length) return null

  const contradictions = rows.filter(r => r.contradicts_claim).length

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
        <ShieldCheck size={15} style={{ color: '#a78bfa' }} />
        <h2 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>Attendance review</h2>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
          · {rows.filter(r => r.reviewed).length}/{rows.length} decided
        </span>
        {contradictions > 0 && (
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11, fontWeight: 800, color: '#ef4444', padding: '2px 9px', borderRadius: 999, background: 'rgba(239,68,68,0.12)' }}>
            <AlertTriangle size={12} /> {contradictions} marked attendance but ruled absent
          </span>
        )}
      </div>

      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '6px 0 0', lineHeight: 1.5 }}>
        Marking attendance in the CRM is what released the joining link — it is not proof anyone stayed in
        a call held on Google Meet, Zoom or Teams. Your decision is recorded beside each person&rsquo;s own
        mark, never over it.
      </p>

      {error && <Banner tone="#ef4444">{error}</Banner>}
      {flash && <Banner tone="#10b981">{flash}</Banner>}

      {!mayReview && (
        <Banner tone="#64748b">
          Only the organiser who called this meeting, or an admin, can decide attendance. You can read the
          register below.
        </Banner>
      )}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 14 }}>
        {rows.map(r => {
          const d = draft[r.id] || {}
          return (
            <div key={r.id} style={{
              padding: 12, borderRadius: 12, background: 'var(--bg-input)',
              border: `1px solid ${r.contradicts_claim ? 'rgba(239,68,68,0.45)' : 'var(--border)'}`,
            }}>
              <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
                {/* ── what this person's own actions say ── */}
                <div style={{ flex: '1 1 260px', minWidth: 0 }}>
                  <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{r.name}</div>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                    {[r.role, r.organisation].filter(Boolean).join(' · ') || '—'}
                  </div>
                  {r.claimed_attended ? (
                    <div style={{ fontSize: 11.5, color: '#10b981', marginTop: 4, display: 'flex', alignItems: 'center', gap: 5 }}>
                      <Clock size={12} /> Marked attendance {clock(r.joined_at)}
                      {r.attendance_source ? ` · ${r.attendance_source}` : ''}
                    </div>
                  ) : (
                    <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 4 }}>
                      No attendance mark in the CRM
                    </div>
                  )}
                  {/* The answer to "how do we know?", which is the organiser's
                      first question — kept next to the decision, not somewhere
                      they would have to go and look. */}
                  {r.claim_evidence && (
                    <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 3, display: 'flex', alignItems: 'flex-start', gap: 5 }}>
                      <Smartphone size={11} style={{ flexShrink: 0, marginTop: 1 }} /> {r.claim_evidence}
                    </div>
                  )}
                </div>

                {/* ── what the organiser decides ── */}
                <div style={{ flex: '1 1 320px', minWidth: 0 }}>
                  <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    {VERDICTS.map(([key, label, tone]) => (
                      <button key={key} type="button" disabled={!mayReview}
                        onClick={() => set(r.id, { verdict: d.verdict === key ? '' : key })}
                        style={{
                          padding: '5px 11px', borderRadius: 8, fontSize: 11.5, fontWeight: 700,
                          cursor: mayReview ? 'pointer' : 'not-allowed', opacity: mayReview ? 1 : 0.55,
                          border: `1px solid ${d.verdict === key ? tone : 'var(--border)'}`,
                          background: d.verdict === key ? tone : 'transparent',
                          color: d.verdict === key ? '#fff' : 'var(--text-muted)',
                        }}>
                        {label}
                      </button>
                    ))}
                  </div>

                  {/* Only Partial carries a window. The other two slabs speak for
                      themselves, and "absent, 9:30 to 10:00" is a contradiction. */}
                  {d.verdict === 'Partial_Absent' && (
                    <div style={{ display: 'flex', gap: 8, marginTop: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                      <label style={lbl}>In from</label>
                      <input type="datetime-local" value={d.verdict_from || ''} disabled={!mayReview}
                        onChange={e => set(r.id, { verdict_from: e.target.value })} style={input} />
                      <label style={lbl}>to</label>
                      <input type="datetime-local" value={d.verdict_to || ''} disabled={!mayReview}
                        onChange={e => set(r.id, { verdict_to: e.target.value })} style={input} />
                    </div>
                  )}

                  <textarea
                    value={d.verdict_note || ''} disabled={!mayReview} rows={2}
                    onChange={e => set(r.id, { verdict_note: e.target.value })}
                    placeholder="Comment — e.g. marked attendance in the CRM but never joined the call"
                    style={{
                      width: '100%', marginTop: 8, padding: '7px 10px', borderRadius: 9, resize: 'vertical',
                      border: '1px solid var(--border)', background: 'var(--bg-card)',
                      color: 'var(--text-h)', fontSize: 12, outline: 'none', fontFamily: 'inherit',
                    }}
                  />

                  {r.contradicts_claim && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 6, fontSize: 11.5, fontWeight: 700, color: '#ef4444' }}>
                      <AlertTriangle size={12} /> This person marked attendance in the CRM and is ruled absent
                    </div>
                  )}
                  {r.reviewed && r.verdict_minutes != null && (
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>
                      Recorded as {r.verdict_minutes} min in the meeting
                    </div>
                  )}
                </div>
              </div>
            </div>
          )
        })}
      </div>

      {mayReview && (
        <button onClick={save} disabled={busy}
          style={{
            marginTop: 14, display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px',
            borderRadius: 10, border: 'none', fontSize: 12.5, fontWeight: 800, color: '#fff',
            cursor: busy ? 'wait' : 'pointer', opacity: busy ? 0.65 : 1,
            background: 'linear-gradient(145deg,#a78bfa,#7C3AED)',
          }}>
          {busy ? <Loader2 size={14} className="ko-spin" /> : <Save size={14} />}
          {busy ? 'Saving…' : 'Save review'}
        </button>
      )}
    </div>
  )
}

const Banner = ({ tone, children }) => (
  <p style={{
    fontSize: 12, color: 'var(--text-h)', margin: '10px 0 0', padding: '8px 11px',
    borderRadius: 9, background: `${tone}14`, border: `1px solid ${tone}55`, lineHeight: 1.5,
  }}>{children}</p>
)

const lbl = { fontSize: 11, fontWeight: 700, color: 'var(--text-muted)' }
const input = {
  padding: '5px 8px', borderRadius: 8, border: '1px solid var(--border)',
  background: 'var(--bg-card)', color: 'var(--text-h)', fontSize: 11.5, outline: 'none',
}
