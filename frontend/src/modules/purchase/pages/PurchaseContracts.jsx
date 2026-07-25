import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, RefreshCw, FileSignature, Clock, CheckCircle, AlertTriangle, ArrowRight,
  Trash2, X,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import {
  CONTRACT_STATUS, contractStatusCfg, CONTRACT_STAGES, CONTRACT_TYPES, contractTypeLabel,
  fmtMoney, fmtDate, canManagePR,
} from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, Field, TextInput, SelectInput,
} from '@/components/ui/kit3d'

const EMPTY_LINE = { description: '', unit: '', rate: '', tax: 18 }

export default function PurchaseContracts() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const manage = canManagePR(user)
  const [rows, setRows] = useState([])
  const [stats, setStats] = useState({})
  const [loading, setLoad] = useState(true)
  const [filter, setFilter] = useState('All')
  const [showNew, setShowNew] = useState(false)

  const load = useCallback(() => {
    setLoad(true)
    Promise.all([
      purchaseApi.contracts.list(filter === 'All' ? {} : { status: filter }),
      purchaseApi.contracts.stats(),
    ]).then(([list, s]) => { setRows(list?.data ?? list ?? []); setStats(s?.data ?? s ?? {}); setLoad(false) })
      .catch(() => setLoad(false))
  }, [filter])
  useEffect(() => { load() }, [load])

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>AGREEMENTS</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Contracts</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Master agreements & rate contracts your POs can draw pre-negotiated pricing from.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          {manage && <button onClick={() => setShowNew(true)} style={solidBtn}><Plus size={15} /> New Contract</button>}
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 18 }}>
        <Kpi label="Draft" value={stats.draft} icon={FileSignature} color="#94a3b8" onClick={() => setFilter(CONTRACT_STATUS.DRAFT)} />
        <Kpi label="Under Review" value={stats.under_review} icon={Clock} color="#f59e0b" onClick={() => setFilter(CONTRACT_STATUS.UNDER_REVIEW)} />
        <Kpi label="Active" value={stats.active} icon={CheckCircle} color="#10b981" onClick={() => setFilter(CONTRACT_STATUS.ACTIVE)} />
        <Kpi label="Expiring ≤30d" value={stats.expiring_soon} icon={AlertTriangle} color="#f59e0b" danger={stats.expiring_soon > 0} />
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        {['All', CONTRACT_STATUS.DRAFT, CONTRACT_STATUS.UNDER_REVIEW, CONTRACT_STATUS.ACTIVE, CONTRACT_STATUS.EXPIRED, CONTRACT_STATUS.TERMINATED].map(fv => {
          const on = filter === fv
          const label = fv === 'All' ? 'All' : contractStatusCfg(fv).label
          return <button key={fv} onClick={() => setFilter(fv)} style={{ padding: '6px 14px', borderRadius: 999, fontSize: 12.5, fontWeight: 700, cursor: 'pointer', background: on ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'var(--bg-card)', border: on ? 'none' : '1px solid var(--border)', color: on ? '#fff' : 'var(--text-muted)', boxShadow: on ? '0 6px 16px -6px rgba(124,58,237,.6)' : 'none' }}>{label}</button>
        })}
      </div>

      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>{[1, 2, 3].map(i => <div key={i} className="skeleton" style={{ height: 84, borderRadius: 16, background: 'var(--border)' }} />)}</div>
      ) : rows.length === 0 ? (
        <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}><FileSignature size={28} style={{ color: '#a78bfa' }} /></div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{filter === 'All' ? 'No contracts yet' : `No ${contractStatusCfg(filter).label} contracts`}</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>Define an MSA or a rate contract to lock in pre-negotiated pricing.</p>
          {manage && filter === 'All' && <button onClick={() => setShowNew(true)} style={{ ...solidBtn, margin: '0 auto' }}><Plus size={15} /> New Contract</button>}
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>{rows.map(r => <Row key={r.id} r={r} onClick={() => navigate(`/app/purchase/contracts/${r.id}`)} />)}</div>
      )}

      {showNew && <NewContractModal onClose={() => setShowNew(false)} onDone={(id) => { setShowNew(false); navigate(`/app/purchase/contracts/${id}`) }} />}
    </div>
  )
}

function Kpi({ label, value, icon: Icon, color, onClick, danger }) {
  return (
    <div className="pr-kpi" onClick={onClick} style={{ padding: 16, cursor: 'pointer', outline: danger ? `1.5px solid ${color}66` : 'none' }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f` }}><Icon size={18} style={{ color }} /></div>
      <div style={{ fontSize: 24, fontWeight: 900, color: 'var(--text-h)', marginTop: 11, lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}

function Row({ r, onClick }) {
  const cfg = contractStatusCfg(r.status)
  const expiringSoon = r.status === 'Active' && r.end_date && (new Date(r.end_date) - new Date()) < 30 * 864e5 && new Date(r.end_date) >= new Date()
  return (
    <div className="pr-glass pr-lift" onClick={onClick} style={{ padding: 16, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 16 }}>
      <div style={{ width: 46, height: 46, borderRadius: 14, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${cfg.color}18`, border: `1px solid ${cfg.color}44` }}><FileSignature size={20} style={{ color: cfg.color }} /></div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ fontSize: 13, fontWeight: 800, color: '#a78bfa' }}>{r.contract_number}</span>
          <span style={{ fontSize: 14, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{r.title}</span>
          <span style={{ padding: '2px 8px', borderRadius: 999, background: 'var(--bg-input)', border: '1px solid var(--border)', fontSize: 10, fontWeight: 700, color: 'var(--text-muted)' }}>{contractTypeLabel(r.type)}</span>
        </div>
        <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>
          {r.vendor?.company_name && <span>{r.vendor.company_name}</span>}
          {r.end_date && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: expiringSoon ? '#f59e0b' : 'var(--text-muted)' }}><Clock size={12} /> {fmtDate(r.start_date)} – {fmtDate(r.end_date)}</span>}
          {r.spend_ceiling && <span>ceiling {fmtMoney(r.spend_ceiling, r.currency)}</span>}
          <span>{r.items_count ?? 0} rate lines</span>
        </div>
      </div>
      {expiringSoon && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 9px', borderRadius: 999, fontSize: 10.5, fontWeight: 800, background: 'rgba(245,158,11,0.14)', color: '#f59e0b' }}><AlertTriangle size={11} /> Expiring</span>}
      <span style={{ padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800, flexShrink: 0 }}>{cfg.label}</span>
      <ArrowRight size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
    </div>
  )
}

