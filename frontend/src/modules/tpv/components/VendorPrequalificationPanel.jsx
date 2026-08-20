import { useState, useEffect, useMemo } from 'react'
import { ClipboardCheck, AlertTriangle, Loader2, Save, X, Award, CalendarClock } from 'lucide-react'

/**
 * Vendor Prequalification (gap report area 6).
 *
 * A scored, sectioned questionnaire → Qualified / Conditional / Not Qualified
 * (the spec's 82/100 bar). Higher is better — the opposite polarity to risk.
 * Shows the current outcome and, for managers, an assessment form with a live
 * score/outcome preview that mirrors the backend scoring.
 */
const STATUS_CFG = {
  Qualified:     { label: 'Qualified',     color: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  Conditional:   { label: 'Conditional',   color: '#f59e0b', bg: 'rgba(245,158,11,0.14)' },
  Not_Qualified: { label: 'Not Qualified', color: '#ef4444', bg: 'rgba(239,68,68,0.14)' },
  Pending:       { label: 'Pending',       color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' },
}
const statusCfg = (s) => STATUS_CFG[s] || STATUS_CFG.Pending

const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }) : '—')

export function VendorPrequalificationPanel({ vendorId, manage, api }) {
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr]         = useState(null)
  const [editing, setEditing] = useState(false)
  const [ans, setAns]         = useState({})
  const [notes, setNotes]     = useState('')
  const [saving, setSaving]   = useState(false)

  const load = () => {
    setLoading(true); setErr(null)
    api.vendors.prequalification(vendorId)
      .then(d => { setData(d); setAns(d.answers || {}); setNotes(d.notes || '') })
      .catch(() => setErr('Could not load the prequalification.'))
      .finally(() => setLoading(false))
  }
  useEffect(load, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps

  const catalogue = data?.catalogue || {}
  const outcomes  = data?.outcomes || {}

  // Live preview — mirrors VendorPrequalificationService::compute()/fromScore().
  const preview = useMemo(() => {
    const sections = Object.values(catalogue)
    if (!sections.length) return null
    let sum = 0, max = 0, answered = 0, totalQ = 0
    sections.forEach(sec => {
      Object.entries(sec.questions || {}).forEach(([qKey, q]) => {
        totalQ++
        const opts = q.options || {}
        const pts  = Object.values(opts).map(o => o.points)
        max += pts.length ? Math.max(...pts) : 0
        const chosen = ans[qKey]
        if (chosen && opts[chosen]) { sum += opts[chosen].points; answered++ }
      })
    })
    const score   = max > 0 ? Math.round(sum / max * 100) : 0
    const entries = Object.entries(outcomes).sort((a, b) => b[1] - a[1])
    const status  = (entries.find(([, min]) => score >= min) || ['Not_Qualified'])[0]
    return { score, status, answered, total: totalQ }
  }, [ans, catalogue, outcomes])

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const d = await api.vendors.assessPrequalification(vendorId, { answers: ans, notes: notes || undefined })
      setData(d); setAns(d.answers || {}); setNotes(d.notes || ''); setEditing(false)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save — prequalification is an admin action.')
    } finally { setSaving(false) }
  }

  if (loading) return <Card><Row><Loader2 size={16} className="pq-spin" style={{ color: '#a78bfa' }} /> <span style={muted}>Loading…</span></Row><Spin /></Card>

  const cfg = statusCfg(data?.status)
  const qualifiedBar = outcomes.Qualified

  return (
    <Card>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
          <ClipboardCheck size={16} style={{ color: '#a78bfa' }} />
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Prequalification</h2>
        </div>
        {manage && !editing && (
          <button onClick={() => setEditing(true)} style={btnPrimary}>{data?.assessed ? 'Re-assess' : 'Assess'}</button>
        )}
      </div>

      {err && <Banner>{err}</Banner>}

      <p style={{ ...muted, fontSize: 12, margin: '0 0 16px', lineHeight: 1.5 }}>
        A scored capability questionnaire. {qualifiedBar ? `Qualifying at ${qualifiedBar}/100.` : ''} The outcome gates onboarding approval.
      </p>

      {editing ? (
        <Assessment
          catalogue={catalogue} ans={ans} setAns={setAns} notes={notes} setNotes={setNotes}
          preview={preview} saving={saving} onSave={save}
          onCancel={() => { setEditing(false); setAns(data?.answers || {}); setNotes(data?.notes || '') }}
        />
      ) : !data?.assessed ? (
        <div style={{ padding: '28px 16px', borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center' }}>
          <Award size={26} style={{ color: 'var(--text-muted)', opacity: 0.5, marginBottom: 8 }} />
          <p style={{ ...muted, margin: 0, fontSize: 13 }}>This vendor has not been prequalified yet.{manage ? ' Click “Assess” to score it.' : ''}</p>
        </div>
      ) : (
        <Summary data={data} cfg={cfg} />
      )}
      <Spin />
    </Card>
  )
}

/* ── Current outcome ───────────────────────────────────────────────────────── */
function Summary({ data, cfg }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap', padding: 16, borderRadius: 14, background: cfg.bg, border: `1px solid ${cfg.color}55` }}>
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', minWidth: 110 }}>
          <span style={{ fontSize: 20, fontWeight: 900, color: cfg.color, lineHeight: 1.1, textAlign: 'center' }}>{cfg.label}</span>
          <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginTop: 4 }}>OUTCOME</span>
        </div>
        <div style={{ flex: 1, minWidth: 180 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11.5, color: 'var(--text-muted)', marginBottom: 5 }}>
            <span>Score</span><strong style={{ color: 'var(--text-h)' }}>{data.score}/100</strong>
          </div>
          <div style={{ height: 8, borderRadius: 999, background: 'var(--bg-card)', overflow: 'hidden' }}>
            <div style={{ height: '100%', width: `${data.score}%`, background: cfg.color, borderRadius: 999, transition: 'width .3s' }} />
          </div>
        </div>
      </div>

      {/* Per-section scores */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {data.sections.map(s => (
          <div key={s.key}>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, marginBottom: 4 }}>
              <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{s.label}</span>
              <span style={{ color: 'var(--text-muted)' }}>{s.points}/{s.max}</span>
            </div>
            <div style={{ height: 5, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden' }}>
              <div style={{ height: '100%', width: `${s.percent}%`, background: '#a78bfa', borderRadius: 999 }} />
            </div>
          </div>
        ))}
      </div>

      {data.notes && (
        <div style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.5, padding: '10px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <span style={{ color: 'var(--text-muted)', fontWeight: 700 }}>Notes: </span>{data.notes}
        </div>
      )}

      <Row><CalendarClock size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} /><span style={{ ...muted, fontSize: 11.5 }}>Assessed {data.assessed_by ? `by ${data.assessed_by} ` : ''}· {fmtDateTime(data.assessed_at)}</span></Row>
    </div>
  )
}

