import { useEffect, useState } from 'react'
import { Loader2, Plus, Trash2, Star, Award as AwardIcon, Share2, Gavel, X } from 'lucide-react'

/**
 * Admin-side Performance panels for the TPV vendor workspace — the mirror of the
 * vendor portal's Performance section. Award/Reward (admin grants), Referral (admin
 * works the prospect), Feedback (the VRS scorecard) and Penalty (this vendor's
 * violations). All take { vendorId, manage, api } like the other vendor panels.
 */

const card = { background: 'var(--bg-card, rgba(255,255,255,0.03))', border: '1px solid var(--border, rgba(148,163,184,0.2))', borderRadius: 12, padding: 16 }
const th = { textAlign: 'left', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted, #9ca3af)', padding: '8px 12px', borderBottom: '1px solid var(--border, rgba(148,163,184,0.2))', whiteSpace: 'nowrap' }
const td = { padding: '10px 12px', borderBottom: '1px solid var(--border, rgba(148,163,184,0.12))', color: 'var(--text-body, #cbd5e1)', fontSize: 13 }
const inp = { background: 'var(--bg-input, rgba(255,255,255,0.05))', border: '1px solid var(--border, rgba(148,163,184,0.3))', borderRadius: 8, padding: '7px 9px', color: 'var(--text-h, #e5e7eb)', fontSize: 13, width: '100%', fontFamily: 'inherit' }
const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, fontSize: 13, fontWeight: 700, cursor: 'pointer', border: '1px solid var(--border, rgba(148,163,184,0.3))', background: 'transparent', color: 'var(--text-h, #e5e7eb)' }
const btnPrimary = { ...btn, background: '#7c3aed', borderColor: '#7c3aed', color: '#fff' }
const date = v => (v ? String(v).slice(0, 10) : '—')
const Spin = () => <div style={{ display: 'flex', justifyContent: 'center', padding: 48 }}><Loader2 className="tpv-spin" size={22} /></div>
const Empty = ({ text }) => <div style={{ ...card, color: 'var(--text-muted, #9ca3af)', fontSize: 14 }}>{text}</div>
const H = ({ children }) => <h3 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h, #e5e7eb)', margin: '0 0 14px' }}>{children}</h3>

