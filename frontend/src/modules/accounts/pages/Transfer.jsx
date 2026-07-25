import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeftRight, Repeat, Tags, Ban, Loader2, StickyNote } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import Drawer from '@/components/ui/Drawer'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import FormField, { Input, Select } from '@/components/ui/FormField'
import { GhostButton } from '@/modules/accounts/components/Btn'
import TransferCategoriesManager from '@/modules/accounts/components/TransferCategoriesManager'
import RichTextEditor from '@/components/ui/RichTextEditor'

const GROUP_LABELS = {
  own: 'My Accounts (Bank, Cash, GST, TDS…)',
  client: 'Clients',
  vendor: 'Vendors',
  tpv: 'Third-Party Vendors',
}

const emptyForm = () => ({
  from_ledger_id: '', to_ledger_id: '', date: new Date().toISOString().slice(0, 10),
  amount: '', transfer_category_id: '', notes_html: '',
})

const stripHtml = (html) => { const el = document.createElement('div'); el.innerHTML = html || ''; return el.textContent.trim() }

/**
 * Transfer Funds — move money between any two accounts (your own, a client, a
 * vendor, or a third-party vendor) with one simple form. The correct posting
 * (internal transfer / payment out / receipt in) is chosen automatically on
 * the server — the user only ever picks From, To, Amount, Category, and Note.
 * Client/Vendor/TPV groups are empty until those modules exist; they'll appear
 * here the moment the first such ledger is created — nothing else to build.
 */
