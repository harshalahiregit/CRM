import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Landmark, FolderTree, Search, Loader2, Trash2, Pencil } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { NATURE_LABEL } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import PagerBar from '@/components/ui/PagerBar'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

const NATURE_COLORS = {
  asset: '#10b981', liability: '#f59e0b', equity: '#a78bfa', income: '#22d3ee', expense: '#f87171',
}

export default function ChartOfAccounts() {
  const inr = useInr()
  const toast = useToast()
  const qc = useQueryClient()
  const [search, setSearch] = useState('')
  const [groupFilter, setGroupFilter] = useState('')
  const [ledgerDrawer, setLedgerDrawer] = useState(null)   // null | {} | ledger
  const [groupDrawer, setGroupDrawer] = useState(null)
  const [confirm, setConfirm] = useState(null)

  const { data: groups = [] } = useQuery({ queryKey: ['accounts', 'groups', 'flat'], queryFn: accountsApi.groups.flat })
  const [pageNo, setPageNo] = useState(1)
  // Search and the group filter both narrow the list, so both return to page 1.
  useEffect(() => { setPageNo(1) }, [search, groupFilter])

  const { data: ledgersPage, isLoading } = useQuery({
    queryKey: ['accounts', 'ledgers', { search, groupFilter }, pageNo],
    queryFn: () => accountsApi.ledgers.list({ search, group_id: groupFilter, per_page: 100, page: pageNo }),
    placeholderData: (prev) => prev,
  })
  const ledgers = ledgersPage?.data ?? []

  const invalidate = () => qc.invalidateQueries({ queryKey: ['accounts'] })

  const saveLedger = useMutation({
    mutationFn: (form) => form.id ? accountsApi.ledgers.update(form.id, form) : accountsApi.ledgers.create(form),
    onSuccess: () => { toast.success('Ledger saved'); setLedgerDrawer(null); invalidate() },
    onError: (e) => toast.error(e.message),
  })
  const removeLedger = useMutation({
    mutationFn: (id) => accountsApi.ledgers.remove(id),
    onSuccess: (r) => { toast.success(`Ledger ${r.outcome}`); setConfirm(null); invalidate() },
    onError: (e) => { toast.error(e.message); setConfirm(null) },
  })
  const saveGroup = useMutation({
    mutationFn: (form) => accountsApi.groups.create(form),
    onSuccess: () => { toast.success('Group created'); setGroupDrawer(null); invalidate() },
    onError: (e) => toast.error(e.message),
  })

  const columns = [
    { key: 'name', label: 'Ledger', sortable: true, render: (r) => (
      <div className="flex items-center gap-2">
        <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{r.name}</span>
        {!r.is_active && <span className="text-[10px] px-1.5 py-0.5 rounded-md" style={{ background: 'var(--bg-hover)', color: 'var(--text-muted)' }}>inactive</span>}
      </div>
    ) },
    { key: 'group', label: 'Group', render: (r) => r.group?.name || '—' },
    { key: 'nature', label: 'Nature', render: (r) => {
      const n = r.group?.nature
      return <span className="text-xs font-bold" style={{ color: NATURE_COLORS[n] || 'var(--text-muted)' }}>{NATURE_LABEL[n] || '—'}</span>
    } },
    { key: 'opening', label: 'Opening', align: 'right', render: (r) => (
      <span style={{ color: 'var(--text-muted)' }}>{inr(r.opening_balance)} {String(r.opening_balance_type).toUpperCase()}</span>
    ) },
    { key: 'actions', label: '', align: 'right', render: (r) => (
      <div className="flex items-center justify-end gap-1">
        <button className="p-1.5 rounded-lg" onClick={() => setLedgerDrawer(r)} style={{ color: 'var(--text-muted)' }} title="Edit"><Pencil size={14} /></button>
        <button className="p-1.5 rounded-lg" onClick={() => setConfirm(r)} style={{ color: '#f87171' }} title="Delete / deactivate"><Trash2 size={14} /></button>
      </div>
    ) },
  ]

  return (
    <div className="space-y-5 animate-fade-in">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <Landmark size={18} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Chart of Accounts</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{ledgers.length} ledgers · {groups.length} groups</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <GhostButton className="flex items-center gap-2" onClick={() => setGroupDrawer({})}><FolderTree size={15} /> New Group</GhostButton>
          <button className="btn-3d flex items-center gap-2" onClick={() => setLedgerDrawer({})}><Plus size={15} /> New Ledger</button>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[200px]">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input className="input-3d text-sm w-full pl-9" placeholder="Search ledgers…" value={search} onChange={e => setSearch(e.target.value)} />
        </div>
        <Select value={groupFilter} onChange={e => setGroupFilter(e.target.value)} style={{ maxWidth: 240 }}>
          <option value="">All groups</option>
          {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
        </Select>
      </div>

      {/* Ledger table */}
      {isLoading
        ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        : <>
            <DataTable columns={columns} rows={ledgers} />
            <PagerBar meta={ledgersPage} onPage={setPageNo} unit="ledgers" className="mt-3" />
          </>}

      {/* Ledger drawer */}
      {ledgerDrawer && (
        <LedgerDrawer
          ledger={ledgerDrawer}
          groups={groups}
          saving={saveLedger.isPending}
          onClose={() => setLedgerDrawer(null)}
          onSave={(form) => saveLedger.mutate(form)}
        />
      )}

      {/* Group drawer */}
      {groupDrawer && (
        <GroupDrawer
          groups={groups}
          saving={saveGroup.isPending}
          onClose={() => setGroupDrawer(null)}
          onSave={(form) => saveGroup.mutate(form)}
        />
      )}

      {confirm && (
        <ConfirmDialog
          title="Delete ledger?"
          message={`"${confirm.name}" will be deleted if it has no postings, otherwise it is deactivated (history is preserved).`}
          confirmLabel="Proceed"
          onCancel={() => setConfirm(null)}
          onConfirm={() => removeLedger.mutate(confirm.id)}
        />
      )}
    </div>
  )
}

function LedgerDrawer({ ledger, groups, saving, onClose, onSave }) {
  const [form, setForm] = useState({
    id: ledger.id,
    name: ledger.name || '',
    group_id: ledger.group_id || ledger.group?.id || '',
    code: ledger.code || '',
    opening_balance: ledger.opening_balance || 0,
    opening_balance_type: ledger.opening_balance_type || 'dr',
    is_bank: ledger.is_bank || false,
    is_cash: ledger.is_cash || false,
    is_party: ledger.is_party || false,
    party_type: ledger.party_type || '',
    is_active: ledger.is_active ?? true,
  })
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const valid = form.name.trim() && form.group_id

  return (
    <Drawer
      open
      onClose={onClose}
      title={form.id ? 'Edit Ledger' : 'New Ledger'}
      footer={
        <div className="flex gap-3">
          <GhostButton className="flex-1" onClick={onClose}>Cancel</GhostButton>
          <button className="btn-3d flex-1 flex items-center justify-center gap-2" disabled={!valid || saving} onClick={() => onSave(form)}>
            {saving && <Loader2 size={15} className="animate-spin" />} Save
          </button>
        </div>
      }
    >
      <div className="space-y-4">
        <FormField label="Ledger name" required>
          <Input value={form.name} onChange={e => set('name', e.target.value)} placeholder="e.g. HDFC Current A/c" />
        </FormField>
        <FormField label="Group" required hint="Nature is inherited from the group.">
          <Select value={form.group_id} onChange={e => set('group_id', e.target.value)}>
            <option value="">Select group…</option>
            {groups.map(g => <option key={g.id} value={g.id}>{g.name} ({NATURE_LABEL[g.nature]})</option>)}
          </Select>
        </FormField>
        <FormField label="Code (optional)">
          <Input value={form.code} onChange={e => set('code', e.target.value)} placeholder="Your own numbering" />
        </FormField>
        <div className="grid grid-cols-2 gap-3">
          <FormField label="Opening balance">
            <Input type="number" step="0.01" value={form.opening_balance} onChange={e => set('opening_balance', e.target.value)} />
          </FormField>
          <FormField label="Type">
            <Select value={form.opening_balance_type} onChange={e => set('opening_balance_type', e.target.value)}>
              <option value="dr">Debit</option>
              <option value="cr">Credit</option>
            </Select>
          </FormField>
        </div>
        <div className="flex flex-wrap gap-4 pt-1">
          {[['is_bank', 'Bank account'], ['is_cash', 'Cash account'], ['is_party', 'Party (debtor/creditor)']].map(([k, label]) => (
            <label key={k} className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
              <input type="checkbox" checked={form[k]} onChange={e => set(k, e.target.checked)} /> {label}
            </label>
          ))}
        </div>
        {form.is_party && (
          <FormField label="Party type" hint="Groups this account in Transfer Funds and future Vendor/TPV screens.">
            <Select value={form.party_type} onChange={e => set('party_type', e.target.value)}>
              <option value="">Unclassified</option>
              <option value="client">Client</option>
              <option value="vendor">Vendor</option>
              <option value="tpv">Third-Party Vendor</option>
              <option value="other">Other</option>
            </Select>
          </FormField>
        )}
        {form.id && (
          <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} /> Active
          </label>
        )}
      </div>
    </Drawer>
  )
}