/* ── Assessment form ───────────────────────────────────────────────────────── */
function Assessment({ catalogue, ans, setAns, notes, setNotes, preview, saving, onSave, onCancel }) {
  const pc = statusCfg(preview?.status)
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      {Object.entries(catalogue).map(([sKey, section]) => (
        <div key={sKey}>
          <div style={{ fontSize: 11.5, fontWeight: 800, color: '#a78bfa', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 8 }}>{section.label}</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {Object.entries(section.questions || {}).map(([qKey, q]) => (
              <div key={qKey}>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', marginBottom: 5 }}>{q.label}</label>
                <select value={ans[qKey] || ''} onChange={e => setAns(a => ({ ...a, [qKey]: e.target.value || undefined }))} style={selectStyle}>
                  <option value="">— Select —</option>
                  {Object.entries(q.options).map(([val, o]) => <option key={val} value={val}>{o.label}</option>)}
                </select>
              </div>
            ))}
          </div>
        </div>
      ))}

      <div>
        <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6 }}>Notes (optional)</label>
        <textarea value={notes} onChange={e => setNotes(e.target.value)} rows={2} placeholder="Rationale, conditions…" style={{ ...selectStyle, resize: 'vertical', fontFamily: 'inherit', lineHeight: 1.5 }} />
      </div>

      {preview && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 12, borderRadius: 12, background: pc.bg, border: `1px solid ${pc.color}55` }}>
          <span style={{ fontSize: 15, fontWeight: 900, color: pc.color }}>{pc.label}</span>
          <span style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 700 }}>{preview.score}/100</span>
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)', marginLeft: 'auto' }}>{preview.answered}/{preview.total} answered</span>
        </div>
      )}

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
        <button onClick={onCancel} disabled={saving} style={btnGhost}><X size={14} /> Cancel</button>
        <button onClick={onSave} disabled={saving} style={{ ...btnPrimary, opacity: saving ? 0.6 : 1 }}>
          {saving ? <Loader2 size={14} className="pq-spin" /> : <Save size={14} />} {saving ? 'Saving…' : 'Save assessment'}
        </button>
      </div>
    </div>
  )
}

/* ── bits ──────────────────────────────────────────────────────────────────── */
const Card = ({ children }) => <div className="pr-glass" style={{ padding: 20 }}>{children}</div>
const Row = ({ children }) => <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>{children}</div>
const Banner = ({ children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 12px', borderRadius: 10, marginBottom: 14, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
    <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0 }} /><span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{children}</span>
  </div>
)
const Spin = () => <style>{`@keyframes pqSpin{to{transform:rotate(360deg)}}.pq-spin{animation:pqSpin .9s linear infinite}`}</style>
const muted = { color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }
const selectStyle = { width: '100%', padding: '9px 11px', borderRadius: 9, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: '#fff', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }

export default VendorPrequalificationPanel
