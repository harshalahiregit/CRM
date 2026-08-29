import { useEffect, useState } from 'react'
import { Loader2, ShieldAlert, Gavel, Award as AwardIcon, Plus, X, Send, Star } from 'lucide-react'
import { portalApi } from '@/services/portalApi'

/**
 * TPV portal — Performance section. Mostly read-only (Risk, Feedback, Penalty,
 * Award are the vendor's own standing, set by admins); Referral is the one write
 * (the vendor submits companies it recommends). Nothing here lets a vendor change
 * its own score — assessment/awards/violations are admin authority.
 */
export default function MyPerformance({ view, api = portalApi }) {
  switch (view) {
    case 'penalty':  return <Penalty api={api} />
    case 'feedback': return <Feedback api={api} />
    case 'award':    return <Awards api={api} />
    case 'referral': return <Referrals api={api} />
    default:         return <Risk api={api} />
  }
}

const TIER_TONE = { critical: '#ef4444', high: '#f59e0b', medium: '#3b82f6', low: '#22c55e' }

function Risk({ api }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => { api.performance.risk().then(setData).finally(() => setLoading(false)) }, [])

  if (loading) return <Center><Loader2 className="mp-spin" size={22} /></Center>

  const assessed = data?.assessed && data?.level
  const tone = TIER_TONE[String(data?.level || '').toLowerCase()] || '#94a3b8'
  const score = Number(data?.score ?? 0)
  const breakdown = Array.isArray(data?.breakdown) ? data.breakdown : []

  return (
    <Wrap>
      <style>{CSS}</style>
      <h2 className="mp-h2">Risk Score</h2>
      {!assessed ? (
        <div className="mp-card mp-empty"><ShieldAlert size={22} style={{ opacity: 0.6 }} /> Your risk profile has not been assessed yet. It will appear here once the review team completes it.</div>
      ) : (
        <>
          <div className="mp-card" style={{ display: 'flex', gap: 24, alignItems: 'center', flexWrap: 'wrap' }}>
            <Gauge score={score} tone={tone} />
            <div style={{ flex: 1, minWidth: 180 }}>
              <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--text-muted)' }}>Risk Tier</div>
              <div style={{ fontSize: 26, fontWeight: 800, color: tone, textTransform: 'capitalize' }}>{data.level}</div>
              {data.monitoring && <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>Monitoring: <strong style={{ color: 'var(--text-h)' }}>{data.monitoring}</strong></div>}
              {data.assessed_at && <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>Assessed {String(data.assessed_at).slice(0, 10)}</div>}
            </div>
          </div>

          {breakdown.length > 0 && (
            <div className="mp-card" style={{ marginTop: 14 }}>
              <div className="mp-cardhead">Factors considered</div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {breakdown.map((b, i) => (
                  <div key={i} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, fontSize: 13 }}>
                    <span style={{ color: 'var(--text-body,#cbd5e1)' }}>{b.label || b.factor || b.name || `Factor ${i + 1}`}</span>
                    <span style={{ color: 'var(--text-h)', fontWeight: 700 }}>{b.points ?? b.score ?? b.value ?? ''}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </Wrap>
  )
}

function Gauge({ score, tone }) {
  const pct = Math.max(0, Math.min(100, score))
  const deg = pct * 3.6
  return (
    <div style={{ width: 130, height: 130, borderRadius: '50%', background: `conic-gradient(${tone} ${deg}deg, var(--bg-input,rgba(255,255,255,0.06)) ${deg}deg)`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div style={{ width: 100, height: 100, borderRadius: '50%', background: 'var(--bg-card,#14161c)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
        <div style={{ fontSize: 30, fontWeight: 800, color: 'var(--text-h)' }}>{pct}</div>
        <div style={{ fontSize: 10, textTransform: 'uppercase', color: 'var(--text-muted)' }}>/ 100</div>
      </div>
    </div>
  )
}

const SEV_TONE = { critical: '#ef4444', major: '#f59e0b', minor: '#3b82f6', low: '#22c55e' }
function Penalty({ api }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => { api.performance.violations().then(setData).finally(() => setLoading(false)) }, [])
  if (loading) return <Center><Loader2 className="mp-spin" size={22} /></Center>

  const rows = data?.data || []
  return (
    <Wrap>
      <style>{CSS}</style>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
        <h2 className="mp-h2" style={{ margin: 0 }}>Penalty & Violations</h2>
        <div style={{ display: 'flex', gap: 18 }}>
          <Stat label="Penalty Points" value={data?.total_points ?? 0} />
          <Stat label="Open" value={data?.open_count ?? 0} />
        </div>
      </div>
      {rows.length === 0 ? (
        <div className="mp-card mp-empty" style={{ marginTop: 14 }}><Gavel size={22} style={{ opacity: 0.6 }} /> No violations on record. Keep it up.</div>
      ) : (
        <div className="mp-card" style={{ marginTop: 14, padding: '6px 4px' }}>
          <div style={{ overflowX: 'auto' }}>
            <table className="mp-table">
              <thead><tr><th>Ref</th><th>Type</th><th>Severity</th><th>Date</th><th style={{ textAlign: 'right' }}>Points</th><th>Status</th></tr></thead>
              <tbody>
                {rows.map(v => (
                  <tr key={v.id}>
                    <td style={{ fontWeight: 700, color: 'var(--text-h)' }}>{v.reference || '—'}</td>
                    <td>{v.type || '—'}</td>
                    <td><span style={{ color: SEV_TONE[String(v.severity || '').toLowerCase()] || '#94a3b8', fontWeight: 700 }}>{v.severity || '—'}</span></td>
                    <td>{v.occurred_at ? String(v.occurred_at).slice(0, 10) : '—'}</td>
                    <td style={{ textAlign: 'right', fontWeight: 700 }}>{v.points ?? 0}</td>
                    <td>{v.status || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </Wrap>
  )
}

/* ── Feedback — the vendor's own performance rating (read-only) ─────────── */
function Feedback({ api }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => { api.performance.feedback().then(setData).finally(() => setLoading(false)) }, [])
  if (loading) return <Center><Loader2 className="mp-spin" size={22} /></Center>

  const live = data?.live || {}
  const overall = Number(live.overall_score ?? 0)
  const tone = overall >= 75 ? '#22c55e' : overall >= 50 ? '#f59e0b' : '#ef4444'
  const dims = live.dimensions || {}

  return (
    <Wrap>
      <style>{CSS}</style>
      <h2 className="mp-h2">Performance Feedback</h2>
      <div className="mp-card" style={{ display: 'flex', gap: 24, alignItems: 'center', flexWrap: 'wrap' }}>
        <Gauge score={overall} tone={tone} />
        <div style={{ flex: 1, minWidth: 180 }}>
          <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--text-muted)' }}>Overall Rating</div>
          {live.band && <div style={{ fontSize: 26, fontWeight: 800, color: tone }}>Band {live.band}</div>}
          <div style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 4 }}>Your live performance score, updated from safety, compliance and workforce.</div>
        </div>
      </div>
      <div className="mp-card" style={{ marginTop: 14 }}>
        <div className="mp-cardhead">By dimension</div>
        {['safety', 'compliance', 'workforce'].map(k => dims[k] && (
          <Bar key={k} label={k} score={Number(dims[k].score ?? 0)} />
        ))}
        {Object.keys(dims).length === 0 && <div style={{ color: 'var(--text-muted)', fontSize: 13 }}>No dimension breakdown available yet.</div>}
      </div>
    </Wrap>
  )
}
function Bar({ label, score }) {
  const pct = Math.max(0, Math.min(100, score))
  const tone = pct >= 75 ? '#22c55e' : pct >= 50 ? '#f59e0b' : '#ef4444'
  return (
    <div style={{ marginBottom: 12 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 4 }}>
        <span style={{ textTransform: 'capitalize', color: 'var(--text-body,#cbd5e1)' }}>{label}</span>
        <span style={{ fontWeight: 700, color: 'var(--text-h)' }}>{pct}</span>
      </div>
      <div style={{ height: 8, borderRadius: 999, background: 'var(--bg-input,rgba(255,255,255,0.06))', overflow: 'hidden' }}>
        <div style={{ width: `${pct}%`, height: '100%', background: tone, borderRadius: 999 }} />
      </div>
    </div>
  )
}

/* ── Award / Reward — read-only recognitions the vendor earned ──────────── */
function Awards({ api }) {
  const [rows, setRows] = useState(null)
  useEffect(() => { api.performance.awards().then(d => setRows(d?.data || [])).catch(() => setRows([])) }, [])
  if (rows === null) return <Center><Loader2 className="mp-spin" size={22} /></Center>

  return (
    <Wrap>
      <style>{CSS}</style>
      <h2 className="mp-h2">Awards & Rewards</h2>
      {rows.length === 0 ? (
        <div className="mp-card mp-empty"><AwardIcon size={22} style={{ opacity: 0.6 }} /> No awards yet. Consistent performance earns recognition here.</div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(260px,1fr))', gap: 12 }}>
          {rows.map(a => (
            <div key={a.id} className="mp-card">
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <Star size={16} fill="#f59e0b" color="#f59e0b" />
                <span style={{ fontWeight: 700, color: 'var(--text-h)', flex: 1 }}>{a.title}</span>
              </div>
              {a.category && <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>{a.category}</div>}
              {a.description && <div style={{ fontSize: 12.5, color: 'var(--text-body,#cbd5e1)', marginTop: 8, lineHeight: 1.5 }}>{a.description}</div>}
              {a.awarded_on && <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 8 }}>Awarded {String(a.awarded_on).slice(0, 10)}</div>}
            </div>
          ))}
        </div>
      )}
    </Wrap>
  )
}

/* ── Referral — the vendor submits companies it recommends (write) ──────── */
function Referrals({ api }) {
  const [rows, setRows] = useState(null)
  const [adding, setAdding] = useState(false)
  const reload = () => api.performance.referrals().then(d => setRows(d?.data || [])).catch(() => setRows([]))
  useEffect(() => { reload() }, [])

  return (
    <Wrap>
      <style>{CSS}</style>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, gap: 12, flexWrap: 'wrap' }}>
        <h2 className="mp-h2" style={{ margin: 0 }}>Referrals</h2>
        <button className="mp-btn mp-btn-primary" onClick={() => setAdding(true)}><Plus size={15} /> Refer a company</button>
      </div>
      {rows === null ? <Center><Loader2 className="mp-spin" size={22} /></Center>
        : rows.length === 0 ? <div className="mp-card mp-empty">No referrals yet. Recommend a company you trust — we’ll take it from there.</div>
        : (
          <div className="mp-card" style={{ padding: '6px 4px' }}>
            <div style={{ overflowX: 'auto' }}>
              <table className="mp-table">
                <thead><tr><th>Company</th><th>Contact</th><th>Submitted</th><th>Status</th></tr></thead>
                <tbody>
                  {rows.map(r => (
                    <tr key={r.id}>
                      <td style={{ fontWeight: 700, color: 'var(--text-h)' }}>{r.company_name}</td>
                      <td>{[r.contact_name, r.contact_email, r.contact_phone].filter(Boolean).join(' · ') || '—'}</td>
                      <td>{r.created_at ? String(r.created_at).slice(0, 10) : '—'}</td>
                      <td>{r.status}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      {adding && <ReferralForm api={api} onClose={() => setAdding(false)} onDone={() => { setAdding(false); reload() }} />}
    </Wrap>
  )
}
function ReferralForm({ api, onClose, onDone }) {
  const [form, setForm] = useState({ company_name: '', contact_name: '', contact_email: '', contact_phone: '', note: '' })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const set = (k, v) => setForm(s => ({ ...s, [k]: v }))
  const submit = async () => {
    setError('')
    if (!form.company_name.trim()) { setError('Company name is required.'); return }
    setSaving(true)
    try { await api.performance.submitReferral(form); onDone() }
    catch (e) { setError(e?.response?.data?.message || 'Could not submit the referral.') }
    finally { setSaving(false) }
  }
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 560, background: 'var(--bg-card,#14161c)', border: '1px solid var(--border,rgba(255,255,255,0.1))', borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 18px', borderBottom: '1px solid var(--border,rgba(255,255,255,0.08))' }}>
          <Send size={16} /><strong style={{ color: 'var(--text-h)', flex: 1 }}>Refer a company</strong>
          <button onClick={onClose} className="mp-icon"><X size={16} /></button>
        </div>
        <div style={{ padding: 18, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 12 }}>
          <Field label="Company name *" value={form.company_name} onChange={v => set('company_name', v)} />
          <Field label="Contact name" value={form.contact_name} onChange={v => set('contact_name', v)} />
          <Field label="Contact email" type="email" value={form.contact_email} onChange={v => set('contact_email', v)} />
          <Field label="Contact phone" value={form.contact_phone} onChange={v => set('contact_phone', v)} />
          <label style={{ gridColumn: '1 / -1', fontSize: 12, color: 'var(--text-muted)' }}>Note<textarea value={form.note} onChange={e => set('note', e.target.value)} className="mp-input" rows={3} style={{ marginTop: 4, resize: 'vertical' }} /></label>
        </div>
        {error && <div style={{ padding: '0 18px', color: '#ef4444', fontSize: 13 }}>{error}</div>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, padding: 18 }}>
          <button onClick={onClose} className="mp-btn">Cancel</button>
          <button onClick={submit} disabled={saving} className="mp-btn mp-btn-primary">{saving ? <Loader2 className="mp-spin" size={14} /> : <Send size={14} />} Submit</button>
        </div>
      </div>
    </div>
  )
}
function Field({ label, value, onChange, type = 'text' }) {
  return <label style={{ fontSize: 12, color: 'var(--text-muted)' }}>{label}<input type={type} value={value} onChange={e => onChange(e.target.value)} className="mp-input" style={{ marginTop: 4 }} /></label>
}

function Stat({ label, value }) {
  return (
    <div style={{ textAlign: 'right' }}>
      <div style={{ fontSize: 10.5, textTransform: 'uppercase', color: 'var(--text-muted)' }}>{label}</div>
      <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>{value}</div>
    </div>
  )
}
function Wrap({ children }) { return <div style={{ maxWidth: 760, margin: '0 auto' }}>{children}</div> }
function Center({ children }) { return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}>{children}</div> }

const CSS = `
.mp-h2 { font-size: 18px; font-weight: 800; color: var(--text-h); margin: 0 0 16px; }
.mp-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 18px; }
.mp-cardhead { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 12px; }
.mp-empty { display: flex; align-items: center; gap: 10px; color: var(--text-muted); font-size: 14px; }
.mp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mp-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); padding: 10px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); white-space: nowrap; }
.mp-table td { padding: 11px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.05)); color: var(--text-body, #cbd5e1); }
.mp-table tbody tr:last-child td { border-bottom: none; }
.mp-input { display: block; width: 100%; background: var(--bg-input, rgba(255,255,255,0.05)); border: 1px solid var(--border, rgba(255,255,255,0.12)); border-radius: 8px; padding: 7px 9px; color: var(--text-h); font-size: 13px; font-family: inherit; }
.mp-input:focus { outline: none; border-color: var(--portal-purple, #7c3aed); }
.mp-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 9px; font-size: 13px; font-weight: 700; cursor: pointer; border: 1px solid var(--border, rgba(255,255,255,0.14)); background: transparent; color: var(--text-h); }
.mp-btn:hover { background: var(--bg-input, rgba(255,255,255,0.05)); }
.mp-btn-primary { background: var(--portal-purple, #7c3aed); border-color: var(--portal-purple, #7c3aed); color: #fff; }
.mp-btn-primary:disabled { opacity: 0.6; cursor: default; }
.mp-icon { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 4px; border-radius: 6px; }
.mp-icon:hover { color: var(--text-h); }
.mp-spin { animation: mp-spin 0.9s linear infinite; }
@keyframes mp-spin { to { transform: rotate(360deg); } }
`
