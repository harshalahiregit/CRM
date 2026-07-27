import { useState, useMemo, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  Search, Plus, X, Inbox, Clock, CheckCircle2, Circle, User, Zap,
  Download, Columns3, Rows3, AlignJustify, ArrowUp, ArrowDown, ChevronsUpDown,
  Trash2, UserCheck, AlertCircle, Check, Sparkles, RotateCcw,
  FolderKanban, ListChecks, Boxes,
  Users, Landmark, ShoppingCart, HardHat, ShieldCheck, TrendingUp, Contact,
} from 'lucide-react'

// Where a ticket was raised from → a small origin badge in the Subject cell, so
// the queue shows at a glance that a ticket came in from another module. 'widget'
// keeps its existing inline badge; 'internal' shows nothing.
const SOURCE_BADGE = {
  project:    { icon: FolderKanban, label: 'project' },
  task:       { icon: ListChecks,   label: 'task' },
  inventory:  { icon: Boxes,        label: 'inventory' },
  hr:         { icon: Users,        label: 'hr' },
  accounts:   { icon: Landmark,     label: 'accounts' },
  purchase:   { icon: ShoppingCart, label: 'purchase' },
  tpv:        { icon: HardHat,      label: 'tpv' },
  compliance: { icon: ShieldCheck,  label: 'compliance' },
  sales:      { icon: TrendingUp,   label: 'sales' },
  customer:   { icon: Contact,      label: 'customer' },
}
import { helpdeskApi } from '@/services/helpdeskApi'
import { useAuth } from '@/context/AuthContext'
import SLABadge from '../components/ui/SLABadge'
import SlaTimer from '../components/ui/SlaTimer'
import Select from '../components/ui/Select'
import { ConfirmModal } from '@/components/ui/SearchPicker'

/* ───────────────────────────────────────────────────────────────
   Universal Data Grid — Ticket inbox (SDS "Nova" flagship component).
   Sticky sortable header · density switch · column show/hide (persisted)
   · saved views · bulk actions · inline status edit · CSV export.
   Backend untouched: uses tickets.list / setStatus / assign / remove.
─────────────────────────────────────────────────────────────── */

const normalize = (raw) => (Array.isArray(raw) ? raw : raw?.data || [])
const PRI_RANK = { urgent: 0, high: 1, medium: 2, low: 3 }
const initials = (name) => name ? name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) : '–'
const fmtAgo = (d) => {
  if (!d) return ''
  const s = Math.floor((Date.now() - new Date(d)) / 1000)
  if (s < 3600) return `${Math.max(1, Math.floor(s / 60))}m`
  if (s < 86400) return `${Math.floor(s / 3600)}h`
  return `${Math.floor(s / 86400)}d`
}

const SLA_RANK = { breached: 0, at_risk: 1, paused: 2, ok: 3, met: 4 }
const slaSort = (t) => {
  const s = t.sla
  if (!s?.tracked) return 9
  const states = [s.response?.state, s.resolution?.state].filter(Boolean)
  return states.length ? Math.min(...states.map(x => SLA_RANK[x] ?? 9)) : 9
}

const ALL_COLS = [
  { key: 'priority',   label: 'Priority',  always: true,  sort: t => PRI_RANK[t.priority] ?? 9 },
  { key: 'sla',        label: 'SLA',       sort: slaSort },
  { key: 'subject',    label: 'Subject',   always: true,  sort: t => (t.subject || '').toLowerCase() },
  { key: 'requester',  label: 'Requester', sort: t => (t.requester_name || '').toLowerCase() },
  { key: 'department', label: 'Dept',      sort: t => t.department_id ?? 999 },
  { key: 'status',     label: 'Status',    always: true,  sort: t => (t.status || '').toLowerCase() },
  { key: 'assignee',   label: 'Assignee',  sort: t => (t.assignee?.name || '~').toLowerCase() },
  { key: 'updated',    label: 'Updated',   sort: t => new Date(t.updated_at || t.created_at || 0).getTime() },
]

const loadPref = (k, fb) => { try { const v = JSON.parse(localStorage.getItem(k)); return v ?? fb } catch { return fb } }

