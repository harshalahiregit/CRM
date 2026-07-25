import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Loader2, Trash2, BookLock, Ban } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { useToast } from '@/hooks/useToast'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

const STATUS_STYLE = {
  active:    { bg: 'rgba(16,185,129,0.12)', fg: '#10b981', label: 'Active' },
  exhausted: { bg: 'rgba(245,158,11,0.12)', fg: '#f59e0b', label: 'Exhausted' },
  closed:    { bg: 'var(--bg-input)',        fg: 'var(--text-muted)', label: 'Closed' },
}

const EMPTY = { bank_account_id: '', name: '', prefix: '', start_raw: '', end_raw: '', notes: '' }

/**
 * Chequebook inventory management (spec §1) — onboard a book against a bank
 * account with its serial range and watch leaves deplete. Reused as an Accounts
 * Settings tab and on the Cheques page "Chequebooks" tab.
 */
export default function ChequebooksManager() {
  const toast = useToast()
  const qc = useQueryClient()
  const [form, setForm] = useState(EMPTY)
  const [adding, setAdding] = useState(false)
  const [confirmDel, setConfirmDel] = useState(null)
  const [confirmClose, setConfirmClose] = useState(null)

  const { data: books = [], isLoading, isError } = useQuery({
    queryKey: ['accounts', 'chequebooks'], queryFn: () => accountsApi.chequebooks.list(), retry: false,
  })
  const { data: banks = [] } = useQuery({ queryKey: ['accounts', 'bank-accounts'], queryFn: accountsApi.bankAccounts.list })
  const inv = () => qc.invalidateQueries({ queryKey: ['accounts', 'chequebooks'] })

  const create = useMutation({
    mutationFn: () => {
      const start = parseInt(form.start_raw, 10)
      const end = parseInt(form.end_raw, 10)
      return accountsApi.chequebooks.create({
        bank_account_id: Number(form.bank_account_id),
        name: form.name.trim(), prefix: form.prefix.trim() || null,
        start_no: start, end_no: end, start_raw: form.start_raw.trim(), end_raw: form.end_raw.trim(),
        notes: form.notes.trim() || null,
      })
    },
    onSuccess: () => { toast.success('Chequebook added'); setForm(EMPTY); setAdding(false); inv() },
    onError: (e) => toast.error(e.message),
  })
  const close = useMutation({
    mutationFn: (id) => accountsApi.chequebooks.close(id),
    onSuccess: () => { toast.success('Chequebook closed'); setConfirmClose(null); inv() },
    onError: (e) => { toast.error(e.message); setConfirmClose(null) },
  })
  const remove = useMutation({
    mutationFn: (id) => accountsApi.chequebooks.remove(id),
    onSuccess: () => { toast.success('Deleted'); setConfirmDel(null); inv() },
    onError: (e) => { toast.error(e.message); setConfirmDel(null) },
  })

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const startN = parseInt(form.start_raw, 10)
  const endN = parseInt(form.end_raw, 10)
  const leafCount = Number.isFinite(startN) && Number.isFinite(endN) && endN >= startN ? endN - startN + 1 : null
  const canAdd = form.bank_account_id && form.name.trim() && Number.isFinite(startN) && Number.isFinite(endN) && endN >= startN

  if (isLoading) return <div className="flex justify-center py-8"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  if (isError) return <p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>The accounts books aren't set up yet — run the one-click setup first.</p>

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Chequebooks</h3>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Onboard a physical book with its serial range — leaves deplete as cheques are issued.</p>
        </div>
        {!adding && (
          <button onClick={() => setAdding(true)} className="btn-3d flex items-center gap-1.5 flex-shrink-0"><Plus size={15} /> Add book</button>
        )}
      </div>

      {banks.length === 0 && (
        <p className="text-xs mb-4 px-3 py-2 rounded-lg" style={{ background: 'rgba(245,158,11,0.1)', color: '#f59e0b' }}>
          Add a bank account first (Banking → Bank Accounts) — a chequebook must be tied to one.
        </p>
      )}

      {adding && (
        <div className="p-4 rounded-xl mb-4 space-y-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <div className="grid sm:grid-cols-2 gap-3">
            <FormField label="Bank account" required>
              <Select value={form.bank_account_id} onChange={e => set('bank_account_id', e.target.value)}>
                <option value="">Select…</option>
                {banks.map(b => <option key={b.id} value={b.id}>{b.bank_name || b.ledger?.name}{b.account_no ? ` · ${b.account_no}` : ''}</option>)}
              </Select>
            </FormField>
            <FormField label="Book name / identifier" required>
              <Input value={form.name} onChange={e => set('name', e.target.value)} placeholder="e.g. Book #1" />
            </FormField>
            <FormField label="Number prefix" hint="Optional text before the number">
              <Input value={form.prefix} onChange={e => set('prefix', e.target.value)} placeholder="e.g. ICICI-" />
            </FormField>
            <FormField label="Leaves" hint="Derived from the range">
              <Input value={leafCount != null ? `${leafCount} leaves` : '—'} readOnly disabled />
            </FormField>
            <FormField label="Start cheque no." required hint="Leading zeros set the width">
              <Input value={form.start_raw} onChange={e => set('start_raw', e.target.value.replace(/[^0-9]/g, ''))} placeholder="000001" />
            </FormField>
            <FormField label="End cheque no." required>
              <Input value={form.end_raw} onChange={e => set('end_raw', e.target.value.replace(/[^0-9]/g, ''))} placeholder="000050" />
            </FormField>
          </div>
          <FormField label="Notes"><Textarea rows={2} value={form.notes} onChange={e => set('notes', e.target.value)} /></FormField>
          <div className="flex gap-2 justify-end">
            <GhostButton onClick={() => { setAdding(false); setForm(EMPTY) }}>Cancel</GhostButton>
            <button className="btn-3d" disabled={!canAdd || create.isPending} onClick={() => create.mutate()}>
              {create.isPending ? 'Saving…' : 'Add chequebook'}
            </button>
          </div>
        </div>
      )}

      <div className="space-y-2">
        {!books.length && !adding && (
          <p className="text-xs text-center py-6" style={{ color: 'var(--text-muted)' }}>No chequebooks yet.</p>
        )}
        {books.map(b => {
          const st = STATUS_STYLE[b.status] ?? STATUS_STYLE.closed
          const pct = b.total_leaves ? Math.round((b.leaves_used / b.total_leaves) * 100) : 0
          return (
            <div key={b.id} className="p-3 rounded-xl" style={{ border: '1px solid var(--border)' }}>
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{b.name}</span>
                    <span className="text-[11px] font-bold px-2 py-0.5 rounded-full" style={{ background: st.bg, color: st.fg }}>{st.label}</span>
                  </div>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    {b.bank_account?.bank_name || 'Bank'} · {b.format ? '' : ''}{(b.prefix || '')}{String(b.start_no).padStart(b.digits, '0')}–{(b.prefix || '')}{String(b.end_no).padStart(b.digits, '0')}
                    {b.next_cheque_no && <> · next <span style={{ color: 'var(--text-h)', fontWeight: 700 }}>{b.next_cheque_no}</span></>}
                  </p>
                </div>
                <div className="flex items-center gap-1.5 flex-shrink-0">
                  {b.status === 'active' && (
                    <button title="Close book" onClick={() => setConfirmClose(b)} className="p-1.5 rounded-lg hover:opacity-80" style={{ color: '#f59e0b' }}><Ban size={14} /></button>
                  )}
                  <button title="Delete book" onClick={() => setConfirmDel(b)} className="p-1.5 rounded-lg hover:opacity-80" style={{ color: '#f87171' }}><Trash2 size={14} /></button>
                </div>
              </div>
              {/* Leaf usage bar */}
              <div className="mt-2 flex items-center gap-2">
                <div className="flex-1 h-2 rounded-full overflow-hidden" style={{ background: 'var(--bg-input)' }}>
                  <div className="h-full rounded-full" style={{ width: `${pct}%`, background: pct >= 100 ? '#f59e0b' : '#a78bfa' }} />
                </div>
                <span className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>
                  {b.leaves_used}/{b.total_leaves} used · {b.leaves_available} left
                </span>
              </div>
            </div>
          )
        })}
      </div>

      {confirmClose && (
        <ConfirmDialog title="Close this chequebook?" icon={BookLock}
          message={`"${confirmClose.name}" will be retired — no further cheques can be issued from it. This can't be undone.`}
          confirmLabel="Close book" onConfirm={() => close.mutate(confirmClose.id)} onCancel={() => setConfirmClose(null)} />
      )}
      {confirmDel && (
        <ConfirmDialog title="Delete chequebook?" tone="danger"
          message={`"${confirmDel.name}" will be removed. Books with cheques already issued can't be deleted — close them instead.`}
          confirmLabel="Delete" onConfirm={() => remove.mutate(confirmDel.id)} onCancel={() => setConfirmDel(null)} />
      )}
    </div>
  )
}
