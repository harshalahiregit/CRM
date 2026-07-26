import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Search, LifeBuoy, FolderKanban, ListTodo, BookOpen, Settings, CornerDownLeft } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import { projectApi } from '@/services/projectApi'
import { taskApi } from '@/services/taskApi'

/**
 * Global Ctrl/Cmd+K command palette (Phase 7d). Searches tickets, projects,
 * tasks and KB articles by name/subject, plus a jump to Support Settings.
 * Lists are fetched once when opened (React-Query cached) and filtered client-side
 * as you type, so typing stays instant with no per-keystroke network.
 */
export default function CommandPalette() {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [active, setActive] = useState(0)
  const navigate = useNavigate()
  const inputRef = useRef(null)

  // Global hotkey + the header search button (which dispatches this event).
  useEffect(() => {
    const onKey = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setOpen(o => !o)
      }
      if (e.key === 'Escape') setOpen(false)
    }
    const onOpen = () => setOpen(true)
    window.addEventListener('keydown', onKey)
    window.addEventListener('open-command-palette', onOpen)
    return () => { window.removeEventListener('keydown', onKey); window.removeEventListener('open-command-palette', onOpen) }
  }, [])

  useEffect(() => { if (open) { setQ(''); setActive(0); setTimeout(() => inputRef.current?.focus(), 50) } }, [open])

  const en = { enabled: open, staleTime: 60_000 }
  const { data: tickets = [] } = useQuery({ queryKey: ['cmdk-tickets'], queryFn: () => helpdeskApi.tickets.list(), ...en })
  const { data: projects = [] } = useQuery({ queryKey: ['cmdk-projects'], queryFn: () => projectApi.list(), ...en })
  const { data: tasks = [] } = useQuery({ queryKey: ['cmdk-tasks'], queryFn: () => taskApi.list(), ...en })
  const { data: articles = [] } = useQuery({ queryKey: ['cmdk-kb'], queryFn: () => helpdeskApi.kb.articles(), ...en })

  const results = useMemo(() => {
    const term = q.trim().toLowerCase()
    const rows = (arr) => (Array.isArray(arr) ? arr : arr?.data || [])
    const match = (s) => !term || String(s || '').toLowerCase().includes(term)

    const groups = [
      { key: 'Tickets', icon: LifeBuoy, color: '#22d3ee', items: rows(tickets).filter(t => match(t.subject)).slice(0, 6).map(t => ({ id: t.id, label: t.subject, sub: `#${t.id} · ${t.status}`, to: `/app/helpdesk/tickets/${t.id}` })) },
      { key: 'Projects', icon: FolderKanban, color: '#ec4899', items: rows(projects).filter(p => match(p.name)).slice(0, 6).map(p => ({ id: p.id, label: p.name, sub: p.status, to: `/app/projects/${p.id}` })) },
      { key: 'Tasks', icon: ListTodo, color: '#f472b6', items: rows(tasks).filter(t => match(t.name)).slice(0, 6).map(t => ({ id: t.id, label: t.name, sub: t.status, to: `/app/tasks/${t.id}` })) },
      { key: 'Knowledge Base', icon: BookOpen, color: '#818cf8', items: rows(articles).filter(a => match(a.title)).slice(0, 6).map(a => ({ id: a.id, label: a.title, sub: 'article', to: `/app/helpdesk/kb-admin` })) },
    ].filter(g => g.items.length > 0)

    // Static action always available
    if (match('settings') || match('support')) {
      groups.push({ key: 'Actions', icon: Settings, color: '#94a3b8', items: [{ id: 'settings', label: 'Go to Support Settings', sub: 'helpdesk', to: '/app/helpdesk/settings' }] })
    }
    return groups
  }, [q, tickets, projects, tasks, articles])

  const flat = useMemo(() => results.flatMap(g => g.items.map(i => ({ ...i, group: g.key }))), [results])
  useEffect(() => { setActive(a => Math.min(a, Math.max(0, flat.length - 1))) }, [flat.length])

  const go = (item) => { if (item) { setOpen(false); navigate(item.to) } }

  const onInputKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(a => Math.min(a + 1, flat.length - 1)) }
    if (e.key === 'ArrowUp') { e.preventDefault(); setActive(a => Math.max(a - 1, 0)) }
    if (e.key === 'Enter') { e.preventDefault(); go(flat[active]) }
  }

  if (!open) return null

  let idx = -1
  return (
    <div className="fixed inset-0 z-[100] flex items-start justify-center pt-[12vh] px-4" style={{ background: 'rgba(0,0,0,0.55)' }} onClick={() => setOpen(false)}>
      <div className="w-full max-w-xl rounded-2xl border overflow-hidden shadow-2xl" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }} onClick={e => e.stopPropagation()}>
        <div className="flex items-center gap-2 px-4 py-3 border-b" style={{ borderColor: 'var(--border)' }}>
          <Search size={16} style={{ color: 'var(--text-muted)' }} />
          <input ref={inputRef} value={q} onChange={e => setQ(e.target.value)} onKeyDown={onInputKey}
            placeholder="Search tickets, projects, tasks, KB…"
            className="flex-1 bg-transparent outline-none text-sm" style={{ color: 'var(--text-h)' }} />
          <kbd className="text-[10px] px-1.5 py-0.5 rounded" style={{ background: 'var(--border)', color: 'var(--text-muted)' }}>esc</kbd>
        </div>

        <div className="max-h-[50vh] overflow-y-auto py-2">
          {flat.length === 0 && <p className="text-center text-sm py-8" style={{ color: 'var(--text-muted)' }}>No matches.</p>}
          {results.map(g => (
            <div key={g.key} className="mb-1">
              <p className="text-[10px] uppercase tracking-wide px-4 py-1" style={{ color: 'var(--text-muted)' }}>{g.key}</p>
              {g.items.map(item => {
                idx++
                const isActive = idx === active
                const Icon = g.icon
                return (
                  <button key={g.key + item.id} onMouseEnter={() => setActive(flat.findIndex(f => f.group === g.key && f.id === item.id))} onClick={() => go(item)}
                    className="w-full flex items-center gap-3 px-4 py-2 text-left" style={{ background: isActive ? 'var(--bg-card-hover)' : 'transparent' }}>
                    <Icon size={14} style={{ color: g.color }} />
                    <span className="flex-1 text-sm truncate" style={{ color: 'var(--text-h)' }}>{item.label}</span>
                    <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{item.sub}</span>
                    {isActive && <CornerDownLeft size={12} style={{ color: 'var(--text-muted)' }} />}
                  </button>
                )
              })}
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
