import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Search, LifeBuoy, FolderKanban, ListTodo, BookOpen, Settings, CornerDownLeft, Truck } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import { projectApi } from '@/services/projectApi'
import { taskApi } from '@/services/taskApi'
import { transportSearchApi } from '@/services/transportApi'

/**
 * Global Ctrl/Cmd+K command palette (Phase 7d). Searches tickets, projects,
 * tasks and KB articles by name/subject, plus a jump to Support Settings.
 * Lists are fetched once when opened (React-Query cached) and filtered client-side
 * as you type, so typing stays instant with no per-keystroke network.
 *
 * ── TRANSPORT IS DIFFERENT, AND IT IS REGISTERED DIFFERENTLY ─────────────
 * Everything above is a LIST filtered by name. Transport is an IDENTIFIER
 * RESOLVER: one server call that tries a container number, then a trip, order,
 * consignment, customer reference, vehicle registration and driver name, and
 * returns at most one exact hit with the path to open it. Container numbers
 * normalise on the way — ABCD1234567, abcd-123456-7 and ABCD 1234 567 are one
 * box — which no amount of client-side `includes()` could do.
 *
 * STOS-CTD §4 lists ELEVEN entry points and requires that "all relevant search
 * paths must ultimately lead to the same Digital Passport". Until now that
 * resolver existed only in the Containers page's own filter box, which meant
 * the one search that understands transport identifiers was reachable from
 * exactly one screen out of every screen in the product.
 *
 * (This said "nine" from the day it was written. §4 lists eleven and §97 lists
 * nine — the document disagrees with itself, and the count here was copied from
 * the wrong one. D-117 records the discrepancy; the two §97 drops, POD Number
 * and Internal Consignment ID, are exactly the two that gave trouble later.)
 *
 * ── THE PASSPORT FOLLOW-THROUGH IS THE SERVER'S, AND THAT IS THE POINT ───
 * A plate or an LR number now lands on the Digital Passport rather than on a
 * list, because TransportSearchService walks the rest of the chain. Nothing
 * here had to change for that. `/app/transport` has a search box too, and the
 * only reason those two screens cannot disagree is that neither of them owns
 * any of this logic.
 *
 * It is placed FIRST in the results. An exact identifier match is a stronger
 * signal than a substring of somebody's ticket subject, and a dispatcher who
 * types a container number wants that container, not a discussion of it.
 *
 * Registering it here is P1's to do: TM-001 §8 puts "Common Architecture /
 * Integration Contracts" with Person 1, and this is the integration contract
 * between one module's search and the shell's.
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

  // Server-side, and only for terms long enough to BE an identifier — it is not
  // called on the first two letters of a name. A user without Transport access
  // simply gets nothing back; the palette must never break because one module
  // declined to answer.
  const term = q.trim()
  const { data: transportHit } = useQuery({
    queryKey: ['cmdk-transport', term],
    queryFn: () => transportSearchApi.resolve(term),
    enabled: open && term.length >= 4,
    retry: false,
    staleTime: 60_000,
  })

  const results = useMemo(() => {
    const term = q.trim().toLowerCase()
    const rows = (arr) => (Array.isArray(arr) ? arr : arr?.data || [])
    const match = (s) => !term || String(s || '').toLowerCase().includes(term)

    const groups = []

    // FIRST. An exact identifier beats a substring of a subject line.
    const hit = transportHit?.result
    if (hit) {
      groups.push({
        key: 'Transport', icon: Truck, color: 'var(--accent)',
        items: [{
          id: `${hit.type}-${hit.id}`,
          label: hit.label,
          // What it matched ON when that differs from what was typed — the
          // thing that makes a normalised container match explicable rather
          // than magic.
          sub: hit.matched_on && hit.matched_on !== hit.label
            ? `${hit.kind} · matched as ${hit.matched_on}`
            : hit.kind,
          to: hit.path,
        }],
      })
    }

    groups.push(...[
      { key: 'Tickets', icon: LifeBuoy, color: '#22d3ee', items: rows(tickets).filter(t => match(t.subject)).slice(0, 6).map(t => ({ id: t.id, label: t.subject, sub: `#${t.id} · ${t.status}`, to: `/app/helpdesk/tickets/${t.id}` })) },
      { key: 'Projects', icon: FolderKanban, color: '#ec4899', items: rows(projects).filter(p => match(p.name)).slice(0, 6).map(p => ({ id: p.id, label: p.name, sub: p.status, to: `/app/projects/${p.id}` })) },
      { key: 'Tasks', icon: ListTodo, color: '#f472b6', items: rows(tasks).filter(t => match(t.name)).slice(0, 6).map(t => ({ id: t.id, label: t.name, sub: t.status, to: `/app/tasks/${t.id}` })) },
      { key: 'Knowledge Base', icon: BookOpen, color: '#818cf8', items: rows(articles).filter(a => match(a.title)).slice(0, 6).map(a => ({ id: a.id, label: a.title, sub: 'article', to: `/app/helpdesk/kb-admin` })) },
    ].filter(g => g.items.length > 0))

    // Static action always available
    if (match('settings') || match('support')) {
      groups.push({ key: 'Actions', icon: Settings, color: '#94a3b8', items: [{ id: 'settings', label: 'Go to Support Settings', sub: 'helpdesk', to: '/app/helpdesk/settings' }] })
    }
    return groups
  }, [q, tickets, projects, tasks, articles, transportHit])

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
            placeholder="Container, trip, order, vehicle, ticket, project, task…"
            className="flex-1 bg-transparent outline-none text-sm" style={{ color: 'var(--text-h)' }} />
          <kbd className="text-[10px] px-1.5 py-0.5 rounded" style={{ background: 'var(--border)', color: 'var(--text-muted)' }}>esc</kbd>
        </div>

        <div className="max-h-[50vh] overflow-y-auto py-2">
          {/* Name what was typed. "No matches." is the same defect the four
              transport lists carried until 18 September: it tells the reader
              nothing about WHAT was searched, and the thing they need to check
              is the characters in the box. An identifier search in particular
              is usually one wrong character rather than a missing record. */}
          {flat.length === 0 && (
            <div className="text-center py-8">
              <p className="text-sm" style={{ color: 'var(--text-h)' }}>
                {term ? <>Nothing matches “{term}”</> : 'Type to search'}
              </p>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                {term
                  ? 'Try a container, trip, order, consignment or LR number, a customer reference, a vehicle registration, a driver name, or part of a ticket or project name. Invoice and POD numbers cannot be searched yet.'
                  : 'Containers, trips, orders, consignments, LR and delivery orders, vehicles, drivers, tickets, projects and tasks.'}
              </p>
            </div>
          )}
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
