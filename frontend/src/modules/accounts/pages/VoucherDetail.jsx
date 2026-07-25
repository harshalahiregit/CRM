import { useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Ban, Loader2, Printer, ExternalLink } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { useToast } from '@/hooks/useToast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

const STATUS_COLORS = { posted: '#10b981', cancelled: '#f87171', draft: '#f59e0b' }

const TYPE_COLORS = {
  sales:        '#10b981',
  purchase:     '#f59e0b',
  payment:      '#f87171',
  receipt:      '#22d3ee',
  contra:       '#a78bfa',
  journal:      '#818cf8',
  debit_note:   '#f97316',
  credit_note:  '#06b6d4',
  stock_journal: '#8b5cf6',
}

// Source type → human label + where to link
function sourceInfo(v) {
  if (!v?.source_type) return null
  const map = {
    vendor_bill:         { label: 'Vendor Bill', path: '/app/accounts/bills' },
    vendor_bill_payment: { label: 'Bill Payment', path: '/app/accounts/bills' },
    sales_invoice:       { label: 'Sales Invoice', path: '/app/sales/invoices' },
    sales_payment:       { label: 'Sales Payment', path: '/app/sales/invoices' },
  }
  return map[v.source_type] ?? { label: v.source_type.replace(/_/g, ' '), path: null }
}