// ── New contract modal ────────────────────────────────────────────────────────
function NewContractModal({ onClose, onDone }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({ title: '', type: 'rate_contract', vendor_id: '', start_date: '', end_date: '', spend_ceiling: '', terms: '' })
  const [lines, setLines] = useState([{ ...EMPTY_LINE }])
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  useEffect(() => { purchaseApi.vendors.list().then(r => setVendors(r?.data ?? r ?? [])).catch(() => {}) }, [])
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const setLine = (i, k, v) => setLines(prev => prev.map((l, idx) => idx === i ? { ...l, [k]: v } : l))
  const isRate = f.type === 'rate_contract'

  const save = async () => {
    if (!f.title.trim()) { setErr('Enter a title.'); return }
    if (!f.vendor_id) { setErr('Choose a vendor.'); return }
    const cleanLines = lines.filter(l => l.description.trim())
    if (isRate && cleanLines.length === 0) { setErr('A rate contract needs at least one rate line.'); return }
    setSaving(true); setErr(null)
    try {
      const c = await purchaseApi.contracts.create({
        title: f.title, type: f.type, vendor_id: Number(f.vendor_id),
        start_date: f.start_date || null, end_date: f.end_date || null,
        spend_ceiling: f.spend_ceiling ? Number(f.spend_ceiling) : null, terms: f.terms || null,
        items: isRate ? cleanLines.map((l, i) => ({ description: l.description, unit: l.unit || null, rate: Number(l.rate) || 0, tax: Number(l.tax) || 0, sort_order: i })) : [],
      })
      onDone(c.id)
    } catch (e) { setErr(e?.response?.data?.message || 'Could not create the contract.'); setSaving(false) }
  }

  return (
    <Overlay onClose={onClose} width={720}>
      <div style={{ padding: '20px 22px 8px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>New Contract</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Lock in terms and — for a rate contract — pre-negotiated prices.</p>
      </div>
      <div style={{ padding: '10px 22px', maxHeight: '60vh', overflowY: 'auto' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 12 }}>
          <Field label="Title *"><TextInput value={f.title} onChange={set('title')} placeholder="Steel rate contract 2026" /></Field>
          <Field label="Type"><SelectInput value={f.type} onChange={set('type')} pairs options={CONTRACT_TYPES} /></Field>
          <Field label="Vendor *">
            <SelectInput value={f.vendor_id} onChange={set('vendor_id')} pairs
              options={[['', 'Select a vendor…'], ...vendors.map(v => [v.id, v.company_name])]} />
          </Field>
          <Field label="Spend ceiling (optional)"><TextInput type="number" value={f.spend_ceiling} onChange={set('spend_ceiling')} placeholder="uncapped" /></Field>
          <Field label="Start date"><TextInput type="date" value={f.start_date} onChange={set('start_date')} /></Field>
          <Field label="End date"><TextInput type="date" value={f.end_date} onChange={set('end_date')} /></Field>
        </div>

        {isRate && (
          <>
            <label style={{ ...labelStyle, marginTop: 12 }}>Rate card *</label>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {lines.map((l, i) => (
                <div key={i} style={{ display: 'grid', gridTemplateColumns: '3fr 1fr 1fr 1fr auto', gap: 8, alignItems: 'center' }}>
                  <TextInput value={l.description} onChange={e => setLine(i, 'description', e.target.value)} placeholder="Item description" />
                  <TextInput value={l.unit} onChange={e => setLine(i, 'unit', e.target.value)} placeholder="Unit" />
                  <TextInput type="number" value={l.rate} onChange={e => setLine(i, 'rate', e.target.value)} placeholder="Rate" />
                  <TextInput type="number" value={l.tax} onChange={e => setLine(i, 'tax', e.target.value)} placeholder="Tax %" />
                  <button onClick={() => setLines(prev => prev.length > 1 ? prev.filter((_, idx) => idx !== i) : prev)} style={{ width: 34, height: 34, borderRadius: 8, cursor: 'pointer', background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Trash2 size={14} /></button>
                </div>
              ))}
            </div>
            <button onClick={() => setLines(prev => [...prev, { ...EMPTY_LINE }])} style={{ ...ghostBtn, marginTop: 8, fontSize: 12 }}><Plus size={13} /> Add rate line</button>
          </>
        )}

        <Field label="Terms (optional)" full><TextInput value={f.terms} onChange={set('terms')} placeholder="Payment terms, SLAs, penalties…" /></Field>

        {err && <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 8 }}><X size={14} style={{ color: '#ef4444' }} /><span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span></div>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel="Create Contract" color="#7C3AED" />
    </Overlay>
  )
}

const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }
