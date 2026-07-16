import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  FolderKanban, Plus, Search, X, Pin, PinOff, Pencil, Copy, Trash2, Users,
} from 'lucide-react'
import { projectApi, PROJECT_STATUS, PROJECT_ACCENT } from '@/services/projectApi'
import { tagApi } from '@/services/tagApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'
import { ConfirmModal } from '@/components/ui/SearchPicker'
import { TagChips } from '@/components/ui/TagInput'
import ProjectFormDrawer from '../components/ProjectFormDrawer'

/**
 * Projects list — status cards, search/filters, and per-row actions.
 *
 * PROJECT_STATUS used to be declared here and imported by ProjectDetail; it now
 * lives in projectApi next to the other shared metadata, so the pages don't
 * import constants from each other.
 */

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const money = v => v != null && v !== '' ? '₹' + Number(v).toLocaleString('en-IN') : '—'

export default function ProjectList() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user } = useAuth()
  // Only an admin or the project's own creator can edit/delete it (backend enforces
  // this too; hiding the buttons keeps the rule visible instead of 403-ing on click).
  const canManage = (p) => user?.role === 'admin' || p.created_by === user?.id

  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState(null)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [status, setStatus] = useState('')
  const [member, setMember] = useState('')
  const [tag, setTag] = useState('')
  const [err, setErr] = useState('')

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const filters = useMemo(() => {
    const f = {}
    if (debounced) f.search = debounced
    if (status) f.status = status
    if (member) f.member = member
    if (tag) f.tag = tag
    return f
  }, [debounced, status, member, tag])

  const { data: projects = [], isLoading, isError, error } = useQuery({
    queryKey: ['projects', filters], queryFn: () => projectApi.list(filters),
  })
  const { data: staff = [] } = useQuery({ queryKey: ['project-staff'], queryFn: projectApi.staff })
  const { data: tags = [] } = useQuery({ queryKey: ['tags', 'project'], queryFn: () => tagApi.list('project') })

  // Unfiltered, purely for the status cards — otherwise picking a status would
  // zero out every other card and the counts would be meaningless.
  const { data: allProjects = [] } = useQuery({ queryKey: ['projects', {}], queryFn: () => projectApi.list() })

  const refresh = () => qc.invalidateQueries({ queryKey: ['projects'] })
  const onErr = (e) => setErr(e?.message || 'That action failed.')

  const pin = useMutation({ mutationFn: (id) => projectApi.pin(id), onSuccess: refresh, onError: onErr })
  const copy = useMutation({
    mutationFn: (id) => projectApi.copy(id, { copy_members: true, copy_milestones: true }),
    onSuccess: (p) => { refresh(); navigate(`/app/projects/${p.id}`) },
    onError: onErr,
  })
  const remove = useMutation({
    mutationFn: (id) => projectApi.remove(id),
    onSuccess: () => { setConfirmDelete(null); refresh() },
    onError: onErr,
  })

  const counts = useMemo(() => {
    const c = {}
    Object.keys(PROJECT_STATUS).forEach(k => { c[k] = allProjects.filter(p => p.status === k).length })
    return c
  }, [allProjects])

  const activeFilters = [status, member, tag, debounced].filter(Boolean).length
  const clearFilters = () => { setSearch(''); setStatus(''); setMember(''); setTag('') }

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 14%, transparent)` }}>
          <FolderKanban size={17} style={{ color: PROJECT_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Projects</h1>
        <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
          {projects.length}
        </span>
        <button onClick={() => { setEditing(null); setShowForm(true) }}
          className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: PROJECT_ACCENT, color: '#fff' }}>
          <Plus size={14} /> New Project
        </button>
      </header>

      {/* Status cards — click to filter, click again to clear. */}
      <div className="grid gap-2 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))' }}>
        {Object.entries(PROJECT_STATUS).map(([key, meta]) => {
          const active = status === key
          return (
            <button key={key} onClick={() => setStatus(active ? '' : key)}
              className="rounded-2xl p-3 text-left transition-all"
              style={{
                background: active ? `color-mix(in srgb, ${meta.color} 10%, var(--bg-card))` : 'var(--bg-card)',
                border: `1px solid ${active ? meta.color : 'var(--border)'}`,
              }}>
              <div className="flex items-center gap-1.5 mb-1">
                <span className="w-2 h-2 rounded-full" style={{ background: meta.color }} />
                <span className="text-[10px] font-bold uppercase tracking-wide truncate" style={{ color: 'var(--text-muted)' }}>{meta.label}</span>
              </div>
              <p className="text-xl font-black" style={{ color: active ? meta.color : 'var(--text-h)' }}>{counts[key] ?? 0}</p>
            </button>
          )
        })}
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2 mb-4 p-2.5 rounded-2xl"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="relative flex-1" style={{ minWidth: 180 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search projects…"
            className="w-full rounded-xl outline-none"
            style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        </div>
        <div style={{ minWidth: 140 }}>
          <Select size="sm" value={member} onChange={setMember} placeholder="Any member"
            options={[{ value: '', label: 'Any member' }, ...staff.map(s => ({ value: String(s.id), label: s.name }))]} />
        </div>
        <div style={{ minWidth: 130 }}>
          <Select size="sm" value={tag} onChange={setTag} placeholder="Any tag"
            options={[{ value: '', label: 'Any tag' }, ...tags.map(t => ({ value: t.name, label: t.name, dot: t.color }))]} />
        </div>
        {activeFilters > 0 && (
          <button onClick={clearFilters} className="text-xs font-semibold px-3 py-2 rounded-xl"
            style={{ color: 'var(--color-danger-500)', border: '1px solid var(--border)' }}>Clear</button>
        )}
      </div>

      {err && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
      )}

      {isError && (
        <div className="p-6 rounded-2xl" style={{ border: '1px solid color-mix(in srgb, var(--color-danger-500) 30%, transparent)', background: 'var(--bg-card)' }}>
          <p className="font-bold text-sm" style={{ color: 'var(--color-danger-500)' }}>Couldn’t load projects</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{error?.message}</p>
        </div>
      )}

      {!isError && (
        <div className="overflow-x-auto rounded-2xl" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          <table className="w-full text-sm" style={{ minWidth: 860 }}>
            <thead>
              <tr className="text-left text-[11px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                <th className="px-4 py-3 font-bold">Project</th>
                <th className="px-4 py-3 font-bold">Customer</th>
                <th className="px-4 py-3 font-bold">Members</th>
                <th className="px-4 py-3 font-bold">Progress</th>
                <th className="px-4 py-3 font-bold">Deadline</th>
                <th className="px-4 py-3 font-bold">Status</th>
                <th className="px-4 py-3 font-bold text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {isLoading && [1, 2, 3].map(i => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {[...Array(7)].map((_, j) => (
                    <td key={j} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ background: 'var(--bg-input)' }} /></td>
                  ))}
                </tr>
              ))}
              {!isLoading && projects.length === 0 && (
                <tr><td colSpan={7} className="px-4 py-12 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
                  {activeFilters ? 'No projects match these filters.' : 'No projects yet.'}
                </td></tr>
              )}
              {!isLoading && projects.map(p => {
                const s = PROJECT_STATUS[p.status] || { label: p.status, color: 'var(--text-muted)' }
                const overdue = p.deadline && !['finished', 'cancelled'].includes(p.status)
                  && new Date(p.deadline) < new Date().setHours(0, 0, 0, 0)
                return (
                  <tr key={p.id} onClick={() => navigate(`/app/projects/${p.id}`)} className="cursor-pointer"
                    style={{ borderBottom: '1px solid var(--border)' }}
                    onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                    onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1.5">
                        {p.is_pinned && <Pin size={11} style={{ color: PROJECT_ACCENT, flexShrink: 0 }} fill="currentColor" />}
                        <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{p.name}</span>
                      </div>
                      {p.tags?.length > 0 && <div className="mt-1"><TagChips tags={p.tags} /></div>}
                    </td>
                    <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{p.customer?.name || '—'}</td>
                    <td className="px-4 py-3">
                      <span className="inline-flex items-center gap-1 text-xs" style={{ color: 'var(--text-muted)' }}>
                        <Users size={11} /> {p.members?.length ?? 0}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <div className="w-16 h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--bg-input)' }}>
                          <div className="h-full rounded-full" style={{ width: `${p.progress || 0}%`, background: PROJECT_ACCENT }} />
                        </div>
                        <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{p.progress || 0}%</span>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-xs" style={{ color: overdue ? 'var(--color-danger-500)' : 'var(--text-muted)', fontWeight: overdue ? 700 : 400 }}>
                      {fmtDate(p.deadline)}
                    </td>
                    <td className="px-4 py-3">
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                        style={{ background: `color-mix(in srgb, ${s.color} 15%, transparent)`, color: s.color }}>{s.label}</span>
                    </td>
                    <td className="px-4 py-3" onClick={e => e.stopPropagation()}>
                      <div className="flex items-center justify-end gap-1">
                        <RowBtn onClick={() => pin.mutate(p.id)} label={p.is_pinned ? 'Unpin' : 'Pin'}>
                          {p.is_pinned ? <PinOff size={12} /> : <Pin size={12} />}
                        </RowBtn>
                        <RowBtn onClick={() => copy.mutate(p.id)} label="Copy project"><Copy size={12} /></RowBtn>
                        {canManage(p) && <RowBtn onClick={() => { setEditing(p); setShowForm(true) }} label="Edit"><Pencil size={12} /></RowBtn>}
                        {canManage(p) && <RowBtn onClick={() => setConfirmDelete(p)} label="Delete" danger><Trash2 size={12} /></RowBtn>}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      <ProjectFormDrawer open={showForm} onClose={() => { setShowForm(false); setEditing(null) }}
        project={editing} onSaved={refresh} />

      <ConfirmModal
        open={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)}
        onConfirm={() => remove.mutate(confirmDelete.id)}
        title="Delete this project?"
        message={`“${confirmDelete?.name}” will be removed. Its tasks stay, but stop being linked to it.`}
        confirmLabel="Delete" danger
      />
    </div>
  )
}

function RowBtn({ onClick, label, danger, children }) {
  return (
    <button onClick={onClick} aria-label={label} title={label}
      className="w-7 h-7 rounded-lg flex items-center justify-center"
      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: danger ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
      {children}
    </button>
  )
}
