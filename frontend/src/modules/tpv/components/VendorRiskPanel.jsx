import { useState, useEffect, useMemo } from 'react'
import { ShieldAlert, AlertTriangle, Loader2, Save, X, Gauge, Eye, CalendarClock } from 'lucide-react'

/**
 * Vendor Risk Classification (gap report area 2).
 *
 * Forward-looking risk tier (Critical/High/Medium/Low) from weighted factors —
 * distinct from the VRS performance scorecard (A–D) on the Overview tab. Shows
 * the current classification and, for managers, an assessment form with a live
 * score/tier preview that mirrors the backend scoring.
 */
const TIER_CFG = {
  Critical: { color: '#ef4444', bg: 'rgba(239,68,68,0.14)' },
  High:     { color: '#f59e0b', bg: 'rgba(245,158,11,0.14)' },
  Medium:   { color: '#eab308', bg: 'rgba(234,179,8,0.14)' },
  Low:      { color: '#10b981', bg: 'rgba(16,185,129,0.14)' },
}
const tierCfg = (t) => TIER_CFG[t] || { color: 'var(--text-muted)', bg: 'var(--bg-input)' }

const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }) : '—')

export function VendorRiskPanel({ vendorId, manage, api }) {
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr]         = useState(null)
  const [editing, setEditing] = useState(false)
  const [sel, setSel]         = useState({})
  const [notes, setNotes]     = useState('')
  const [saving, setSaving]   = useState(false)

  const load = () => {
    setLoading(true); setErr(null)
    api.vendors.risk(vendorId)
      .then(d => { setData(d); setSel(d.factors || {}); setNotes(d.notes || '') })
      .catch(() => setErr('Could not load the risk classification.'))
      .finally(() => setLoading(false))
  }
  useEffect(load, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps

  const catalogue = data?.catalogue || {}
  const bands     = data?.bands || {}
  const factorKeys = Object.keys(catalogue)

  // Live preview — mirrors VendorRiskService::compute()/tierFromScore().
  const preview = useMemo(() => {
    if (!factorKeys.length) return null
    let sum = 0, max = 0, answered = 0
    factorKeys.forEach(k => {
      const opts = catalogue[k].options || {}
      const pts  = Object.values(opts).map(o => o.points)
      max += pts.length ? Math.max(...pts) : 0
      const chosen = sel[k]
      if (chosen && opts[chosen]) { sum += opts[chosen].points; answered++ }
    })
    const score   = max > 0 ? Math.round(sum / max * 100) : 0
    const entries = Object.entries(bands).sort((a, b) => b[1] - a[1])
    const tier    = (entries.find(([, min]) => score >= min) || ['Low'])[0]
    return { score, tier, answered, total: factorKeys.length }
  }, [sel, catalogue, bands, factorKeys])

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const d = await api.vendors.assessRisk(vendorId, { factors: sel, notes: notes || undefined })
      setData(d); setSel(d.factors || {}); setNotes(d.notes || ''); setEditing(false)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save — risk assessment is an admin action.')
    } finally { setSaving(false) }
  }

  if (loading) return <Card><Row><Loader2 size={16} className="rk-spin" style={{ color: '#a78bfa' }} /> <span style={muted}>Loading…</span></Row><Spin /></Card>

  const cfg = tierCfg(data?.level)

  return (
    <Card>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
          <ShieldAlert size={16} style={{ color: '#a78bfa' }} />
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Risk Classification</h2>
        </div>
        {manage && !editing && (
          <button onClick={() => setEditing(true)} style={btnPrimary}>
            {data?.assessed ? 'Re-assess' : 'Assess risk'}
          </button>
        )}
      </div>

      {err && <Banner>{err}</Banner>}

      {/* A note distinguishing this from the performance scorecard. */}
      <p style={{ ...muted, fontSize: 12, margin: '0 0 16px', lineHeight: 1.5 }}>
        Forward-looking risk from engagement factors — separate from the performance scorecard (A–D) on Overview. It sets how closely the vendor is monitored.
      </p>

      {editing ? (
        <Assessment
          catalogue={catalogue} sel={sel} setSel={setSel} notes={notes} setNotes={setNotes}
          preview={preview} saving={saving} onSave={save} onCancel={() => { setEditing(false); setSel(data?.factors || {}); setNotes(data?.notes || '') }}
        />
      ) : !data?.assessed ? (
        <div style={{ padding: '28px 16px', borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center' }}>
          <Gauge size={26} style={{ color: 'var(--text-muted)', opacity: 0.5, marginBottom: 8 }} />
          <p style={{ ...muted, margin: 0, fontSize: 13 }}>This vendor has not been risk-classified yet.{manage ? ' Click “Assess risk” to score it.' : ''}</p>
        </div>
      ) : (
        <Summary data={data} cfg={cfg} />
      )}
      <Spin />
    </Card>
  )
}

/* ── Current classification ────────────────────────────────────────────────── */
function Summary({ data, cfg }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      {/* Tier + score */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap', padding: 16, borderRadius: 14, background: cfg.bg, border: `1px solid ${cfg.color}55` }}>
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', minWidth: 96 }}>
          <span style={{ fontSize: 26, fontWeight: 900, color: cfg.color, lineHeight: 1 }}>{data.level}</span>
          <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginTop: 4 }}>RISK TIER</span>
        </div>
        <div style={{ flex: 1, minWidth: 180 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11.5, color: 'var(--text-muted)', marginBottom: 5 }}>
            <span>Risk score</span><strong style={{ color: 'var(--text-h)' }}>{data.score}/100</strong>
          </div>
          <div style={{ height: 8, borderRadius: 999, background: 'var(--bg-card)', overflow: 'hidden' }}>
            <div style={{ height: '100%', width: `${data.score}%`, background: cfg.color, borderRadius: 999, transition: 'width .3s' }} />
          </div>
        </div>
      </div>

      {data.monitoring && (
        <Row><Eye size={14} style={{ color: cfg.color, flexShrink: 0 }} /><span style={{ fontSize: 12.5, color: 'var(--text-h)' }}><strong>Monitoring:</strong> {data.monitoring}</span></Row>
      )}

      {/* Factor breakdown */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        {data.breakdown.filter(b => b.value).map(b => (
          <div key={b.key} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '9px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>{b.label}</span>
            <span style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)', textAlign: 'right' }}>
              {b.option_label} <span style={{ color: 'var(--text-muted)', fontWeight: 700 }}>· {b.points}/{b.max}</span>
            </span>
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
function Assessment({ catalogue, sel, setSel, notes, setNotes, preview, saving, onSave, onCancel }) {
  const pc = tierCfg(preview?.tier)
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      {Object.entries(catalogue).map(([key, def]) => (
        <div key={key}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6 }}>{def.label}</label>
          <select value={sel[key] || ''} onChange={e => setSel(s => ({ ...s, [key]: e.target.value || undefined }))}
            style={selectStyle}>
            <option value="">— Select —</option>
            {Object.entries(def.options).map(([val, o]) => <option key={val} value={val}>{o.label}</option>)}
          </select>
        </div>
      ))}

      <div>
        <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6 }}>Notes (optional)</label>
        <textarea value={notes} onChange={e => setNotes(e.target.value)} rows={2} placeholder="Rationale, caveats…"
          style={{ ...selectStyle, resize: 'vertical', fontFamily: 'inherit', lineHeight: 1.5 }} />
      </div>

      {/* Live preview */}
      {preview && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 12, borderRadius: 12, background: pc.bg, border: `1px solid ${pc.color}55` }}>
          <span style={{ fontSize: 18, fontWeight: 900, color: pc.color }}>{preview.tier}</span>
          <span style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 700 }}>{preview.score}/100</span>
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)', marginLeft: 'auto' }}>{preview.answered}/{preview.total} factors answered</span>
        </div>
      )}

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
        <button onClick={onCancel} disabled={saving} style={btnGhost}><X size={14} /> Cancel</button>
        <button onClick={onSave} disabled={saving} style={{ ...btnPrimary, opacity: saving ? 0.6 : 1 }}>
          {saving ? <Loader2 size={14} className="rk-spin" /> : <Save size={14} />} {saving ? 'Saving…' : 'Save assessment'}
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
const Spin = () => <style>{`@keyframes rkSpin{to{transform:rotate(360deg)}}.rk-spin{animation:rkSpin .9s linear infinite}`}</style>
const muted = { color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }
const selectStyle = { width: '100%', padding: '9px 11px', borderRadius: 9, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: '#fff', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }

export default VendorRiskPanel