export default function Transfer() {
  const inr = useInr()
  const toast = useToast()
  const qc = useQueryClient()
  const [form, setForm] = useState(emptyForm())
  const [manageCategories, setManageCategories] = useState(false)
  const [confirmCancel, setConfirmCancel] = useState(null)

  const { data: accounts } = useQuery({ queryKey: ['accounts', 'transfers', 'accounts'], queryFn: accountsApi.transfers.accounts })
  const { data: categories = [] } = useQuery({ queryKey: ['accounts', 'transfer-categories'], queryFn: accountsApi.transferCategories.list })
  const { data: page, isLoading: historyLoading } = useQuery({ queryKey: ['accounts', 'transfers', 'history'], queryFn: () => accountsApi.transfers.history({ per_page: 50 }) })
  const history = page?.data ?? []

  const invalidate = () => { qc.invalidateQueries({ queryKey: ['accounts', 'transfers'] }); qc.invalidateQueries({ queryKey: ['accounts', 'ledgers'] }) }

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const swapAccounts = () => setForm(p => ({ ...p, from_ledger_id: p.to_ledger_id, to_ledger_id: p.from_ledger_id }))

  const submit = useMutation({
    mutationFn: () => accountsApi.vouchers.transfer({
      ...form,
      amount: Number(form.amount),
      transfer_category_id: form.transfer_category_id || null,
      narration: stripHtml(form.notes_html).slice(0, 255),
      notes_html: form.notes_html || null,
    }),
    onSuccess: (v) => { toast.success(`Transferred — voucher ${v.number}`); setForm(emptyForm()); invalidate() },
    onError: (e) => toast.error(e.message),
  })

  const cancelTransfer = useMutation({
    mutationFn: (id) => accountsApi.transfers.cancel(id, 'Transfer reversed'),
    onSuccess: () => { toast.success('Transfer reversed'); setConfirmCancel(null); invalidate() },
    onError: (e) => { toast.error(e.message); setConfirmCancel(null) },
  })

  const valid = form.from_ledger_id && form.to_ledger_id && form.from_ledger_id !== form.to_ledger_id && Number(form.amount) > 0 && form.date

  const groupOptions = (excludeId) => Object.entries(GROUP_LABELS)
    .map(([key, label]) => [key, label, (accounts?.[key] || []).filter(a => String(a.id) !== String(excludeId))])
    .filter(([, , rows]) => rows.length > 0)

  const findName = (id, excludeId) => groupOptions(excludeId).flatMap(([, , rows]) => rows).find(a => String(a.id) === String(id))?.name

  const columns = [
    { key: 'date', label: 'Date', sortable: true, render: (r) => fmtDate(r.date) },
    { key: 'number', label: 'Voucher', render: (r) => <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{r.number}</span> },
    { key: 'from', label: 'From → To', render: (r) => {
      const to = r.lines?.find(l => Number(l.debit) > 0)?.ledger?.name
      const from = r.lines?.find(l => Number(l.credit) > 0)?.ledger?.name
      return <span>{from || '—'} <ArrowLeftRight size={11} className="inline mx-1" style={{ color: 'var(--text-muted)' }} /> {to || '—'}</span>
    } },
    { key: 'amount', label: 'Amount', align: 'right', render: (r) => inr(r.total_amount) },
    { key: 'category', label: 'Category', render: (r) => r.transfer_category?.name
        ? <span className="text-xs px-2 py-0.5 rounded-md" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>{r.transfer_category.name}</span>
        : <span style={{ color: 'var(--text-muted)' }}>—</span> },
    { key: 'narration', label: 'Note', render: (r) => r.notes_html
        ? <div className="rich-content text-xs max-w-xs truncate" style={{ color: 'var(--text-muted)' }} dangerouslySetInnerHTML={{ __html: r.notes_html }} />
        : <span style={{ color: 'var(--text-muted)' }}>{r.narration || '—'}</span> },
    { key: 'status', label: 'Status', render: (r) => r.is_reversal
        ? <span className="text-xs font-bold" style={{ color: '#a78bfa' }}>reversal</span>
        : r.reversed_by_count > 0
          ? <span className="text-xs font-bold" style={{ color: '#f87171' }}>reversed</span>
          : <span className="text-xs font-bold" style={{ color: '#10b981' }}>posted</span> },
    { key: 'actions', label: '', align: 'right', render: (r) => (
      !r.is_reversal && !r.reversed_by_count
        ? <button className="p-1.5 rounded-lg" title="Reverse this transfer" onClick={() => setConfirmCancel(r)} style={{ color: '#f87171' }}><Ban size={14} /></button>
        : null
    ) },
  ]

  return (
    <div className="space-y-6 animate-fade-in">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <ArrowLeftRight size={18} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Transfer Funds</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Move money between any two accounts — your own, a client, a vendor, or a third-party vendor</p>
          </div>
        </div>
        <GhostButton className="flex items-center gap-2" onClick={() => setManageCategories(true)}><Tags size={15} /> Categories</GhostButton>
      </div>

      {/* Transfer card */}
      <div className="max-w-2xl rounded-3xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
        {/* Amount hero strip */}
        <div className="px-6 pt-6 pb-5" style={{ background: 'linear-gradient(135deg, rgba(124,58,237,0.10), rgba(167,139,250,0.03))', borderBottom: '1px solid var(--border)' }}>
          <label className="label-caps">Amount</label>
          <div className="flex items-center gap-2 mt-1">
            <span className="text-3xl font-black" style={{ color: '#a78bfa' }}>₹</span>
            <input
              type="number" step="0.01" placeholder="0.00" value={form.amount}
              onChange={e => sf('amount', e.target.value)}
              className="text-3xl font-black bg-transparent border-none outline-none w-full"
              style={{ color: 'var(--text-h)' }}
            />
          </div>
        </div>

        <div className="p-6 space-y-4">
          {/* From / Swap / To */}
          <div className="grid gap-2 items-end" style={{ gridTemplateColumns: '1fr auto 1fr' }}>
            <FormField label="From Account" required>
              <Select value={form.from_ledger_id} onChange={e => sf('from_ledger_id', e.target.value)}>
                <option value="">Select…</option>
                {groupOptions(form.to_ledger_id).map(([key, label, rows]) => (
                  <optgroup key={key} label={label}>
                    {rows.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
                  </optgroup>
                ))}
              </Select>
            </FormField>

            <button
              onClick={swapAccounts}
              disabled={!form.from_ledger_id && !form.to_ledger_id}
              title="Swap accounts"
              className="w-9 h-9 mb-0.5 rounded-full flex items-center justify-center flex-shrink-0 transition-transform hover:rotate-180 disabled:opacity-30"
              style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}
            >
              <Repeat size={15} />
            </button>

            <FormField label="To Account" required>
              <Select value={form.to_ledger_id} onChange={e => sf('to_ledger_id', e.target.value)}>
                <option value="">Select…</option>
                {groupOptions(form.from_ledger_id).map(([key, label, rows]) => (
                  <optgroup key={key} label={label}>
                    {rows.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
                  </optgroup>
                ))}
              </Select>
            </FormField>
          </div>

          {/* Live preview */}
          {form.from_ledger_id && form.to_ledger_id && Number(form.amount) > 0 && (
            <div className="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-semibold" style={{ background: 'rgba(16,185,129,0.08)', color: '#10b981' }}>
              {inr(form.amount)} will move from <b>{findName(form.from_ledger_id, form.to_ledger_id) || '—'}</b> to <b>{findName(form.to_ledger_id, form.from_ledger_id) || '—'}</b>
            </div>
          )}

          <FormField label="Date" required><Input type="date" value={form.date} onChange={e => sf('date', e.target.value)} /></FormField>

          <FormField label="Category" hint="Helps classify the transfer for reporting (reversal, refund, etc.)">
            <Select value={form.transfer_category_id} onChange={e => sf('transfer_category_id', e.target.value)}>
              <option value="">No category</option>
              {categories.filter(c => c.is_active).map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </FormField>

          {/* Note — rich-text composer, matching the Customer profile's Notes section */}
          <div>
            <label className="label-caps flex items-center gap-1.5 mb-1.5">
              <StickyNote size={12} style={{ color: '#f59e0b' }} /> Note
            </label>
            <RichTextEditor
              value={form.notes_html}
              onChange={v => sf('notes_html', v)}
              placeholder="e.g. Returned excess payment to client"
              minHeight={110}
            />
          </div>

          <button onClick={() => submit.mutate()} disabled={!valid || submit.isPending} className="btn-3d w-full flex items-center justify-center gap-2 disabled:opacity-50">
            {submit.isPending && <Loader2 size={15} className="animate-spin" />} Transfer
          </button>
        </div>
      </div>

      {/* History */}
      <div>
        <p className="label-caps mb-2">Recent Transfers</p>
        {historyLoading
          ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
          : <DataTable columns={columns} rows={history} />}
      </div>

      {/* Manage categories drawer */}
      {manageCategories && (
        <Drawer open onClose={() => setManageCategories(false)} title="Transfer Categories"
          footer={<GhostButton className="w-full" onClick={() => setManageCategories(false)}>Done</GhostButton>}>
          <TransferCategoriesManager />
        </Drawer>
      )}

      {confirmCancel && (
        <ConfirmDialog
          title="Reverse this transfer?"
          message={`A reversing entry will neutralise ${confirmCancel.number} in the books. The original stays on record.`}
          confirmLabel="Reverse"
          onCancel={() => setConfirmCancel(null)}
          onConfirm={() => cancelTransfer.mutate(confirmCancel.id)}
        />
      )}
    </div>
  )
}
