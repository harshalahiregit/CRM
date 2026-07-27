import { useState, useEffect, useCallback, useRef } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, FileSignature, Send, CheckCircle2, Ban, CornerUpLeft, Upload, Download,
  Calendar, Wallet, Package, AlertTriangle, Loader2, Building2, History,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import {
  CONTRACT_STATUS, contractStatusCfg, contractTypeLabel, fmtMoney, fmtDate,
  canManagePR, canApprovePR,
} from '../constants'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'
import AuditTimeline from '@/components/ui/AuditTimeline'

export default function PurchaseContractDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const manage = canManagePR(user)
  const admin = canApprovePR(user)

  const [c, setC] = useState(null)
  const [loading, setLoad] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const docRef = useRef(null)

  const load = useCallback(() => {
    purchaseApi.contracts.get(id).then(d => { setC(d?.data ?? d); setLoad(false) })
      .catch(e => { if (e?.response?.status === 404) setNotFound(true); setLoad(false) })
  }, [id])
  useEffect(() => { load() }, [load])

  const act = async (fn, confirmMsg) => {
    if (confirmMsg && !confirm(confirmMsg)) return
    setBusy(true); setErr(null)
    try { await fn(); load() }
    catch (e) { setErr(e?.response?.data?.message || 'Action failed.') }
    finally { setBusy(false) }
  }
  const uploadDoc = async (file) => {
    if (!file) return
    setBusy(true); setErr(null)
    try { await purchaseApi.contracts.uploadDocument(id, file); load() }
    catch (e) { setErr(e?.response?.data?.message || 'Upload failed.') }
    finally { setBusy(false) }
  }
  const download = async () => {
    try {
      const res = await fetch(`${import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'}/purchase/contracts/${id}/download`, { headers: { Authorization: `Bearer ${localStorage.getItem('crm_token')}` } })
      const b = await res.blob(); const url = URL.createObjectURL(b)
      const a = document.createElement('a'); a.href = url; a.download = `Contract-${c.contract_number}`; a.click(); URL.revokeObjectURL(url)
    } catch { setErr('Could not download the document.') }
  }

  if (loading) return <Wrap><div className="skeleton" style={{ height: 44, width: 260, borderRadius: 12, background: 'var(--border)', marginBottom: 16 }} /><div className="skeleton" style={{ height: 200, borderRadius: 16, background: 'var(--border)' }} /></Wrap>
  if (notFound || !c) return <Wrap><NotFound onBack={() => navigate('/app/purchase/contracts')} /></Wrap>

  const cfg = contractStatusCfg(c.status)
  const items = c.items || []
  const editable = c.status === CONTRACT_STATUS.DRAFT || c.status === CONTRACT_STATUS.UNDER_REVIEW
  const ceiling = c.spend_ceiling != null ? Number(c.spend_ceiling) : null
  const consumed = Number(c.consumed_amount || 0)
  const pct = ceiling ? Math.min(100, Math.round((consumed / ceiling) * 100)) : 0

  return (
    <Wrap>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18, flexWrap: 'wrap' }}>
        <button onClick={() => navigate('/app/purchase/contracts')} style={backBtn}><ArrowLeft size={16} /></button>
        <div style={{ flex: 1, minWidth: 0 }}>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>{c.contract_number} · {contractTypeLabel(c.type)}</p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>{c.title}</h1>
            <span style={{ padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800 }}>{cfg.label}</span>
            {c.is_expired && c.status === CONTRACT_STATUS.ACTIVE && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, color: '#f59e0b' }}><AlertTriangle size={12} /> Past end date</span>}
          </div>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 5, fontSize: 12.5, color: 'var(--text-muted)' }}>
            <Building2 size={13} /> {c.vendor?.company_name}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 8, flexShrink: 0, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          {manage && c.status === CONTRACT_STATUS.DRAFT && <button onClick={() => act(() => purchaseApi.contracts.submit(id))} disabled={busy} style={actBtn('#0ea5e9', true)}><Send size={14} /> Submit for review</button>}
          {manage && c.status === CONTRACT_STATUS.UNDER_REVIEW && <button onClick={() => act(() => purchaseApi.contracts.returnToDraft(id))} disabled={busy} style={actBtn('#94a3b8')}><CornerUpLeft size={14} /> Return to draft</button>}
          {admin && c.status === CONTRACT_STATUS.UNDER_REVIEW && <button onClick={() => act(() => purchaseApi.contracts.activate(id))} disabled={busy} style={actBtn('#10b981', true)}><CheckCircle2 size={14} /> Activate</button>}
          {admin && [CONTRACT_STATUS.DRAFT, CONTRACT_STATUS.UNDER_REVIEW, CONTRACT_STATUS.ACTIVE].includes(c.status) && <button onClick={() => act(() => purchaseApi.contracts.terminate(id, 'Terminated by admin'), 'Terminate this contract? This cannot be undone.')} disabled={busy} style={actBtn('#ef4444')}><Ban size={14} /> Terminate</button>}
        </div>
      </div>

      {err && <Banner tone="#ef4444" icon={AlertTriangle}>{err}</Banner>}

      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }}>
        {/* Left: rate card + terms */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={Package}>Rate Card <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {items.length} lines</span></SectionTitle>
            {items.length === 0 ? (
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>{c.type === 'msa' ? 'This is a service agreement — no locked rate lines.' : 'No rate lines yet.'}</p>
            ) : (
              <div style={{ overflowX: 'auto', marginTop: 12 }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 440 }}>
                  <thead><tr>{['Item', 'Unit', 'Locked Rate', 'Tax %', 'Qty band'].map((h, i) => <th key={h} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '7px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{h}</th>)}</tr></thead>
                  <tbody>
                    {items.map(it => (
                      <tr key={it.id}>
                        <td style={{ padding: '9px 10px', fontSize: 12.5, color: 'var(--text-h)' }}>{it.description}</td>
                        <td style={{ padding: '9px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.unit || '—'}</td>
                        <td style={{ padding: '9px 10px', fontSize: 12.5, textAlign: 'right', fontWeight: 800, color: '#10b981', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(it.rate, c.currency)}</td>
                        <td style={{ padding: '9px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.tax}%</td>
                        <td style={{ padding: '9px 10px', fontSize: 12, textAlign: 'right', color: 'var(--text-muted)' }}>{it.min_qty || it.max_qty ? `${it.min_qty ?? 0}–${it.max_qty ?? '∞'}` : 'any'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          {c.terms && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <SectionTitle icon={FileSignature}>Terms</SectionTitle>
              <p style={{ fontSize: 13, color: 'var(--text-h)', margin: '10px 0 0', lineHeight: 1.6, whiteSpace: 'pre-wrap' }}>{c.terms}</p>
            </div>
          )}

          {(c.audit_logs || []).length > 0 && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <SectionTitle icon={History}>Activity</SectionTitle>
              <div style={{ marginTop: 12 }}><AuditTimeline entries={c.audit_logs} /></div>
            </div>
          )}
        </div>

        {/* Right: term window, ceiling meter, document */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={Calendar}>Term</SectionTitle>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 12 }}>
              <Meta label="Start" value={fmtDate(c.start_date)} />
              <Meta label="End" value={fmtDate(c.end_date)} />
            </div>
          </div>

          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={Wallet}>Spending</SectionTitle>
            {ceiling === null ? (
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No spend ceiling — uncapped.</p>
            ) : (
              <div style={{ marginTop: 12 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                  <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Consumed</span>
                  <span style={{ fontSize: 11.5, fontWeight: 700, color: c.over_ceiling ? '#ef4444' : 'var(--text-h)' }}>{fmtMoney(consumed, c.currency)} / {fmtMoney(ceiling, c.currency)}</span>
                </div>
                <div className="pr-bar" style={{ height: 10 }}>
                  <span style={{ width: `${pct}%`, background: pct >= 90 ? 'linear-gradient(90deg,#f59e0b,#ef4444)' : 'linear-gradient(90deg,#a78bfa,#7C3AED)' }} />
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 8, fontSize: 12 }}>
                  <span style={{ color: 'var(--text-muted)' }}>Remaining</span>
                  <strong style={{ color: c.remaining < 0 ? '#ef4444' : '#10b981' }}>{fmtMoney(c.remaining, c.currency)}</strong>
                </div>
              </div>
            )}
          </div>

          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={FileSignature}>Agreement Document</SectionTitle>
            {c.document_path ? (
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12, padding: '11px 13px', borderRadius: 11, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.3)' }}>
                <FileSignature size={16} style={{ color: '#10b981', flexShrink: 0 }} />
                <span style={{ fontSize: 12.5, color: 'var(--text-h)', flex: 1 }}>Document attached</span>
                <button onClick={download} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, fontWeight: 700, color: '#a78bfa', background: 'none', border: 'none', cursor: 'pointer' }}><Download size={13} /> Download</button>
                {manage && editable && <label style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)', cursor: 'pointer' }}>Replace<input ref={docRef} type="file" accept=".pdf,.doc,.docx" onChange={e => { uploadDoc(e.target.files?.[0]); e.target.value = '' }} style={{ display: 'none' }} /></label>}
              </div>
            ) : manage && editable ? (
              <label style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 7, marginTop: 12, padding: '20px', borderRadius: 12, cursor: 'pointer', background: 'linear-gradient(150deg, rgba(124,58,237,.1), rgba(124,58,237,.03))', border: '1.5px dashed rgba(124,58,237,.4)' }}>
                {busy ? <Loader2 size={22} style={{ color: '#a78bfa' }} className="ctr-spin" /> : <Upload size={22} style={{ color: '#a78bfa' }} />}
                <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>Upload signed agreement</span>
                <input type="file" accept=".pdf,.doc,.docx" onChange={e => { uploadDoc(e.target.files?.[0]); e.target.value = '' }} disabled={busy} style={{ display: 'none' }} />
              </label>
            ) : (
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No document attached.</p>
            )}
          </div>
        </div>
      </div>
    </Wrap>
  )
}

const Wrap = ({ children }) => <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}><style>{PURCHASE_STYLE}</style><style>{`@keyframes ctrSpin{to{transform:rotate(360deg)}}.ctr-spin{animation:ctrSpin .9s linear infinite}`}</style>{children}</div>
const SectionTitle = ({ icon: Icon, children }) => <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}><Icon size={16} style={{ color: '#a78bfa' }} /><h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{children}</h2></div>
const Meta = ({ label, value }) => <div><div style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 3 }}>{label}</div><div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-h)' }}>{value}</div></div>
const backBtn = { width: 36, height: 36, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 2, flexShrink: 0 }
const actBtn = (color, solid = false) => ({ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, background: solid ? `linear-gradient(145deg, ${color}dd, ${color})` : 'var(--bg-card)', border: solid ? 'none' : `1px solid ${color}55`, color: solid ? '#fff' : color, boxShadow: solid ? `0 8px 18px -6px ${color}88` : 'none' })
const Banner = ({ tone, icon: Icon, children }) => <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 16, background: `${tone}12`, border: `1px solid ${tone}55` }}><Icon size={15} style={{ color: tone, flexShrink: 0 }} /><span style={{ fontSize: 13, color: 'var(--text-h)' }}>{children}</span></div>
const NotFound = ({ onBack }) => (
  <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center', maxWidth: 460, margin: '40px auto' }}>
    <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(148,163,184,0.14)' }}><AlertTriangle size={26} style={{ color: '#94a3b8' }} /></div>
    <h3 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>Contract not found</h3>
    <button onClick={onBack} style={{ marginTop: 16, display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }}><ArrowLeft size={15} /> Back to contracts</button>
  </div>
)