// Requester precedence: widget/public tickets store free-text requester_*; agent-
// raised ones link a customer the API decorates onto the row. Mirrors the backend's
// mail recipient resolution so the tooltip shows the address that's actually emailed.
const requesterOf = (t) => ({
  name: t?.requester_name || t?.customer?.name || null,
  email: t?.requester_email || t?.customer?.email || null,
})

export default function TicketGrid() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user } = useAuth()

  const [view, setView] = useState('all')
  const [q, setQ] = useState('')
  const [showNew, setShowNew] = useState(false)
  const [sort, setSort] = useState({ key: 'updated', dir: 'desc' })
  const [selected, setSelected] = useState(() => new Set())
  const [density, setDensity] = useState(() => loadPref('hd-grid-density', 'comfortable'))
  const [hidden, setHidden] = useState(() => new Set(loadPref('hd-grid-hidden', [])))
  const [colMenu, setColMenu] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)   // bulk-delete guard
  const [bulkErr, setBulkErr] = useState('')

  useEffect(() => { localStorage.setItem('hd-grid-density', JSON.stringify(density)) }, [density])
  useEffect(() => { localStorage.setItem('hd-grid-hidden', JSON.stringify([...hidden])) }, [hidden])

  const { data: raw = [], isLoading } = useQuery({ queryKey: ['helpdesk-tickets'], queryFn: () => helpdeskApi.tickets.list() })
  const { data: settings } = useQuery({ queryKey: ['helpdesk-settings'], queryFn: helpdeskApi.settings.all })
  const { data: agents = [] } = useQuery({ queryKey: ['helpdesk-agents'], queryFn: helpdeskApi.agents })
  const tickets = normalize(raw)

  // Only offer bulk-delete when every selected ticket is one the user may delete
  // (admin or a manager of its department — carried on each row as can_delete).
  const canDeleteSelected = user?.role === 'admin'
    || (selected.size > 0 && [...selected].every(id => tickets.find(t => t.id === id)?.can_delete))

  const statusColor = useMemo(() => {
    const m = {}; (settings?.statuses || []).forEach(s => { m[s.name] = s.color }); return (n) => m[n] || '#64748b'
  }, [settings])
  const deptName = useMemo(() => {
    const m = {}; (settings?.departments || []).forEach(d => { m[d.id] = d.name }); return (id) => m[id] || '—'
  }, [settings])

  const counts = useMemo(() => ({
    all: tickets.length,
    open: tickets.filter(t => t.status === 'open').length,
    'in-progress': tickets.filter(t => t.status === 'in-progress').length,
    closed: tickets.filter(t => t.status === 'closed').length,
    unassigned: tickets.filter(t => !t.assigned_to).length,
    unseen: tickets.filter(t => t.is_new).length,
    reopened: tickets.filter(t => t.is_reopened).length,
  }), [tickets])

  // The single ticket with the most-recent UNSEEN change gets the "new" spotlight
  // — a moving highlight that follows the latest activity, not a wall of glowing
  // rows. The server re-flags a ticket unseen on any change (new reply, status
  // move, reassignment) and bumps updated_at, so keying off updated_at makes the
  // dot land on whatever changed last. Once opened (is_new clears) the next
  // most-recently-changed unseen ticket takes over.
  const newestNewId = useMemo(() => {
    const fresh = tickets.filter(t => t.is_new)
    if (!fresh.length) return null
    const activity = t => new Date(t.updated_at || t.created_at || 0).getTime()
    return fresh.reduce((a, b) => (activity(b) > activity(a) ? b : a)).id
  }, [tickets])

  const rows = useMemo(() => {
    const term = q.trim().toLowerCase()
    const col = ALL_COLS.find(c => c.key === sort.key)
    const out = tickets.filter(t => {
      if (view === 'unassigned' && t.assigned_to) return false
      if (view === 'unseen' && !t.is_new) return false
      if (view === 'reopened' && !t.is_reopened) return false
      if (view !== 'all' && view !== 'unassigned' && view !== 'unseen' && view !== 'reopened' && t.status !== view) return false
      if (term && !(`${t.subject} ${t.requester_name || ''} #${t.id}`.toLowerCase().includes(term))) return false
      return true
    })
    if (col) out.sort((a, b) => {
      const av = col.sort(a), bv = col.sort(b)
      const r = av < bv ? -1 : av > bv ? 1 : 0
      return sort.dir === 'asc' ? r : -r
    })
    return out
  }, [tickets, view, q, sort])

  // Selection is scoped to what's currently visible.
  const allSelected = rows.length > 0 && rows.every(t => selected.has(t.id))
  const toggleAll = () => setSelected(allSelected ? new Set() : new Set(rows.map(t => t.id)))
  const toggleOne = (id) => setSelected(s => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })

  // Row status change — optimistic so the pill flips instantly instead of waiting
  // on a full list refetch. Patch the row in cache, roll back on error, then
  // reconcile the list + sidebar badges once settled.
  const rowStatus = useMutation({
    mutationFn: ({ id, status }) => helpdeskApi.tickets.setStatus(id, status),
    onMutate: async ({ id, status }) => {
      await qc.cancelQueries({ queryKey: ['helpdesk-tickets'] })
      const prev = qc.getQueryData(['helpdesk-tickets'])
      qc.setQueryData(['helpdesk-tickets'], (old) => {
        if (!old) return old
        const patch = (row) => row.id === id ? { ...row, status } : row
        return Array.isArray(old) ? old.map(patch) : { ...old, data: (old.data || []).map(patch) }
      })
      return { prev }
    },
    onError: (_e, _v, ctx) => { if (ctx?.prev !== undefined) qc.setQueryData(['helpdesk-tickets'], ctx.prev) },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['helpdesk-tickets'] })
      qc.invalidateQueries({ queryKey: ['helpdesk-status-counts'] })
      qc.invalidateQueries({ queryKey: ['helpdesk-unseen-count'] })
    },
  })
  // REQ-05: opening a ticket marks it seen for THIS user. Clear its "new" dot in
  // the grid cache optimistically (instant feedback), fire the PATCH best-effort,
  // and refresh the sidebar unseen badge once the server confirms.
  const markSeen = useMutation({
    mutationFn: (id) => helpdeskApi.tickets.markSeen(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['helpdesk-unseen-count'] }),
  })
  const openTicket = (t) => {
    if (t.is_new) {
      qc.setQueryData(['helpdesk-tickets'], (old) => {
        if (!old) return old
        const clear = (row) => row.id === t.id ? { ...row, is_new: false } : row
        return Array.isArray(old) ? old.map(clear) : { ...old, data: (old.data || []).map(clear) }
      })
      markSeen.mutate(t.id)
    }
    navigate(`/app/helpdesk/tickets/${t.id}`)
  }

  // One request for the whole selection — the server applies each ticket with the
  // same guards as the single routes and returns { ok, failed }. The old version
  // fired one HTTP call PER ticket in parallel, which crawled on the single-threaded
  // dev server and left the selection half-applied on any mid-way failure.
  const bulk = useMutation({
    mutationFn: ({ action, value }) => helpdeskApi.tickets.bulk({ action, ids: [...selected], value }),
    onMutate: () => setBulkErr(''),
    onSuccess: (res) => {
      setSelected(new Set()); setConfirmDelete(false)
      qc.invalidateQueries({ queryKey: ['helpdesk-tickets'] })
      qc.invalidateQueries({ queryKey: ['helpdesk-status-counts'] })
      qc.invalidateQueries({ queryKey: ['helpdesk-unseen-count'] })
      // If the server skipped some tickets (e.g. no rights on a few), say so
      // instead of silently pretending the whole batch went through.
      const failed = res?.failed || []
      if (failed.length) setBulkErr(`${failed.length} ticket${failed.length === 1 ? '' : 's'} skipped — ${failed[0].reason}`)
    },
    // Without this, a failed bulk action cleared nothing and showed nothing —
    // it just looked like the buttons did nothing. Now the reason surfaces.
    onError: (e) => setBulkErr(e?.message || 'That action failed. Please try again.'),
  })

  const exportCsv = () => {
    const cols = ['id', 'subject', 'requester_name', 'priority', 'status', 'created_at']
    const esc = v => `"${String(v ?? '').replace(/"/g, '""')}"`
    const csv = [cols.join(','), ...rows.map(t => cols.map(c => esc(t[c])).join(','))].join('\n')
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
    const a = document.createElement('a'); a.href = url; a.download = `tickets-${view}.csv`; a.click(); URL.revokeObjectURL(url)
  }

  const TABS = [
    { key: 'all', label: 'All', icon: Inbox },
    { key: 'unseen', label: 'Unseen', icon: Sparkles },
    { key: 'open', label: 'Open', icon: Circle },
    { key: 'in-progress', label: 'In Progress', icon: Clock },
    { key: 'closed', label: 'Closed', icon: CheckCircle2 },
    { key: 'reopened', label: 'Reopened', icon: RotateCcw },
    { key: 'unassigned', label: 'Unassigned', icon: User },
  ]
  const cols = ALL_COLS.filter(c => !hidden.has(c.key))
  const rowPad = density === 'dense' ? '6px 12px' : '11px 12px'
  const ACCENT = 'var(--color-support-500)'

  const setSortKey = (key) => setSort(s => s.key === key ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' })

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <p className="label-caps mb-0.5">Helpdesk</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2.2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Tickets</h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{rows.length} of {tickets.length} tickets</p>
        </div>
        <button onClick={() => setShowNew(true)}
          className="flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-xl transition-opacity hover:opacity-90"
          style={{ background: `linear-gradient(135deg,var(--color-support-400),var(--color-support-600))`, color: '#fff' }}>
          <Plus size={15} /> New Ticket
        </button>
      </div>

      {/* Views (saved) */}
      <div className="flex items-center gap-1.5 flex-wrap">
        {TABS.map(({ key, label, icon: Icon }) => {
          const active = view === key
          return (
            <button key={key} onClick={() => setView(key)} className="flex items-center gap-1.5 transition-colors"
              style={{
                padding: '6px 13px', borderRadius: 999, fontSize: 13, fontWeight: 600,
                background: active ? ACCENT : 'var(--bg-card)', color: active ? '#fff' : 'var(--text-muted)',
                border: `1px solid ${active ? 'transparent' : 'var(--border)'}`,
              }}>
              <Icon size={12} /> {label}
              <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full"
                style={{ background: active ? 'rgba(255,255,255,0.25)' : 'var(--bg-global)', color: active ? '#fff' : 'var(--text-muted)' }}>
                {counts[key] ?? 0}
              </span>
            </button>
          )
        })}
      </div>

      {/* Toolbar */}
      <div className="flex items-center gap-2 flex-wrap">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
          <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search tickets…"
            style={{ padding: '8px 12px 8px 32px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', fontSize: 13.5, outline: 'none', width: 240, color: 'var(--text-h)' }} />
        </div>
        <div className="flex-1" />
        {/* Density */}
        <div className="flex items-center rounded-lg overflow-hidden" style={{ border: '1px solid var(--border)' }}>
          {[['comfortable', Rows3], ['dense', AlignJustify]].map(([k, Icon]) => (
            <button key={k} onClick={() => setDensity(k)} title={k}
              className="p-2 transition-colors"
              style={{ background: density === k ? 'var(--bg-input)' : 'transparent', color: density === k ? ACCENT : 'var(--text-muted)' }}>
              <Icon size={15} />
            </button>
          ))}
        </div>
        {/* Columns */}
        <div className="relative">
          <ToolBtn icon={Columns3} label="Columns" onClick={() => setColMenu(o => !o)} />
          {colMenu && (
            <>
              <div className="fixed inset-0 z-40" onClick={() => setColMenu(false)} />
              <div className="absolute right-0 mt-1 z-50 rounded-xl p-1.5 w-44" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
                {ALL_COLS.map(c => (
                  <button key={c.key} disabled={c.always}
                    onClick={() => setHidden(h => { const n = new Set(h); n.has(c.key) ? n.delete(c.key) : n.add(c.key); return n })}
                    className="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-left disabled:opacity-40 transition-colors hover:bg-[var(--bg-input)]"
                    style={{ color: 'var(--text-body)' }}>
                    <span className="w-4 h-4 rounded flex items-center justify-center shrink-0" style={{ border: '1px solid var(--border)', background: !hidden.has(c.key) ? ACCENT : 'transparent' }}>
                      {!hidden.has(c.key) && <Check size={11} className="text-white" />}
                    </span>
                    {c.label}
                  </button>
                ))}
              </div>
            </>
          )}
        </div>
        <ToolBtn icon={Download} label="Export" onClick={exportCsv} />
      </div>

      {/* Grid */}
      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        {/* Bulk actions bar */}
        {selected.size > 0 && (
          <div className="flex items-center gap-2 px-4 py-2.5 flex-wrap" style={{ background: 'var(--bg-input)', borderBottom: '1px solid var(--border)' }}>
            <span className="text-xs font-bold" style={{ color: ACCENT }}>{selected.size} selected</span>
            <div className="flex-1" />
            <Select
              value=""
              onChange={v => v && bulk.mutate({ action: 'status', value: v })}
              options={(settings?.statuses || []).map(s => ({ value: s.name, label: s.name, dot: s.color }))}
              placeholder="Set status…"
              size="sm"
              className="w-36"
              ariaLabel="Set status for selected tickets"
            />
            <Select
              value=""
              onChange={v => v && bulk.mutate({ action: 'assign', value: Number(v) })}
              options={agents.map(a => ({ value: a.id, label: a.name }))}
              placeholder="Assign to…"
              size="sm"
              className="w-40"
              ariaLabel="Assign selected tickets to an agent"
            />
            {user?.id && <BulkBtn icon={UserCheck} label="Assign to me" onClick={() => bulk.mutate({ action: 'assign', value: user.id })} />}
            {canDeleteSelected && <BulkBtn icon={Trash2} label="Delete" danger onClick={() => setConfirmDelete(true)} />}
            <button onClick={() => { setSelected(new Set()); setBulkErr('') }} className="text-xs font-semibold px-2" style={{ color: 'var(--text-muted)' }}>Clear</button>
          </div>
        )}
        {bulkErr && (
          <div className="px-4 py-2 text-xs font-semibold" style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)', borderBottom: '1px solid var(--border)' }}>{bulkErr}</div>
        )}

        <div className="overflow-x-auto">
          <table className="w-full" style={{ borderCollapse: 'collapse', minWidth: 720 }}>
            <thead>
              <tr style={{ borderBottom: '1px solid var(--border)' }}>
                <th style={{ padding: '10px 12px', width: 36 }}>
                  <Checkbox checked={allSelected} onChange={toggleAll} accent={ACCENT} />
                </th>
                {cols.map(c => (
                  <th key={c.key} onClick={() => setSortKey(c.key)}
                    className="cursor-pointer select-none"
                    style={{ padding: '10px 12px', textAlign: 'left', fontSize: 11, fontWeight: 800, letterSpacing: '0.05em', textTransform: 'uppercase', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                    <span className="inline-flex items-center gap-1">
                      {c.label}
                      {sort.key === c.key
                        ? (sort.dir === 'asc' ? <ArrowUp size={11} style={{ color: ACCENT }} /> : <ArrowDown size={11} style={{ color: ACCENT }} />)
                        : <ChevronsUpDown size={11} style={{ opacity: 0.35 }} />}
                    </span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && [1, 2, 3, 4, 5, 6].map(i => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td colSpan={cols.length + 1} style={{ padding: rowPad }}>
                    <div className="h-4 rounded-lg animate-pulse" style={{ background: 'var(--border)' }} />
                  </td>
                </tr>
              ))}

              {!isLoading && rows.length === 0 && (
                <tr><td colSpan={cols.length + 1}>
                  <div className="flex flex-col items-center justify-center py-16 gap-2">
                    <div className="w-14 h-14 rounded-2xl flex items-center justify-center" style={{ background: 'var(--bg-input)' }}>
                      <Inbox size={26} style={{ color: ACCENT }} />
                    </div>
                    <p className="font-semibold" style={{ color: 'var(--text-h)' }}>{q ? 'No matching tickets' : 'No tickets here'}</p>
                    <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{q ? 'Try a different search' : 'Create the first ticket to get started'}</p>
                  </div>
                </td></tr>
              )}

              {!isLoading && rows.map(t => {
                const isSel = selected.has(t.id)
                const sColor = statusColor(t.status)
                return (
                  <tr key={t.id}
                    onClick={() => openTicket(t)}
                    className="cursor-pointer transition-colors"
                    style={{
                      borderBottom: '1px solid var(--border)',
                      background: isSel ? 'var(--bg-input)' : 'transparent',
                      // The ticket with the latest unseen change is spotlighted
                      // (primary left-border glow), not every unseen row.
                      borderLeft: t.id === newestNewId ? '3px solid var(--color-primary-500)' : '3px solid transparent',
                      boxShadow: t.id === newestNewId ? 'inset 8px 0 12px -8px var(--color-primary-500)' : 'none',
                    }}
                    onMouseEnter={e => { if (!isSel) e.currentTarget.style.background = 'var(--bg-card-hover)' }}
                    onMouseLeave={e => { if (!isSel) e.currentTarget.style.background = 'transparent' }}>
                    <td style={{ padding: rowPad, position: 'relative' }} onClick={e => e.stopPropagation()}>
                      {t.id === newestNewId && (
                        <span className="absolute left-1 top-1/2 -translate-y-1/2 flex h-2 w-2" title="Recently updated — not opened yet">
                          <span className="animate-ping absolute inline-flex h-full w-full rounded-full" style={{ background: 'var(--color-primary-500)', opacity: 0.55 }} />
                          <span className="relative inline-flex rounded-full h-2 w-2" style={{ background: 'var(--color-primary-500)' }} />
                        </span>
                      )}
                      <Checkbox checked={isSel} onChange={() => toggleOne(t.id)} accent={ACCENT} />
                    </td>
                    {cols.map(c => (
                      <td key={c.key} style={{ padding: rowPad, fontSize: 13, color: 'var(--text-body)', whiteSpace: c.key === 'subject' ? 'normal' : 'nowrap' }}
                        onClick={c.key === 'status' ? e => e.stopPropagation() : undefined}>
                        {c.key === 'priority' && <SLABadge priority={t.priority} showSla={false} />}
                        {c.key === 'sla' && <SlaTimer sla={t.sla} compact />}
                        {c.key === 'subject' && (
                          <div className="min-w-0">
                            <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{t.subject}</span>
                            <span className="ml-2 text-xs" style={{ color: 'var(--text-muted)', fontFamily: 'monospace' }}>#{t.id}</span>
                            {t.source === 'widget' && <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10px]" style={{ color: ACCENT }}><Zap size={9} />widget</span>}
                            {SOURCE_BADGE[t.source] && (() => {
                              const S = SOURCE_BADGE[t.source]
                              return (
                                <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10px] font-bold px-1.5 py-0.5 rounded-full align-middle" title={`Raised from the ${S.label} module`}
                                  style={{ background: `color-mix(in srgb, ${ACCENT} 14%, transparent)`, color: ACCENT, border: `1px solid color-mix(in srgb, ${ACCENT} 30%, transparent)` }}>
                                  <S.icon size={9} />from {S.label}
                                </span>
                              )
                            })()}
                            {t.is_reopened && (
                              <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10px] font-bold px-1.5 py-0.5 rounded-full align-middle" title="This ticket was reopened"
                                style={{ background: 'color-mix(in srgb, var(--color-warning-500) 16%, transparent)', color: 'var(--color-warning-500)', border: '1px solid color-mix(in srgb, var(--color-warning-500) 30%, transparent)' }}>
                                <RotateCcw size={9} />Reopened
                              </span>
                            )}
                          </div>
                        )}
                        {c.key === 'requester' && (() => {
                          const { name, email } = requesterOf(t)
                          if (!name && !email) return <span style={{ color: 'var(--text-muted)' }}>—</span>
                          // Tooltip surfaces the email (REQ-14) without widening the column.
                          const tip = email ? (name ? `${name} <${email}>` : email) : name
                          return <span title={tip} style={{ cursor: 'default' }}>{name || email}</span>
                        })()}
                        {c.key === 'department' && deptName(t.department_id)}
                        {c.key === 'status' && (
                          <Select
                            value={t.status}
                            onChange={v => rowStatus.mutate({ id: t.id, status: v })}
                            options={(settings?.statuses || [{ id: 0, name: t.status }]).map(s => ({ value: s.name, label: s.name, dot: s.color }))}
                            size="sm"
                            ariaLabel={`Status for ticket ${t.id}`}
                            buttonStyle={{
                              color: sColor,
                              background: `${sColor}18`,
                              border: `1px solid ${sColor}30`,
                              borderRadius: 999,
                              padding: '4px 10px',
                              fontSize: 11.5,
                              fontWeight: 700,
                              textTransform: 'capitalize',
                            }}
                          />
                        )}
                        {c.key === 'assignee' && (
                          <span className="inline-flex items-center gap-1.5">
                            <span className="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-bold shrink-0"
                              style={{ background: t.assignee?.name ? `linear-gradient(135deg,var(--color-support-400),var(--color-support-600))` : 'var(--bg-global)', color: t.assignee?.name ? '#fff' : 'var(--text-muted)', border: '1px solid var(--border)' }}>
                              {initials(t.assignee?.name)}
                            </span>
                            <span className="text-xs" style={{ color: t.assignee?.name ? 'var(--text-body)' : 'var(--text-muted)' }}>{t.assignee?.name || 'Unassigned'}</span>
                          </span>
                        )}
                        {c.key === 'updated' && <span style={{ color: 'var(--text-muted)' }}>{fmtAgo(t.updated_at || t.created_at)}</span>}
                      </td>
                    ))}
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>

      {showNew && (
        <NewTicketModal settings={settings} onClose={() => setShowNew(false)}
          onCreated={(id) => { qc.invalidateQueries({ queryKey: ['helpdesk-tickets'] }); setShowNew(false); navigate(`/app/helpdesk/tickets/${id}`) }} />
      )}

      <ConfirmModal
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => bulk.mutate({ action: 'delete' })}
        title={`Delete ${selected.size} ticket${selected.size === 1 ? '' : 's'}?`}
        message="This permanently removes the selected tickets and their replies. This can't be undone."
        confirmLabel="Delete"
        danger
      />
    </div>
  )
}

/* ── Small grid controls ─────────────────────────────────────── */
function ToolBtn({ icon: Icon, label, onClick }) {
  return (
    <button onClick={onClick} className="flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-lg transition-colors hover:bg-[var(--bg-input)]"
      style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}>
      <Icon size={14} /> {label}
    </button>
  )
}
function BulkBtn({ icon: Icon, label, onClick, danger }) {
  return (
    <button onClick={onClick} className="flex items-center gap-1 text-xs font-semibold px-2.5 py-1.5 rounded-lg transition-opacity hover:opacity-80"
      style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: danger ? 'var(--color-danger-500)' : 'var(--text-body)' }}>
      <Icon size={13} /> {label}
    </button>
  )
}
function Checkbox({ checked, onChange, accent }) {
  return (
    <button onClick={onChange} className="w-4 h-4 rounded flex items-center justify-center transition-colors"
      style={{ border: `1px solid ${checked ? accent : 'var(--border)'}`, background: checked ? accent : 'transparent' }}>
      {checked && <Check size={11} className="text-white" />}
    </button>
  )
}

