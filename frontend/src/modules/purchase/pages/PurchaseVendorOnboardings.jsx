import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Rocket, RefreshCw } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'

/**
 * Purchase Vendor Onboarding — admin list of the 6-step onboarding records
 * (/api/purchase/onboarding). Purchase-owned; no TPV component.
 */
export default function PurchaseVendorOnboardings() {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)

  const load = useCallback(() => {
    setLoading(true)
    purchaseApi.onboarding.list()
      .then((l) => setRows(Array.isArray(l) ? l : l?.data ?? []))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  return (
    <div style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Rocket size={22} style={{ color: '#7C3AED' }} />
          <h1 style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Vendor Onboarding</h1>
        </div>
        <button onClick={load} style={btn}><RefreshCw size={14} /> Refresh</button>
      </div>

      <div className="card-3d" style={{ overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead><tr style={{ background: 'var(--bg-input)' }}>{['Vendor', 'Code', 'Step', 'Status', ''].map((h) => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {loading ? <tr><td colSpan={5} style={empty}>Loading…</td></tr>
              : rows.length === 0 ? <tr><td colSpan={5} style={empty}>No onboarding records yet.</td></tr>
              : rows.map((o) => (
                <tr key={o.id} style={{ borderTop: '1px solid var(--border)' }}>
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>{o.vendor?.company_name || '—'}</td>
                  <td style={td}>{o.vendor?.purchase_vendor_code || '—'}</td>
                  <td style={td}>{o.current_step}/6</td>
                  <td style={td}>{o.status_label || o.status}</td>
                  <td style={{ ...td, textAlign: 'right' }}><button onClick={() => navigate(`/app/purchase/onboarding/${o.id}`)} style={miniBtn}>Open</button></td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const miniBtn = { padding: '4px 10px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }
const th = { textAlign: 'left', padding: '10px 12px', fontSize: 11, textTransform: 'uppercase', color: 'var(--text-muted)', fontWeight: 700 }
const td = { padding: '10px 12px', color: 'var(--text-muted)' }
const empty = { padding: 24, textAlign: 'center', color: 'var(--text-muted)' }
