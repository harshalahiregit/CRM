import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ScrollText, Loader2, Trash2, Clock, Pencil, Eye, Printer,
  BookOpen, ArrowUpRight, ArrowDownLeft, LayoutDashboard, AlertTriangle,
} from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import PagerBar from '@/components/ui/PagerBar'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'
import ChequebooksManager from '@/modules/accounts/components/ChequebooksManager'
import { ChequePreview, printChequeLeaf } from '@/modules/accounts/components/ChequePreview'

// Internal status → colour; wording is direction-aware (spec §3 & §4).
const STATUS_META = {
  post_dated: { color: '#f59e0b' },
  issued:     { color: '#a78bfa' },
  received:   { color: '#a78bfa' },
  presented:  { color: '#22d3ee' },
  deposited:  { color: '#22d3ee' },
  cleared:    { color: '#10b981' },
  bounced:    { color: '#f87171' },
  cancelled:  { color: 'var(--text-muted)' },
}
function statusLabel(status, direction) {
  if (direction === 'issued') {
    return { post_dated: 'Post-dated', issued: 'Issued', presented: 'In-Process / Pending',
      deposited: 'In-Process / Pending', cleared: 'Withdrawn / Settled', bounced: 'Bounced / Dishonored',
      cancelled: 'Cancelled / Voided' }[status] || status
  }
  return { post_dated: 'Post-dated', received: 'Received', issued: 'Received', presented: 'Deposited in Bank',
    deposited: 'Deposited in Bank', cleared: 'Cleared', bounced: 'Bounced', cancelled: 'Cancelled' }[status] || status
}
// Valid transitions — mirrors ChequeService::TRANSITIONS.
const NEXT_STATUS = {
  post_dated: ['issued', 'received', 'deposited', 'cancelled'],
  received:   ['deposited', 'cleared', 'bounced', 'cancelled'],
  issued:     ['presented', 'cleared', 'bounced', 'cancelled'],
  deposited:  ['cleared', 'bounced', 'cancelled'],
  presented:  ['cleared', 'bounced', 'cancelled'],
  bounced:    ['deposited', 'cancelled'],
  cleared: [], cancelled: [],
}

const TABS = [
  { key: 'overview', label: 'Overview', icon: LayoutDashboard },
  { key: 'issued',   label: 'Issued',   icon: ArrowUpRight },
  { key: 'received', label: 'Received', icon: ArrowDownLeft },
  { key: 'books',    label: 'Chequebooks', icon: BookOpen },
]

