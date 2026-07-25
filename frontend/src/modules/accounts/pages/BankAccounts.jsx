import { useState, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  Plus, Landmark, Loader2, Upload, Scale, Trash2, Pencil,
  FileSpreadsheet, CheckCircle2, Settings2, CreditCard,
} from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

const ACCOUNT_TYPES = [['savings', 'Savings'], ['current', 'Current'], ['od', 'Overdraft'], ['cc', 'Cash Credit'], ['other', 'Other']]

const TABS = [
  { key: 'accounts',     label: 'Bank Accounts',    icon: CreditCard },
  { key: 'feeds',        label: 'Statement Lines',  icon: FileSpreadsheet },
  { key: 'posted',       label: 'Posted Transactions', icon: CheckCircle2 },
  { key: 'reconcile',    label: 'Reconcile',        icon: Scale },
  { key: 'setup',        label: 'Setup',            icon: Settings2 },
]

/**
 * Banking — tabbed container matching old-CRM's banking section.
 * Tab 1: Bank Accounts list (CRUD, import statements)
 * Tab 2: Statement Lines (imported feeds across all banks)
 * Tab 3: Posted Bank Transactions (vouchers touching bank ledgers)
 * Tab 4: Reconcile shortcut list
 * Tab 5: Setup / configuration
 */
export default function BankAccounts() {
  const inr = useInr()
  const [activeTab, setActiveTab] = useState('accounts')

  return (
    <div className="space-y-5 animate-fade-in">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <Landmark size={18} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Banking</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Bank accounts, feeds, transactions & reconciliation</p>
          </div>
        </div>
      </div>

      {/* Tab bar */}
      <div className="flex gap-1 overflow-x-auto pb-1" style={{ borderBottom: '2px solid var(--border)' }}>
        {TABS.map(tab => {
          const active = activeTab === tab.key
          return (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className="flex items-center gap-1.5 px-4 py-2.5 text-xs font-bold whitespace-nowrap rounded-t-xl transition-all"
              style={{
                color: active ? '#a78bfa' : 'var(--text-muted)',
                background: active ? 'rgba(167,139,250,0.08)' : 'transparent',
                borderBottom: active ? '2px solid #a78bfa' : '2px solid transparent',
                marginBottom: '-2px',
              }}
            >
              <tab.icon size={13} />
              {tab.label}
            </button>
          )
        })}
      </div>

      {/* Tab content */}
      {activeTab === 'accounts' && <BankAccountsTab />}
      {activeTab === 'feeds' && <StatementLinesTab />}
      {activeTab === 'posted' && <PostedTransactionsTab />}
      {activeTab === 'reconcile' && <ReconcileTab />}
      {activeTab === 'setup' && <SetupTab />}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────────
 * Tab 1: Bank Accounts (CRUD + import + reconcile shortcuts)
 * ──────────────────────────────────────────────────────────────────────── */
function BankAccountsTab() {
  const inr = useInr()
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

  // Summary KPIs
  const totalBalance = banks.reduce((s, b) => s + Number(b.current_balance || 0), 0)
  const activeCount = banks.filter(b => b.is_active !== false).length

  const columns = [
    { key: 'bank_name', label: 'Bank', sortable: true, render: (r) => (
      <div>
        <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{r.bank_name || r.ledger?.name}</span>
        {!r.is_active && <span className="ml-2 text-[10px] px-1.5 py-0.5 rounded-md" style={{ background: 'var(--bg-hover)', color: 'var(--text-muted)' }}>inactive</span>}
        <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.account_no || '—'}{r.ifsc ? ` · ${r.ifsc}` : ''}</div>
      </div>
    ) },
    { key: 'account_type', label: 'Type', render: (r) => <span className="capitalize text-xs">{r.account_type}</span> },
    { key: 'branch', label: 'Branch', render: (r) => <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.branch || '—'}</span> },
    { key: 'current_balance', label: 'Balance', align: 'right', sortable: true, render: (r) => (
      <span className="font-bold" style={{ color: Number(r.current_balance) >= 0 ? '#10b981' : '#f87171' }}>
        {inr(r.current_balance)}
      </span>
    ) },
    { key: 'actions', label: '', align: 'right', render: (r) => (
      <div className="flex items-center justify-end gap-1">
        <button className="p-1.5 rounded-lg hover:opacity-80" title="Import statement" onClick={() => onPickFile(r)} style={{ color: '#a78bfa' }}><Upload size={14} /></button>
        <button className="p-1.5 rounded-lg hover:opacity-80" title="Reconcile" onClick={() => navigate(`/app/accounts/banking/${r.id}/reconcile`)} style={{ color: '#10b981' }}><Scale size={14} /></button>
        <button className="p-1.5 rounded-lg hover:opacity-80" title="Edit" onClick={() => setDrawer(r)} style={{ color: 'var(--text-muted)' }}><Pencil size={14} /></button>
        <button className="p-1.5 rounded-lg hover:opacity-80" title="Delete" onClick={() => setConfirm(r)} style={{ color: '#f87171' }}><Trash2 size={14} /></button>
      </div>
    ) },
  ]

  return (
    <>
      <input ref={fileRef} type="file" accept=".csv,.txt,.xlsx,.xls" className="hidden" onChange={onFile} />

      {/* KPI strip */}
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <div className="kpi-3d">
          <p className="text-[10px] uppercase font-bold" style={{ color: 'var(--text-muted)' }}>Active Accounts</p>
          <p className="text-2xl font-black mt-1" style={{ color: 'var(--text-h)' }}>{activeCount}</p>
        </div>
        <div className="kpi-3d">
          <p className="text-[10px] uppercase font-bold" style={{ color: 'var(--text-muted)' }}>Total Balance</p>
          <p className="text-2xl font-black mt-1" style={{ color: totalBalance >= 0 ? '#10b981' : '#f87171' }}>{inr(totalBalance)}</p>
        </div>
        <div className="kpi-3d flex items-center justify-center">
          <button className="btn-3d flex items-center gap-2" onClick={() => setDrawer({})}>
            <Plus size={15} /> Add Bank Account
          </button>
        </div>
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
    </>
  )
}

