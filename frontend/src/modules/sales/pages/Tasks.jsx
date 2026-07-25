import { useState, useEffect, useCallback } from 'react'
import { Plus, LayoutGrid, List, CheckSquare, Clock, AlertTriangle, Flag } from 'lucide-react'
import { taskApi } from '@/services/taskApi'
import { useToast } from '@/hooks/useToast'
import ConfirmIconButton from '@/modules/customer/components/ConfirmIconButton'
import KanbanBoard from '@/modules/sales/components/KanbanBoard'
import TaskDrawer from '@/modules/sales/components/TaskDrawer'

const PRIORITY_COLORS = { low: '#94a3b8', medium: '#3b82f6', high: '#f59e0b', urgent: '#ef4444' }
const d10 = s => (s ? String(s).slice(0, 10) : '—')

export default function Tasks() {
  const toast = useToast()
  const [view, setView] = useState('table')
  const [rows, setRows] = useState(null)
  const [columns, setColumns] = useState([])
  const [statuses, setStatuses] = useState([])
  const [filterPriority, setFilterPriority] = useState('')
  const [search, setSearch] = useState('')
  const [drawer, setDrawer] = useState({ open: false, id: null })

  const load = useCallback(() => {
    taskApi.list({ priority: filterPriority || undefined, search: search || undefined }).then(setRows).catch(e => toast.error(e.message))
    taskApi.kanban().then(setColumns).catch(() => {})
  }, [filterPriority, search])

  useEffect(() => { load() }, [load])
  useEffect(() => { taskApi.statuses().then(setStatuses).catch(() => {}) }, [])

  const onSaved = () => load()
  const del = async (id) => { try { await taskApi.delete(id); toast.success('Task deleted'); load() } catch (e) { toast.error(e.message) } }
  const moveCard = async (taskId, statusId) => {
    try { await taskApi.updateStatus(taskId, statusId); load() } catch (e) { toast.error(e.message) }
  }

  const kpis = rows ? [
    { label: 'Total Tasks', val: rows.length, icon: CheckSquare, color: '#8b5cf6' },
    { label: 'Due This Week', val: rows.filter(t => t.due_date && new Date(t.due_date) <= new Date(Date.now() + 7 * 864e5)).length, icon: Clock, color: '#3b82f6' },
    { label: 'Urgent', val: rows.filter(t => t.priority === 'urgent').length, icon: AlertTriangle, color: '#ef4444' },
    { label: 'Completed', val: rows.filter(t => t.progress === 100).length, icon: Flag, color: '#10b981' },
  ] : []

  const kanbanColumns = columns.map(c => ({
    key: c.id, label: c.name, color: c.color,
    items: c.tasks || [],
  }))

  return (
    <div className="p-4 md:p-6 max-w-[1400px] mx-auto">
      <div className="flex items-center justify-between mb-5 flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Sales &amp; Revenue</p>
          <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>Tasks</h1>
        </div>
        <button onClick={() => setDrawer({ open: true, id: null })} className="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          <Plus size={16} /> New Task
        </button>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        {kpis.map(k => (
          <div key={k.label} className="kpi-3d p-4">
            <k.icon size={18} style={{ color: k.color }} />
            <p className="text-2xl font-black mt-2" style={{ color: 'var(--text-h)' }}>{k.val}</p>
            <p className="text-[11px] font-medium" style={{ color: 'var(--text-muted)' }}>{k.label}</p>
          </div>
        ))}
      </div>

      {/* Controls */}
      <div className="flex items-center gap-2 mb-4 flex-wrap">
        <div className="flex rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
          <button onClick={() => setView('table')} className="px-3 py-2 flex items-center gap-1 text-xs font-bold" style={{ background: view === 'table' ? 'var(--accent)' : 'transparent', color: view === 'table' ? '#fff' : 'var(--text-body)' }}><List size={14} /> Table</button>
          <button onClick={() => setView('kanban')} className="px-3 py-2 flex items-center gap-1 text-xs font-bold" style={{ background: view === 'kanban' ? 'var(--accent)' : 'transparent', color: view === 'kanban' ? '#fff' : 'var(--text-body)' }}><LayoutGrid size={14} /> Board</button>
        </div>
        <select className="input-3d text-sm w-40" value={filterPriority} onChange={e => setFilterPriority(e.target.value)}>
          <option value="">All Priorities</option>
          {['low', 'medium', 'high', 'urgent'].map(p => <option key={p} value={p}>{p[0].toUpperCase() + p.slice(1)}</option>)}
        </select>
        <input className="input-3d text-sm w-56" placeholder="Search tasks…" value={search} onChange={e => setSearch(e.target.value)} />
      </div>

      {/* Table view */}
      {view === 'table' && (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                {['Task', 'Priority', 'Status', 'Due', 'Progress', 'Assignees', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps whitespace-nowrap">{h}</th>)}
              </tr></thead>
              <tbody>
                {rows === null ? (
                  <tr><td colSpan={7} className="p-6 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
                ) : rows.length === 0 ? (
                  <tr><td colSpan={7} className="py-12 text-center" style={{ color: 'var(--text-muted)' }}>No tasks yet. Create your first task.</td></tr>
                ) : rows.map(t => (
                  <tr key={t.id} className="cursor-pointer" onClick={() => setDrawer({ open: true, id: t.id })} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{t.name}</td>
                    <td className="py-3 px-4"><span className="text-[10px] font-bold px-2 py-0.5 rounded-md" style={{ background: `${PRIORITY_COLORS[t.priority]}22`, color: PRIORITY_COLORS[t.priority] }}>{t.priority}</span></td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{t.status?.name || '—'}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{d10(t.due_date)}</td>
                    <td className="py-3 px-4">
                      <div className="w-20 h-1.5 rounded-full" style={{ background: 'var(--border)' }}>
                        <div className="h-full rounded-full" style={{ width: `${t.progress}%`, background: '#10b981' }} />
                      </div>
                    </td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{(t.assignees || []).map(a => a.name).join(', ') || '—'}</td>
                    <td className="py-3 px-4" onClick={e => e.stopPropagation()}>
                      <ConfirmIconButton onConfirm={() => del(t.id)} title="Delete task?" message="This task will be permanently removed." />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Kanban view */}
      {view === 'kanban' && (
        <KanbanBoard
          columns={kanbanColumns}
          getId={(t) => t.id}
          onCardMove={moveCard}
          renderCard={(t) => (
            <div onClick={() => setDrawer({ open: true, id: t.id })} className="card-3d p-3" style={{ borderRadius: 12 }}>
              <p className="text-xs font-bold mb-1" style={{ color: 'var(--text-h)' }}>{t.name}</p>
              <div className="flex items-center gap-2 flex-wrap">
                <span className="text-[10px] font-bold px-1.5 py-0.5 rounded" style={{ background: `${PRIORITY_COLORS[t.priority]}22`, color: PRIORITY_COLORS[t.priority] }}>{t.priority}</span>
                {t.due_date && <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{d10(t.due_date)}</span>}
                {t.checklist_items_count > 0 && <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>☑ {t.checklist_items_count}</span>}
              </div>
            </div>
          )}
        />
      )}

      <TaskDrawer open={drawer.open} taskId={drawer.id} statuses={statuses} onClose={() => setDrawer({ open: false, id: null })} onSaved={onSaved} />
    </div>
  )
}
