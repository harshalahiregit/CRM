import { useState, useEffect, useMemo } from 'react'
import { ShieldCheck, AlertTriangle, Loader2, Save, X, CalendarClock } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'

/**
 * Purchase vendor Due-Diligence — the Purchase-side mirror of the TPV
 * VendorDueDiligenceController's checklist.
 *
 * A per-vendor verification record: company / document / licence / insurance
 * verification, background & reference checks, plus previous performance,
 * incident and compliance history. Each check carries a state (Pending /
 * Verified / Failed / Not Applicable); the record rolls up to a single
 * Cleared / Rejected outcome. Managers record the verification (admin-only
 * server-side). 100% Purchase-owned: hits only /api/purchase/vendors/*.
 */
const CHECK_LABELS = {
  company_verification:   'Company verification',
  document_verification:  'Document verification',
  licence_verification:   'Licence verification',
  insurance_verification: 'Insurance verification',
  background_check:       'Background check',
  reference_check:        'Reference check',
  previous_performance:   'Previous performance',
  incident_history:       'Incident history',
  compliance_history:     'Compliance history',
}
const STATE_LABELS = { Pending: 'Pending', Verified: 'Verified', Failed: 'Failed', Not_Applicable: 'Not applicable' }

const STATUS_CFG = {
  Cleared:     { label: 'Cleared',     color: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  Rejected:    { label: 'Rejected',    color: '#ef4444', bg: 'rgba(239,68,68,0.14)' },
  In_Progress: { label: 'In Progress', color: '#f59e0b', bg: 'rgba(245,158,11,0.14)' },
  Pending:     { label: 'Pending',     color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' },
}
const statusCfg = (s) => STATUS_CFG[s] || STATUS_CFG.Pending
const STATE_DOT = { Pending: '#94a3b8', Verified: '#10b981', Failed: '#ef4444', Not_Applicable: '#64748b' }

const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }) : '—')

// Mirrors PurchaseDueDiligence::deriveStatus() so the preview matches the server.
function deriveStatus(checkStates, states) {
  const values = states.map(c => checkStates[c] || 'Pending')
  if (values.includes('Failed')) return 'Rejected'
  const actionable = values.filter(s => s !== 'Not_Applicable')
  if (actionable.length && !actionable.includes('Pending')) return 'Cleared'
  return values.includes('Verified') ? 'In_Progress' : 'Pending'
}

