import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Loader2, Wand2, CheckCircle2, X } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { useToast } from '@/hooks/useToast'
import FormField, { Input } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

export default function Reconcile() {
  const inr = useInr()
  const { bankId } = useParams()
  const toast = useToast()
  const qc = useQueryClient()
  const [cancelling, setCancelling] = useState(false)

  const { data: history = [], isLoading } = useQuery({
    queryKey: ['accounts', 'reconciliations', bankId],
    queryFn: () => accountsApi.reconciliations.history(bankId),
  })
  const active = history.find(r => r.status === 'in_progress')

  const invalidate = () => qc.invalidateQueries({ queryKey: ['accounts', 'reconciliations', bankId] })

  const start = useMutation({
    mutationFn: (data) => accountsApi.reconciliations.start({ bank_account_id: Number(bankId), ...data }),
    onSuccess: () => { toast.success('Reconciliation started'); invalidate() },
    onError: (e) => toast.error(e.message),
  })
  const cancel = useMutation({
    mutationFn: (id) => accountsApi.reconciliations.cancel(id),
    onSuccess: () => { toast.success('Reconciliation cancelled'); setCancelling(false); invalidate() },
    onError: (e) => { toast.error(e.message); setCancelling(false) },
  })

  return (
    <div className="space-y-5 animate-fade-in max-w-5xl">
      <Link to="/app/accounts/banking" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={15} /> Bank accounts
      </Link>
      <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Bank Reconciliation</h1>

      {isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        active
          ? <Workspace recId={active.id} onCancel={() => setCancelling(true)} onDone={invalidate} />
          : <StartForm saving={start.isPending} onStart={(d) => start.mutate(d)} />
      )}

      {/* History */}
      {history.filter(r => r.status === 'completed').length > 0 && (
        <div>
          <p className="label-caps mb-2">Completed</p>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Statement date</th><th style={{ textAlign: 'right' }}>Statement balance</th><th style={{ textAlign: 'right' }}>Book balance</th><th>Completed</th></tr></thead>
              <tbody>
                {history.filter(r => r.status === 'completed').map(r => (
                  <tr key={r.id}>
                    <td style={{ color: 'var(--text-h)' }}>{fmtDate(r.statement_date)}</td>
                    <td style={{ textAlign: 'right' }}>{inr(r.statement_balance)}</td>
                    <td style={{ textAlign: 'right' }}>{inr(r.book_balance)}</td>
                    <td style={{ color: 'var(--text-muted)' }}>{fmtDate(r.completed_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {cancelling && (
        <ConfirmDialog title="Cancel reconciliation?" message="All matches in this session are released. The statement lines stay imported." confirmLabel="Cancel it"
          onCancel={() => setCancelling(false)} onConfirm={() => cancel.mutate(active.id)} />
      )}
    </div>
  )
}

function StartForm({ saving, onStart }) {
  const [form, setForm] = useState({ statement_date: new Date().toISOString().slice(0, 10), statement_balance: '' })
  const valid = form.statement_date && form.statement_balance !== ''
  return (
    <div className="kpi-3d max-w-md space-y-4">
      <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Enter the closing balance shown on your bank statement, then match it against your books.</p>
      <FormField label="Statement (closing) date" required>
        <Input type="date" value={form.statement_date} onChange={e => setForm(f => ({ ...f, statement_date: e.target.value }))} />
      </FormField>
      <FormField label="Statement ending balance" required>
        <Input type="number" step="0.01" value={form.statement_balance} onChange={e => setForm(f => ({ ...f, statement_balance: e.target.value }))} />
      </FormField>
      <button className="btn-3d w-full flex items-center justify-center gap-2" disabled={!valid || saving} onClick={() => onStart({ ...form, statement_balance: Number(form.statement_balance) })}>
        {saving && <Loader2 size={15} className="animate-spin" />} Start reconciliation
      </button>
    </div>
  )
}

function Workspace({ recId, onCancel, onDone }) {
  const inr = useInr()
  const toast = useToast()
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['accounts', 'reconciliation', recId],
    queryFn: () => accountsApi.reconciliations.get(recId),
  })
  const refresh = () => qc.invalidateQueries({ queryKey: ['accounts', 'reconciliation', recId] })

  const toggle = useMutation({
    mutationFn: (voucherLineId) => accountsApi.reconciliations.toggle(recId, { voucher_line_id: voucherLineId }),
    onSuccess: () => refresh(),
    onError: (e) => toast.error(e.message),
  })
  const auto = useMutation({
    mutationFn: () => accountsApi.reconciliations.autoSuggest(recId),
    onSuccess: (r) => { toast.success(`Auto-matched ${r.matched} line(s)`); refresh() },
    onError: (e) => toast.error(e.message),
  })
  const complete = useMutation({
    mutationFn: () => accountsApi.reconciliations.complete(recId),
    onSuccess: () => { toast.success('Reconciliation completed'); onDone() },
    onError: (e) => toast.error(e.message),
  })

  if (isLoading || !data) return <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  const s = data.summary

  return (
    <div className="space-y-4">
      {/* Summary bar */}
      <div className="kpi-3d grid grid-cols-2 sm:grid-cols-4 gap-4">
        <Stat label="Opening" value={inr(s.opening_balance)} />
        <Stat label="Cleared movement" value={inr(s.cleared_movement)} />
        <Stat label="Statement balance" value={inr(s.statement_balance)} />
        <Stat label="Difference" value={inr(s.difference)} color={s.is_balanced ? '#10b981' : '#f87171'} />
      </div>

      <div className="flex items-center gap-2">
        <button className="btn-3d flex items-center gap-2" onClick={() => auto.mutate()} disabled={auto.isPending}>
          {auto.isPending ? <Loader2 size={15} className="animate-spin" /> : <Wand2 size={15} />} Auto-match
        </button>
        <button className="btn-3d flex items-center gap-2" onClick={() => complete.mutate()} disabled={!s.is_balanced || complete.isPending}
          style={!s.is_balanced ? { opacity: 0.5 } : undefined}>
          <CheckCircle2 size={15} /> Finish
        </button>
        <GhostButton onClick={onCancel}><span className="flex items-center gap-1.5"><X size={14} /> Cancel</span></GhostButton>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {/* Book lines */}
        <div>
          <p className="label-caps mb-2">Book entries (tick when they appear on the statement)</p>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th style={{ width: 36 }}></th><th>Voucher</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
              <tbody>
                {data.book_lines.length === 0 && <tr><td colSpan={3} style={{ color: 'var(--text-muted)' }}>No unreconciled entries.</td></tr>}
                {data.book_lines.map(l => (
                  <tr key={l.voucher_line_id}>
                    <td><input type="checkbox" checked={l.cleared} onChange={() => toggle.mutate(l.voucher_line_id)} /></td>
                    <td>
                      <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{l.number}</span>
                      <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{fmtDate(l.date)}{l.narration ? ` · ${l.narration}` : ''}</div>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {l.debit > 0 ? <span style={{ color: '#10b981' }}>+{inr(l.debit)}</span> : <span style={{ color: '#f87171' }}>−{inr(l.credit)}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Statement lines */}
        <div>
          <p className="label-caps mb-2">Statement lines</p>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Date</th><th>Description</th><th style={{ textAlign: 'right' }}>Amount</th><th></th></tr></thead>
              <tbody>
                {data.statement_lines.length === 0 && <tr><td colSpan={4} style={{ color: 'var(--text-muted)' }}>No statement lines. Import a statement first.</td></tr>}
                {data.statement_lines.map(l => (
                  <tr key={l.id}>
                    <td>{fmtDate(l.txn_date)}</td>
                    <td style={{ color: 'var(--text-muted)' }}>{l.description || l.ref_no || '—'}</td>
                    <td style={{ textAlign: 'right' }}>
                      {Number(l.credit) > 0 ? <span style={{ color: '#10b981' }}>+{inr(l.credit)}</span> : <span style={{ color: '#f87171' }}>−{inr(l.debit)}</span>}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <span className="text-[10px] px-1.5 py-0.5 rounded-md capitalize" style={{ background: 'var(--bg-hover)', color: l.match_status === 'matched' ? '#10b981' : 'var(--text-muted)' }}>{l.match_status}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  )
}

function Stat({ label, value, color }) {
  return (
    <div>
      <p className="text-[10px] uppercase font-bold" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-lg font-black" style={{ color: color || 'var(--text-h)' }}>{value}</p>
    </div>
  )
}
