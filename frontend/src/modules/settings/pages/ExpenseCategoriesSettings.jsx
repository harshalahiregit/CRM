import { useState, useEffect } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { createPortal } from 'react-dom'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

export default function ExpenseCategoriesSettings() {
  const toast = useToast()
  const [cats, setCats] = useState(null)
  const [name, setName] = useState('')
  const [confirmDel, setConfirmDel] = useState(null)

  const load = () => customerApi.expenseCategories.list().then(setCats).catch(e => toast.error(e.message))
  useEffect(() => { load() }, [])

  const add = async () => {
    if (!name.trim()) return toast.error('Category name required')
    try { await customerApi.expenseCategories.create({ name: name.trim() }); setName(''); load(); toast.success('Category added') }
    catch (e) { toast.error(e.message) }
  }

  const toggle = async (c) => {
    try { await customerApi.expenseCategories.update(c.id, { name: c.name, active: !c.active }); load() }
    catch (e) { toast.error(e.message) }
  }

  const doDelete = async () => {
    try { await customerApi.expenseCategories.remove(confirmDel.id); load(); toast.success('Deleted') }
    catch (e) { toast.error(e.message) } finally { setConfirmDel(null) }
  }

  return (
    <div className="card-3d max-w-xl">
      <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Expense Categories</h2>
      <p className="text-xs mt-1 mb-4" style={{ color: 'var(--text-muted)' }}>
        Feed the Category dropdown on customer expenses. Existing expenses keep their saved
        category even if it's removed here.
      </p>
      <div className="flex gap-2 mb-4">
        <input className="input-3d text-sm flex-1" placeholder="e.g. Travel, Software, Office Supplies" value={name}
          onChange={e => setName(e.target.value)} onKeyDown={e => e.key === 'Enter' && add()} />
        <button onClick={add} className="px-4 py-2 rounded-xl text-xs font-bold text-white flex items-center gap-1" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Plus size={13} /> Add</button>
      </div>
      {!cats ? (
        <div className="skeleton h-20 rounded-xl" style={{ background: 'var(--border)' }} />
      ) : !cats.length ? (
        <p className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No categories yet — add your first one above.</p>
      ) : (
        <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
          {cats.map(c => (
            <div key={c.id} className="flex items-center justify-between py-2.5">
              <p className="text-sm font-semibold" style={{ color: c.active ? 'var(--text-h)' : 'var(--text-muted)' }}>{c.name}</p>
              <div className="flex items-center gap-2">
                <label className="flex items-center gap-1.5 text-[11px] font-bold cursor-pointer" style={{ color: c.active ? '#10b981' : 'var(--text-muted)' }}>
                  <input type="checkbox" checked={!!c.active} onChange={() => toggle(c)} /> {c.active ? 'Active' : 'Off'}
                </label>
                <button onClick={() => setConfirmDel(c)} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)]"><Trash2 size={12} style={{ color: '#f87171' }} /></button>
              </div>
            </div>
          ))}
        </div>
      )}

      {confirmDel && createPortal(
        <ConfirmDialog title="Delete category?" message={`Remove "${confirmDel.name}"? Existing expenses keep it as saved text.`} confirmLabel="Delete" onConfirm={doDelete} onCancel={() => setConfirmDel(null)} />,
        document.body,
      )}
    </div>
  )
}