export function PurchaseDueDiligencePanel({ vendorId, manage }) {
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr]         = useState(null)
  const [editing, setEditing] = useState(false)
  const [checks, setChecks]   = useState({})
  const [notes, setNotes]     = useState('')
  const [saving, setSaving]   = useState(false)

  const CHECKS = data?.checks || []
  const STATES = data?.states || ['Pending', 'Verified', 'Failed', 'Not_Applicable']

  const hydrate = (d) => {
    const rec = d.record || {}
    const next = {}
    ;(d.checks || []).forEach(c => { next[c] = rec[c] || 'Pending' })
    setChecks(next); setNotes(rec.notes || '')
  }

  const load = () => {
    setLoading(true); setErr(null)
    purchaseApi.vendors.dueDiligence(vendorId)
      .then(d => { setData(d); hydrate(d) })
      .catch(() => setErr('Could not load the due-diligence checklist.'))
      .finally(() => setLoading(false))
  }
  useEffect(load, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps

  const record = data?.record
  const preview = useMemo(() => deriveStatus(checks, CHECKS), [checks, CHECKS])

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = { ...checks, notes: notes || undefined }
      const rec = await purchaseApi.vendors.saveDueDiligence(vendorId, payload)
      const next = { ...data, record: rec }
      setData(next); hydrate(next); setEditing(false)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save — due-diligence is an admin action.')
    } finally { setSaving(false) }
  }

  if (loading) return <Card><Row><Loader2 size={16} className="dd-spin" style={{ color: '#a78bfa' }} /> <span style={muted}>Loading…</span></Row><Spin /></Card>

  const cfg = statusCfg(record?.status)

  return (
    <Card>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
          <ShieldCheck size={16} style={{ color: '#a78bfa' }} />
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Due Diligence</h2>
        </div>
        {manage && !editing && (
          <button onClick={() => setEditing(true)} style={btnPrimary}>{record ? 'Update checks' : 'Record checks'}</button>
        )}
      </div>

      {err && <Banner>{err}</Banner>}

      <p style={{ ...muted, fontSize: 12, margin: '0 0 16px', lineHeight: 1.5 }}>
        Company, document, licence &amp; insurance verification, background and reference checks, and prior
        performance / incident / compliance history. Any failed check rejects; all verified clears.
      </p>

      {editing ? (
        <Editor
          checks={checks} setChecks={setChecks} states={STATES} checkKeys={CHECKS}
          notes={notes} setNotes={setNotes} preview={preview} saving={saving} onSave={save}
          onCancel={() => { setEditing(false); hydrate(data) }}
        />
      ) : !record ? (
        <div style={{ padding: '28px 16px', borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center' }}>
          <ShieldCheck size={26} style={{ color: 'var(--text-muted)', opacity: 0.5, marginBottom: 8 }} />
          <p style={{ ...muted, margin: 0, fontSize: 13 }}>No due-diligence has been recorded for this vendor yet.{manage ? ' Click “Record checks” to start.' : ''}</p>
        </div>
      ) : (
        <Summary record={record} cfg={cfg} checkKeys={CHECKS} />
      )}
      <Spin />
    </Card>
  )
}

/* ── Current outcome ───────────────────────────────────────────────────────── */
function Summary({ record, cfg, checkKeys }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap', padding: 16, borderRadius: 14, background: cfg.bg, border: `1px solid ${cfg.color}55` }}>
        <span style={{ fontSize: 20, fontWeight: 900, color: cfg.color, lineHeight: 1.1 }}>{cfg.label}</span>
        <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)' }}>OVERALL OUTCOME</span>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        {checkKeys.map(c => {
          const st = record[c] || 'Pending'
          return (
            <div key={c} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
              <span style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{CHECK_LABELS[c] || c}</span>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7, fontSize: 12, color: 'var(--text-muted)', fontWeight: 700 }}>
                <span style={{ width: 8, height: 8, borderRadius: 999, background: STATE_DOT[st] || '#94a3b8', flexShrink: 0 }} />
                {STATE_LABELS[st] || st}
              </span>
            </div>
          )
        })}
      </div>

      {record.notes && (
        <div style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.5, padding: '10px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <span style={{ color: 'var(--text-muted)', fontWeight: 700 }}>Findings / notes: </span>{record.notes}
        </div>
      )}

      <Row><CalendarClock size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} /><span style={{ ...muted, fontSize: 11.5 }}>Last verified · {fmtDateTime(record.verified_at)}</span></Row>
    </div>
  )
}

/* ── Editor ────────────────────────────────────────────────────────────────── */
function Editor({ checks, setChecks, states, checkKeys, notes, setNotes, preview, saving, onSave, onCancel }) {
  const pc = statusCfg(preview)
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        {checkKeys.map(c => (
          <div key={c} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
            <label style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-muted)' }}>{CHECK_LABELS[c] || c}</label>
            <select value={checks[c] || 'Pending'} onChange={e => setChecks(s => ({ ...s, [c]: e.target.value }))} style={{ ...selectStyle, maxWidth: 200 }}>
              {states.map(st => <option key={st} value={st}>{STATE_LABELS[st] || st}</option>)}
            </select>
          </div>
        ))}
      </div>

      <div>
        <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6 }}>Findings / notes (optional)</label>
        <textarea value={notes} onChange={e => setNotes(e.target.value)} rows={2} placeholder="Evidence, referee responses, observations…" style={{ ...selectStyle, resize: 'vertical', fontFamily: 'inherit', lineHeight: 1.5 }} />
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 12, borderRadius: 12, background: pc.bg, border: `1px solid ${pc.color}55` }}>
        <span style={{ fontSize: 15, fontWeight: 900, color: pc.color }}>{pc.label}</span>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)', marginLeft: 'auto' }}>Overall outcome preview</span>
      </div>

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
        <button onClick={onCancel} disabled={saving} style={btnGhost}><X size={14} /> Cancel</button>
        <button onClick={onSave} disabled={saving} style={{ ...btnPrimary, opacity: saving ? 0.6 : 1 }}>
          {saving ? <Loader2 size={14} className="dd-spin" /> : <Save size={14} />} {saving ? 'Saving…' : 'Save checklist'}
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
const Spin = () => <style>{`@keyframes ddSpin{to{transform:rotate(360deg)}}.dd-spin{animation:ddSpin .9s linear infinite}`}</style>
const muted = { color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }
const selectStyle = { width: '100%', padding: '9px 11px', borderRadius: 9, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: '#fff', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }

export default PurchaseDueDiligencePanel
