import { useEffect, useState } from 'react'
import { Loader2, Users } from 'lucide-react'
import { portalApi } from '@/services/portalApi'

/**
 * TPV portal — General › Customer. The customers linked to this vendor
 * (clients.vendor_id), read-only. Linking/creating a customer is an admin action
 * in the Vendor section.
 */
export default function MyCustomers({ api = portalApi }) {
  const [rows, setRows] = useState(null)
  useEffect(() => { api.customers().then(d => setRows(d || [])).catch(() => setRows([])) }, [api])

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      <style>{CSS}</style>
      <h2 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 16px' }}>My Customers</h2>
      {rows === null ? <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}><Loader2 className="cu-spin" size={22} /></div>
        : rows.length === 0 ? <div className="cu-card" style={{ display: 'flex', alignItems: 'center', gap: 10, color: 'var(--text-muted)', fontSize: 14 }}><Users size={22} style={{ opacity: 0.6 }} /> No customers linked yet.</div>
        : (
          <div className="cu-card" style={{ padding: '6px 4px' }}>
            <div style={{ overflowX: 'auto' }}>
              <table className="cu-table">
                <thead><tr><th>Company</th><th>Phone</th><th>GST</th><th>Location</th><th>Active</th></tr></thead>
                <tbody>
                  {rows.map(c => (
                    <tr key={c.id}>
                      <td style={{ fontWeight: 700, color: 'var(--text-h)' }}>{c.company}</td>
                      <td>{c.phone || '—'}</td><td>{c.gst_number || '—'}</td>
                      <td>{[c.city, c.state, c.country].filter(Boolean).join(', ') || '—'}</td>
                      <td>{c.active ? 'Yes' : 'No'}</td>
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

const CSS = `
.cu-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 18px; }
.cu-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.cu-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); padding: 10px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); white-space: nowrap; }
.cu-table td { padding: 11px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.05)); color: var(--text-body, #cbd5e1); }
.cu-table tbody tr:last-child td { border-bottom: none; }
.cu-spin { animation: cu-spin 0.9s linear infinite; }
@keyframes cu-spin { to { transform: rotate(360deg); } }
`
