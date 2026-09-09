import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FileSignature, Plus, Search, AlertTriangle, CheckCircle2, Clock, FileText, IndianRupee,
} from 'lucide-react'
import { contractModuleApi as api } from '@/services/contractModuleApi'
import { inputStyle, PRIMARY_GRADIENT } from '@/components/ui/kit3d'

/**
 * The Contract dashboard — the module's landing page.
 *
 * Top-line numbers, then every contract in one table with the two things a
 * person actually scans for: what it is worth, and whether it is signed. The
 * signature column shows BOTH parties rather than a single yes/no, because
 * "half-signed" is the state that needs chasing and a boolean hides it.
 */

const STATUS_STYLE = {
  draft:     { bg: 'rgba(107,114,128,.12)', fg: '#6b7280', label: 'Draft' },
  sent:      { bg: 'rgba(59,130,246,.12)',  fg: '#3b82f6', label: 'Sent' },
  signed:    { bg: 'rgba(139,92,246,.12)',  fg: '#8b5cf6', label: 'Signed' },
  active:    { bg: 'rgba(16,185,129,.12)',  fg: '#10b981', label: 'Active' },
  expired:   { bg: 'rgba(245,158,11,.12)',  fg: '#f59e0b', label: 'Expired' },
  cancelled: { bg: 'rgba(239,68,68,.12)',   fg: '#ef4444', label: 'Cancelled' },
}

const card = {
  background: 'var(--bg-card)', border: '1px solid var(--border)',
  borderRadius: 14, padding: 16,
}

function Stat({ icon: Icon, label, value, tone = '#7C3AED', hint }) {
  return (
    <div style={card}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
        <span style={{ width: 28, height: 28, borderRadius: 8, background: `${tone}1f`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <Icon size={15} style={{ color: tone }} />
        </span>
        <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.05em' }}>
          {label}
        </span>
      </div>
      <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{value}</div>
      {hint && <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{hint}</div>}
    </div>
  )
}

/** Both parties at a glance — a single tick would hide the half-signed state. */
function SignatureCell({ signatures = [] }) {
  const of = (p) => signatures.find(s => s.party === p)
  return (
    <div style={{ display: 'flex', gap: 10 }}>
      {[['party', 'Customer'], ['company', 'Us']].map(([key, label]) => {
        const s = of(key)
        const done = Boolean(s?.signed_at)
        return (
          <span key={key} title={done ? `${label}: signed by ${s.signer_name}` : `${label}: awaiting signature`}
            style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 11.5, color: done ? '#10b981' : 'var(--text-muted)' }}>
            {done ? <CheckCircle2 size={13} /> : <Clock size={13} />} {label}
          </span>
        )
      })}
    </div>
  )
}