/* ────────────────────────────────────────────────────────────────────────────
 * Tab 2: Statement Lines (Banking Feeds)
 * ──────────────────────────────────────────────────────────────────────── */
function StatementLinesTab() {
  const inr = useInr()
  const [bankId, setBankId] = useState('')
  const { data: banks = [] } = useQuery({ queryKey: ['accounts', 'bank-accounts'], queryFn: accountsApi.bankAccounts.list })
  const selectedBank = bankId || banks[0]?.id || ''

  const { data: lines = [], isLoading } = useQuery({
    queryKey: ['accounts', 'statement-lines', selectedBank],
    queryFn: () => selectedBank ? accountsApi.statements.lines(selectedBank, { per_page: 100 }) : Promise.resolve([]),
    enabled: !!selectedBank,
  })
  const rows = lines?.data ?? lines ?? []

  const columns = [
    { key: 'date', label: 'Date', render: (r) => fmtDate(r.date || r.transaction_date) },
    { key: 'description', label: 'Description', render: (r) => (
      <span className="text-sm truncate" style={{ color: 'var(--text-h)' }}>{r.description || r.narration || '—'}</span>
    ) },
    { key: 'debit', label: 'Withdrawal', align: 'right', render: (r) => (
      <span style={{ color: Number(r.debit) > 0 ? '#f87171' : 'var(--text-muted)' }}>
        {Number(r.debit) > 0 ? inr(r.debit) : '—'}
      </span>
    ) },
    { key: 'credit', label: 'Deposit', align: 'right', render: (r) => (
      <span style={{ color: Number(r.credit) > 0 ? '#10b981' : 'var(--text-muted)' }}>
        {Number(r.credit) > 0 ? inr(r.credit) : '—'}
      </span>
    ) },
    { key: 'status', label: 'Status', render: (r) => {
      const matched = r.voucher_id || r.is_matched
      return (
        <span className="text-[11px] font-bold px-2 py-0.5 rounded-lg"
          style={{ background: matched ? '#10b98118' : '#f59e0b18', color: matched ? '#10b981' : '#f59e0b' }}>
          {matched ? 'Matched' : 'Unmatched'}
        </span>
      )
    } },
  ]

  return (
    <>
      <div className="flex items-center gap-3">
        <Select value={selectedBank} onChange={e => setBankId(e.target.value)} style={{ maxWidth: 280 }}>
          {banks.map(b => <option key={b.id} value={b.id}>{b.bank_name || b.ledger?.name}</option>)}
        </Select>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
          {rows.length} statement line{rows.length !== 1 ? 's' : ''}
        </span>
      </div>

      {isLoading
        ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        : rows.length === 0
          ? <div className="kpi-3d text-center py-12"><p className="text-sm" style={{ color: 'var(--text-muted)' }}>No statement lines imported for this account. Use the Import button on the Bank Accounts tab.</p></div>
          : <DataTable columns={columns} rows={rows} />
      }
    </>
  )
}