function GroupDrawer({ groups, saving, onClose, onSave }) {
  const [form, setForm] = useState({ name: '', parent_id: '', nature: 'asset' })
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const valid = form.name.trim() && (form.parent_id || form.nature)

  return (
    <Drawer
      open
      onClose={onClose}
      title="New Account Group"
      footer={
        <div className="flex gap-3">
          <GhostButton className="flex-1" onClick={onClose}>Cancel</GhostButton>
          <button className="btn-3d flex-1 flex items-center justify-center gap-2" disabled={!valid || saving} onClick={() => onSave({ ...form, parent_id: form.parent_id || null })}>
            {saving && <Loader2 size={15} className="animate-spin" />} Create
          </button>
        </div>
      }
    >
      <div className="space-y-4">
        <FormField label="Group name" required>
          <Input value={form.name} onChange={e => set('name', e.target.value)} placeholder="e.g. Sales — GST 18%" />
        </FormField>
        <FormField label="Parent group (optional)" hint="A sub-group inherits its parent's nature.">
          <Select value={form.parent_id} onChange={e => set('parent_id', e.target.value)}>
            <option value="">None (top-level group)</option>
            {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
          </Select>
        </FormField>
        {!form.parent_id && (
          <FormField label="Nature" required>
            <Select value={form.nature} onChange={e => set('nature', e.target.value)}>
              {Object.entries(NATURE_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </Select>
          </FormField>
        )}
      </div>
    </Drawer>
  )
}
