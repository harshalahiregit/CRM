import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Loader2, Trash2 } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { useToast } from '@/hooks/useToast'
import { Input } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import ToggleSwitch from '@/modules/customer/components/ToggleSwitch'

/**
 * Bill category master — the classification behind the New Bill "Category"
 * dropdown. Mounted in Accounts Settings and, via a button, on the Bills page.
 */
export default function BillCategoriesManager() {
  const toast = useToast()
  const qc = useQueryClient()
  const [name, setName] = useState('')
  const [confirmDel, setConfirmDel] = useState(null)

  const { data: rows = [], isLoading, isError } = useQuery({
    queryKey: ['accounts', 'bill-categories'],
    queryFn: accountsApi.billCategories.list,
    retry: false,
  })
  const inv = () => qc.invalidateQueries({ queryKey: ['accounts', 'bill-categories'] })

  const create = useMutation({
    mutationFn: () => accountsApi.billCategories.create({ name: name.trim() }),
    onSuccess: () => { toast.success('Category added'); setName(''); inv() },
    onError: (e) => toast.error(e.message),
  })
  const toggle = useMutation({
    mutationFn: (r) => accountsApi.billCategories.update(r.id, { active: !r.active }),
    onSuccess: inv, onError: (e) => toast.error(e.message),
  })
  const remove = useMutation({
    mutationFn: (id) => accountsApi.billCategories.remove(id),
    onSuccess: () => { toast.success('Deleted'); setConfirmDel(null); inv() },
    onError: (e) => { toast.error(e.message); setConfirmDel(null) },
  })

  if (isLoading) return <div className="flex justify-center py-8"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  if (isError) return <p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>The accounts books aren't set up yet — run the one-click setup first.</p>

  return (
    <div>
      <h3 className="font-bold text-sm mb-1" style={{ color: 'var(--text-h)' }}>Bill Categories</h3>
      <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>Classify vendor bills (Utilities, Rent, …) — feeds the New Bill category dropdown.</p>

      <div className="flex gap-2 mb-4">
        <Input value={name} onChange={e => setName(e.target.value)} placeholder="New category name"
          onKeyDown={e => { if (e.key === 'Enter' && name.trim()) create.mutate() }} />
        <button onClick={() => create.mutate()} disabled={!name.trim() || create.isPending}
          className="btn-3d flex items-center gap-1.5 flex-shrink-0 disabled:opacity-50"><Plus size={15} /> Add</button>
      </div>

      <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        {!rows.length ? (
          <p className="text-xs text-center py-6" style={{ color: 'var(--text-muted)' }}>No categories yet.</p>
        ) : rows.map((r, i) => (
          <div key={r.id} className="flex items-center justify-between px-3 py-2.5" style={{ borderBottom: i < rows.length - 1 ? '1px solid var(--border)' : 'none' }}>
            <span className="text-sm" style={{ color: r.active ? 'var(--text-h)' : 'var(--text-faint)' }}>{r.name}</span>
            <div className="flex items-center gap-3">
              <ToggleSwitch checked={r.active} onChange={() => toggle.mutate(r)} size="sm" title="Active" />
              <button onClick={() => setConfirmDel(r)} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)]"><Trash2 size={13} style={{ color: '#f87171' }} /></button>
            </div>
          </div>
        ))}
      </div>

      {confirmDel && (
        <ConfirmDialog title="Delete category?" message={`"${confirmDel.name}" will be removed. Existing bills keep their saved category label.`}
          confirmLabel="Delete" tone="danger" onConfirm={() => remove.mutate(confirmDel.id)} onCancel={() => setConfirmDel(null)} />
      )}
    </div>
  )
}
