import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Ban, Loader2 } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { inr, fmtDate } from '@/modules/accounts/format'
import { useToast } from '@/hooks/useToast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

const STATUS_COLORS = { posted: '#10b981', cancelled: '#f87171', draft: '#f59e0b' }

export default function VoucherDetail() {
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

  const totalDebit = (v.lines || []).reduce((s, l) => s + Number(l.debit || 0), 0)
  const totalCredit = (v.lines || []).reduce((s, l) => s + Number(l.credit || 0), 0)

  return (
    <div className="space-y-5 animate-fade-in max-w-4xl">
      <button className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }} onClick={() => navigate('/app/accounts/vouchers')}>
        <ArrowLeft size={15} /> Back to vouchers
      </button>

      <div className="kpi-3d">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>{v.number}</h1>
              <span className="text-xs font-bold capitalize px-2 py-0.5 rounded-md" style={{ background: 'var(--bg-hover)', color: STATUS_COLORS[v.status] }}>{v.status}</span>
              {v.is_reversal && <span className="text-xs font-bold px-2 py-0.5 rounded-md" style={{ background: 'rgba(124,58,237,0.15)', color: '#a78bfa' }}>reversal</span>}
              {v.reversed_by && <span className="text-xs font-bold px-2 py-0.5 rounded-md" style={{ background: 'rgba(239,68,68,0.12)', color: '#f87171' }}>reversed</span>}
            </div>
            <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
              {v.voucher_type?.name} · {fmtDate(v.date)}{v.reference_no ? ` · Ref ${v.reference_no}` : ''}
            </p>
          </div>
          {v.status === 'posted' && !v.is_reversal && !v.reversed_by && (
            <button className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }} onClick={() => setConfirm(true)}>
              <Ban size={15} /> Cancel (reverse)
            </button>
          )}
        </div>
        {v.narration && <p className="text-sm mt-3" style={{ color: 'var(--text-muted)' }}>{v.narration}</p>}
      </div>

      {/* Lines */}
      <div className="table-wrapper">
        <table className="table">
          <thead><tr>
            <th>Ledger</th><th>Narration</th>
            <th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th>
          </tr></thead>
          <tbody>
            {(v.lines || []).map(l => (
              <tr key={l.id}>
                <td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{l.ledger?.name || '—'}</td>
                <td style={{ color: 'var(--text-muted)' }}>{l.line_narration || '—'}</td>
                <td style={{ textAlign: 'right' }}>{Number(l.debit) > 0 ? inr(l.debit) : '—'}</td>
                <td style={{ textAlign: 'right' }}>{Number(l.credit) > 0 ? inr(l.credit) : '—'}</td>
              </tr>
            ))}
            <tr style={{ borderTop: '2px solid var(--border)' }}>
              <td colSpan={2} style={{ fontWeight: 800, color: 'var(--text-h)' }}>Totals</td>
              <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(totalDebit)}</td>
              <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(totalCredit)}</td>
            </tr>
          </tbody>
        </table>
      </div>

      {v.reversed_voucher && (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Reverses voucher {v.reversed_voucher.number}.</p>
      )}
      {v.reversed_by && (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Reversed by voucher {v.reversed_by.number} — its effect is neutralised in the books.</p>
      )}

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
