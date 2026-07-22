import { useState, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, Landmark, Loader2, Upload, Scale, Trash2, Pencil } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { inr } from '@/modules/accounts/format'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

const ACCOUNT_TYPES = [['savings', 'Savings'], ['current', 'Current'], ['od', 'Overdraft'], ['cc', 'Cash Credit'], ['other', 'Other']]

export default function BankAccounts() {
  const toast = useToast()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [drawer, setDrawer] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [importing, setImporting] = useState(null)
  const fileRef = useRef(null)

  const { data: banks = [], isLoading } = useQuery({ queryKey: ['accounts', 'bank-accounts'], queryFn: accountsApi.bankAccounts.list })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['accounts'] })

  const save = useMutation({
    mutationFn: (f) => f.id ? accountsApi.bankAccounts.update(f.id, f) : accountsApi.bankAccounts.create(f),
    onSuccess: () => { toast.success('Bank account saved'); setDrawer(null); invalidate() },
    onError: (e) => toast.error(e.message),
  })
  const remove = useMutation({
    mutationFn: (id) => accountsApi.bankAccounts.remove(id),
    onSuccess: (r) => { toast.success(`Bank account ${r.outcome}`); setConfirm(null); invalidate() },
    onError: (e) => { toast.error(e.message); setConfirm(null) },
  })
  const doImport = useMutation({
    mutationFn: ({ bankId, file }) => accountsApi.statements.import(bankId, file),
    onSuccess: (r) => { toast.success(`Imported ${r.imported} lines (${r.skipped} skipped)`); setImporting(null); invalidate() },
    onError: (e) => { toast.error(e.message); setImporting(null) },
  })

  const onPickFile = (bank) => { setImporting(bank); fileRef.current?.click() }
  const onFile = (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (file && importing) doImport.mutate({ bankId: importing.id, file })
  }

  const columns = [
    { key: 'bank_name', label: 'Bank', sortable: true, render: (r) => (
      <div>
        <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{r.bank_name || r.ledger?.name}</span>
        {!r.is_active && <span className="ml-2 text-[10px] px-1.5 py-0.5 rounded-md" style={{ background: 'var(--bg-hover)', color: 'var(--text-muted)' }}>inactive</span>}
        <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.account_no || '—'}{r.ifsc ? ` · ${r.ifsc}` : ''}</div>
      </div>
    ) },
    { key: 'account_type', label: 'Type', render: (r) => <span className="capitalize">{r.account_type}</span> },
    { key: 'current_balance', label: 'Balance', align: 'right', sortable: true, render: (r) => <span className="font-bold" style={{ color: 'var(--text-h)' }}>{inr(r.current_balance)}</span> },
    { key: 'actions', label: '', align: 'right', render: (r) => (
      <div className="flex items-center justify-end gap-1">
        <button className="p-1.5 rounded-lg" title="Import statement" onClick={() => onPickFile(r)} style={{ color: '#a78bfa' }}><Upload size={14} /></button>
        <button className="p-1.5 rounded-lg" title="Reconcile" onClick={() => navigate(`/app/accounts/banking/${r.id}/reconcile`)} style={{ color: '#10b981' }}><Scale size={14} /></button>
        <button className="p-1.5 rounded-lg" title="Edit" onClick={() => setDrawer(r)} style={{ color: 'var(--text-muted)' }}><Pencil size={14} /></button>
        <button className="p-1.5 rounded-lg" title="Delete" onClick={() => setConfirm(r)} style={{ color: '#f87171' }}><Trash2 size={14} /></button>
      </div>
    ) },
  ]

  return (
    <div className="space-y-5 animate-fade-in">
      <input ref={fileRef} type="file" accept=".csv,.txt,.xlsx,.xls" className="hidden" onChange={onFile} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <Landmark size={18} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Bank Accounts</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Each account is backed by a bank ledger · import statements &amp; reconcile</p>
          </div>
        </div>
        <button className="btn-3d flex items-center gap-2" onClick={() => setDrawer({})}><Plus size={15} /> New Bank Account</button>
      </div>

      {(isLoading || doImport.isPending)
        ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        : <DataTable columns={columns} rows={banks} />}

      {drawer && <BankDrawer bank={drawer} saving={save.isPending} onClose={() => setDrawer(null)} onSave={(f) => save.mutate(f)} />}

      {confirm && (
        <ConfirmDialog
          title="Delete bank account?"
          message={`"${confirm.bank_name}" will be deleted if its ledger has no postings, otherwise deactivated.`}
          confirmLabel="Proceed"
          onCancel={() => setConfirm(null)}
          onConfirm={() => remove.mutate(confirm.id)}
        />
      )}
    </div>
  )
}

function BankDrawer({ bank, saving, onClose, onSave }) {
  const [form, setForm] = useState({
    id: bank.id,
    name: bank.ledger?.name || bank.bank_name || '',
    bank_name: bank.bank_name || '',
    account_no: bank.account_no || '',
    ifsc: bank.ifsc || '',
    branch: bank.branch || '',
    account_type: bank.account_type || 'current',
    opening_balance: bank.id ? undefined : 0,
    opening_balance_type: 'dr',
  })
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const valid = form.name.trim()

  return (
    <Drawer open onClose={onClose} title={form.id ? 'Edit Bank Account' : 'New Bank Account'}
      footer={
        <div className="flex gap-3">
          <GhostButton className="flex-1" onClick={onClose}>Cancel</GhostButton>
          <button className="btn-3d flex-1 flex items-center justify-center gap-2" disabled={!valid || saving} onClick={() => onSave(form)}>
            {saving && <Loader2 size={15} className="animate-spin" />} Save
          </button>
        </div>
      }>
      <div className="space-y-4">
        <FormField label="Account name (ledger)" required hint="Created under the Bank Accounts group.">
          <Input value={form.name} onChange={e => set('name', e.target.value)} placeholder="e.g. HDFC Current A/c" />
        </FormField>
        <div className="grid grid-cols-2 gap-3">
          <FormField label="Bank name"><Input value={form.bank_name} onChange={e => set('bank_name', e.target.value)} /></FormField>
          <FormField label="Type">
            <Select value={form.account_type} onChange={e => set('account_type', e.target.value)}>
              {ACCOUNT_TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          </FormField>
          <FormField label="Account no."><Input value={form.account_no} onChange={e => set('account_no', e.target.value)} /></FormField>
          <FormField label="IFSC"><Input value={form.ifsc} onChange={e => set('ifsc', e.target.value)} /></FormField>
          <FormField label="Branch"><Input value={form.branch} onChange={e => set('branch', e.target.value)} /></FormField>
        </div>
        {!form.id && (
          <div className="grid grid-cols-2 gap-3">
            <FormField label="Opening balance"><Input type="number" step="0.01" value={form.opening_balance} onChange={e => set('opening_balance', e.target.value)} /></FormField>
            <FormField label="Type">
              <Select value={form.opening_balance_type} onChange={e => set('opening_balance_type', e.target.value)}>
                <option value="dr">Debit (positive)</option>
                <option value="cr">Credit (overdrawn)</option>
              </Select>
            </FormField>
          </div>
        )}
      </div>
    </Drawer>
  )
}
