import { useState } from 'react'
import { createPortal } from 'react-dom'
import { StickyNote, Flag, CalendarClock, Bell, Eye, EyeOff, Share2 } from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { taskApi } from '@/services/taskApi'
import { helpdeskApi } from '@/services/helpdeskApi'
import { projectApi } from '@/services/projectApi'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import RichTextEditor from '@/components/ui/RichTextEditor'
import RowMenu from '@/modules/sales/components/RowMenu'

const PRIORITIES = {
  low: { label: 'Low', color: '#10b981' },
  medium: { label: 'Medium', color: '#3b82f6' },
  high: { label: 'High', color: '#f59e0b' },
  urgent: { label: 'Urgent', color: '#ef4444' },
}
const VISIBILITIES = {
  team: { label: 'Team', icon: Eye },
  private: { label: 'Private', icon: EyeOff },
  client: { label: 'Client-visible', icon: Eye },
}
const EMPTY = { content: '', type: '', priority: '', deadline: '', reminder_at: '', visibility: 'team' }

const d10 = (v) => (v ? String(v).slice(0, 10) : '')
const stripHtml = (html) => { const el = document.createElement('div'); el.innerHTML = html || ''; return el.textContent.trim() }

export default function NotesTab({ id, client, notes, reload, toast }) {
  const [form, setForm] = useState(EMPTY)
  const [editing, setEditing] = useState(null)
  const [busy, setBusy] = useState(false)
  const [confirmDel, setConfirmDel] = useState(null)

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const payload = () => ({
    content: form.content,
    type: form.type || null,
    priority: form.priority || null,
    deadline: form.deadline || null,
    reminder_at: form.reminder_at ? form.reminder_at.replace('T', ' ') : null,
    visibility: form.visibility || 'team',
  })

  const save = async () => {
    if (!stripHtml(form.content)) return toast.error('Note content required')
    setBusy(true)
    try {
      if (editing) { await customerApi.notes.update(id, editing.id, payload()); toast.success('Note updated') }
      else { await customerApi.notes.create(id, payload()) }
      setForm(EMPTY); setEditing(null); reload()
    } catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }

  const startEdit = (n) => {
    setEditing(n)
    setForm({
      content: n.content || '',
      type: n.type || '',
      priority: n.priority || '',
      deadline: d10(n.deadline),
      reminder_at: n.reminder_at ? String(n.reminder_at).slice(0, 16).replace(' ', 'T') : '',
      visibility: n.visibility || 'team',
    })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const doDelete = async () => {
    try { await customerApi.notes.remove(id, confirmDel.id); toast.success('Note deleted'); reload() }
    catch (e) { toast.error(e.message) } finally { setConfirmDel(null) }
  }

  const convertToTask = async (n) => {
    try {
      await taskApi.create({
        name: `Note: ${stripHtml(n.content).slice(0, 80)}`,
        description: stripHtml(n.content),
        priority: n.priority || undefined,
        due_date: d10(n.deadline) || undefined,
        rel_type: 'customer',
        rel_id: Number(id),
      })
      toast.success('Task created from note')
    } catch (e) { toast.error(e.message) }
  }

  const convertToTicket = async (n) => {
    try {
      await helpdeskApi.tickets.create({
        subject: `Note: ${stripHtml(n.content).slice(0, 80)}`,
        description: stripHtml(n.content),
        priority: n.priority || undefined,
        customer_id: Number(id),
        due_date: d10(n.deadline) || undefined,
      })
      toast.success('Ticket created from note')
    } catch (e) { toast.error(e.message) }
  }

  // The menu offered this behind a permanently-disabled "Projects module coming
  // soon" tooltip. Projects is built, routed, and already reachable from this
  // same customer record — the excuse had simply outlived the fact. Reached
  // through the projects HTTP API, exactly as the two conversions above do.
  const convertToProject = async (n) => {
    try {
      const project = await projectApi.create({
        name: `Note: ${stripHtml(n.content).slice(0, 80)}`,
        description: stripHtml(n.content),
        link_type: 'customer',
        customer_id: Number(id),
        // start_date is required|date server-side; a converted note starts today.
        start_date: new Date().toLocaleDateString('en-CA'),
        deadline: d10(n.deadline) || undefined,
      })
      toast.success('Project created from note')
      return project
    } catch (e) { toast.error(e.message) }
  }

  const share = async (n) => {
    const text = `Note on ${client?.company || 'customer'} (${n.author?.name || 'Staff'}, ${d10(n.created_at)}):\n\n${stripHtml(n.content)}`
    try { await navigator.clipboard.writeText(text); toast.success('Note copied to clipboard') }
    catch { toast.error('Could not copy to clipboard') }
  }

  return (
    <div className="space-y-4">
      {/* Composer */}
      <div className="card-3d" style={{ padding: '16px' }}>
        <RichTextEditor value={form.content} onChange={v => sf('content', v)} placeholder="Add a note about this customer…" minHeight={110} />
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3">
          <div>
            <label className="label">Priority</label>
            <select className="input-3d text-sm" value={form.priority} onChange={e => sf('priority', e.target.value)}>
              <option value="">None</option>
              {Object.entries(PRIORITIES).map(([k, p]) => <option key={k} value={k}>{p.label}</option>)}
            </select>
          </div>
          <div>
            <label className="label">Deadline</label>
            <input type="date" className="input-3d text-sm" value={form.deadline} onChange={e => sf('deadline', e.target.value)} />
          </div>
          <div>
            <label className="label">Reminder</label>
            <input type="datetime-local" className="input-3d text-sm" value={form.reminder_at} onChange={e => sf('reminder_at', e.target.value)} />
          </div>
          <div>
            {/* §16 — what kind of note this is. Separate from visibility, which
                answers who may read it; this answers what it is about. */}
            <label className="label">Type</label>
            <select className="input-3d text-sm" value={form.type} onChange={e => sf('type', e.target.value)}>
              <option value="">General</option>
              {['Customer', 'Internal', 'Meeting', 'Commercial', 'Service', 'Escalation'].map(t => (
                <option key={t} value={t}>{t}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">Visibility</label>
            <select className="input-3d text-sm" value={form.visibility} onChange={e => sf('visibility', e.target.value)}>
              <option value="team">Team</option>
              <option value="private">Private (only me)</option>
              <option value="client">Client-visible</option>
            </select>
          </div>
        </div>
        <div className="flex justify-end gap-2 mt-3">
          {editing && (
            <button onClick={() => { setEditing(null); setForm(EMPTY) }} className="px-4 py-2 rounded-xl text-xs font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          )}
          <button onClick={save} disabled={busy} className="px-4 py-2 rounded-xl text-xs font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            {busy ? 'Saving…' : editing ? 'Save Changes' : 'Add Note'}
          </button>
        </div>
      </div>

      {/* Notes list */}
      {!notes?.length ? (
        <div className="card-3d text-center py-10">
          <StickyNote size={22} className="mx-auto mb-2" style={{ color: 'var(--text-faint)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No notes yet.</p>
        </div>
      ) : notes.map(n => {
        const pr = PRIORITIES[n.priority]
        const vis = VISIBILITIES[n.visibility] || VISIBILITIES.team
        const overdue = n.deadline && d10(n.deadline) < new Date().toISOString().slice(0, 10)
        return (
          <div key={n.id} className="card-3d" style={{ padding: '16px' }}>
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0 flex-1">
                <div className="flex items-center flex-wrap gap-1.5 mb-2">
                  {pr && <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold flex items-center gap-1" style={{ background: `${pr.color}1a`, color: pr.color }}><Flag size={9} /> {pr.label}</span>}
                  {n.deadline && <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold flex items-center gap-1" style={{ background: overdue ? 'rgba(239,68,68,0.1)' : 'var(--bg-input)', color: overdue ? '#ef4444' : 'var(--text-muted)', border: '1px solid var(--border)' }}><CalendarClock size={9} /> Due {d10(n.deadline)}{overdue ? ' · overdue' : ''}</span>}
                  {n.reminder_at && <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold flex items-center gap-1" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}><Bell size={9} /> {String(n.reminder_at).slice(0, 16).replace('T', ' ')}</span>}
                  <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold flex items-center gap-1" style={{ background: n.visibility === 'private' ? 'rgba(245,158,11,0.1)' : 'var(--bg-input)', color: n.visibility === 'private' ? '#f59e0b' : 'var(--text-muted)', border: '1px solid var(--border)' }}><vis.icon size={9} /> {vis.label}</span>
                </div>
                <div className="rich-content text-sm" style={{ color: 'var(--text-h)' }} dangerouslySetInnerHTML={{ __html: n.content }} />
                <p className="text-[11px] mt-2" style={{ color: 'var(--text-muted)' }}>{n.author?.name || 'Staff'} · {d10(n.created_at)}</p>
              </div>
              <RowMenu>
                <button className="row-menu-item" onClick={() => startEdit(n)}>Edit</button>
                <button className="row-menu-item" onClick={() => convertToTask(n)}>Convert to Task</button>
                <button className="row-menu-item" onClick={() => convertToTicket(n)}>Convert to Ticket</button>
                <button className="row-menu-item" onClick={() => convertToProject(n)}>Convert to Project</button>
                <button className="row-menu-item" onClick={() => share(n)}><Share2 size={11} className="inline mr-1" />Share (copy)</button>
                <button className="row-menu-item" style={{ color: '#f87171' }} onClick={() => setConfirmDel(n)}>Delete</button>
              </RowMenu>
            </div>
          </div>
        )
      })}

      {confirmDel && createPortal(
        <ConfirmDialog title="Delete note?" message="This note will be permanently removed." confirmLabel="Delete" onConfirm={doDelete} onCancel={() => setConfirmDel(null)} />,
        document.body,
      )}
    </div>
  )
}