/* ── New Ticket Modal (unchanged behaviour) ──────────────────── */
const inp = { width: '100%', padding: '10px 13px', borderRadius: 10, border: '1px solid var(--border)', fontSize: 14, outline: 'none', color: 'var(--text-h)', background: 'var(--bg-input)' }

function NewTicketModal({ settings, onClose, onCreated }) {
  const [form, setForm] = useState({ subject: '', description: '', priority: 'medium', status: 'open', department_id: '', assigned_to: '', requester_name: '', requester_email: '' })
  const create = useMutation({
    mutationFn: () => { const p = { ...form }; Object.keys(p).forEach(k => p[k] === '' && delete p[k]); return helpdeskApi.tickets.create(p) },
    onSuccess: (t) => onCreated(t.id),
  })
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const LBL = { display: 'block', fontSize: 11, fontWeight: 700, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 5 }
  // People the admin assigned to the chosen department (the ticket routes to them).
  const deptAgents = (settings?.departments || []).find(d => String(d.id) === String(form.department_id))?.managers || []

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center bg-black/50" style={{ paddingTop: '8vh' }} onClick={onClose}>
      <div className="w-full max-w-[500px] rounded-2xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)', padding: 24, maxHeight: '85vh', overflowY: 'auto' }} onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="font-black text-base" style={{ color: 'var(--text-h)' }}>New Ticket</h2>
          <button onClick={onClose} className="hover:opacity-70"><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div className="space-y-3.5">
          <div><label style={LBL}>Subject *</label><input style={inp} value={form.subject} onChange={e => set('subject', e.target.value)} placeholder="What's the issue?" /></div>
          <div><label style={LBL}>Description</label><textarea style={{ ...inp, minHeight: 80, resize: 'vertical' }} value={form.description} onChange={e => set('description', e.target.value)} placeholder="Describe in detail…" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label style={LBL}>Priority</label>
              <Select value={form.priority} onChange={v => set('priority', v)} ariaLabel="Priority"
                options={(settings?.priorities || [{ name: 'medium' }]).map(p => ({ value: p.name, label: p.name, dot: p.color }))} />
            </div>
            <div>
              <label style={LBL}>Status</label>
              <Select value={form.status} onChange={v => set('status', v)} ariaLabel="Status"
                options={(settings?.statuses || [{ name: 'open' }]).filter(s => s.name !== 'merged').map(s => ({ value: s.name, label: s.name, dot: s.color }))} />
            </div>
          </div>
          <div>
            <label style={LBL}>Department</label>
            <Select value={form.department_id ?? ''} onChange={v => setForm(f => ({ ...f, department_id: v, assigned_to: '' }))} placeholder="— none —" ariaLabel="Department"
              options={[{ value: '', label: '— none —' }, ...(settings?.departments || []).map(d => ({ value: d.id, label: d.name }))]} />
          </div>

          {/* People the admin assigned to this department — the ticket routes to all
              of them; tap one to assign it directly. */}
          {form.department_id && (
            <div className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <p style={{ ...LBL, marginBottom: 8 }} className="flex items-center gap-1.5">
                <UserCheck size={12} style={{ color: 'var(--color-support-500)' }} /> Handled by this department
              </p>
              {deptAgents.length === 0 ? (
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No one assigned yet — it’ll go to the ticket managers.</p>
              ) : (
                <div className="flex flex-wrap gap-1.5">
                  {deptAgents.map(a => {
                    const on = String(form.assigned_to) === String(a.id)
                    return (
                      <button type="button" key={a.id} onClick={() => set('assigned_to', on ? '' : a.id)}
                        className="flex items-center gap-1 text-xs font-semibold px-2.5 py-1.5 rounded-lg"
                        style={on
                          ? { background: 'color-mix(in srgb, var(--color-support-500) 16%, transparent)', color: 'var(--color-support-500)', border: '1px solid var(--color-support-500)' }
                          : { background: 'var(--bg-card)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
                        {on && <Check size={12} />} {a.name}
                      </button>
                    )
                  })}
                </div>
              )}
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div><label style={LBL}>Requester name</label><input style={inp} value={form.requester_name} onChange={e => set('requester_name', e.target.value)} /></div>
            <div><label style={LBL}>Requester email</label><input style={inp} value={form.requester_email} onChange={e => set('requester_email', e.target.value)} /></div>
          </div>
          {create.isError && <div className="flex items-center gap-2 p-3 rounded-xl text-sm" style={{ background: 'rgba(239,68,68,0.08)', color: 'var(--color-danger-500)', border: '1px solid rgba(239,68,68,0.2)' }}><AlertCircle size={14} />{create.error?.message}</div>}
          <div className="flex items-center justify-end gap-2 pt-1">
            <button onClick={onClose} className="px-4 py-2 rounded-xl text-sm font-semibold hover:opacity-80" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
            <button disabled={!form.subject.trim() || create.isPending} onClick={() => create.mutate()} className="px-5 py-2 rounded-xl text-sm font-bold disabled:opacity-50" style={{ background: `linear-gradient(135deg,var(--color-support-400),var(--color-support-600))`, color: '#fff' }}>{create.isPending ? 'Creating…' : 'Create Ticket'}</button>
          </div>
        </div>
      </div>
    </div>
  )
}
