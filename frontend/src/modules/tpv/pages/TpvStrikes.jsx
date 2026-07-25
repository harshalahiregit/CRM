import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, RefreshCw, Search, ShieldAlert, Ban, RotateCcw, AlertTriangle, Eye, Skull,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import {
  SEVERITIES, severityCfg, STRIKE_LIMIT, workerStatusCfg,
  canApproveTpv, fmtDateTime, fmtDate,
} from '../constants'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

export default function TpvStrikes() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const admin = canApproveTpv(user)

  const [rows, setRows]     = useState([])
  const [stats, setStats]   = useState({})
  const [loading, setLoad]  = useState(true)
  const [search, setSearch] = useState('')
  const [severity, setSeverity] = useState('All')
  const [onlyActive, setOnlyActive] = useState(false)
  const [issuing, setIssuing] = useState(false)
  const [voiding, setVoiding] = useState(null)

  const fetchAll = useCallback(async () => {
    setLoad(true)
    try {
      const [l, s] = await Promise.all([
        tpvApi.strikes.list({ severity: severity === 'All' ? undefined : severity, active: onlyActive ? 'true' : undefined }),
        tpvApi.strikes.stats(),
      ])
      setRows(Array.isArray(l?.data ?? l) ? (l.data ?? l) : [])
      setStats(s?.data ?? s ?? {})
    } catch (e) { console.error('Failed to load strikes', e) }
    finally { setLoad(false) }
  }, [severity, onlyActive])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = rows.filter(r => {
    const q = search.toLowerCase()
    return !q || r.worker?.name?.toLowerCase().includes(q) || r.reason?.toLowerCase().includes(q) || r.worker?.worker_code?.toLowerCase().includes(q)
  })

  const statCards = [
    { label: 'Active',        value: stats.active,       color: '#f59e0b' },
    { label: 'Critical',      value: stats.critical,     color: '#ef4444' },
    { label: 'At Risk',       value: stats.at_risk,      color: '#f97316', hint: `${STRIKE_LIMIT - 1} strikes — one from termination` },
    { label: 'Terminations',  value: stats.terminations, color: '#ef4444' },
    { label: 'Voided',        value: stats.voided,       color: '#10b981' },
    { label: 'Total',         value: stats.total,        color: '#7C3AED' },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Safety Strikes</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>
            {STRIKE_LIMIT} active strikes — or one Critical — terminate site access automatically.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
          {admin && (
            <button onClick={() => setIssuing(true)} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#f59e0b,#d97706)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 8px 20px -6px rgba(245,158,11,.6)' }}>
              <Plus size={15} /> Issue Strike
            </button>
          )}
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6,1fr)', gap: 12, marginBottom: 20 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" title={s.hint} style={{ textAlign: 'center', cursor: 'default' }}>
            <div style={{ fontSize: 24, fontWeight: 900, color: s.color }}>{s.value || 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', borderRadius: 14 }}>
        <div style={{ position: 'relative', flex: 1 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search worker or reason…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={severity} onChange={e => setSeverity(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All severities</option>
          {SEVERITIES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <label style={{ display: 'inline-flex', alignItems: 'center', gap: 7, fontSize: 12.5, color: 'var(--text-muted)', cursor: 'pointer', whiteSpace: 'nowrap' }}>
          <input type="checkbox" checked={onlyActive} onChange={e => setOnlyActive(e.target.checked)} style={{ cursor: 'pointer' }} />
          Active only
        </label>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading strike ledger…</div>
      ) : filtered.length === 0 ? (
        <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#34d399,#10b981)', boxShadow: '0 10px 24px -6px rgba(16,185,129,.6)' }}>
            <ShieldAlert size={26} color="#fff" />
          </div>
          <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>No strikes on record</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>A clean safety ledger. Strikes issued against workers appear here.</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {filtered.map(r => (
            <StrikeRow key={r.id} r={r} admin={admin} onVoid={() => setVoiding(r)} onOpenWorker={() => navigate(`/app/tpv/workforce/${r.tpv_worker_id}`)} />
          ))}
        </div>
      )}

      {issuing && <IssueModal onClose={() => setIssuing(false)} onDone={() => { setIssuing(false); fetchAll() }} />}
      {voiding && <VoidModal strike={voiding} onClose={() => setVoiding(null)} onDone={() => { setVoiding(null); fetchAll() }} />}
    </div>
  )
}

// ── One strike ───────────────────────────────────────────────────────────────
function StrikeRow({ r, admin, onVoid, onOpenWorker }) {
  const cfg = severityCfg(r.severity)
  const voided = !r.is_active
  return (
    <div className="pr-glass pr-lift" style={{ padding: 18, opacity: voided ? 0.62 : 1, border: r.triggered_termination ? '1px solid rgba(239,68,68,0.45)' : undefined }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14 }}>
        <div style={{ width: 40, height: 40, borderRadius: 13, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(145deg, ${cfg.color}, ${cfg.color}bb)`, boxShadow: `0 6px 16px -4px ${cfg.color}99` }}>
          {r.severity === 'Critical' ? <Skull size={18} color="#fff" /> : <ShieldAlert size={18} color="#fff" />}
        </div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 9, flexWrap: 'wrap', marginBottom: 5 }}>
            <StatusPill cfg={cfg} />
            <button onClick={onOpenWorker} style={{ background: 'none', border: 'none', padding: 0, cursor: 'pointer', color: 'var(--text-h)', fontWeight: 800, fontSize: 14 }}>
              {r.worker?.name}
            </button>
            <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11 }}>{r.worker?.worker_code}</span>
            {r.worker?.status && <StatusPill cfg={workerStatusCfg(r.worker.status)} />}
            {voided && <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 10.5, fontWeight: 700, background: 'rgba(16,185,129,0.12)', color: '#10b981', border: '1px solid rgba(16,185,129,0.3)' }}>VOIDED</span>}
            {r.triggered_termination && (
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '2px 8px', borderRadius: 10, fontSize: 10.5, fontWeight: 800, background: 'rgba(239,68,68,0.14)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.35)' }}>
                <Ban size={10} /> TRIGGERED TERMINATION
              </span>
            )}
          </div>
          <div style={{ fontSize: 13.5, color: 'var(--text-h)', fontWeight: 600, marginBottom: 4, textDecoration: voided ? 'line-through' : 'none' }}>{r.reason}</div>
          {r.notes && <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 5 }}>{r.notes}</div>}
          <div style={{ display: 'flex', gap: 14, fontSize: 11.5, color: 'var(--text-muted)', flexWrap: 'wrap' }}>
            <span>{fmtDateTime(r.occurred_at)}</span>
            {r.location && <span>📍 {r.location}</span>}
            {r.issuer?.name && <span>by {r.issuer.name}</span>}
          </div>
          {voided && r.void_reason && (
            <div style={{ marginTop: 8, padding: '8px 11px', borderRadius: 9, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.25)', fontSize: 12, color: '#10b981' }}>
              <strong>Voided:</strong> {r.void_reason}
            </div>
          )}
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0 }}>
          <button onClick={onOpenWorker} style={miniBtn}><Eye size={12} /> Worker</button>
          {admin && !voided && (
            <button onClick={onVoid} style={{ ...miniBtn, color: '#10b981' }}><RotateCcw size={12} /> Void</button>
          )}
        </div>
      </div>
    </div>
  )
}

const miniBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 5, padding: '5px 10px', borderRadius: 8,
  border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)',
  cursor: 'pointer', fontSize: 11.5, fontWeight: 700, whiteSpace: 'nowrap',
}

// ── Issue a strike ───────────────────────────────────────────────────────────
function IssueModal({ onClose, onDone }) {
  const [workers, setWorkers] = useState([])
  const [f, setF] = useState({ worker_id: '', severity: 'Minor', reason: '', notes: '', location: '' })
  const [saving, setSaving] = useState(false)
  const [result, setResult] = useState(null)
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  useEffect(() => {
    // Only workers who can actually be struck — terminated ones are rejected.
    tpvApi.workers.list().then(res => {
      const all = Array.isArray(res?.data ?? res) ? (res.data ?? res) : []
      setWorkers(all.filter(w => w.status !== 'Terminated'))
    }).catch(() => {})
  }, [])

  const chosen = workers.find(w => String(w.id) === String(f.worker_id))
  const isCritical = f.severity === 'Critical'

  const submit = async () => {
    if (!f.worker_id || !f.reason.trim()) { alert('Pick a worker and give a reason.'); return }
    setSaving(true)
    try {
      const res = await tpvApi.strikes.issue(f.worker_id, {
        severity: f.severity, reason: f.reason, notes: f.notes || null, location: f.location || null,
      })
      // Surface the consequence rather than silently closing.
      setResult(res)
    } catch (e) { alert(e?.response?.data?.message || 'Failed to issue strike') }
    finally { setSaving(false) }
  }

  if (result) {
    return (
      <Overlay onClose={onDone} width={460}>
        <div style={{ textAlign: 'center', padding: '8px 0 4px' }}>
          <div style={{ width: 64, height: 64, borderRadius: '50%', margin: '0 auto 14px', display: 'flex', alignItems: 'center', justifyContent: 'center',
            background: result.terminated ? 'linear-gradient(145deg,#f87171,#ef4444)' : 'linear-gradient(145deg,#fbbf24,#f59e0b)',
            boxShadow: `0 12px 30px -8px ${result.terminated ? 'rgba(239,68,68,.7)' : 'rgba(245,158,11,.7)'}` }}>
            {result.terminated ? <Ban size={30} color="#fff" /> : <ShieldAlert size={30} color="#fff" />}
          </div>
          <h3 style={{ margin: '0 0 6px', fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>
            {result.terminated ? 'Strike issued — worker terminated' : 'Strike issued'}
          </h3>
          <p style={{ fontSize: 13.5, color: 'var(--text-muted)', margin: '0 0 14px', lineHeight: 1.5 }}>
            {result.terminated
              ? 'Site access has been revoked and the badge QR destroyed — a retained card will no longer scan.'
              : `${result.active_count} of ${STRIKE_LIMIT} active strikes.`}
          </p>
          {!result.terminated && result.active_count >= STRIKE_LIMIT - 1 && (
            <InfoBox tone="danger">One more strike will terminate this worker's site access automatically.</InfoBox>
          )}
        </div>
        <div style={{ display: 'flex', justifyContent: 'center' }}>
          <button onClick={onDone} style={{ padding: '9px 24px', borderRadius: 9, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Done</button>
        </div>
      </Overlay>
    )
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={560}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Issue Safety Strike</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Strikes revoke site access automatically — the consequence is shown before you confirm.</p>

      {/* Make the consequence unmissable before the user commits. */}
      {isCritical && (
        <InfoBox tone="danger">
          <strong>Critical terminates immediately.</strong> This worker's site access will be revoked on this single
          strike and their badge QR destroyed.
        </InfoBox>
      )}
      {!isCritical && chosen && (chosen.active_strikes ?? null) === null && (
        <InfoBox>Reaching {STRIKE_LIMIT} active strikes terminates site access automatically.</InfoBox>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        <Field label="Worker *" full>
          <SelectInput value={f.worker_id} onChange={set('worker_id')} pairs
            options={[['', 'Select worker…'], ...workers.map(w => [String(w.id), `${w.name} · ${w.worker_code} · ${w.vendor?.company_name || ''}`])]} />
        </Field>
        <Field label="Severity *">
          <SelectInput value={f.severity} onChange={set('severity')} pairs options={SEVERITIES} />
        </Field>
        <Field label="Location"><TextInput value={f.location} onChange={set('location')} placeholder="e.g. Block C, level 3" /></Field>
        <Field label="Reason *" full><TextInput value={f.reason} onChange={set('reason')} placeholder="e.g. Helmet not worn in an active zone" /></Field>
        <Field label="Notes" full><textarea value={f.notes} onChange={set('notes')} rows={2} placeholder="What happened?" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>

      <ModalFooter onClose={onClose} onConfirm={submit} loading={saving} disabled={!f.worker_id || !f.reason.trim()}
        confirmLabel={isCritical ? 'Issue & Terminate' : 'Issue Strike'} color={isCritical ? '#ef4444' : '#f59e0b'} />
    </Overlay>
  )
}

// ── Void a strike ────────────────────────────────────────────────────────────
function VoidModal({ strike, onClose, onDone }) {
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async () => {
    if (!reason.trim()) return
    setSaving(true)
    try { await tpvApi.strikes.void(strike.id, reason); onDone() }
    catch (e) { alert(e?.response?.data?.message || 'Failed to void strike') }
    finally { setSaving(false) }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={480}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        <RotateCcw size={22} color="#10b981" />
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>Void Strike</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 12 }}>
        <strong style={{ color: 'var(--text-h)' }}>{strike.worker?.name}</strong> — {strike.reason}
      </p>
      <InfoBox>
        The strike stays on the ledger as a voided record and stops counting.
        {strike.triggered_termination
          ? ' This strike terminated the worker — voiding it does not restore access; reinstate them explicitly if appropriate.'
          : ''}
      </InfoBox>
      <label style={labelStyle}>Reason for voiding *</label>
      <textarea value={reason} onChange={e => setReason(e.target.value)} rows={3}
        placeholder="e.g. Appeal upheld — CCTV showed the helmet was worn"
        style={{ ...inputStyle, resize: 'vertical', borderColor: !reason ? '#ef444480' : 'var(--border)' }} />
      <ModalFooter onClose={onClose} onConfirm={submit} loading={saving} disabled={!reason.trim()} confirmLabel="Void Strike" color="#10b981" />
    </Overlay>
  )
}