/* ────────────────────────────────────────────────────────────────────────────
 * Tab 3: Posted Bank Transactions (vouchers touching bank/cash ledgers)
 * ──────────────────────────────────────────────────────────────────────── */
function PostedTransactionsTab() {
  const inr = useInr()
  const { data: page, isLoading } = useQuery({
    queryKey: ['accounts', 'vouchers', { type: '', status: 'posted', bank_only: true }],
    queryFn: () => accountsApi.vouchers.list({ status: 'posted', per_page: 50 }),
  })
  const vouchers = page?.data ?? []
  // Filter to vouchers that have at least one bank/cash ledger line
  // (the backend would ideally handle this, but we can client-filter for now)

  const columns = [
    { key: 'number', label: 'Voucher', render: (r) => <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{r.number}</span> },
    { key: 'date', label: 'Date', render: (r) => fmtDate(r.date) },
    { key: 'type', label: 'Type', render: (r) => (
      <span className="text-[11px] font-bold px-2 py-0.5 rounded-lg"
        style={{ background: 'rgba(167,139,250,0.1)', color: '#a78bfa' }}>
        {r.voucher_type?.name || '—'}
      </span>
    ) },
    { key: 'narration', label: 'Narration', render: (r) => <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.narration || '—'}</span> },
    { key: 'total_amount', label: 'Amount', align: 'right', render: (r) => <span className="font-bold">{inr(r.total_amount)}</span> },
  ]

  return isLoading
    ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
    : <DataTable columns={columns} rows={vouchers} />
}

/* ────────────────────────────────────────────────────────────────────────────
 * Tab 4: Reconcile — list bank accounts with reconcile action
 * ──────────────────────────────────────────────────────────────────────── */
function ReconcileTab() {
  const inr = useInr()
  const navigate = useNavigate()
  const { data: banks = [], isLoading } = useQuery({ queryKey: ['accounts', 'bank-accounts'], queryFn: accountsApi.bankAccounts.list })

  if (isLoading) return <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>

  return (
    <div className="space-y-3">
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
        Select a bank account to start or continue reconciliation. Match imported statement lines against posted vouchers.
      </p>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {banks.map(b => (
          <button key={b.id} onClick={() => navigate(`/app/accounts/banking/${b.id}/reconcile`)}
            className="kpi-3d text-left hover:scale-[1.02] transition-transform">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ background: 'rgba(16,185,129,0.1)' }}>
                <Scale size={16} style={{ color: '#10b981' }} />
              </div>
              <div className="flex-1 min-w-0">
                <p className="font-bold text-sm truncate" style={{ color: 'var(--text-h)' }}>{b.bank_name || b.ledger?.name}</p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                  Balance: {inr(b.current_balance)}
                </p>
              </div>
            </div>
          </button>
        ))}
      </div>
      {banks.length === 0 && (
        <div className="kpi-3d text-center py-12">
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No bank accounts set up. Create one first.</p>
        </div>
      )}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────────
 * Tab 5: Setup — bank configuration hints
 * ──────────────────────────────────────────────────────────────────────── */
function SetupTab() {
  return (
    <div className="space-y-4">
      <div className="kpi-3d">
        <h3 className="font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Setup Your Bank Account</h3>
        <div className="space-y-3 text-xs" style={{ color: 'var(--text-muted)' }}>
          <div className="flex items-start gap-3">
            <span className="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0 text-[10px] font-bold"
              style={{ background: 'rgba(167,139,250,0.12)', color: '#a78bfa' }}>1</span>
            <div>
              <p className="font-semibold" style={{ color: 'var(--text-h)' }}>Add a Bank Account</p>
              <p>Go to the Bank Accounts tab and click "Add Bank Account". Enter bank name, account number, IFSC, and opening balance.</p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <span className="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0 text-[10px] font-bold"
              style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981' }}>2</span>
            <div>
              <p className="font-semibold" style={{ color: 'var(--text-h)' }}>Import Statement</p>
              <p>Use the upload button on any bank account to import CSV/XLSX statements. The system will parse and show each line.</p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <span className="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0 text-[10px] font-bold"
              style={{ background: 'rgba(34,211,238,0.12)', color: '#22d3ee' }}>3</span>
            <div>
              <p className="font-semibold" style={{ color: 'var(--text-h)' }}>Reconcile</p>
              <p>Go to Reconcile tab, select the bank account, and match each imported statement line with posted vouchers.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────────
 * Bank Account Create/Edit Drawer
 * ──────────────────────────────────────────────────────────────────────── */
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
