import { useState, useEffect } from 'react'
import { clientPortalApi } from '@/lib/clientPortalApi'

/** Account statement — every invoice with what is still owed against it. */
const money = (v) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(Number(v) || 0)
const date = (v) => (v ? new Date(v).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')

export default function ClientPortalStatement() {
  const [d, setD] = useState(null); const [denied, setDenied] = useState(false); const [err, setErr] = useState('')

  useEffect(() => {
    clientPortalApi.statement().then(setD).catch(e => {
      if (e?.response?.status === 403) setDenied(true)
      else setErr(e?.response?.data?.message || 'Could not load your statement.')
    })
  }, [])

  const card = { background: 'var(--bg-card,#12141b)', border: '1px solid var(--border,#2a2f3a)', borderRadius: 14 }
  if (denied) return <div style={{ ...card, padding: 22, fontSize: 13, color: 'var(--text-muted,#9ca3af)' }}>This section has not been shared with you.</div>
  if (err) return <div style={{ ...card, padding: 22, color: '#ef4444', fontSize: 13 }}>{err}</div>
  if (!d) return <div style={{ color: 'var(--text-muted,#9ca3af)', fontSize: 13 }}>Loading…</div>

  const t = d.totals ?? {}
  return (
    <div style={{ maxWidth: 1000, display: 'grid', gap: 16 }}>
      <h1 style={{ fontSize: 21, fontWeight: 800, color: 'var(--text-h,#fff)', margin: 0 }}>Account Statement</h1>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(190px,1fr))', gap: 12 }}>
        {[['Invoiced', t.invoiced], ['Paid', t.paid], ['Outstanding', t.outstanding]].map(([label, v]) => (
          <div key={label} style={{ ...card, padding: 18 }}>
            <div style={{ fontSize: 10.5, fontWeight: 800, letterSpacing: '.07em', textTransform: 'uppercase', color: 'var(--text-muted,#9ca3af)' }}>{label}</div>
            <div style={{ fontSize: 23, fontWeight: 900, color: label === 'Outstanding' && Number(v) > 0 ? '#f59e0b' : 'var(--text-h,#fff)', marginTop: 8, fontVariantNumeric: 'tabular-nums' }}>{money(v)}</div>
          </div>
        ))}
      </div>

      <div style={{ ...card, overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 560 }}>
          <thead><tr>{['Invoice', 'Date', 'Total', 'Paid', 'Balance'].map((h, i) => (
            <th key={h} style={{ textAlign: i > 1 ? 'right' : 'left', padding: '11px 16px', fontSize: 10.5, fontWeight: 800,
              letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-muted,#9ca3af)', borderBottom: '1px solid var(--border,#2a2f3a)' }}>{h}</th>
          ))}</tr></thead>
          <tbody>
            {(d.rows ?? []).length === 0
              ? <tr><td colSpan={5} style={{ padding: 20, textAlign: 'center', fontSize: 12.5, color: 'var(--text-muted,#9ca3af)' }}>No invoices yet.</td></tr>
              : d.rows.map((r, i) => (
                <tr key={i}>
                  <td style={{ padding: '11px 16px', fontSize: 12.5, fontWeight: 700, color: 'var(--text-h,#fff)', borderBottom: '1px solid var(--border,#2a2f3a)' }}>{r.number}</td>
                  <td style={{ padding: '11px 16px', fontSize: 12.5, color: 'var(--text-body,#cbd5e1)', borderBottom: '1px solid var(--border,#2a2f3a)' }}>{date(r.date)}</td>
                  {['total', 'paid', 'balance'].map(k => (
                    <td key={k} style={{ padding: '11px 16px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums',
                      color: k === 'balance' && Number(r[k]) > 0 ? '#f59e0b' : 'var(--text-body,#cbd5e1)', borderBottom: '1px solid var(--border,#2a2f3a)' }}>{money(r[k])}</td>
                  ))}
                </tr>
              ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