export default function Cheques() {
  const inr = useInr()
  const toast = useToast()
  const qc = useQueryClient()
  const [tab, setTab] = useState('overview')
  const [issueDrawer, setIssueDrawer] = useState(false)
  const [receiveDrawer, setReceiveDrawer] = useState(false)
  const [editTarget, setEditTarget] = useState(null)
  const [viewTarget, setViewTarget] = useState(null)
  const [confirm, setConfirm] = useState(null)

  const { data: summary } = useQuery({ queryKey: ['accounts', 'cheques', 'summary'], queryFn: accountsApi.cheques.summary })
  const { data: inventory } = useQuery({ queryKey: ['accounts', 'chequebooks', 'summary'], queryFn: accountsApi.chequebooks.summary })
  const { data: banks = [] } = useQuery({ queryKey: ['accounts', 'bank-accounts'], queryFn: accountsApi.bankAccounts.list })
  const { data: books = [] } = useQuery({ queryKey: ['accounts', 'chequebooks'], queryFn: () => accountsApi.chequebooks.list() })
  const { data: due = [] } = useQuery({ queryKey: ['accounts', 'cheques', 'due'], queryFn: accountsApi.cheques.due })

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['accounts', 'cheques'] })
    qc.invalidateQueries({ queryKey: ['accounts', 'chequebooks'] })
  }

  const create = useMutation({
    mutationFn: (f) => accountsApi.cheques.create(f),
    onSuccess: () => { toast.success('Cheque recorded'); setIssueDrawer(false); setReceiveDrawer(false); invalidate() },
    onError: (e) => toast.error(e.message),
  })
  const edit = useMutation({
    mutationFn: (f) => accountsApi.cheques.update(editTarget.id, f),
    onSuccess: () => { toast.success('Cheque updated'); setEditTarget(null); invalidate() },
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

  return (
    <div className="space-y-5 animate-fade-in">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <ScrollText size={18} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Cheque Management</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Chequebook inventory, issuance &amp; receiving with settlement tracking</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button className="btn-3d flex items-center gap-2" onClick={() => setIssueDrawer(true)}><ArrowUpRight size={15} /> Issue Cheque</button>
          <GhostButton className="flex items-center gap-2 !py-2 !px-3" onClick={() => setReceiveDrawer(true)}><ArrowDownLeft size={15} /> Receive</GhostButton>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 flex-wrap" style={{ borderBottom: '1px solid var(--border)' }}>
        {TABS.map(t => {
          const Icon = t.icon
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              className="px-4 py-2.5 text-sm font-bold -mb-px flex items-center gap-1.5"
              style={{ color: tab === t.key ? '#a78bfa' : 'var(--text-muted)', borderBottom: tab === t.key ? '2px solid #a78bfa' : '2px solid transparent' }}>
              <Icon size={14} /> {t.label}
            </button>
          )
        })}
      </div>

      {tab === 'overview' && <OverviewTab summary={summary} inventory={inventory} due={due} inr={inr} />}
      {tab === 'issued'   && <ChequeList direction="issued" inr={inr} banks={banks}
        onView={setViewTarget} onEdit={setEditTarget} onDelete={setConfirm} onStatus={(id, status) => changeStatus.mutate({ id, status })} />}
      {tab === 'received' && <ChequeList direction="received" inr={inr} banks={banks}
        onView={setViewTarget} onEdit={setEditTarget} onDelete={setConfirm} onStatus={(id, status) => changeStatus.mutate({ id, status })} />}
      {tab === 'books'    && <ChequebooksManager />}

      {issueDrawer && (
        <IssueChequeDrawer banks={banks} books={books} saving={create.isPending}
          onClose={() => setIssueDrawer(false)} onSave={(f) => create.mutate(f)} />
      )}
      {receiveDrawer && (
        <ReceiveChequeDrawer saving={create.isPending}
          onClose={() => setReceiveDrawer(false)} onSave={(f) => create.mutate(f)} />
      )}
      {editTarget && (editTarget.direction === 'issued'
        ? <IssueChequeDrawer banks={banks} books={books} initial={editTarget} isEdit saving={edit.isPending}
            onClose={() => setEditTarget(null)} onSave={(f) => edit.mutate(f)} />
        : <ReceiveChequeDrawer initial={editTarget} isEdit saving={edit.isPending}
            onClose={() => setEditTarget(null)} onSave={(f) => edit.mutate(f)} />)}

      {viewTarget && <ChequeViewModal cheque={viewTarget} onClose={() => setViewTarget(null)} />}

      {confirm && (
        <ConfirmDialog title="Delete cheque?" tone="danger"
          message={`Cheque ${confirm.cheque_no || ''} for ${inr(confirm.amount)} will be removed.`}
          confirmLabel="Delete" onCancel={() => setConfirm(null)} onConfirm={() => remove.mutate(confirm.id)} />
      )}
    </div>
  )
}

