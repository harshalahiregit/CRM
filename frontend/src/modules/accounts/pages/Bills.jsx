import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Plus, Receipt, Loader2, AlertTriangle, Paperclip, RefreshCw,
  CheckCircle2, X, Trash2, Eye, Download, ExternalLink,
} from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

const EMPTY = {
  vendor_name: '', vendor_ledger_id: '', bill_number: '', bill_date: '', due_date: '',
  amount: '', expense_ledger_id: '', note: '',
  attachment: '', attachment_name: '',
  is_recurring: false, recurring_type: 'month', recurring_every: 1, recurring_cycles: '',
}

/**
 * Vendor bills (old-CRM "Bills" parity). A bill posts a Purchase voucher
 * (Dr expense ledger / Cr the vendor's control ledger) the moment it's
 * saved — there is no separate "converted / not converted" limbo state like
 * the reference build's manual posting step. A bill must be Approved
 * before it can be paid (old-CRM "Approve payable"); paying it posts a
 * second Payment voucher and never edits the original. Recurring bills
 * don't run on a background schedule — "Generate Next" is a one-click
 * manual trigger, same trust boundary as everything else that posts here.
 */
export default function Bills() {
  const inr = useInr()
  const toast = useToast()
  const qc = useQueryClient()
  const [filters, setFilters] = useState({ status: '', vendor: '' })
  const [drawer, setDrawer] = useState(false)
  const [viewBill, setViewBill] = useState(null)      // bill detail slide-over
  const [payingBill, setPayingBill] = useState(null)
  const [deleteBill, setDeleteBill] = useState(null)
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
  const bankLedgers    = ledgers.filter(l => l.is_bank || l.is_cash)

  const create = useMutation({
    mutationFn: () => accountsApi.bills.create({
      ...form, amount: Number(form.amount),
      vendor_ledger_id: form.vendor_ledger_id ? Number(form.vendor_ledger_id) : undefined,
      recurring_every: form.is_recurring ? Number(form.recurring_every) || 1 : null,
      recurring_cycles: form.is_recurring && form.recurring_cycles ? Number(form.recurring_cycles) : null,
    }),
    onSuccess: () => { toast.success('Bill posted'); setDrawer(false); setForm(EMPTY); invalidate() },
    onError: (e) => toast.error(e.message),
  })

  const approve = useMutation({
    mutationFn: (bill) => accountsApi.bills.approve(bill.id),
    onSuccess: () => { toast.success('Bill approved'); invalidate() },
    onError: (e) => toast.error(e.message),
  })

  const recur = useMutation({
    mutationFn: (bill) => accountsApi.bills.recur(bill.id),
    onSuccess: () => { toast.success('Next bill generated'); invalidate() },
    onError: (e) => toast.error(e.message),
  })

  const pay = useMutation({
    mutationFn: () => accountsApi.bills.pay(payingBill.id, payForm),
    onSuccess: () => { toast.success('Bill marked paid'); setPayingBill(null); invalidate() },
    onError: (e) => toast.error(e.message),
  })

  const remove = useMutation({
    mutationFn: () => accountsApi.bills.remove(deleteBill.id),
    onSuccess: () => { toast.success('Bill deleted'); setDeleteBill(null); invalidate() },
    onError: (e) => { toast.error(e.message); setDeleteBill(null) },
  })

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const onFile = (e) => {
    const f = e.target.files?.[0]
    if (!f) return
    if (f.size > 1024 * 1024) return toast.error('Attachment must be under 1MB')
    const reader = new FileReader()
    reader.onload = () => setForm(p => ({ ...p, attachment: reader.result, attachment_name: f.name }))
    reader.readAsDataURL(f)
  }

  // Summary KPIs
  const total    = bills.length
  const unpaid   = bills.filter(b => b.status === 'unpaid').length
  const overdue  = bills.filter(b => b.status === 'unpaid' && new Date(b.due_date) < new Date()).length
  const paid     = bills.filter(b => b.status === 'paid').length

  const columns = [
    {
      key: 'vendor_name', label: 'Vendor',
      render: (r) => (
        <div>
          <span className="font-bold" style={{ color: 'var(--text-h)' }}>{r.vendor_name}</span>
          {r.is_recurring && <RefreshCw size={10} className="inline ml-1.5" style={{ color: '#a78bfa', marginBottom: 1 }} title="Recurring bill" />}
          {r.attachment_name && <Paperclip size={10} className="inline ml-1" style={{ color: 'var(--text-muted)', marginBottom: 1 }} title={r.attachment_name} />}
          {r.bill_number && <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.bill_number}</div>}
        </div>
      ),
    },
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
    { key: 'amount', label: 'Amount', align: 'right', render: (r) => <span className="font-bold">{inr(r.amount)}</span> },
    {
      key: 'status', label: 'Status',
      render: (r) => {
        const overdue = r.status === 'unpaid' && new Date(r.due_date) < new Date()
        const label = overdue ? 'Overdue' : r.status === 'paid' ? 'Paid' : r.approved ? 'Approved' : 'Pending'
        const color = overdue ? '#f87171' : r.status === 'paid' ? '#10b981' : r.approved ? '#22d3ee' : '#f59e0b'
        return <span className="px-2 py-0.5 rounded-full text-[11px] font-bold" style={{ background: `${color}22`, color }}>{label}</span>
      },
    },
    {
      key: 'actions', label: '',
      render: (r) => (
        <div className="flex items-center gap-1.5 justify-end">
          {/* View detail */}
          <button title="View details" onClick={() => setViewBill(r)}
            className="p-1.5 rounded-lg hover:opacity-80 transition-opacity"
            style={{ color: '#22d3ee' }}>
            <Eye size={14} />
          </button>
          {r.status === 'unpaid' && !r.approved && (
            <GhostButton className="!py-1.5 !px-3 text-xs flex items-center gap-1" onClick={() => approve.mutate(r)} disabled={approve.isPending}>
              <CheckCircle2 size={12} /> Approve
            </GhostButton>
          )}
          {r.status === 'unpaid' && r.approved && (
            <GhostButton className="!py-1.5 !px-3 text-xs" onClick={() => { setPayingBill(r); setPayForm({ bank_ledger_id: bankLedgers[0]?.id || '', paid_date: new Date().toISOString().slice(0, 10) }) }}>
              Pay
            </GhostButton>
          )}
          {r.status === 'paid' && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.paid_voucher?.number}</span>}
          {r.is_recurring && (
            <button title="Generate next occurrence" onClick={() => recur.mutate(r)} disabled={recur.isPending}
              className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)]">
              <RefreshCw size={13} style={{ color: 'var(--accent, #a78bfa)' }} />
            </button>
          )}
          {r.status !== 'paid' && (
            <button title="Delete bill" onClick={() => setDeleteBill(r)}
              className="p-1.5 rounded-lg hover:opacity-80"
              style={{ color: '#f87171' }}>
              <Trash2 size={13} />
            </button>
          )}
        </div>
      ),
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
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Vendor bills — Approve, then Pay. Posts a Purchase voucher immediately.</p>
          </div>
        </div>
        <button onClick={() => setDrawer(true)} className="btn-3d flex items-center gap-2"><Plus size={15} /> New Bill</button>
      </div>

      {/* Summary KPI strip */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {[
          { label: 'Total Bills',  value: total,  color: 'var(--text-h)' },
          { label: 'Unpaid',       value: unpaid,  color: '#f59e0b' },
          { label: 'Overdue',      value: overdue, color: overdue > 0 ? '#f87171' : 'var(--text-muted)' },
          { label: 'Paid',         value: paid,    color: '#10b981' },
        ].map(({ label, value, color }) => (
          <div key={label} className="kpi-3d">
            <p className="text-[10px] uppercase font-bold" style={{ color: 'var(--text-muted)' }}>{label}</p>
            <p className="text-2xl font-black mt-1" style={{ color }}>{value}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
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

      {/* ── New Bill Drawer ──────────────────────────────── */}
      <Drawer open={drawer} onClose={() => setDrawer(false)} title="New Bill"
        footer={<button onClick={() => create.mutate()} disabled={create.isPending} className="btn-3d w-full">{create.isPending ? 'Posting…' : 'Post Bill'}</button>}>
        <div className="space-y-4">
          <FormField label="Vendor Name" required hint="A party ledger is auto-created under Sundry Creditors">
            <Input value={form.vendor_name} onChange={e => sf('vendor_name', e.target.value)} placeholder="e.g. Acme Supplies" />
          </FormField>
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

          <FormField label="Attachment" hint="Invoice/receipt copy, max 1MB">
            {form.attachment_name ? (
              <div className="flex items-center gap-2 text-xs px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <Paperclip size={13} style={{ color: 'var(--text-muted)' }} />
                <span className="flex-1 truncate" style={{ color: 'var(--text-h)' }}>{form.attachment_name}</span>
                <button onClick={() => sf('attachment', '') || sf('attachment_name', '')}><X size={13} style={{ color: 'var(--text-muted)' }} /></button>
              </div>
            ) : (
              <input type="file" accept="image/*,.pdf" onChange={onFile} className="text-xs" style={{ color: 'var(--text-muted)' }} />
            )}
          </FormField>

          <FormField label="Note"><Textarea rows={2} value={form.note} onChange={e => sf('note', e.target.value)} /></FormField>

          <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-h)' }}>
            <input type="checkbox" checked={form.is_recurring} onChange={e => sf('is_recurring', e.target.checked)} /> Recurring bill
          </label>
          {form.is_recurring && (
            <div className="grid grid-cols-3 gap-3 p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <FormField label="Every">
                <div className="flex items-center gap-1.5">
                  <Input type="number" min="1" value={form.recurring_every} onChange={e => sf('recurring_every', e.target.value)} />
                  <Select value={form.recurring_type} onChange={e => sf('recurring_type', e.target.value)}>
                    <option value="week">week(s)</option>
                    <option value="month">month(s)</option>
                    <option value="year">year(s)</option>
                  </Select>
                </div>
              </FormField>
              <FormField label="Cycles" hint="Blank = indefinite">
                <Input type="number" min="1" value={form.recurring_cycles} onChange={e => sf('recurring_cycles', e.target.value)} placeholder="∞" />
              </FormField>
            </div>
          )}
        </div>
      </Drawer>

      {/* ── Bill Detail Drawer ───────────────────────────── */}
      {viewBill && <BillDetailDrawer bill={viewBill} bankLedgers={bankLedgers} onClose={() => setViewBill(null)}
        onApprove={() => { approve.mutate(viewBill); setViewBill(null) }}
        onPay={() => { setPayingBill(viewBill); setViewBill(null); setPayForm({ bank_ledger_id: bankLedgers[0]?.id || '', paid_date: new Date().toISOString().slice(0, 10) }) }}
      />}

      {/* ── Pay Drawer ───────────────────────────────────── */}
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

      {/* ── Delete Confirm ───────────────────────────────── */}
      {deleteBill && (
        <ConfirmDialog
          title="Delete bill?"
          message={`Bill from ${deleteBill.vendor_name} for ${inr(deleteBill.amount)} will be removed and its voucher cancelled. This cannot be undone.`}
          confirmLabel="Delete"
          onCancel={() => setDeleteBill(null)}
          onConfirm={() => remove.mutate()}
        />
      )}
    </div>
  )
}

/** Bill detail slide-over — shows all fields, attachment link, voucher link */
function BillDetailDrawer({ bill, bankLedgers, onClose, onApprove, onPay }) {
  const inr = useInr()
  const overdue = bill.status === 'unpaid' && new Date(bill.due_date) < new Date()
  const statusLabel = overdue ? 'Overdue' : bill.status === 'paid' ? 'Paid' : bill.approved ? 'Approved' : 'Pending'
  const statusColor = overdue ? '#f87171' : bill.status === 'paid' ? '#10b981' : bill.approved ? '#22d3ee' : '#f59e0b'

  return (
    <Drawer open onClose={onClose} title="Bill Details"
      footer={
        <div className="flex gap-2">
          {bill.status === 'unpaid' && !bill.approved && (
            <button className="btn-3d flex-1" onClick={onApprove}><CheckCircle2 size={14} className="inline mr-1" />Approve</button>
          )}
          {bill.status === 'unpaid' && bill.approved && (
            <button className="btn-3d flex-1" onClick={onPay}>Record Payment</button>
          )}
          <GhostButton className="flex-1" onClick={onClose}>Close</GhostButton>
        </div>
      }>
      <div className="space-y-4">
        {/* Status banner */}
        <div className="flex items-center justify-between px-4 py-3 rounded-xl" style={{ background: `${statusColor}15`, border: `1px solid ${statusColor}30` }}>
          <span className="text-sm font-bold" style={{ color: statusColor }}>{statusLabel}</span>
          {bill.is_recurring && <span className="text-xs font-semibold flex items-center gap-1" style={{ color: '#a78bfa' }}><RefreshCw size={11} /> Recurring</span>}
        </div>

        {/* Details table */}
        <div className="space-y-2">
          {[
            ['Vendor',     bill.vendor_name],
            ['Bill No.',   bill.bill_number || '—'],
            ['Bill Date',  fmtDate(bill.bill_date)],
            ['Due Date',   fmtDate(bill.due_date)],
            ['Amount',     <span className="font-black" style={{ color: 'var(--text-h)' }}>{inr(bill.amount)}</span>],
            bill.voucher?.number && ['Voucher',   <a href={`/app/accounts/vouchers/${bill.voucher?.id}`} className="inline-flex items-center gap-1 text-xs font-semibold" style={{ color: '#22d3ee' }}>{bill.voucher?.number} <ExternalLink size={10} /></a>],
            bill.paid_voucher?.number && ['Payment Voucher', <a href={`/app/accounts/vouchers/${bill.paid_voucher?.id}`} className="inline-flex items-center gap-1 text-xs font-semibold" style={{ color: '#10b981' }}>{bill.paid_voucher?.number} <ExternalLink size={10} /></a>],
            bill.note && ['Note', <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{bill.note}</span>],
          ].filter(Boolean).map(([label, value]) => (
            <div key={label} className="flex items-start justify-between gap-4 text-sm py-1.5" style={{ borderBottom: '1px solid var(--border)' }}>
              <span style={{ color: 'var(--text-muted)', minWidth: 110 }}>{label}</span>
              <span style={{ color: 'var(--text-h)', textAlign: 'right' }}>{value}</span>
            </div>
          ))}
        </div>

        {/* Attachment */}
        {bill.attachment_name && (
          <div className="flex items-center gap-3 p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <Paperclip size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
            <span className="flex-1 text-xs truncate" style={{ color: 'var(--text-h)' }}>{bill.attachment_name}</span>
            {bill.attachment && (
              <a href={bill.attachment} download={bill.attachment_name}
                className="flex items-center gap-1 text-xs font-bold" style={{ color: '#22d3ee' }}>
                <Download size={13} /> Download
              </a>
            )}
          </div>
        )}

        {/* Recurring details */}
        {bill.is_recurring && (
          <div className="p-3 rounded-xl text-xs space-y-1" style={{ background: 'rgba(167,139,250,0.08)', border: '1px solid rgba(167,139,250,0.2)' }}>
            <p className="font-bold" style={{ color: '#a78bfa' }}>Recurring Schedule</p>
            <p style={{ color: 'var(--text-muted)' }}>
              Every {bill.recurring_every} {bill.recurring_type}(s)
              {bill.recurring_cycles ? ` · ${bill.recurring_done ?? 0}/${bill.recurring_cycles} done` : ' · indefinite'}
            </p>
            {bill.next_recurrence_date && (
              <p style={{ color: 'var(--text-muted)' }}>Next: {fmtDate(bill.next_recurrence_date)}</p>
            )}
          </div>
        )}
      </div>
    </Drawer>
  )
}