/* ── Award / Reward ───────────────────────────────────────────────────────── */
export function VendorAwardsPanel({ vendorId, manage, api }) {
  const [rows, setRows] = useState(null)
  const [f, setF] = useState({ title: '', category: '', description: '', awarded_on: '' })
  const [saving, setSaving] = useState(false)
  const reload = () => api.vendors.awards.list(vendorId).then(d => setRows(d?.data || [])).catch(() => setRows([]))
  useEffect(() => { reload() }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps

  const grant = async () => {
    if (!f.title.trim()) return
    setSaving(true)
    try { await api.vendors.awards.grant(vendorId, f); setF({ title: '', category: '', description: '', awarded_on: '' }); reload() }
    finally { setSaving(false) }
  }
  const del = async (id) => { await api.vendors.awards.delete(vendorId, id).catch(() => {}); reload() }

  return (
    <div>
      <H>Awards & Rewards</H>
      {manage && (
        <div style={{ ...card, marginBottom: 14 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 10 }}>
            <input style={inp} placeholder="Title *" value={f.title} onChange={e => setF({ ...f, title: e.target.value })} />
            <input style={inp} placeholder="Category" value={f.category} onChange={e => setF({ ...f, category: e.target.value })} />
            <input style={inp} type="date" value={f.awarded_on} onChange={e => setF({ ...f, awarded_on: e.target.value })} />
            <input style={inp} placeholder="Description" value={f.description} onChange={e => setF({ ...f, description: e.target.value })} />
          </div>
          <div style={{ marginTop: 10, textAlign: 'right' }}>
            <button style={btnPrimary} disabled={saving || !f.title.trim()} onClick={grant}>{saving ? <Loader2 className="tpv-spin" size={14} /> : <Plus size={14} />} Grant award</button>
          </div>
        </div>
      )}
      {rows === null ? <Spin /> : rows.length === 0 ? <Empty text="No awards granted yet." /> : (
        <div style={{ ...card, padding: '6px 4px' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr><th style={th}>Title</th><th style={th}>Category</th><th style={th}>Awarded</th><th style={th}>By</th>{manage && <th style={th}></th>}</tr></thead>
              <tbody>
                {rows.map(a => (
                  <tr key={a.id}>
                    <td style={{ ...td, color: 'var(--text-h,#e5e7eb)', fontWeight: 700 }}><Star size={13} fill="#f59e0b" color="#f59e0b" style={{ marginRight: 6, verticalAlign: -1 }} />{a.title}</td>
                    <td style={td}>{a.category || '—'}</td>
                    <td style={td}>{date(a.awarded_on)}</td>
                    <td style={td}>{a.granted_by?.name || a.grantedBy?.name || '—'}</td>
                    {manage && <td style={{ ...td, textAlign: 'right' }}><button style={{ ...btn, padding: 6, color: '#ef4444' }} onClick={() => del(a.id)}><Trash2 size={14} /></button></td>}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Referral ─────────────────────────────────────────────────────────────── */
const REFERRAL_STATUSES = ['New', 'Contacted', 'Converted', 'Declined']
export function VendorReferralsPanel({ vendorId, manage, api }) {
  const [rows, setRows] = useState(null)
  const reload = () => api.vendors.referrals.list(vendorId).then(d => setRows(d?.data || [])).catch(() => setRows([]))
  useEffect(() => { reload() }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps
  const setStatus = async (id, status) => { await api.vendors.referrals.setStatus(vendorId, id, status).catch(() => {}); reload() }

  return (
    <div>
      <H>Referrals</H>
      {rows === null ? <Spin /> : rows.length === 0 ? <Empty text="This vendor has not referred anyone yet." /> : (
        <div style={{ ...card, padding: '6px 4px' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr><th style={th}>Company</th><th style={th}>Contact</th><th style={th}>Submitted</th><th style={th}>Status</th></tr></thead>
              <tbody>
                {rows.map(r => (
                  <tr key={r.id}>
                    <td style={{ ...td, color: 'var(--text-h,#e5e7eb)', fontWeight: 700 }}>{r.company_name}</td>
                    <td style={td}>{[r.contact_name, r.contact_email, r.contact_phone].filter(Boolean).join(' · ') || '—'}{r.note ? <div style={{ color: 'var(--text-muted,#9ca3af)', fontSize: 12 }}>{r.note}</div> : null}</td>
                    <td style={td}>{date(r.created_at)}</td>
                    <td style={td}>
                      {manage
                        ? <select style={{ ...inp, padding: '4px 8px', width: 'auto' }} value={r.status} onChange={e => setStatus(r.id, e.target.value)}>{REFERRAL_STATUSES.map(s => <option key={s} value={s}>{s}</option>)}</select>
                        : r.status}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Feedback — the VRS scorecard (read-only) ─────────────────────────────── */
export function VendorFeedbackPanel({ vendorId, api }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => { api.vendors.scorecard(vendorId).then(setData).catch(() => setData(null)).finally(() => setLoading(false)) }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps
  if (loading) return <Spin />
  const live = data?.live || {}
  const dims = live.dimensions || {}
  const overall = Number(live.overall_score ?? 0)
  const tone = overall >= 75 ? '#22c55e' : overall >= 50 ? '#f59e0b' : '#ef4444'
  return (
    <div>
      <H>Performance Feedback (VRS)</H>
      <div style={{ ...card, display: 'flex', gap: 24, alignItems: 'center', flexWrap: 'wrap' }}>
        <div style={{ textAlign: 'center' }}>
          <div style={{ fontSize: 34, fontWeight: 800, color: tone }}>{overall}</div>
          <div style={{ fontSize: 11, color: 'var(--text-muted,#9ca3af)' }}>/ 100{live.band ? ` · Band ${live.band}` : ''}</div>
        </div>
        <div style={{ flex: 1, minWidth: 220 }}>
          {['safety', 'compliance', 'workforce'].map(k => dims[k] && (
            <div key={k} style={{ marginBottom: 10 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 3 }}>
                <span style={{ textTransform: 'capitalize', color: 'var(--text-body,#cbd5e1)' }}>{k}</span>
                <span style={{ fontWeight: 700, color: 'var(--text-h,#e5e7eb)' }}>{Number(dims[k].score ?? 0)}</span>
              </div>
              <div style={{ height: 7, borderRadius: 999, background: 'var(--bg-input,rgba(255,255,255,0.08))', overflow: 'hidden' }}>
                <div style={{ width: `${Math.min(100, Number(dims[k].score ?? 0))}%`, height: '100%', background: tone }} />
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

/* ── Penalty — this vendor's violations ───────────────────────────────────── */
export function VendorPenaltyPanel({ vendorId, api }) {
  const [rows, setRows] = useState(null)
  useEffect(() => { api.vendors.violations(vendorId).then(d => setRows(d?.data || [])).catch(() => setRows([])) }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps
  const points = (rows || []).reduce((s, v) => s + (Number(v.points) || 0), 0)
  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
        <H>Penalty & Violations</H>
        {rows && <div style={{ textAlign: 'right' }}><div style={{ fontSize: 10.5, textTransform: 'uppercase', color: 'var(--text-muted,#9ca3af)' }}>Penalty points</div><div style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h,#e5e7eb)' }}>{points}</div></div>}
      </div>
      {rows === null ? <Spin /> : rows.length === 0 ? <Empty text="No violations on record." /> : (
        <div style={{ ...card, padding: '6px 4px' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr><th style={th}>Ref</th><th style={th}>Type</th><th style={th}>Severity</th><th style={th}>Date</th><th style={{ ...th, textAlign: 'right' }}>Points</th><th style={th}>Status</th></tr></thead>
              <tbody>
                {rows.map(v => (
                  <tr key={v.id}>
                    <td style={{ ...td, color: 'var(--text-h,#e5e7eb)', fontWeight: 700 }}>{v.reference || '—'}</td>
                    <td style={td}>{v.type || '—'}</td><td style={td}>{v.severity || '—'}</td>
                    <td style={td}>{date(v.occurred_at)}</td>
                    <td style={{ ...td, textAlign: 'right', fontWeight: 700 }}>{v.points ?? 0}</td>
                    <td style={td}>{v.status || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
