import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Building2, FileSignature, CircleDot } from 'lucide-react'
import { taskApi, TASK_PRIORITY } from '@/services/taskApi'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'
import TaskFormDrawer from '@/modules/tasks/components/TaskFormDrawer'

/**
 * Sales → Tasks. The Tasks feature itself lives in the Tasks module (owner:
 * Shivam); this view is just a sales-scoped lens on it — it shows ONLY tasks
 * linked to a customer or a contract (rel_type in customer|contract), never the
 * whole tenant's task list. New tasks created here default to a customer link so
 * they stay inside that scope.
 */

const SALES_REL = 'customer,contract'
const REL_META = {
  customer: { label: 'Customer', icon: Building2, color: '#7C3AED' },
  contract: { label: 'Contract', icon: FileSignature, color: '#0ea5e9' },
}

export default function SalesTasks() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['sales-tasks'],
    queryFn: () => taskApi.list({ rel_types: SALES_REL }),
  })
  const tasks = (Array.isArray(data) ? data : data?.data ?? []).filter(t =>
    !search || t.name?.toLowerCase().includes(search.toLowerCase()))

  const refresh = () => qc.invalidateQueries({ queryKey: ['sales-tasks'] })

  // Paging + count over the page's own search result.
  const { pageSize, setPageSize, visible, matched, pager } = useListView(tasks, [])

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease]">
      {/* Header */}
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p className="label-caps mb-1">Sales &amp; Revenue</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.4rem,2.5vw,1.9rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            Sales <span className="text-gradient">Tasks</span>
          </h1>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>Only tasks linked to a customer or contract.</p>
        </div>
        <button onClick={() => setCreating(true)}
          className="flex items-center gap-1.5 px-4 py-2.5 rounded-2xl text-sm font-bold text-white hover:scale-[1.03] transition-all"
          style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.35)' }}>
          <Plus size={15} /> New Task
        </button>
      </div>

      {/* Toolbar: search · count · rows-per-page · refresh */}
      <ListToolbar
        search={search} onSearch={setSearch} searchPlaceholder="Search sales tasks…"
        count={matched} total={(Array.isArray(data) ? data : data?.data ?? []).length} unit="task"
        pageSize={pageSize} onPageSize={setPageSize} pager={pager} onRefresh={refresh} />

      {/* List */}
      <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['Task', 'Linked to', 'Priority', 'Status', 'Due'].map(h => (
                <th key={h} className="text-left px-4 py-3 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={5} className="px-4 py-10 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && tasks.length === 0 && (
              <tr><td colSpan={5} className="px-4 py-12 text-center" style={{ color: 'var(--text-muted)' }}>
                No sales tasks yet. Create one, or add tasks from a customer or contract.
              </td></tr>
            )}
            {visible.map(t => {
              const meta = REL_META[t.rel_type] || { label: t.rel_type || '—', icon: CircleDot, color: 'var(--text-muted)' }
              const Icon = meta.icon
              const prio = TASK_PRIORITY[t.priority] || {}
              return (
                <tr key={t.id} onClick={() => navigate(`/app/tasks/${t.id}`)}
                  className="cursor-pointer transition-colors hover:bg-[rgba(124,58,237,0.05)]" style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-4 py-3 font-semibold" style={{ color: 'var(--text-h)' }}>{t.name}</td>
                  <td className="px-4 py-3">
                    <span className="inline-flex items-center gap-1.5 text-xs font-semibold" style={{ color: meta.color }}>
                      <Icon size={13} /> {meta.label}{t.rel_id ? ` #${t.rel_id}` : ''}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-[11px] font-bold px-2 py-0.5 rounded-full capitalize"
                      style={{ background: `color-mix(in srgb, ${prio.color || '#94a3b8'} 16%, transparent)`, color: prio.color || '#94a3b8' }}>
                      {t.priority || '—'}
                    </span>
                  </td>
                  <td className="px-4 py-3 capitalize" style={{ color: 'var(--text-body)' }}>{String(t.status || '').replace(/_/g, ' ') || '—'}</td>
                  <td className="px-4 py-3 tabular-nums" style={{ color: 'var(--text-muted)' }}>
                    {t.due_date ? String(t.due_date).split('T')[0] : '—'}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <TaskFormDrawer open={creating} onClose={() => setCreating(false)} defaults={{ rel_type: 'customer' }} onSaved={refresh} />
    </div>
  )
}
