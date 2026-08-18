import { useState, useEffect, useCallback, useMemo } from 'react'
import { HardHat, Search, ChevronRight, ShieldCheck, ShieldAlert } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import PurchaseWorkerDetail from './PurchaseWorkerDetail'

const STEP_LABELS = ['Profile', 'Medical', 'Training', 'PPE', 'Badge']

/**
 * Admin/staff view of every Purchase vendor's workforce.
 *
 * The vendor supplies the evidence in its own portal; this is where the site
 * reviews it and decides who may walk in. Scoped by TENANT on the server — the
 * vendor filter below only narrows the list, it never grants access, so a
 * tampered vendor_id shows nothing extra.
 *
 * Progress is read from the worker's persisted current_step, never recomputed
 * here, so this screen and the vendor's wizard always agree.
 */
export default function PurchaseWorkforce() {
  const [workers, setWorkers] = useState([])
  const [vendors, setVendors] = useState([])
  const [vendorId, setVendorId] = useState('')
  const [q, setQ] = useState('')
  const [loading, setLoading] = useState(true)
  const [openId, setOpenId] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    purchaseApi.workforce.workers(vendorId ? { vendor_id: vendorId } : {})
      .then(d => setWorkers(d?.data ?? d ?? []))
      .catch(() => setWorkers([]))
      .finally(() => setLoading(false))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    purchaseApi.vendors.list?.({ per_page: 200 })
      .then(d => setVendors(d?.data ?? d ?? []))
      .catch(() => setVendors([]))
  }, [])

  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase()
    if (!needle) return workers
    return workers.filter(w =>
      [w.full_name, w.worker_code, w.designation, w.vendor?.company_name]
        .filter(Boolean).some(v => String(v).toLowerCase().includes(needle))
    )
  }, [workers, q])

  if (openId) {
    return <PurchaseWorkerDetail workerId={openId} onBack={() => { setOpenId(null); load() }} />
  }

  return (
    <div className="animate-fade-in" style={{ padding: 4 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
        <HardHat size={19} style={{ color: '#0ea5e9' }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>Purchase Workforce</h1>
      </div>
      <p style={{ margin: '0 0 18px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Workers supplied by your Purchase vendors, and how far each has progressed.
      </p>

      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
        <select value={vendorId} onChange={e => setVendorId(e.target.value)} style={{ ...input, maxWidth: 260 }}>
          <option value="">All vendors</option>
          {vendors.map(v => (
            <option key={v.id} value={v.id}>{v.company_name}</option>
          ))}
        </select>
        <div style={{ position: 'relative', flex: '1 1 220px', maxWidth: 320 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search workers…"
            style={{ ...input, paddingLeft: 30 }} />
        </div>
      </div>

      {loading ? (
        <div style={{ padding: 24, color: 'var(--text-muted)', fontSize: 13 }}>Loading workers…</div>
      ) : rows.length === 0 ? (
        <div style={{ ...card, textAlign: 'center', padding: '34px 20px' }}>
          <HardHat size={28} style={{ color: 'var(--text-muted)', marginBottom: 8 }} />
          <p style={{ margin: 0, fontSize: 13.5, color: 'var(--text-muted)' }}>
            {vendorId || q ? 'No workers match this filter.' : 'No Purchase workers have been added yet.'}
          </p>
        </div>
      ) : (
        <div style={{ ...card, padding: 0, overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5, minWidth: 760 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', background: 'var(--bg-input)' }}>
                <th style={th}>Worker</th>
                <th style={th}>Vendor</th>
                <th style={th}>Designation</th>
                <th style={th}>Progress</th>
                <th style={th}>Badge</th>
                <th style={th}></th>
              </tr>
            </thead>
            <tbody>
              {rows.map(w => {
                const step = Number(w.current_step || 1)
                return (
                  <tr key={w.id} onClick={() => setOpenId(w.id)}
                    style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                    <td style={td}>
                      <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{w.full_name}</div>
                      <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{w.worker_code}</div>
                    </td>
                    <td style={td}>{w.vendor?.company_name || '—'}</td>
                    <td style={td}>{w.designation || '—'}</td>
                    <td style={td}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
                        <div style={{ width: 84, height: 5, borderRadius: 3, background: 'var(--bg-input)', overflow: 'hidden' }}>
                          <div style={{ width: `${(step / 5) * 100}%`, height: '100%', background: step >= 5 ? '#10b981' : '#0ea5e9' }} />
                        </div>
                        <span style={{ fontSize: 11.5, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                          {step}/5 {STEP_LABELS[step - 1]}
                        </span>
                      </div>
                    </td>
                    <td style={td}>
                      {w.badge_number
                        ? <span style={pill('#10b981')}><ShieldCheck size={11} /> {w.badge_number}</span>
                        : <span style={pill('#6b7280')}><ShieldAlert size={11} /> Not issued</span>}
                    </td>
                    <td style={{ ...td, textAlign: 'right' }}>
                      <ChevronRight size={15} style={{ color: 'var(--text-muted)' }} />
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 18 }
const input = { width: '100%', padding: '8px 10px', borderRadius: 9, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
const th = { padding: '10px 12px', fontWeight: 700, fontSize: 11.5, whiteSpace: 'nowrap' }
const td = { padding: '10px 12px', color: 'var(--text-h)', verticalAlign: 'middle' }
const pill = (c) => ({ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 9px', borderRadius: 20, fontSize: 11, fontWeight: 700, color: c, background: `${c}1a`, whiteSpace: 'nowrap' })
