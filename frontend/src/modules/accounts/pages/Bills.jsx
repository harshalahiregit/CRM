import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Receipt, Loader2, AlertTriangle } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { inr, fmtDate } from '@/modules/accounts/format'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'
import { GhostButton } from '@/modules/accounts/components/Btn'

const EMPTY = { vendor_name: '', bill_number: '', bill_date: '', due_date: '', amount: '', expense_ledger_id: '', note: '' }

/**
 * Vendor bills (old-CRM "Bills" parity). A bill posts a Purchase voucher
 * (Dr expense ledger / Cr the vendor's control ledger) the moment it's
 * saved — there is no separate "converted / not converted" limbo state like
 * the reference build's manual posting step; paying it posts a second
 * Payment voucher and never edits the original.
 */
export default function Bills() {
  const toast = useToast()
  const qc = useQueryClient()
  const [filters, setFilters] = useState({ status: '', vendor: '' })
  const [drawer, setDrawer] = useState(false)
  const [payingBill, setPayingBill] = useState(null)
  const [form, setForm] = useState(EMPTY)
  const [payForm, setPayForm] = useState({ bank_ledger_id: '', paid_date: new Date().toISOString().slice(0, 10) })

  const { data: ledgers = [] } = useQuery({ queryKey: ['accounts', 'ledgers', 'options'], queryFn: accountsApi.ledgers.options })
  const { data: page, isLoading } = useQuery({
    queryKey: ['accounts', 'bills', filters],
    queryFn: () => accountsApi.bills.list({ ...filters, per_page: 100 }),
  })
  const bills = page?.data ?? []
  const invalidate = () => qc.invalidateQueries({ queryKey: ['accounts', 'bills'] })

  const expenseLedgers = ledgers.filter(l => l.group?.nature === 'expense')
  const bankLedgers = ledgers.filter(l => l.is_bank || l.is_cash)

  const create = useMutation({
    mutationFn: () => accountsApi.bills.create({ ...form, amount: Number(form.amount) }),
    onSuccess: () => { toast.success('Bill posted'); setDrawer(false); setForm(EMPTY); invalidate() },
    onError: (e) => toast.error(e.message),
  })

  const pay = useMutation({
    mutationFn: () => accountsApi.bills.pay(payingBill.id, payForm),
    onSuccess: () => { toast.success('Bill marked paid'); setPayingBill(null); invalidate() },
    onError: (e) => toast.error(e.message),
  })

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const columns = [
    { key: 'vendor_name', label: 'Vendor', render: (r) => <span className="font-bold" style={{ color: 'var(--text-h)' }}>{r.vendor_name}</span> },
    { key: 'bill_number', label: 'Bill #', render: (r) => r.bill_number || '—' },
    { key: 'bill_date', label: 'Bill Date', render: (r) => fmtDate(r.bill_date) },
    {
      key: 'due_date', label: 'Due Date',
      render: (r) => (
        <span style={{ color: r.status === 'unpaid' && new Date(r.due_date) < new Date() ? '#f87171' : 'var(--text-h)' }}>
          {fmtDate(r.due_date)}
          {r.status === 'unpaid' && new Date(r.due_date) < new Date() && <AlertTriangle size={11} className="inline ml-1" style={{ marginBottom: 2 }} />}
        </span>
      ),
    },
    { key: 'amount', label: 'Amount', render: (r) => <span className="font-bold">{inr(r.amount)}</span> },
    {
      key: 'status', label: 'Status',
      render: (r) => {
        const overdue = r.status === 'unpaid' && new Date(r.due_date) < new Date()
        const label = overdue ? 'Overdue' : r.status === 'paid' ? 'Paid' : 'Unpaid'
        const color = overdue ? '#f87171' : r.status === 'paid' ? '#10b981' : '#f59e0b'
        return <span className="px-2 py-0.5 rounded-full text-[11px] font-bold" style={{ background: `${color}22`, color }}>{label}</span>
      },
    },
    {
      key: 'actions', label: '',
      render: (r) => r.status === 'unpaid' ? (
        <GhostButton className="!py-1.5 !px-3 text-xs" onClick={() => { setPayingBill(r); setPayForm({ bank_ledger_id: bankLedgers[0]?.id || '', paid_date: new Date().toISOString().slice(0, 10) }) }}>
          Pay
        </GhostButton>
      ) : <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.paid_voucher?.number}</span>,
    },
  ]

  return (
    <div className="space-y-5 animate-fade-in">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <Receipt size={18} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Bills</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Vendor bills — posts a Purchase voucher immediately</p>
          </div>
        </div>
        <button onClick={() => setDrawer(true)} className="btn-3d flex items-center gap-2"><Plus size={15} /> New Bill</button>
      </div>

      <div className="flex gap-2 flex-wrap">
        {[['', 'All'], ['unpaid', 'Unpaid'], ['overdue', 'Overdue'], ['paid', 'Paid']].map(([v, label]) => (
          <button key={v} onClick={() => setFilters(f => ({ ...f, status: v }))}
            className="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={filters.status === v
              ? { background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff' }
              : { background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
            {label}
          </button>
        ))}
      </div>

      {isLoading ? <Loader2 className="animate-spin mx-auto my-10" style={{ color: 'var(--text-muted)' }} /> : (
        <DataTable columns={columns} rows={bills} emptyState={<p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>No bills yet.</p>} keyField="id" />
      )}

      <Drawer open={drawer} onClose={() => setDrawer(false)} title="New Bill"
        footer={<button onClick={() => create.mutate()} disabled={create.isPending} className="btn-3d w-full">{create.isPending ? 'Posting…' : 'Post Bill'}</button>}>
        <div className="space-y-4">
          <FormField label="Vendor" required><Input value={form.vendor_name} onChange={e => sf('vendor_name', e.target.value)} placeholder="e.g. Acme Supplies" /></FormField>
          <div className="grid grid-cols-2 gap-3">
            <FormField label="Bill Number"><Input value={form.bill_number} onChange={e => sf('bill_number', e.target.value)} /></FormField>
            <FormField label="Amount" required><Input type="number" step="0.01" value={form.amount} onChange={e => sf('amount', e.target.value)} /></FormField>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <FormField label="Bill Date" required><Input type="date" value={form.bill_date} onChange={e => sf('bill_date', e.target.value)} /></FormField>
            <FormField label="Due Date" required><Input type="date" value={form.due_date} onChange={e => sf('due_date', e.target.value)} /></FormField>
          </div>
          <FormField label="Expense / Purchase Account" required hint="What this bill is for — debited when posted">
            <Select value={form.expense_ledger_id} onChange={e => sf('expense_ledger_id', e.target.value)}>
              <option value="">Select…</option>
              {expenseLedgers.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
            </Select>
          </FormField>
          <FormField label="Note"><Textarea rows={2} value={form.note} onChange={e => sf('note', e.target.value)} /></FormField>
        </div>
      </Drawer>

      <Drawer open={!!payingBill} onClose={() => setPayingBill(null)} title={`Pay ${payingBill?.vendor_name || ''}`}
        footer={<button onClick={() => pay.mutate()} disabled={pay.isPending} className="btn-3d w-full">{pay.isPending ? 'Paying…' : `Mark Paid — ${inr(payingBill?.amount)}`}</button>}>
        {payingBill && (
          <div className="space-y-4">
            <FormField label="Pay From" required>
              <Select value={payForm.bank_ledger_id} onChange={e => setPayForm(p => ({ ...p, bank_ledger_id: e.target.value }))}>
                <option value="">Select…</option>
                {bankLedgers.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
              </Select>
            </FormField>
            <FormField label="Payment Date" required><Input type="date" value={payForm.paid_date} onChange={e => setPayForm(p => ({ ...p, paid_date: e.target.value }))} /></FormField>
          </div>
        )}
      </Drawer>
    </div>
  )
}