/* ── Overview / inventory dashboard (spec §1) ─────────────────────────────── */
function OverviewTab({ summary, inventory, due, inr }) {
  const kpis = [
    ['Chequebooks', inventory?.active_books, '#a78bfa', `${inventory?.total_books ?? 0} total`],
    ['Available Leaves', inventory?.available_leaves, '#10b981', `${inventory?.used_leaves ?? 0} used`],
    ['Issued', summary?.issued, '#a78bfa'],
    ['In-Process', summary?.in_process, '#22d3ee'],
    ['Cleared / Withdrawn', summary?.cleared, '#10b981'],
    ['Bounced', summary?.bounced, summary?.bounced > 0 ? '#f87171' : 'var(--text-muted)'],
  ]
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        {kpis.map(([label, val, color, sub]) => (
          <div key={label} className="kpi-3d">
            <p className="text-[10px] uppercase font-bold" style={{ color: 'var(--text-muted)' }}>{label}</p>
            <p className="text-2xl font-black mt-1" style={{ color }}>{val ?? '—'}</p>
            {sub && <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
          </div>
        ))}
      </div>

      {due?.length > 0 && (
        <div className="rounded-xl p-4" style={{ background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.25)' }}>
          <p className="text-sm font-bold flex items-center gap-2 mb-2" style={{ color: '#f59e0b' }}>
            <AlertTriangle size={15} /> {due.length} post-dated cheque{due.length > 1 ? 's' : ''} due for clearing
          </p>
          <div className="space-y-1">
            {due.slice(0, 6).map(c => (
              <div key={c.id} className="flex items-center justify-between text-xs" style={{ color: 'var(--text-body)' }}>
                <span>{c.cheque_no || '—'} · {c.party_name || '—'}</span>
                <span>{inr(c.amount)} · due {fmtDate(c.pdc_due_date)}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Direction-scoped cheque list (Issued / Received) ─────────────────────── */
function ChequeList({ direction, inr, banks, onView, onEdit, onDelete, onStatus }) {
  const [filters, setFiltersRaw] = useState({ status: '', search: '', bank_account_id: '' })
  const [pageNo, setPageNo] = useState(1)
  const setFilters = (fn) => { setFiltersRaw(fn); setPageNo(1) }
  const today = new Date().toISOString().slice(0, 10)
  const { data: page, isLoading } = useQuery({
    queryKey: ['accounts', 'cheques', direction, filters, pageNo],
    queryFn: () => accountsApi.cheques.list({ ...filters, direction, per_page: 100, page: pageNo }),
    placeholderData: (prev) => prev,
  })
  const rows = page?.data ?? []

  const columns = [
    {
      key: 'cheque_no', label: 'Cheque',
      render: (r) => (
        <div>
          <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{r.cheque_no || '—'}</span>
          <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.party_name || '—'}</div>
        </div>
      ),
    },
    {
      key: 'cheque_date', label: 'Date',
      render: (r) => {
        const isDue = (r.pdc_due_date || '').slice(0, 10) <= today
        return (
          <span>{fmtDate(r.cheque_date)}
            {r.is_pdc && r.status === 'post_dated' && (
              <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10px]" style={{ color: isDue ? '#f87171' : '#f59e0b' }}>
                <Clock size={10} /> PDC {isDue ? 'due' : fmtDate(r.pdc_due_date)}
              </span>
            )}
          </span>
        )
      },
    },
    {
      key: 'bank', label: direction === 'issued' ? 'Bank' : 'Drawee Bank',
      render: (r) => <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
        {direction === 'issued' ? (r.bank_account?.bank_name || '—') : (r.payer_bank || '—')}
      </span>,
    },
    ...(direction === 'received' ? [{
      key: 'source', label: 'Source',
      render: (r) => <span className="text-xs capitalize" style={{ color: 'var(--text-muted)' }}>{r.source_type || '—'}</span>,
    }] : []),
    { key: 'amount', label: 'Amount', align: 'right', render: (r) => <span className="font-bold">{inr(r.amount)}</span> },
    {
      key: 'status', label: direction === 'issued' ? 'Settlement' : 'Deposit Status',
      render: (r) => <span className="text-xs font-bold" style={{ color: STATUS_META[r.status]?.color ?? 'var(--text-muted)' }}>{statusLabel(r.status, direction)}</span>,
    },
    {
      key: 'actions', label: '', align: 'right',
      render: (r) => (
        <div className="flex items-center justify-end gap-1.5">
          {NEXT_STATUS[r.status]?.length > 0 && (
            <select className="input-3d text-xs" style={{ padding: '2px 6px', width: 'auto' }}
              value="" onChange={e => e.target.value && onStatus(r.id, e.target.value)}>
              <option value="">Move to…</option>
              {NEXT_STATUS[r.status].map(s => <option key={s} value={s}>{statusLabel(s, direction)}</option>)}
            </select>
          )}
          <button title="View / print" onClick={() => onView(r)} className="p-1.5 rounded-lg hover:opacity-80" style={{ color: '#22d3ee' }}><Eye size={14} /></button>
          {!['cleared', 'cancelled'].includes(r.status) && (
            <button title="Edit" onClick={() => onEdit(r)} className="p-1.5 rounded-lg hover:opacity-80" style={{ color: '#a78bfa' }}><Pencil size={13} /></button>
          )}
          <button title="Delete" onClick={() => onDelete(r)} className="p-1.5 rounded-lg hover:opacity-80" style={{ color: '#f87171' }}><Trash2 size={14} /></button>
        </div>
      ),
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <input className="input-3d text-sm flex-1 min-w-[180px]" placeholder="Search cheque no / party…"
          value={filters.search} onChange={e => setFilters(f => ({ ...f, search: e.target.value }))} />
        <Select value={filters.status} onChange={e => setFilters(f => ({ ...f, status: e.target.value }))} style={{ maxWidth: 190 }}>
          <option value="">All statuses</option>
          {(direction === 'issued'
            ? ['issued', 'presented', 'cleared', 'bounced', 'cancelled', 'post_dated']
            : ['received', 'deposited', 'cleared', 'bounced', 'cancelled']
          ).map(s => <option key={s} value={s}>{statusLabel(s, direction)}</option>)}
        </Select>
        {direction === 'issued' && banks.length > 0 && (
          <Select value={filters.bank_account_id} onChange={e => setFilters(f => ({ ...f, bank_account_id: e.target.value }))} style={{ maxWidth: 180 }}>
            <option value="">All banks</option>
            {banks.map(b => <option key={b.id} value={b.id}>{b.bank_name || b.ledger?.name}</option>)}
          </Select>
        )}
      </div>
      {isLoading
        ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        : <>
            <DataTable columns={columns} rows={rows} emptyState={<p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>No {direction} cheques yet.</p>} />
            <PagerBar meta={page} onPage={setPageNo} unit="cheques" className="mt-3" />
          </>}
    </div>
  )
}

/* ── Issue cheque drawer (spec §2 — bank → book → auto number) ─────────────── */
const ISSUE_EMPTY = {
  bank_account_id: '', chequebook_id: '', cheque_no: '', cheque_date: new Date().toISOString().slice(0, 10),
  party_name: '', party_type: '', party_id: '', party_source: '', amount: '', is_account_payee: true,
  reference: '', project_id: '', is_pdc: false, pdc_due_date: '', memo: '',
}
function IssueChequeDrawer({ banks, books, initial, isEdit = false, saving, onClose, onSave }) {
  const [form, setForm] = useState(initial ? { ...ISSUE_EMPTY, ...initial, is_account_payee: initial.is_account_payee ?? true } : ISSUE_EMPTY)
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  // Payee directory: customers + vendors + third-party vendors (§ future modules).
  const { data: dir } = useQuery({ queryKey: ['accounts', 'party-directory'], queryFn: () => accountsApi.partyDirectory.list(), retry: false })
  const { data: projects = [] } = useQuery({ queryKey: ['accounts', 'projects'], queryFn: accountsApi.projects.list, retry: false })
  const hasParties = (dir?.customers?.length || dir?.vendors?.length || dir?.tpv?.length) > 0
  const [manualPayee, setManualPayee] = useState(false)
  const useDropdown = hasParties && !manualPayee && !(isEdit && !form.party_id)

  const pickParty = (val) => {
    if (val === '__manual__') { setManualPayee(true); setForm(f => ({ ...f, party_type: '', party_id: '', party_source: '' })); return }
    if (!val) { setForm(f => ({ ...f, party_name: '', party_type: '', party_id: '', party_source: '' })); return }
    const [type, id] = val.split(':')
    const groups = { customer: dir?.customers, vendor: dir?.vendors, tpv: dir?.tpv }
    const hit = (groups[type] || []).find(p => String(p.id) === id)
    // `source` says WHICH table `id` belongs to — ledger ids and vendor-master ids
    // overlap, so without it a saved payee can't be resolved back to its record.
    setForm(f => ({ ...f, party_name: hit?.name || '', party_type: type, party_id: id, party_source: hit?.source || '' }))
  }

  const bankBooks = useMemo(
    () => books.filter(b => String(b.bank_account_id) === String(form.bank_account_id) && b.status === 'active' && b.next_cheque_no),
    [books, form.bank_account_id])
  const selectedBook = books.find(b => String(b.id) === String(form.chequebook_id))
  const autoNo = selectedBook?.next_cheque_no
  const valid = form.cheque_date && form.amount !== '' && form.party_name && (isEdit || form.bank_account_id)

  const submit = () => onSave({
    ...form, direction: 'issued', amount: Number(form.amount),
    bank_account_id: form.bank_account_id || null,
    chequebook_id: isEdit ? undefined : (form.chequebook_id || null),
    cheque_no: (!isEdit && form.chequebook_id) ? undefined : (form.cheque_no || null),
    party_type: form.party_id ? form.party_type : null,
    party_id: form.party_id || null,
    party_source: form.party_id ? (form.party_source || null) : null,
    project_id: form.project_id || null,
    pdc_due_date: form.pdc_due_date || null,
  })

  return (
    <Drawer open onClose={onClose} title={isEdit ? 'Edit Issued Cheque' : 'Issue Cheque'}
      footer={<div className="flex gap-3">
        <GhostButton className="flex-1" onClick={onClose}>Cancel</GhostButton>
        <button className="btn-3d flex-1 flex items-center justify-center gap-2" disabled={!valid || saving} onClick={submit}>
          {saving && <Loader2 size={15} className="animate-spin" />}{isEdit ? 'Save changes' : 'Issue Cheque'}
        </button>
      </div>}>
      <div className="space-y-4">
        {!isEdit && (
          <>
            <FormField label="Bank account" required>
              <Select value={form.bank_account_id} onChange={e => { set('bank_account_id', e.target.value); set('chequebook_id', '') }}>
                <option value="">Select bank…</option>
                {banks.map(b => <option key={b.id} value={b.id}>{b.bank_name || b.ledger?.name}{b.account_no ? ` · ${b.account_no}` : ''}</option>)}
              </Select>
            </FormField>
            <FormField label="Chequebook" hint={bankBooks.length ? 'Next cheque number is assigned automatically' : 'No active book — enter the number manually below'}>
              <Select value={form.chequebook_id} onChange={e => set('chequebook_id', e.target.value)} disabled={!form.bank_account_id || bankBooks.length === 0}>
                <option value="">{bankBooks.length ? 'Select book…' : 'No active chequebook'}</option>
                {bankBooks.map(b => <option key={b.id} value={b.id}>{b.name} · next {b.next_cheque_no} ({b.leaves_available} left)</option>)}
              </Select>
            </FormField>
            {autoNo && (
              <div className="px-3 py-2 rounded-xl text-sm flex items-center justify-between" style={{ background: 'rgba(16,185,129,0.1)', color: '#10b981' }}>
                <span>Auto-assigned cheque no.</span><span className="font-black tracking-wider">{autoNo}</span>
              </div>
            )}
            {!form.chequebook_id && (
              <FormField label="Cheque no." hint="Manual entry (no chequebook selected)">
                <Input value={form.cheque_no} onChange={e => set('cheque_no', e.target.value)} />
              </FormField>
            )}
          </>
        )}
        {isEdit && <FormField label="Cheque no."><Input value={form.cheque_no || ''} onChange={e => set('cheque_no', e.target.value)} /></FormField>}

        {/* Payee — from the party directory (Customers / Vendors / Third-Party Vendors) */}
        <FormField label="Payee (Vendor / Client / TPV)" required
          hint={hasParties ? 'Choose a party, or enter a name manually' : 'Directory is empty — enter the payee name'}>
          {useDropdown ? (
            <div className="flex items-center gap-2">
              <Select value={form.party_id ? `${form.party_type}:${form.party_id}` : ''} onChange={e => pickParty(e.target.value)}>
                <option value="">Select payee…</option>
                {dir?.customers?.length > 0 && (
                  <optgroup label="Customers">
                    {dir.customers.map(p => <option key={`customer:${p.id}`} value={`customer:${p.id}`}>{p.name}</option>)}
                  </optgroup>
                )}
                {dir?.vendors?.length > 0 && (
                  <optgroup label="Vendors">
                    {dir.vendors.map(p => <option key={`vendor:${p.id}`} value={`vendor:${p.id}`}>{p.name}</option>)}
                  </optgroup>
                )}
                {dir?.tpv?.length > 0 && (
                  <optgroup label="Third-Party Vendors">
                    {dir.tpv.map(p => <option key={`tpv:${p.id}`} value={`tpv:${p.id}`}>{p.name}</option>)}
                  </optgroup>
                )}
                <option value="__manual__">✏️  Enter name manually…</option>
              </Select>
            </div>
          ) : (
            <div className="flex items-center gap-2">
              <Input value={form.party_name} onChange={e => set('party_name', e.target.value)} placeholder="Payee name" />
              {hasParties && (
                <button type="button" onClick={() => { setManualPayee(false); set('party_name', '') }}
                  className="text-xs whitespace-nowrap px-2 py-1.5 rounded-lg" style={{ color: '#a78bfa', border: '1px solid var(--border)' }}>
                  Directory
                </button>
              )}
            </div>
          )}
        </FormField>

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Amount" required><Input type="number" step="0.01" value={form.amount} onChange={e => set('amount', e.target.value)} /></FormField>
          <FormField label="Issue date" required><Input type="date" value={form.cheque_date} onChange={e => set('cheque_date', e.target.value)} /></FormField>
          <FormField label="Project" hint={projects.length ? null : 'Projects module coming soon'}>
            <Select value={form.project_id || ''} onChange={e => set('project_id', e.target.value)} disabled={!projects.length}>
              <option value="">{projects.length ? 'Select project…' : 'No projects yet'}</option>
              {projects.map(p => <option key={p.id} value={p.id}>{p.name || p.title}</option>)}
            </Select>
          </FormField>
          <FormField label="Work reference"><Input value={form.reference} onChange={e => set('reference', e.target.value)} placeholder="e.g. PO number, work order" /></FormField>
        </div>

        <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-h)' }}>
          <input type="checkbox" checked={form.is_account_payee} onChange={e => set('is_account_payee', e.target.checked)} /> Account Payee (crossed cheque)
        </label>
        <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={form.is_pdc} onChange={e => set('is_pdc', e.target.checked)} /> Post-dated cheque (PDC)
        </label>
        {form.is_pdc && (
          <FormField label="PDC due date" hint="Date this cheque can be presented">
            <Input type="date" value={form.pdc_due_date} onChange={e => set('pdc_due_date', e.target.value)} />
          </FormField>
        )}
        <FormField label="Memo"><Input value={form.memo || ''} onChange={e => set('memo', e.target.value)} /></FormField>
      </div>
    </Drawer>
  )
}

/* ── Receive cheque drawer (spec §4 — incoming register) ──────────────────── */
const RECEIVE_EMPTY = {
  source_type: 'client', party_name: '', party_type: '', party_id: '', payer_bank: '', cheque_no: '',
  cheque_date: new Date().toISOString().slice(0, 10), amount: '', reference: '', status: 'received', memo: '',
}
// Received "source type" (client) maps to the directory's "customer" type.
const SRC_TO_DIR = { client: 'customers', vendor: 'vendors', other: null }
function ReceiveChequeDrawer({ initial, isEdit = false, saving, onClose, onSave }) {
  const [form, setForm] = useState(initial ? { ...RECEIVE_EMPTY, ...initial } : RECEIVE_EMPTY)
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const valid = form.party_name && form.cheque_date && form.amount !== ''

  const { data: dir } = useQuery({ queryKey: ['accounts', 'party-directory'], queryFn: () => accountsApi.partyDirectory.list(), retry: false })
  const suggestions = SRC_TO_DIR[form.source_type] ? (dir?.[SRC_TO_DIR[form.source_type]] || []) : [...(dir?.customers || []), ...(dir?.vendors || []), ...(dir?.tpv || [])]

  // Typing/selecting a payer captures the structured link when it matches a directory entry.
  const onPayer = (name) => {
    const hit = suggestions.find(p => p.name?.toLowerCase() === name.trim().toLowerCase())
    setForm(f => ({ ...f, party_name: name, party_type: hit ? (form.source_type === 'client' ? 'customer' : form.source_type) : '', party_id: hit ? String(hit.id) : '' }))
  }

  const submit = () => onSave({
    ...form, direction: 'received', amount: Number(form.amount),
    is_pdc: false, bank_account_id: null, cheque_no: form.cheque_no || null,
    party_type: form.party_id ? (form.source_type === 'client' ? 'customer' : form.source_type) : null,
    party_id: form.party_id || null,
  })

  return (
    <Drawer open onClose={onClose} title={isEdit ? 'Edit Received Cheque' : 'Receive Cheque'}
      footer={<div className="flex gap-3">
        <GhostButton className="flex-1" onClick={onClose}>Cancel</GhostButton>
        <button className="btn-3d flex-1 flex items-center justify-center gap-2" disabled={!valid || saving} onClick={submit}>
          {saving && <Loader2 size={15} className="animate-spin" />}{isEdit ? 'Save changes' : 'Record Cheque'}
        </button>
      </div>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <FormField label="Source type" required>
            <Select value={form.source_type} onChange={e => setForm(f => ({ ...f, source_type: e.target.value, party_type: '', party_id: '' }))}>
              <option value="client">Client / Customer</option>
              <option value="vendor">Vendor</option>
              <option value="other">Other</option>
            </Select>
          </FormField>
          <FormField label="Payer name" required hint="Pick from the directory or type a new payer">
            <Input list="cheque-payer-directory" value={form.party_name} onChange={e => onPayer(e.target.value)} placeholder="Who the cheque is from" />
            <datalist id="cheque-payer-directory">
              {suggestions.map(p => <option key={`${p.type}:${p.id}`} value={p.name} />)}
            </datalist>
          </FormField>
          <FormField label="Cheque no."><Input value={form.cheque_no || ''} onChange={e => set('cheque_no', e.target.value)} /></FormField>
          <FormField label="Cheque date" required><Input type="date" value={form.cheque_date} onChange={e => set('cheque_date', e.target.value)} /></FormField>
          <FormField label="Drawee bank" hint="Bank the cheque is drawn on"><Input value={form.payer_bank || ''} onChange={e => set('payer_bank', e.target.value)} placeholder="e.g. HDFC Bank" /></FormField>
          <FormField label="Amount" required><Input type="number" step="0.01" value={form.amount} onChange={e => set('amount', e.target.value)} /></FormField>
        </div>
        <FormField label="Purpose / Project"><Input value={form.reference || ''} onChange={e => set('reference', e.target.value)} placeholder="e.g. Advance payment, security deposit" /></FormField>
        {!isEdit && (
          <FormField label="Deposit status">
            <Select value={form.status} onChange={e => set('status', e.target.value)}>
              <option value="received">Received</option>
              <option value="deposited">Deposited in Bank</option>
            </Select>
          </FormField>
        )}
        <FormField label="Memo"><Textarea rows={2} value={form.memo || ''} onChange={e => set('memo', e.target.value)} /></FormField>
      </div>
    </Drawer>
  )
}

/* ── View + print modal (spec §2 — visual render & precision print) ───────── */
function ChequeViewModal({ cheque, onClose }) {
  return (
    <Drawer open onClose={onClose} title={`Cheque ${cheque.cheque_no || ''}`}
      footer={<div className="flex gap-3">
        <GhostButton className="flex-1" onClick={onClose}>Close</GhostButton>
        <button className="btn-3d flex-1 flex items-center justify-center gap-2" onClick={() => printChequeLeaf(cheque)}>
          <Printer size={15} /> Print Cheque
        </button>
      </div>}>
      <div className="space-y-4">
        <ChequePreview cheque={cheque} />
        <div className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          {[
            ['Direction', cheque.direction],
            ['Status', cheque.status?.replace('_', ' ')],
            cheque.reference && ['Reference', cheque.reference],
            cheque.source_type && ['Source', cheque.source_type],
            cheque.payer_bank && ['Drawee bank', cheque.payer_bank],
            cheque.chequebook?.name && ['Chequebook', cheque.chequebook.name],
            cheque.memo && ['Memo', cheque.memo],
          ].filter(Boolean).map(([k, v]) => (
            <div key={k} className="flex justify-between gap-3 py-1" style={{ borderBottom: '1px solid var(--border)' }}>
              <span style={{ color: 'var(--text-muted)' }}>{k}</span>
              <span className="capitalize text-right" style={{ color: 'var(--text-h)' }}>{v}</span>
            </div>
          ))}
        </div>
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          Print opens a leaf-sized page positioning only the field values, so a pre-printed blank cheque fed into your printer aligns with its fields. Do a test print on plain paper first and adjust tray guides if needed.
        </p>
      </div>
    </Drawer>
  )
}
