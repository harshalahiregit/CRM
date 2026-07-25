import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  Calendar, Clock, Wallet, FileText, ShieldCheck, GitMerge, CheckCircle2,
} from 'lucide-react'
import { portalApi } from '@/services/portalApi'
import { invStatusCfg, matchCfg, fmtMoney, fmtDate } from './portalConstants'
import { KIT3D_STYLE } from '@/components/ui/kit3d'
import { BackHeader, Meta, TotalRow, Loading, NotFound } from './PortalOrderDetail'

/**
 * Read-only invoice detail for the vendor — line items, the 3-way match verdict
 * recorded at approval, cash payments, and any debit-note credit netted against
 * the bill. Foreign/guessed ids 404 via the portal middleware.
 */
export default function PortalInvoiceDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [inv, setInv] = useState(null)
  const [loading, setLoad] = useState(true)
  const [notFound, setNotFound] = useState(false)

  useEffect(() => {
    portalApi.invoice(id)
      .then(d => { setInv(d?.data ?? d); setLoad(false) })
      .catch(e => { if (e?.response?.status === 404) setNotFound(true); setLoad(false) })
  }, [id])

  if (loading) return <Loading />
  if (notFound || !inv) return <NotFound onBack={() => navigate('/vendor-portal/dashboard')} label="Invoice" />

  const cfg = invStatusCfg(inv.status)
  const items = inv.items || []
  const payments = inv.payments || []
  const credits = inv.credit_applications || []
  const match = matchCfg(inv.match_verdict)

  return (
    <div>
      <style>{KIT3D_STYLE}</style>

      <BackHeader onBack={() => navigate('/vendor-portal/dashboard')}
        eyebrow="INVOICE" title={inv.invoice_number} subtitle={inv.title} statusCfg={cfg}
        extra={match && (
          <span title="Checked against your order and our goods receipt"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '4px 10px', borderRadius: 999, background: match.bg, color: match.color, fontSize: 11, fontWeight: 800 }}>
            <ShieldCheck size={12} /> {match.label}
          </span>
        )} />

      <div className="pr-glass" style={{ padding: 18, marginBottom: 16, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 14 }}>
        <Meta icon={Calendar} label="Invoice date" value={fmtDate(inv.invoice_date)} />
        <Meta icon={Clock} label="Due date" value={fmtDate(inv.due_date)} />
        <Meta icon={Wallet} label="Balance due" value={fmtMoney(inv.balance, inv.currency)} />
      </div>

      {/* Line items */}
      <div className="pr-glass" style={{ padding: 20, marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
          <FileText size={16} style={{ color: '#a78bfa' }} />
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Line Items</h2>
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 520 }}>
            <thead><tr>{['Item', 'Qty', 'Rate', 'Tax %', 'Amount'].map((h, i) => (
              <th key={h} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '8px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
            ))}</tr></thead>
            <tbody>
              {items.map((it, i) => (
                <tr key={i}>
                  <td style={{ padding: '9px 10px', fontSize: 12.5, color: 'var(--text-h)' }}>{it.description}{it.unit ? <span style={{ color: 'var(--text-muted)' }}> · {it.unit}</span> : ''}</td>
                  <td style={{ padding: '9px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{it.qty}</td>
                  <td style={{ padding: '9px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(it.rate, inv.currency)}</td>
                  <td style={{ padding: '9px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.tax}%</td>
                  <td style={{ padding: '9px 10px', fontSize: 12.5, textAlign: 'right', fontWeight: 700, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(it.amount, inv.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 16 }}>
          <div style={{ minWidth: 240, display: 'flex', flexDirection: 'column', gap: 6 }}>
            <TotalRow label="Subtotal" value={fmtMoney(inv.subtotal, inv.currency)} />
            <TotalRow label="Tax" value={fmtMoney(inv.tax_total, inv.currency)} />
            <TotalRow label="Total" value={fmtMoney(inv.total, inv.currency)} strong />
            <div style={{ height: 1, background: 'var(--border)', margin: '2px 0' }} />
            <TotalRow label="Paid (cash)" value={fmtMoney(inv.amount_paid, inv.currency)} />
            {Number(inv.amount_credited) > 0 && <TotalRow label="Credit applied" value={fmtMoney(inv.amount_credited, inv.currency)} />}
            <TotalRow label="Balance" value={fmtMoney(inv.balance, inv.currency)} strong />
          </div>
        </div>
      </div>

      {/* Payments + credits, side by side */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <div className="pr-glass" style={{ padding: 20 }}>
          <h2 style={{ margin: '0 0 12px', fontSize: 14.5, fontWeight: 800, color: 'var(--text-h)' }}>Payments Received</h2>
          {payments.length === 0 ? (
            <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No payments recorded yet.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {payments.map(p => (
                <div key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', borderRadius: 11, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  <CheckCircle2 size={15} style={{ color: '#10b981', flexShrink: 0 }} />
                  <span style={{ fontSize: 13.5, fontWeight: 800, color: '#10b981' }}>{fmtMoney(p.amount, inv.currency)}</span>
                  <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{fmtDate(p.payment_date)}</span>
                  {p.reference && <span style={{ fontSize: 11.5, color: 'var(--text-muted)', marginLeft: 'auto' }}>Ref: {p.reference}</span>}
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="pr-glass" style={{ padding: 20 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
            <GitMerge size={15} style={{ color: '#a78bfa' }} />
            <h2 style={{ margin: 0, fontSize: 14.5, fontWeight: 800, color: 'var(--text-h)' }}>Credits Applied</h2>
          </div>
          {credits.length === 0 ? (
            <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No debit-note credits applied.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {credits.map(c => (
                <div key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', borderRadius: 11, background: 'rgba(124,58,237,0.06)', border: '1px solid rgba(124,58,237,0.28)' }}>
                  <span style={{ fontSize: 13.5, fontWeight: 800, color: '#a78bfa' }}>{fmtMoney(c.amount, inv.currency)}</span>
                  <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>from debit note <strong style={{ color: 'var(--text-h)' }}>{c.debit_note?.debit_number || '—'}</strong></span>
                  <span style={{ fontSize: 11.5, color: 'var(--text-muted)', marginLeft: 'auto' }}>{fmtDate(c.applied_date)}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