export default function VoucherDetail() {
  const inr = useInr()
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const qc = useQueryClient()
  const [confirm, setConfirm] = useState(false)

  const { data: v, isLoading } = useQuery({
    queryKey: ['accounts', 'voucher', id],
    queryFn: () => accountsApi.vouchers.get(id),
  })

  const cancel = useMutation({
    mutationFn: () => accountsApi.vouchers.cancel(id, 'Cancelled from voucher detail'),
    onSuccess: (r) => { toast.success(`Reversed via ${r.reversal?.number}`); setConfirm(false); qc.invalidateQueries({ queryKey: ['accounts'] }) },
    onError: (e) => { toast.error(e.message); setConfirm(false) },
  })

  if (isLoading) return <div className="flex justify-center py-16"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  if (!v) return null

  const totalDebit  = (v.lines || []).reduce((s, l) => s + Number(l.debit  || 0), 0)
  const totalCredit = (v.lines || []).reduce((s, l) => s + Number(l.credit || 0), 0)
  const src         = sourceInfo(v)
  const typeColor   = TYPE_COLORS[v.voucher_type?.code] ?? 'var(--text-muted)'

  const handlePrint = () => {
    window.print()
  }

  return (
    <div className="space-y-5 animate-fade-in max-w-4xl">
      {/* Back nav */}
      <button className="flex items-center gap-1.5 text-sm font-semibold hover:opacity-80 transition-opacity"
        style={{ color: 'var(--text-muted)' }} onClick={() => navigate('/app/accounts/vouchers')}>
        <ArrowLeft size={15} /> Back to vouchers
      </button>

      {/* Header card */}
      <div className="kpi-3d">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>{v.number}</h1>
              <span className="text-xs font-bold capitalize px-2 py-0.5 rounded-md"
                style={{ background: `${STATUS_COLORS[v.status]}18`, color: STATUS_COLORS[v.status] }}>
                {v.status}
              </span>
              {v.is_reversal && (
                <span className="text-xs font-bold px-2 py-0.5 rounded-md"
                  style={{ background: 'rgba(124,58,237,0.15)', color: '#a78bfa' }}>reversal</span>
              )}
              {v.reversed_by && (
                <span className="text-xs font-bold px-2 py-0.5 rounded-md"
                  style={{ background: 'rgba(239,68,68,0.12)', color: '#f87171' }}>reversed</span>
              )}
              {/* Voucher type badge */}
              <span className="text-xs font-bold px-2 py-0.5 rounded-md"
                style={{ background: `${typeColor}18`, color: typeColor }}>
                {v.voucher_type?.name}
              </span>
            </div>

            <p className="text-sm mt-1 flex items-center gap-2 flex-wrap" style={{ color: 'var(--text-muted)' }}>
              <span>{fmtDate(v.date)}</span>
              {v.reference_no && <><span>·</span><span>Ref: {v.reference_no}</span></>}
              {v.narration && <><span>·</span><span className="italic">{v.narration}</span></>}
            </p>
          </div>

          <div className="flex items-center gap-2">
            {/* Print */}
            <button onClick={handlePrint}
              className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-bold hover:opacity-80 transition-opacity"
              style={{ background: 'var(--bg-hover)', color: 'var(--text-muted)' }}>
              <Printer size={14} /> Print
            </button>
            {v.status === 'posted' && !v.is_reversal && !v.reversed_by && (
              <button
                className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold"
                style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}
                onClick={() => setConfirm(true)}>
                <Ban size={15} /> Cancel (reverse)
              </button>
            )}
          </div>
        </div>

        {/* Source link — when voucher was auto-posted from a bill or invoice */}
        {src && (
          <div className="mt-3 flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
            <span>Source:</span>
            <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{src.label}</span>
            {v.source_id && src.path && (
              <Link to={src.path} className="inline-flex items-center gap-0.5 font-bold hover:underline"
                style={{ color: '#22d3ee' }}>
                #{v.source_id} <ExternalLink size={10} />
              </Link>
            )}
          </div>
        )}
      </div>

      {/* Journal entries table */}
      <div className="kpi-3d overflow-hidden p-0">
        <div className="px-5 py-3 text-xs font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
          Journal Entries
        </div>
        <table className="w-full">
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)', background: 'var(--bg-hover)' }}>
              <th className="px-5 py-2.5 text-left text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>Ledger</th>
              <th className="px-5 py-2.5 text-left text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>Narration</th>
              <th className="px-5 py-2.5 text-right text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>Debit</th>
              <th className="px-5 py-2.5 text-right text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>Credit</th>
            </tr>
          </thead>
          <tbody>
            {(v.lines || []).map((l, idx) => (
              <tr key={l.id} style={{ borderBottom: idx < v.lines.length - 1 ? '1px solid var(--border)' : 'none' }}>
                <td className="px-5 py-3 text-sm font-semibold" style={{ color: 'var(--text-h)' }}>
                  {l.ledger?.name || '—'}
                </td>
                <td className="px-5 py-3 text-sm" style={{ color: 'var(--text-muted)' }}>
                  {l.line_narration || '—'}
                </td>
                <td className="px-5 py-3 text-sm text-right font-semibold"
                  style={{ color: Number(l.debit) > 0 ? '#10b981' : 'var(--text-muted)' }}>
                  {Number(l.debit) > 0 ? inr(l.debit) : '—'}
                </td>
                <td className="px-5 py-3 text-sm text-right font-semibold"
                  style={{ color: Number(l.credit) > 0 ? '#f87171' : 'var(--text-muted)' }}>
                  {Number(l.credit) > 0 ? inr(l.credit) : '—'}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr style={{ borderTop: '2px solid var(--border)', background: 'var(--bg-hover)' }}>
              <td colSpan={2} className="px-5 py-3 text-sm font-black" style={{ color: 'var(--text-h)' }}>Totals</td>
              <td className="px-5 py-3 text-sm font-black text-right" style={{ color: '#10b981' }}>{inr(totalDebit)}</td>
              <td className="px-5 py-3 text-sm font-black text-right" style={{ color: '#f87171' }}>{inr(totalCredit)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      {/* Tax lines (if any) */}
      {v.tax_lines?.length > 0 && (
        <div className="kpi-3d overflow-hidden p-0">
          <div className="px-5 py-3 text-xs font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
            Tax Lines (GST / TDS)
          </div>
          <table className="w-full">
            <thead>
              <tr style={{ borderBottom: '1px solid var(--border)', background: 'var(--bg-hover)' }}>
                {['Tax type', 'Rate', 'Taxable', 'Tax amount'].map(h => (
                  <th key={h} className="px-5 py-2.5 text-left text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {v.tax_lines.map((tl, idx) => (
                <tr key={idx} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-5 py-3 text-sm" style={{ color: 'var(--text-h)' }}>{tl.tax_type?.toUpperCase() || '—'}</td>
                  <td className="px-5 py-3 text-sm" style={{ color: 'var(--text-muted)' }}>{tl.rate_percent}%</td>
                  <td className="px-5 py-3 text-sm" style={{ color: 'var(--text-h)' }}>{inr(tl.taxable_amount)}</td>
                  <td className="px-5 py-3 text-sm font-semibold" style={{ color: '#f59e0b' }}>{inr(tl.tax_amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Reversal chain links */}
      <div className="flex flex-col gap-1">
        {v.reversed_voucher && (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Reverses voucher{' '}
            <Link to={`/app/accounts/vouchers/${v.reversed_voucher.id}`}
              className="font-bold hover:underline" style={{ color: '#a78bfa' }}>
              {v.reversed_voucher.number}
            </Link>
          </p>
        )}
        {v.reversed_by && (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Reversed by{' '}
            <Link to={`/app/accounts/vouchers/${v.reversed_by.id}`}
              className="font-bold hover:underline" style={{ color: '#f87171' }}>
              {v.reversed_by.number}
            </Link>{' '}
            — its effect is neutralised in the books.
          </p>
        )}
      </div>

      {confirm && (
        <ConfirmDialog
          title="Cancel this voucher?"
          message={`A reversing entry will be posted to neutralise ${v.number}. The original is retained (never deleted) for the audit trail.`}
          confirmLabel="Cancel voucher"
          onCancel={() => setConfirm(false)}
          onConfirm={() => cancel.mutate()}
        />
      )}
    </div>
  )
}
