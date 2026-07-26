import { useState, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { Plus, X, Table2, Columns3 } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import { customerApi } from '@/services/customerApi'
import KanbanBoard from '@/modules/sales/components/KanbanBoard'

const d10 = s => (s ? String(s).slice(0, 10) : '—')

const STATUS_COLS = [
  { key: 'open', label: 'Open', color: '#3b82f6' },
  { key: 'in-progress', label: 'In Progress', color: '#f59e0b' },
  { key: 'closed', label: 'Closed', color: '#10b981' },
]
const PRIORITY_COLORS = { low: '#10b981', medium: '#3b82f6', high: '#f59e0b', urgent: '#ef4444' }
const EMPTY = { subject: '', description: '', priority: 'medium', due_date: '', assigned_to: '' }

export default function SupportTab({ id, tickets, reload, toast }) {
  const [view, setView] = useState('table')
  const [drawer, setDrawer] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [staff, setStaff] = useState([])
  const [saving, setSaving] = useState(false)
  const rows = tickets ?? []

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => {
    if (drawer && !staff.length) customerApi.assignableStaff().then(setStaff).catch(() => {})
  }, [drawer])

  const save = async () => {
    if (!form.subject.trim()) return toast.error('Subject required')
    setSaving(true)
    try {
      await helpdeskApi.tickets.create({
        subject: form.subject,
        description: form.description || undefined,
        priority: form.priority || undefined,
        due_date: form.due_date || undefined,
        assigned_to: form.assigned_to ? Number(form.assigned_to) : undefined,
        customer_id: Number(id),
      })
      toast.success('Ticket created')
      setDrawer(false); setForm(EMPTY); reload()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const moveTicket = async (ticketId, toStatus) => {
    try { await helpdeskApi.tickets.setStatus(ticketId, toStatus); reload() }
    catch (e) { toast.error(e.message) }
  }

  const bucket = (status) => (STATUS_COLS.some(c => c.key === status) ? status : 'open')
  const columns = STATUS_COLS.map(c => ({ ...c, items: rows.filter(t => bucket(t.status) === c.key) }))

  const card = (t) => (
    <div className="p-3 rounded-xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <p className="text-xs font-bold mb-1" style={{ color: 'var(--text-h)' }}>{t.subject}</p>
      <div className="flex items-center gap-2">
        {t.priority && <span className="px-1.5 py-0.5 rounded text-[10px] font-bold" style={{ background: `${PRIORITY_COLORS[t.priority] || '#3b82f6'}1a`, color: PRIORITY_COLORS[t.priority] || '#3b82f6' }}>{t.priority}</span>}
        <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{d10(t.created_at)}</span>
      </div>
    </div>
  )

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
          {[['table', Table2, 'Table'], ['kanban', Columns3, 'Kanban']].map(([k, Icon, label]) => (
            <button key={k} onClick={() => setView(k)} className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold transition-colors"
              style={{ background: view === k ? 'rgba(124,58,237,0.12)' : 'transparent', color: view === k ? 'var(--accent)' : 'var(--text-muted)' }}>
              <Icon size={13} /> {label}
            </button>
          ))}
        </div>
        <button onClick={() => setDrawer(true)} className="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
          <Plus size={13} /> New Ticket
        </button>
      </div>

      {view === 'table' ? (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                {['Subject', 'Status', 'Priority', 'Opened'].map(h => <th key={h} className="py-3 px-4 text-left label-caps whitespace-nowrap">{h}</th>)}
              </tr></thead>
              <tbody>
                {!rows.length ? (
                  <tr><td colSpan="4" className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No support tickets linked to this customer.</td></tr>
                ) : rows.map(t => (
                  <tr key={t.id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{t.subject}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{t.status}</td>
                    <td className="py-3 px-4" style={{ color: PRIORITY_COLORS[t.priority] || 'var(--text-muted)' }}>{t.priority || '—'}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{d10(t.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : (
        <KanbanBoard columns={columns} getId={t => t.id} renderCard={card} onCardMove={moveTicket} />
      )}

      {drawer && createPortal(
        <>
          <div className="drawer-backdrop" onClick={() => setDrawer(false)} />
          <div className="drawer-panel" style={{ width: 'min(520px, 96vw)' }}>
            <div className="drawer-header">
              <div>
                <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>New Ticket</h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Opens a helpdesk ticket linked to this customer</p>
              </div>
              <button onClick={() => setDrawer(false)} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)]" style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>
            <div className="drawer-body space-y-4">
              <div><label className="label">Subject *</label><input className="input-3d text-sm" value={form.subject} onChange={e => sf('subject', e.target.value)} /></div>
              <div><label className="label">Description</label><textarea rows={4} className="input-3d text-sm resize-none" value={form.description} onChange={e => sf('description', e.target.value)} /></div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Priority</label>
                  <select className="input-3d text-sm" value={form.priority} onChange={e => sf('priority', e.target.value)}>
                    {['low', 'medium', 'high', 'urgent'].map(p => <option key={p} value={p}>{p[0].toUpperCase() + p.slice(1)}</option>)}
                  </select>
                </div>
                <div><label className="label">Due Date</label><input type="date" className="input-3d text-sm" value={form.due_date} onChange={e => sf('due_date', e.target.value)} /></div>
              </div>
              <div>
                <label className="label">Assign To</label>
                <select className="input-3d text-sm" value={form.assigned_to} onChange={e => sf('assigned_to', e.target.value)}>
                  <option value="">Unassigned</option>
                  {staff.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
              </div>
            </div>
            <div className="drawer-footer">
              <button onClick={() => setDrawer(false)} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={save} disabled={saving} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
                {saving ? 'Creating…' : 'Create Ticket'}
              </button>
            </div>
          </div>
        </>,
        document.body,
      )}
    </div>
  )
}
