import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, ScrollText, Loader2, Trash2, Clock } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { inr, fmtDate } from '@/modules/accounts/format'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

const STATUS_COLORS = {
  issued: '#a78bfa', deposited: '#22d3ee', presented: '#22d3ee',
  cleared: '#10b981', bounced: '#f87171', cancelled: 'var(--text-muted)', post_dated: '#f59e0b',
}
const NEXT_STATUS = {
  post_dated: ['issued', 'deposited', 'cancelled'],
  issued: ['presented', 'deposited', 'cleared', 'bounced', 'cancelled'],
  deposited: ['presented', 'cleared', 'bounced', 'cancelled'],
  presented: ['cleared', 'bounced', 'cancelled'],
  bounced: ['deposited', 'cancelled'],
  cleared: [], cancelled: [],
}

export default function Cheques() {
  const toast = useToast()
  const qc = useQueryClient()
  const [filters, setFilters] = useState({ direction: '', status: '', search: '' })
  const [drawer, setDrawer] = useState(false)
  const [confirm, setConfirm] = useState(null)

  const { data: summary } = useQuery({ queryKey: ['accounts', 'cheques', 'summary'], queryFn: accountsApi.cheques.summary })
  const { data: banks = [] } = useQuery({ queryKey: ['accounts', 'bank-accounts'], queryFn: accountsApi.bankAccounts.list })
  const { data: page, isLoading } = useQuery({
    queryKey: ['accounts', 'cheques', filters],
    queryFn: () => accountsApi.cheques.list({ ...filters, per_page: 100 }),
  })
  const cheques = page?.data ?? []
  const invalidate = () => qc.invalidateQueries({ queryKey: ['accounts', 'cheques'] })

  const create = useMutation({
    mutationFn: (f) => accountsApi.cheques.create(f),
    onSuccess: () => { toast.success('Cheque recorded'); setDrawer(false); invalidate() },
    onError: (e) => toast.error(e.message),
  })
  const changeStatus = useMutation({
    mutationFn: ({ id, status }) => accountsApi.cheques.changeStatus(id, status),
    onSuccess: () => { toast.success('Status updated'); invalidate() },
    onError: (e) => toast.error(e.message),
  })
  const remove = useMutation({
    mutationFn: (id) => accountsApi.cheques.remove(id),
    onSuccess: () => { toast.success('Cheque deleted'); setConfirm(null); invalidate() },
    onError: (e) => { toast.error(e.message); setConfirm(null) },
  })

  const today = new Date().toISOString().slice(0, 10)
  const columns = [
    { key: 'cheque_no', label: 'Cheque', sortable: true, render: (r) => (
      <div>
        <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{r.cheque_no || '—'}</span>
        <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.party_name || '—'}</div>
      </div>
    ) },
    { key: 'direction', label: 'Dir', render: (r) => <span className="capitalize text-xs">{r.direction}</span> },
    { key: 'cheque_date', label: 'Date', sortable: true, render: (r) => {
      // pdc_due_date arrives as a full ISO datetime — compare date-only so "due today" is caught.
      const due = (r.pdc_due_date || '').slice(0, 10)
      const isDue = due && due <= today
      return (
        <span>{fmtDate(r.cheque_date)}
          {r.is_pdc && r.status === 'post_dated' && (
            <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10px]" style={{ color: isDue ? '#f87171' : '#f59e0b' }}>
              <Clock size={10} /> PDC {isDue ? 'due' : fmtDate(r.pdc_due_date)}
            </span>
          )}
        </span>
      )
    } },
    { key: 'amount', label: 'Amount', align: 'right', sortable: true, render: (r) => inr(r.amount) },
    { key: 'status', label: 'Status', render: (r) => <span className="text-xs font-bold capitalize" style={{ color: STATUS_COLORS[r.status] }}>{r.status.replace('_', ' ')}</span> },
    { key: 'actions', label: '', align: 'right', render: (r) => (
      <div className="flex items-center justify-end gap-2">
        {NEXT_STATUS[r.status]?.length > 0 && (
          <select className="input-3d text-xs" style={{ padding: '2px 6px', width: 'auto' }} value="" onChange={e => e.target.value && changeStatus.mutate({ id: r.id, status: e.target.value })}>
            <option value="">Move to…</option>
            {NEXT_STATUS[r.status].map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
          </select>
        )}
        <button className="p-1.5 rounded-lg" title="Delete" onClick={() => setConfirm(r)} style={{ color: '#f87171' }}><Trash2 size={14} /></button>
      </div>
    ) },
  ]

  const kpis = [
    ['Total', summary?.total], ['Pending', summary?.pending],
    ['PDC', summary?.pdc], ['PDC due', summary?.pdc_due], ['Bounced', summary?.bounced],
  ]

  return (
    <div className="space-y-5 animate-fade-in">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <ScrollText size={18} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Cheque Register</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Issued &amp; received cheques with PDC tracking</p>
          </div>
        </div>
        <button className="btn-3d flex items-center gap-2" onClick={() => setDrawer(true)}><Plus size={15} /> New Cheque</button>
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
        {kpis.map(([label, val]) => (
          <div key={label} className="kpi-3d">
            <p className="text-[10px] uppercase font-bold" style={{ color: 'var(--text-muted)' }}>{label}</p>
            <p className="text-xl font-black" style={{ color: label === 'PDC due' && val > 0 ? '#f87171' : 'var(--text-h)' }}>{val ?? '—'}</p>
          </div>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <input className="input-3d text-sm flex-1 min-w-[200px]" placeholder="Search cheque no / party…" value={filters.search} onChange={e => setFilters(f => ({ ...f, search: e.target.value }))} />
        <Select value={filters.direction} onChange={e => setFilters(f => ({ ...f, direction: e.target.value }))} style={{ maxWidth: 160 }}>
          <option value="">All directions</option><option value="issued">Issued</option><option value="received">Received</option>
        </Select>
        <Select value={filters.status} onChange={e => setFilters(f => ({ ...f, status: e.target.value }))} style={{ maxWidth: 160 }}>
          <option value="">All statuses</option>
          {Object.keys(NEXT_STATUS).map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
        </Select>
      </div>

      {isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        : <DataTable columns={columns} rows={cheques} />}

      {drawer && <ChequeDrawer banks={banks} saving={create.isPending} onClose={() => setDrawer(false)} onSave={(f) => create.mutate(f)} />}

      {confirm && (
        <ConfirmDialog title="Delete cheque?" message={`Cheque ${confirm.cheque_no || ''} for ${inr(confirm.amount)} will be removed.`} confirmLabel="Delete"
          onCancel={() => setConfirm(null)} onConfirm={() => remove.mutate(confirm.id)} />
      )}
    </div>
  )
}

function ChequeDrawer({ banks, saving, onClose, onSave }) {
  const [form, setForm] = useState({
    direction: 'issued', bank_account_id: '', cheque_no: '', cheque_date: new Date().toISOString().slice(0, 10),
    party_name: '', amount: '', is_pdc: false, pdc_due_date: '', memo: '',
  })
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const valid = form.direction && form.cheque_date && form.amount !== ''

  return (
    <Drawer open onClose={onClose} title="New Cheque"
      footer={
        <div className="flex gap-3">
          <GhostButton className="flex-1" onClick={onClose}>Cancel</GhostButton>
          <button className="btn-3d flex-1 flex items-center justify-center gap-2" disabled={!valid || saving}
            onClick={() => onSave({ ...form, amount: Number(form.amount), bank_account_id: form.bank_account_id || null, pdc_due_date: form.pdc_due_date || null })}>
            {saving && <Loader2 size={15} className="animate-spin" />} Save
          </button>
        </div>
      }>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <FormField label="Direction" required>
            <Select value={form.direction} onChange={e => set('direction', e.target.value)}>
              <option value="issued">Issued (we pay)</option>
              <option value="received">Received (we get)</option>
            </Select>
          </FormField>
          <FormField label="Bank account">
            <Select value={form.bank_account_id} onChange={e => set('bank_account_id', e.target.value)}>
              <option value="">—</option>
              {banks.map(b => <option key={b.id} value={b.id}>{b.bank_name || b.ledger?.name}</option>)}
            </Select>
          </FormField>
          <FormField label="Cheque no."><Input value={form.cheque_no} onChange={e => set('cheque_no', e.target.value)} /></FormField>
          <FormField label="Amount" required><Input type="number" step="0.01" value={form.amount} onChange={e => set('amount', e.target.value)} /></FormField>
          <FormField label="Cheque date" required><Input type="date" value={form.cheque_date} onChange={e => set('cheque_date', e.target.value)} /></FormField>
          <FormField label="Party name"><Input value={form.party_name} onChange={e => set('party_name', e.target.value)} /></FormField>
        </div>
        <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={form.is_pdc} onChange={e => set('is_pdc', e.target.checked)} /> Post-dated cheque (PDC)
        </label>
        {(form.is_pdc) && (
          <FormField label="PDC due date" hint="Defaults to the cheque date.">
            <Input type="date" value={form.pdc_due_date} onChange={e => set('pdc_due_date', e.target.value)} />
          </FormField>
        )}
        <FormField label="Memo"><Input value={form.memo} onChange={e => set('memo', e.target.value)} /></FormField>
      </div>
    </Drawer>
  )
}