export default function Contracts() {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [stats, setStats] = useState(null)
  const [loading, setLoading] = useState(true)
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('All')

  const load = useCallback(() => {
    setLoading(true)
    Promise.all([
      api.list({ search: q || undefined, status: status !== 'All' ? status : undefined }),
      api.stats(),
    ])
      .then(([list, s]) => { setRows(Array.isArray(list) ? list : (list?.data ?? [])); setStats(s) })
      .catch(() => { setRows([]) })
      .finally(() => setLoading(false))
  }, [q, status])

  useEffect(() => {
    // Debounced so typing in the search box does not fire a request per key.
    const t = setTimeout(load, 250)
    return () => clearTimeout(t)
  }, [load])

  const money = useMemo(() => new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }), [])

  return (
    <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 18 }}>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ display: 'flex', alignItems: 'center', gap: 9, fontSize: 20, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>
            <FileSignature size={20} style={{ color: '#7C3AED' }} /> Contracts
          </h1>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '3px 0 0' }}>
            Agreements with customers and vendors — drafted, signed and tracked to renewal.
          </p>
        </div>
        <button onClick={() => navigate('/app/contracts/new')}
          style={{ display: 'flex', alignItems: 'center', gap: 7, padding: '9px 15px', borderRadius: 10, border: 'none', background: PRIMARY_GRADIENT, color: '#fff', fontSize: 13, fontWeight: 700, cursor: 'pointer' }}>
          <Plus size={15} /> New Contract
        </button>
      </div>

      {stats && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12 }}>
          <Stat icon={FileText}      label="Total"       value={stats.total} />
          <Stat icon={Clock}         label="Awaiting"    value={stats.awaiting} tone="#3b82f6" hint="sent, not yet signed" />
          <Stat icon={CheckCircle2}  label="Signed"      value={stats.signed} tone="#10b981" hint="both parties" />
          <Stat icon={AlertTriangle} label="Expiring"    value={stats.expiring} tone="#f59e0b" hint="inside notice period" />
          <Stat icon={IndianRupee}   label="Total value" value={money.format(stats.total_value || 0)} tone="#8b5cf6" hint="open contracts" />
        </div>
      )}

      <div style={{ ...card, padding: 0 }}>
        <div style={{ display: 'flex', gap: 10, padding: 14, borderBottom: '1px solid var(--border)', flexWrap: 'wrap' }}>
          <div style={{ position: 'relative', flex: 1, minWidth: 200 }}>
            <Search size={14} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--text-muted)' }} />
            <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search title, reference or party…"
              style={{ ...inputStyle, paddingLeft: 32 }} />
          </div>
          <select value={status} onChange={e => setStatus(e.target.value)} style={{ ...inputStyle, width: 'auto' }}>
            <option value="All">All statuses</option>
            {Object.entries(STATUS_STYLE).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </select>
        </div>

        {/* The table scrolls inside its own container so the page never scrolls
            sideways on a narrow screen. */}
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 820 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em' }}>
                {['Reference', 'Contract', 'Party', 'Value', 'Signatures', 'Status', 'Ends'].map(h => (
                  <th key={h} style={{ padding: '10px 14px', borderBottom: '1px solid var(--border)', fontWeight: 700 }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} style={{ padding: 28, textAlign: 'center', color: 'var(--text-muted)' }}>Loading…</td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={7} style={{ padding: 34, textAlign: 'center', color: 'var(--text-muted)' }}>
                  No contracts yet. Create the first one to get started.
                </td></tr>
              ) : rows.map(r => {
                const st = STATUS_STYLE[r.status] || STATUS_STYLE.draft
                const expiring = r.days_to_expiry !== null && r.days_to_expiry <= (r.renewal_notice_days ?? 30) && r.days_to_expiry >= 0
                return (
                  <tr key={r.id} onClick={() => navigate(`/app/contracts/${r.id}`)}
                    style={{ cursor: 'pointer', borderBottom: '1px solid var(--border)' }}
                    onMouseEnter={e => { e.currentTarget.style.background = 'var(--bg-input)' }}
                    onMouseLeave={e => { e.currentTarget.style.background = 'transparent' }}>
                    <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 12, color: 'var(--text-muted)' }}>{r.reference_no}</td>
                    <td style={{ padding: '11px 14px' }}>
                      <div style={{ fontWeight: 600, color: 'var(--text-h)' }}>{r.title}</div>
                      {r.category?.name && <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{r.category.name}</div>}
                    </td>
                    <td style={{ padding: '11px 14px' }}>{r.party_name || '—'}</td>
                    <td style={{ padding: '11px 14px', fontVariantNumeric: 'tabular-nums' }}>
                      {r.value ? `${r.currency} ${money.format(r.value)}` : '—'}
                    </td>
                    <td style={{ padding: '11px 14px' }}><SignatureCell signatures={r.signatures} /></td>
                    <td style={{ padding: '11px 14px' }}>
                      <span style={{ padding: '3px 9px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: st.bg, color: st.fg }}>
                        {st.label}
                      </span>
                    </td>
                    <td style={{ padding: '11px 14px', fontSize: 12 }}>
                      {r.end_date
                        ? <span style={{ color: expiring ? '#f59e0b' : 'var(--text-muted)', fontWeight: expiring ? 700 : 400 }}>
                            {new Date(r.end_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })}
                            {expiring && ` · ${r.days_to_expiry}d`}
                          </span>
                        : <span style={{ color: 'var(--text-muted)' }}>open-ended</span>}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
