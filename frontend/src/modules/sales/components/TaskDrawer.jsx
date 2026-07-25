import { useState, useEffect } from 'react'
import { Plus, Check, Trash2, Square, CheckSquare } from 'lucide-react'
import Drawer from '@/components/ui/Drawer'
import { taskApi } from '@/services/taskApi'
import { useToast } from '@/hooks/useToast'

const PRIORITIES = ['low', 'medium', 'high', 'urgent']
const BLANK = { name: '', description: '', priority: 'medium', status_id: '', start_date: '', due_date: '', progress: 0, is_billable: false, billable_amount: '' }

export default function TaskDrawer({ open, onClose, taskId, statuses = [], onSaved }) {
  const toast = useToast()
  const [form, setForm] = useState(BLANK)
  const [saving, setSaving] = useState(false)
  const [full, setFull] = useState(null) // full task (checklist/comments) when editing
  const [newItem, setNewItem] = useState('')
  const [newComment, setNewComment] = useState('')
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => {
    if (!open) return
    if (taskId) {
      taskApi.get(taskId).then(t => {
        setFull(t)
        setForm({
          name: t.name || '', description: t.description || '', priority: t.priority || 'medium',
          status_id: t.status_id || '', start_date: (t.start_date || '').slice(0, 10),
          due_date: (t.due_date || '').slice(0, 10), progress: t.progress || 0,
          is_billable: !!t.is_billable, billable_amount: t.billable_amount || '',
        })
      }).catch(e => toast.error(e.message))
    } else {
      setFull(null); setForm(BLANK)
    }
  }, [open, taskId])

  const save = async () => {
    if (!form.name.trim()) return toast.error('Task name is required')
    setSaving(true)
    try {
      const payload = { ...form, billable_amount: form.billable_amount === '' ? 0 : Number(form.billable_amount), progress: Number(form.progress) }
      if (taskId) await taskApi.update(taskId, payload)
      else await taskApi.create(payload)
      toast.success(taskId ? 'Task updated' : 'Task created')
      onSaved?.(); onClose()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const reloadFull = () => taskApi.get(taskId).then(setFull)
  const addItem = async () => { if (!newItem.trim()) return; await taskApi.addChecklistItem(taskId, newItem.trim()); setNewItem(''); reloadFull() }
  const toggleItem = async (id) => { await taskApi.toggleChecklistItem(id); reloadFull() }
  const delItem = async (id) => { await taskApi.deleteChecklistItem(id); reloadFull() }
  const addComment = async () => { if (!newComment.trim()) return; await taskApi.addComment(taskId, newComment.trim()); setNewComment(''); reloadFull() }

  return (
    <Drawer open={open} onClose={onClose} title={taskId ? 'Edit Task' : 'New Task'}
      footer={
        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="px-4 py-2 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving} className="px-5 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{saving ? 'Saving…' : 'Save Task'}</button>
        </div>
      }>
      <div className="space-y-4">
        <div><label className="label">Task Name *</label><input className="input-3d text-sm" value={form.name} onChange={e => sf('name', e.target.value)} placeholder="e.g. Follow up on proposal" /></div>
        <div><label className="label">Description</label><textarea rows={3} className="input-3d text-sm resize-none" value={form.description} onChange={e => sf('description', e.target.value)} /></div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Priority</label><select className="input-3d text-sm" value={form.priority} onChange={e => sf('priority', e.target.value)}>{PRIORITIES.map(p => <option key={p} value={p}>{p[0].toUpperCase() + p.slice(1)}</option>)}</select></div>
          <div><label className="label">Status</label><select className="input-3d text-sm" value={form.status_id} onChange={e => sf('status_id', e.target.value)}><option value="">Default</option>{statuses.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Start Date</label><input type="date" className="input-3d text-sm" value={form.start_date} onChange={e => sf('start_date', e.target.value)} /></div>
          <div><label className="label">Due Date</label><input type="date" className="input-3d text-sm" value={form.due_date} onChange={e => sf('due_date', e.target.value)} /></div>
        </div>
        <div>
          <label className="label">Progress — {form.progress}%</label>
          <input type="range" min="0" max="100" step="5" value={form.progress} onChange={e => sf('progress', e.target.value)} className="w-full" />
        </div>
        <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={form.is_billable} onChange={e => sf('is_billable', e.target.checked)} /> Billable
        </label>
        {form.is_billable && <div><label className="label">Billable Amount (₹)</label><input type="number" className="input-3d text-sm" value={form.billable_amount} onChange={e => sf('billable_amount', e.target.value)} /></div>}

        {/* Checklist + comments only when editing an existing task */}
        {taskId && full && (
          <>
            <div className="pt-2" style={{ borderTop: '1px solid var(--border)' }}>
              <p className="label-caps mb-2" style={{ color: 'var(--accent)' }}>Checklist</p>
              <div className="space-y-1.5">
                {(full.checklist_items || []).map(it => (
                  <div key={it.id} className="flex items-center gap-2 text-xs">
                    <button onClick={() => toggleItem(it.id)}>{it.is_finished ? <CheckSquare size={15} style={{ color: '#10b981' }} /> : <Square size={15} style={{ color: 'var(--text-muted)' }} />}</button>
                    <span className="flex-1" style={{ color: it.is_finished ? 'var(--text-muted)' : 'var(--text-body)', textDecoration: it.is_finished ? 'line-through' : 'none' }}>{it.description}</span>
                    <button onClick={() => delItem(it.id)}><Trash2 size={12} style={{ color: 'var(--text-muted)' }} /></button>
                  </div>
                ))}
              </div>
              <div className="flex gap-2 mt-2">
                <input className="input-3d text-sm flex-1" placeholder="Add checklist item…" value={newItem} onChange={e => setNewItem(e.target.value)} onKeyDown={e => e.key === 'Enter' && addItem()} />
                <button onClick={addItem} className="px-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}><Plus size={14} style={{ color: 'var(--accent)' }} /></button>
              </div>
            </div>
            <div className="pt-2" style={{ borderTop: '1px solid var(--border)' }}>
              <p className="label-caps mb-2" style={{ color: 'var(--accent)' }}>Comments</p>
              <div className="space-y-2">
                {(full.comments || []).map(c => (
                  <div key={c.id} className="text-xs">
                    <span className="font-bold" style={{ color: 'var(--text-body)' }}>{c.author?.name || 'User'}: </span>
                    <span style={{ color: 'var(--text-muted)' }}>{c.content}</span>
                  </div>
                ))}
              </div>
              <div className="flex gap-2 mt-2">
                <input className="input-3d text-sm flex-1" placeholder="Add a comment…" value={newComment} onChange={e => setNewComment(e.target.value)} onKeyDown={e => e.key === 'Enter' && addComment()} />
                <button onClick={addComment} className="px-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}><Check size={14} style={{ color: 'var(--accent)' }} /></button>
              </div>
            </div>
          </>
        )}
      </div>
    </Drawer>
  )
}
