import { useState, useEffect, useRef, useMemo, useCallback } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import {
  Paperclip, Send, X, ListTodo, FolderKanban, GitMerge,
  Plus, Trash2, Sparkles, RefreshCw, ArrowLeft, AlertCircle,
  MessageSquare, Activity, StickyNote, CheckCircle2, User,
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import { useAuth } from '@/context/AuthContext'
import TicketIntelligencePanel from './TicketIntelligencePanel'
import TicketTimeline from './TicketTimeline'
import KnowledgeSuggestions from './KnowledgeSuggestions'
import CannedResponsePicker from './CannedResponsePicker'
import Select from './ui/Select'
import SearchPicker from './ui/SearchPicker'
import { projectApi } from '@/services/projectApi'

const PRIORITY_OPTS = [
  { value: 'low', label: 'Low', dot: 'var(--color-info-500)' },
  { value: 'medium', label: 'Medium', dot: 'var(--color-warning-500)' },
  { value: 'high', label: 'High', dot: '#f97316' },
  { value: 'urgent', label: 'Urgent', dot: 'var(--color-danger-500)' },
]

// Shape of one convert-to-tasks row. Real defaults are layered on top of this by
// makeRow() inside the component, which needs the loaded ticket to do its job.
const BASE_ROW = { name: '', priority: 'medium', assigned_to: '', due_date: '' }

const fmtTime = ts =>
  ts
    ? new Date(ts).toLocaleString('en-IN', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
      })
    : ''

const initials = (name) => {
  if (!name) return '?'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
// Split a comma-separated Cc string into { valid, invalid } email lists. Keeps a
// stray/autofilled value from 422-ing the whole reply — only valid addresses are
// sent, and invalid ones are surfaced instead of silently swallowing the send.
const parseCc = (raw) => {
  const parts = (raw || '').split(',').map(e => e.trim()).filter(Boolean)
  return {
    valid: parts.filter(e => EMAIL_RE.test(e)),
    invalid: parts.filter(e => !EMAIL_RE.test(e)),
  }
}

/**
 * Who raised this ticket. Widget/public tickets carry free-text
 * requester_name/requester_email; agent-raised ones link a customer, decorated
 * onto the payload as `customer: { name, email, … }`. Precedence mirrors the
 * backend's recipient resolution so this shows the address that is really mailed.
 */
const requesterOf = (ticket) => ({
  name: ticket?.requester_name || ticket?.customer?.name || null,
  email: ticket?.requester_email || ticket?.customer?.email || null,
})

/**
 * `due_date` is a Laravel datetime cast, so it arrives as an ISO string
 * ("2026-07-20T00:00:00.000000Z"). <input type="date"> needs a bare YYYY-MM-DD.
 * Slice the ISO prefix rather than round-tripping through `new Date(…)`, whose
 * toISOString() would re-interpret the local-midnight value and can land a day off.
 */
const toDateInput = (v) => {
  if (!v) return ''
  if (typeof v === 'string') {
    const m = v.match(/^(\d{4}-\d{2}-\d{2})/)
    if (m) return m[1]
  }
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return ''
  const pad = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const STATUS_COLOR = {
  open:          { color: '#3b82f6', bg: 'rgba(59,130,246,0.12)', border: 'rgba(59,130,246,0.25)' },
  'in-progress': { color: '#f59e0b', bg: 'rgba(245,158,11,0.12)', border: 'rgba(245,158,11,0.25)' },
  closed:        { color: '#10b981', bg: 'rgba(16,185,129,0.12)', border: 'rgba(16,185,129,0.25)' },
}

export default function TicketThread() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const queryClient = useQueryClient()

  const [message, setMessage] = useState('')
  const [files, setFiles] = useState([])
  const [cc, setCc] = useState('')
  const [tasksOpen, setTasksOpen] = useState(false)
  const [tab, setTab] = useState('conversation') // 'conversation' | 'activity'
  const [composerMode, setComposerMode] = useState('reply') // 'reply' | 'note'
  // Searchable pop-ups — these replace window.prompt('…enter an id').
  const [projectPickerOpen, setProjectPickerOpen] = useState(false)
  const [mergePickerOpen, setMergePickerOpen] = useState(false)
  const textareaRef = useRef(null)

  // Resolve status name (prefer a configured "resolved"/"closed" status).
  const { data: settings } = useQuery({ queryKey: ['helpdesk-settings'], queryFn: helpdeskApi.settings.all })

  // Agents power both the convert-to-tasks modal and the header Assignee picker,
  // so fetch them up front rather than only when a modal opens.
  const { data: agents = [] } = useQuery({
    queryKey: ['helpdesk-agents'], queryFn: helpdeskApi.agents,
  })
  const { data: projectList = [], isLoading: projectsLoading } = useQuery({
    queryKey: ['projects-picker'], queryFn: () => projectApi.list(), enabled: projectPickerOpen,
  })
  const { data: allTickets = [], isLoading: ticketsLoading } = useQuery({
    queryKey: ['tickets-picker'], queryFn: () => helpdeskApi.tickets.list(), enabled: mergePickerOpen,
  })
  const resolveStatus = (settings?.statuses || []).find(s => /resolved|closed/i.test(s.name))?.name || 'closed'
  // Is resolveStatus backed by a real configured status yet? Until the settings
  // query resolves, resolveStatus is the hardcoded 'closed' guess — which may not
  // be a status this tenant actually has. Firing the `e` shortcut in that window
  // POSTs a value the API rejects with a 422 that the optimistic mutation swallows
  // silently. Gate the shortcut on this so it no-ops until a valid target exists.
  const canResolve = (settings?.statuses || []).some(s => s.name === resolveStatus)
  const [taskRows, setTaskRows] = useState([{ ...BASE_ROW }])

  const createTasks = useMutation({
    mutationFn: () => {
      const clean = taskRows
        .filter(r => r.name.trim())
        .map(r => ({
          name: r.name.trim(),
          priority: r.priority,
          assigned_to: r.assigned_to ? Number(r.assigned_to) : null,
          due_date: r.due_date || null,
        }))
      return helpdeskApi.tickets.createTasks(id, clean)
    },
    onSuccess: () => {
      setTasksOpen(false)
      setTaskRows([makeRow()])
      navigate(`/app/tasks?rel_type=ticket&rel_id=${id}`)
    },
  })
  const linkProject = useMutation({
    mutationFn: (pid) => helpdeskApi.tickets.linkProject(id, pid),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['helpdesk-ticket', id] }),
  })
  const merge = useMutation({
    mutationFn: (mergeId) => helpdeskApi.tickets.merge(id, mergeId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['helpdesk-ticket-replies', id] })
      queryClient.invalidateQueries({ queryKey: ['helpdesk-ticket', id] })
    },
  })

  const { data: ticket } = useQuery({
    queryKey: ['helpdesk-ticket', id],
    queryFn: () => helpdeskApi.tickets.get(id),
    enabled: !!id,
  })

  // A converted task inherits the ticket's own deadline, priority and assignee —
  // an agent breaking a ticket into tasks is scheduling against the ticket's date,
  // not against nothing. Every field stays overridable per row.
  //
  // This has to be a factory rather than a plain `emptyRow` object: rows are seeded
  // when the modal OPENS, and the ticket is fetched asynchronously below, so an
  // object built during the first render would capture `ticket === undefined` and
  // bake blank defaults in forever. Reading it lazily at open/add time is what
  // makes the defaults actually appear.
  const makeRow = useCallback(() => ({
    ...BASE_ROW,
    // Tenants can define custom priority names, but the task form only accepts
    // this fixed set — fall back rather than feeding Select an unknown value.
    priority: PRIORITY_OPTS.some(p => p.value === ticket?.priority) ? ticket.priority : BASE_ROW.priority,
    assigned_to: ticket?.assigned_to ?? '',
    due_date: toDateInput(ticket?.due_date),
  }), [ticket])

  // Seed the rows from the ticket at open time (the button only renders once the
  // ticket is loaded, so `makeRow` always sees real data here).
  const openTaskModal = () => {
    setTaskRows([makeRow()])
    setTasksOpen(true)
  }

  useEffect(() => {
    if (ticket?.merged_into_id && String(ticket.merged_into_id) !== String(id)) {
      navigate(`/app/helpdesk/tickets/${ticket.merged_into_id}`, { replace: true })
    }
  }, [ticket?.merged_into_id, id, navigate])

  const { data: replies = [], isLoading, isError, error } = useQuery({
    queryKey: ['helpdesk-ticket-replies', id],
    queryFn: () => helpdeskApi.tickets.replies(id),
    enabled: !!id,
  })

  // The ticket's own description is the customer's opening message — every real
  // helpdesk shows it as the first bubble. Replies alone made new tickets look
  // empty ("No replies yet") even though the customer had written in.
  const thread = useMemo(() => {
    const items = []
    if (ticket?.description?.trim()) {
      items.push({
        id: `ticket-${id}-original`,
        message: ticket.description,
        sender_type: 'client',
        sender: { name: ticket.customer?.name || ticket.requester_name || 'Customer' },
        created_at: ticket.created_at,
        _original: true,
      })
    }
    return [...items, ...(Array.isArray(replies) ? replies : [])]
  }, [ticket, replies, id])

  // summarize() is a POST that (re)generates the AI summary server-side, but the
  // server caches it and returns the cached copy unless refresh=true. Load it
  // exactly ONCE per ticket: staleTime Infinity stops react-query from refetching
  // on window focus / remount, so we don't re-POST — and, once a real LLM key is
  // wired, don't burn quota — every time the tab regains focus. Regeneration is an
  // explicit user action via the Refresh button (refreshSummary, refresh=true).
  // retry:false keeps a failed summary from hammering the endpoint.
  const { data: summary } = useQuery({
    queryKey: ['helpdesk-ticket-summary', id],
    queryFn: () => helpdeskApi.tickets.summarize(id),
    enabled: !!id,
    staleTime: Infinity,
    gcTime: Infinity,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchOnMount: false,
    retry: false,
  })
  const refreshSummary = useMutation({
    mutationFn: () => helpdeskApi.tickets.summarize(id, true),
    onSuccess: (d) => queryClient.setQueryData(['helpdesk-ticket-summary', id], d),
  })

  const repliesKey = ['helpdesk-ticket-replies', id]
  const notesKey = ['ticket-notes', id]
  const ticketKey = ['helpdesk-ticket', id]

  // Optimistic status change — reflected instantly in the header + panel.
  const setStatusMut = useMutation({
    mutationFn: (status) => helpdeskApi.tickets.setStatus(id, status),
    onMutate: async (status) => {
      await queryClient.cancelQueries({ queryKey: ticketKey })
      const prev = queryClient.getQueryData(ticketKey)
      queryClient.setQueryData(ticketKey, (o) => (o ? { ...o, status } : o))
      return { prev }
    },
    onError: (_e, _v, ctx) => ctx?.prev && queryClient.setQueryData(ticketKey, ctx.prev),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ticketKey }),
  })

  // Assign / reassign / unassign the ticket to an agent. Optimistic so the header
  // reflects the choice instantly; the ticket list is refreshed too.
  const assignMut = useMutation({
    mutationFn: (agentId) => helpdeskApi.tickets.assign(id, agentId),
    onMutate: async (agentId) => {
      await queryClient.cancelQueries({ queryKey: ticketKey })
      const prev = queryClient.getQueryData(ticketKey)
      const agent = agents.find(a => String(a.id) === String(agentId))
      queryClient.setQueryData(ticketKey, (o) => (o ? { ...o, assigned_to: agentId, assignee: agent || null } : o))
      return { prev }
    },
    onError: (_e, _v, ctx) => ctx?.prev && queryClient.setQueryData(ticketKey, ctx.prev),
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ticketKey })
      queryClient.invalidateQueries({ queryKey: ['helpdesk-tickets'] })
    },
  })

  // Optimistic reply — the bubble appears immediately; composer clears at once.
  // The composer text/files/cc are snapshotted at click-time and passed in as
  // mutation variables. onMutate clears the textarea and, because it is async,
  // React re-renders during its await — so reading `message` inside mutationFn
  // would see the already-cleared '' and send an empty message (422). Passing
  // the snapshot avoids that race entirely.
  const postReply = useMutation({
    mutationFn: async ({ resolve, text, ccVal, fileList } = {}) => {
      const fd = new FormData()
      fd.append('message', text)
      // sender_type/sender_id are derived from the auth token server-side — the
      // server ignores them if sent, so sending them would only be a lie we tell
      // ourselves. The optimistic bubble below mirrors the same rule locally.
      parseCc(ccVal).valid.forEach(email => fd.append('cc[]', email))
      ;(fileList || []).forEach(f => fd.append('attachments[]', f))
      const res = await helpdeskApi.tickets.reply(id, fd)
      if (resolve) await helpdeskApi.tickets.setStatus(id, resolveStatus)
      return res
    },
    onMutate: async ({ text } = {}) => {
      await queryClient.cancelQueries({ queryKey: repliesKey })
      const prev = queryClient.getQueryData(repliesKey)
      const optimistic = { id: `tmp-${Date.now()}`, message: text, sender_type: user?.role === 'admin' ? 'admin' : 'agent', sender: { name: user?.name }, created_at: new Date().toISOString(), _optimistic: true }
      queryClient.setQueryData(repliesKey, (old = []) => [...(Array.isArray(old) ? old : []), optimistic])
      setMessage(''); setFiles([]); setCc('')
      return { prev, sent: text }
    },
    onError: (_e, _v, ctx) => {
      if (ctx?.prev) queryClient.setQueryData(repliesKey, ctx.prev)
      if (ctx?.sent) setMessage(ctx.sent) // don't lose the agent's text
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: repliesKey })
      queryClient.invalidateQueries({ queryKey: ticketKey })
    },
  })

  // Optimistic private note — appears instantly in the panel + activity timeline.
  const addNote = useMutation({
    mutationFn: ({ text } = {}) => helpdeskApi.tickets.addNote(id, text),
    onMutate: async ({ text } = {}) => {
      await queryClient.cancelQueries({ queryKey: notesKey })
      const prev = queryClient.getQueryData(notesKey)
      const optimistic = { id: `tmp-${Date.now()}`, content: text, user: { name: user?.name }, created_at: new Date().toISOString(), _optimistic: true }
      queryClient.setQueryData(notesKey, (old = []) => [...(Array.isArray(old) ? old : []), optimistic])
      setMessage('')
      return { prev, sent: text }
    },
    onError: (_e, _v, ctx) => {
      if (ctx?.prev) queryClient.setQueryData(notesKey, ctx.prev)
      if (ctx?.sent) setMessage(ctx.sent)
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: notesKey }),
  })

  const busy = postReply.isPending || addNote.isPending
  const submit = (e, resolve = false) => {
    e?.preventDefault?.()
    const text = message.trim()
    if (!text || busy) return
    if (composerMode === 'note') addNote.mutate({ text })
    else postReply.mutate({ resolve, text, ccVal: cc, fileList: files })
  }

  // Keyboard shortcuts: r = reply, n = note, e = resolve (ignored while typing).
  useEffect(() => {
    const onKey = (e) => {
      if (e.metaKey || e.ctrlKey || e.altKey) return
      const el = document.activeElement
      const typing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable)
      if (typing) return
      if (e.key === 'r') { e.preventDefault(); setTab('conversation'); setComposerMode('reply'); setTimeout(() => textareaRef.current?.focus(), 0) }
      else if (e.key === 'n') { e.preventDefault(); setTab('conversation'); setComposerMode('note'); setTimeout(() => textareaRef.current?.focus(), 0) }
      else if (e.key === 'e' && ticket && canResolve) { e.preventDefault(); setStatusMut.mutate(resolveStatus) }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [ticket, resolveStatus, canResolve]) // eslint-disable-line react-hooks/exhaustive-deps

  const statusCfg = STATUS_COLOR[ticket?.status] || { color: '#64748b', bg: 'rgba(100,116,139,0.12)', border: 'rgba(100,116,139,0.2)' }

  return (
    <div>

      {/* ── Back link */}
      <button
        onClick={() => navigate('/app/helpdesk/tickets')}
        className="inline-flex items-center gap-1.5 text-xs font-semibold mb-4 transition-all duration-150 hover:opacity-70"
        style={{ color: '#22d3ee' }}
      >
        <ArrowLeft size={13} />
        All Tickets
      </button>

      {/* ── Ticket header */}
      <header className="mb-5 flex items-start justify-between gap-3 flex-wrap">
        <div className="min-w-0">
          <div className="flex items-center gap-2 flex-wrap mb-1">
            <span
              className="text-xs font-mono font-bold px-2 py-0.5 rounded-lg"
              style={{ background: 'rgba(34,211,238,0.12)', color: '#22d3ee' }}
            >
              #{id}
            </span>
            {ticket && (
              <span
                className="text-xs font-bold capitalize px-2 py-0.5 rounded-lg"
                style={{
                  color: statusCfg.color,
                  background: statusCfg.bg,
                  border: `1px solid ${statusCfg.border}`,
                }}
              >
                {String(ticket.status).replace('-', ' ')}
              </span>
            )}
            {ticket?.project_id && (
              <span
                className="text-xs font-semibold px-2 py-0.5 rounded-lg"
                style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}
              >
                Project #{ticket.project_id}
              </span>
            )}
          </div>
          <h1
            className="font-black"
            style={{
              fontSize: 'clamp(1.1rem,2vw,1.5rem)',
              color: 'var(--text-h)',
              letterSpacing: '-0.02em',
              lineHeight: 1.3,
            }}
          >
            {ticket ? ticket.subject : 'Ticket Conversation'}
          </h1>
          {ticket && (() => {
            const { name, email } = requesterOf(ticket)
            if (!name && !email) return null
            return (
              <p className="text-xs mt-1 truncate" style={{ color: 'var(--text-muted)' }}>
                From:{' '}
                <span className="font-semibold" style={{ color: 'var(--text-body)' }}>{name || 'Unknown'}</span>
                {email && (
                  <>
                    {' '}
                    <a href={`mailto:${email}`} className="hover:underline" style={{ color: '#22d3ee' }} title={`Email ${email}`}>
                      &lt;{email}&gt;
                    </a>
                  </>
                )}
              </p>
            )
          })()}
        </div>

        {/* Action buttons */}
        {ticket && (
          <div className="flex items-center gap-2 shrink-0 flex-wrap">
            {/* Assignee — pick any agent, or Unassigned. */}
            <div className="flex items-center gap-1.5 pr-1 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', paddingLeft: 8 }}>
              <User size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <Select
                value={ticket.assigned_to ?? ''}
                onChange={v => assignMut.mutate(v === '' ? null : Number(v))}
                options={[{ value: '', label: 'Unassigned' }, ...agents.map(a => ({ value: a.id, label: a.name }))]}
                placeholder="Assign…"
                size="sm"
                className="w-36"
                ariaLabel="Assign ticket to an agent"
              />
              {user?.id && ticket.assigned_to !== user.id && (
                <button
                  onClick={() => assignMut.mutate(user.id)}
                  className="text-[10px] font-bold px-2 py-1 rounded-lg whitespace-nowrap hover:opacity-80"
                  style={{ color: '#22d3ee' }}
                  title="Assign this ticket to me"
                >
                  to me
                </button>
              )}
            </div>
            <ActionBtn
              icon={ListTodo}
              label="Convert to tasks"
              color="#db2777"
              bg="rgba(219,39,119,0.1)"
              border="rgba(219,39,119,0.2)"
              onClick={openTaskModal}
            />
            <ActionBtn
              icon={FolderKanban}
              label="Link project"
              color="#22d3ee"
              bg="rgba(34,211,238,0.1)"
              border="rgba(34,211,238,0.2)"
              onClick={() => setProjectPickerOpen(true)}
            />
            <ActionBtn
              icon={GitMerge}
              label="Merge"
              color="var(--text-muted)"
              bg="var(--bg-input)"
              border="var(--border)"
              onClick={() => setMergePickerOpen(true)}
            />
          </div>
        )}
      </header>

      {/* ── Main layout: thread + right panel */}
      <div className="flex flex-col lg:flex-row gap-5 items-start">
        <div className="flex-1 min-w-0 w-full">

          {/* AI Summary */}
          {ticket && summary && (
            <div
              className="mb-4 rounded-2xl p-4"
              style={{
                border: '1px solid rgba(139,92,246,0.25)',
                background: 'rgba(139,92,246,0.06)',
                boxShadow: '0 2px 12px rgba(139,92,246,0.08)',
              }}
            >
              <div className="flex items-center gap-2 mb-2">
                <div
                  className="w-6 h-6 rounded-lg flex items-center justify-center"
                  style={{ background: 'rgba(139,92,246,0.2)' }}
                >
                  <Sparkles size={13} style={{ color: '#a78bfa' }} />
                </div>
                <span className="text-xs font-black" style={{ color: '#a78bfa' }}>AI Summary</span>
                {summary.has_provider === false && (
                  <span
                    className="text-[9px] px-1.5 py-0.5 rounded font-semibold"
                    style={{ background: 'rgba(245,158,11,0.15)', color: '#f59e0b' }}
                  >
                    placeholder · no LLM key
                  </span>
                )}
                <button
                  onClick={() => refreshSummary.mutate()}
                  disabled={refreshSummary.isPending}
                  className="ml-auto flex items-center gap-1 text-[10px] font-bold disabled:opacity-40 hover:opacity-70 transition-opacity"
                  style={{ color: '#a78bfa' }}
                >
                  <RefreshCw size={11} className={refreshSummary.isPending ? 'animate-spin' : ''} />
                  Refresh
                </button>
              </div>
              <p className="text-xs leading-relaxed" style={{ color: 'var(--text-body)' }}>
                {summary.ai_summary}
              </p>
            </div>
          )}

          {/* Error state */}
          {isError && (
            <div
              className="p-5 rounded-2xl flex items-start gap-3 mb-4"
              style={{ border: '1px solid rgba(239,68,68,0.25)', background: 'rgba(239,68,68,0.06)' }}
            >
              <AlertCircle size={16} style={{ color: '#ef4444', flexShrink: 0, marginTop: 1 }} />
              <div>
                <p className="font-semibold text-red-400">Couldn't load the conversation</p>
                <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>{error?.message}</p>
              </div>
            </div>
          )}

          {/* Loading skeletons */}
          {isLoading && (
            <div className="space-y-4">
              {[1, 2, 3].map(i => (
                <div
                  key={i}
                  className={`h-20 rounded-2xl animate-pulse ${i % 2 === 0 ? 'ml-12' : 'mr-12'}`}
                  style={{ background: 'var(--border)' }}
                />
              ))}
            </div>
          )}

          {/* Conversation thread */}
          {!isLoading && !isError && (
            <div>
              {/* Conversation / Activity tabs (SDS workspace pattern) */}
              <div className="flex items-center gap-1 mb-4">
                {[['conversation', 'Conversation', MessageSquare], ['activity', 'Activity', Activity]].map(([k, label, Icon]) => (
                  <button key={k} onClick={() => setTab(k)}
                    className="flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-lg transition-colors"
                    style={{ background: tab === k ? 'var(--bg-input)' : 'transparent', color: tab === k ? 'var(--color-support-500)' : 'var(--text-muted)', border: `1px solid ${tab === k ? 'var(--border)' : 'transparent'}` }}>
                    <Icon size={13} /> {label}
                  </button>
                ))}
              </div>

              {tab === 'activity' && (
                <div className="mb-5 rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
                  <TicketTimeline ticketId={id} replies={replies} />
                </div>
              )}

              {tab === 'conversation' && (<>
              {thread.length === 0 && (
                <div
                  className="flex flex-col items-center justify-center py-12 gap-3 rounded-2xl mb-4"
                  style={{ border: '1px dashed var(--border)', background: 'var(--bg-card)' }}
                >
                  <div
                    className="w-12 h-12 rounded-2xl flex items-center justify-center"
                    style={{ background: 'rgba(34,211,238,0.08)' }}
                  >
                    <Send size={20} style={{ color: '#22d3ee' }} />
                  </div>
                  <p className="text-sm font-medium" style={{ color: 'var(--text-muted)' }}>
                    No replies yet — start the conversation below
                  </p>
                </div>
              )}

              {/* Message bubbles */}
              <div className="flex flex-col gap-3 mb-5">
                {thread.map(msg => {
                  const isStaff = msg.sender_type !== 'client'
                  const senderName = msg.sender?.name || (isStaff ? 'Agent' : 'Customer')
                  return (
                    <div
                      key={msg.id}
                      className={clsx('flex flex-col max-w-[78%]', isStaff ? 'self-end items-end' : 'self-start items-start')}
                      style={{ opacity: msg._optimistic ? 0.55 : 1 }}
                    >
                      {/* Sender info */}
                      <div className="flex items-center gap-1.5 mb-1 px-1">
                        {!isStaff && (
                          <div
                            className="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold"
                            style={{ background: 'rgba(34,211,238,0.15)', color: '#22d3ee' }}
                          >
                            {initials(senderName)}
                          </div>
                        )}
                        <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                          <span className="font-semibold" style={{ color: 'var(--text-body)' }}>{senderName}</span> · {fmtTime(msg.created_at)}
                        </span>
                        {msg._original && (
                          <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background: 'rgba(34,211,238,0.12)', color: '#22d3ee' }}>
                            Original request
                          </span>
                        )}
                        {isStaff && (
                          <div
                            className="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold"
                            style={{ background: 'linear-gradient(135deg,#22d3ee,#0891b2)', color: '#fff' }}
                          >
                            {initials(senderName)}
                          </div>
                        )}
                      </div>

                      {/* Bubble */}
                      <div
                        className={clsx('rounded-2xl px-4 py-3 text-sm leading-relaxed', isStaff ? 'rounded-br-sm' : 'rounded-bl-sm')}
                        style={isStaff
                          ? {
                              background: 'rgba(34,211,238,0.1)',
                              color: 'var(--text-h)',
                              border: '1px solid rgba(34,211,238,0.2)',
                            }
                          : {
                              background: 'var(--bg-card)',
                              color: 'var(--text-body)',
                              border: '1px solid var(--border)',
                              boxShadow: 'var(--shadow-card)',
                            }
                        }
                      >
                        <p>{msg.message}</p>

                        {/* Attachments */}
                        {msg.has_attachments && msg.attachments?.length > 0 && (
                          <ul
                            className="mt-2 pt-2 space-y-1"
                            style={{ borderTop: '1px solid rgba(255,255,255,0.08)' }}
                          >
                            {msg.attachments.map(file => (
                              <li key={file.id}>
                                <button
                                  type="button"
                                  onClick={() => helpdeskApi.tickets.downloadAttachment(id, file.id, file.file_name)}
                                  className="flex items-center gap-1.5 text-xs hover:underline"
                                  style={{ color: '#22d3ee' }}
                                >
                                  <Paperclip size={11} className="shrink-0" />
                                  <span className="truncate">{file.file_name}</span>
                                </button>
                              </li>
                            ))}
                          </ul>
                        )}
                      </div>
                    </div>
                  )
                })}
              </div>
              </>)}

              {/* Knowledge suggestions (UX Book-4: suggest → insert into reply) */}
              <KnowledgeSuggestions ticket={ticket} onInsert={(txt) => { setTab('conversation'); setMessage(m => m + txt) }} />

              {/* Reply / Note composer */}
              <form
                onSubmit={submit}
                className="rounded-2xl overflow-hidden"
                style={{
                  background: composerMode === 'note' ? 'color-mix(in srgb, var(--color-warning-500) 7%, var(--bg-card))' : 'var(--bg-card)',
                  border: `1px solid ${composerMode === 'note' ? 'color-mix(in srgb, var(--color-warning-500) 32%, var(--border))' : 'var(--border)'}`,
                  boxShadow: 'var(--shadow-card)',
                }}
              >
                {/* Mode toggle */}
                <div className="flex items-center gap-1 px-3 pt-3">
                  {[['reply', 'Reply', Send, 'var(--color-support-500)'], ['note', 'Note', StickyNote, 'var(--color-warning-500)']].map(([k, label, Icon, c]) => (
                    <button key={k} type="button" onClick={() => setComposerMode(k)}
                      className="flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-lg transition-colors"
                      style={{ background: composerMode === k ? `color-mix(in srgb, ${c} 14%, transparent)` : 'transparent', color: composerMode === k ? c : 'var(--text-muted)' }}>
                      <Icon size={13} /> {label}
                    </button>
                  ))}
                  <span className="ml-auto text-[11px] pr-1" style={{ color: 'var(--text-muted)', opacity: 0.7 }}>
                    {composerMode === 'note' ? 'Visible to your team only' : 'Sent to the customer'}
                  </span>
                </div>

                <textarea
                  ref={textareaRef}
                  value={message}
                  onChange={e => setMessage(e.target.value)}
                  onKeyDown={e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) submit(e) }}
                  rows={4}
                  placeholder={composerMode === 'note' ? 'Add an internal note…' : 'Write a reply…'}
                  className="w-full bg-transparent resize-none outline-none text-sm px-4 py-3"
                  style={{ color: 'var(--text-h)' }}
                />

                {/* CC + attachments — reply mode only */}
                {composerMode === 'reply' && (
                  <>
                    <div style={{ borderTop: '1px solid var(--border)' }}>
                      <input value={cc} onChange={e => setCc(e.target.value)} placeholder="Cc: comma-separated emails (optional)"
                        type="text" name="ticket-cc" autoComplete="off" autoCorrect="off" spellCheck={false}
                        className="w-full bg-transparent outline-none text-xs px-4 py-2.5" style={{ color: 'var(--text-muted)' }} />
                      {parseCc(cc).invalid.length > 0 && (
                        <p className="text-[11px] px-4 pb-2 -mt-1" style={{ color: 'var(--color-warning-500)' }}>
                          Ignored invalid Cc: {parseCc(cc).invalid.join(', ')}
                        </p>
                      )}
                    </div>
                    {files.length > 0 && (
                      <div className="flex flex-wrap gap-2 px-4 py-2" style={{ borderTop: '1px solid var(--border)' }}>
                        {files.map((f, i) => (
                          <span key={i} className="flex items-center gap-1.5 text-[11px] px-2.5 py-1 rounded-lg font-semibold" style={{ background: 'rgba(34,211,238,0.1)', color: '#22d3ee' }}>
                            <Paperclip size={10} />{f.name}
                            <button type="button" onClick={() => setFiles(files.filter((_, j) => j !== i))} className="hover:opacity-60"><X size={10} /></button>
                          </span>
                        ))}
                      </div>
                    )}
                  </>
                )}

                {/* Toolbar */}
                <div className="flex items-center justify-between px-4 py-3" style={{ borderTop: '1px solid var(--border)', background: composerMode === 'note' ? 'transparent' : 'var(--bg-input)' }}>
                  <div className="flex items-center gap-4">
                    {composerMode === 'reply' ? (
                      <>
                        <label className="flex items-center gap-1.5 text-xs font-semibold cursor-pointer hover:opacity-70 transition-opacity" style={{ color: 'var(--text-muted)' }}>
                          <Paperclip size={14} /> Attach
                          <input type="file" multiple className="hidden" onChange={e => setFiles(prev => [...prev, ...Array.from(e.target.files)])} />
                        </label>
                        <CannedResponsePicker onInsert={txt => setMessage(m => m ? `${m}\n\n${txt}` : txt)} />
                      </>
                    ) : (
                      <span className="flex items-center gap-1.5 text-xs font-semibold" style={{ color: 'var(--color-warning-500)' }}><StickyNote size={13} /> Internal note</span>
                    )}
                  </div>

                  <div className="flex items-center gap-2">
                    {(postReply.isError || addNote.isError) && <span className="text-xs" style={{ color: '#ef4444' }}>{(postReply.error || addNote.error)?.message}</span>}
                    {composerMode === 'note' ? (
                      <button type="submit" disabled={!message.trim() || busy} className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40" style={{ background: 'var(--color-warning-500)', color: '#fff' }}>
                        <StickyNote size={12} /> {addNote.isPending ? 'Adding…' : 'Add note'}
                      </button>
                    ) : (
                      <>
                        <button type="button" onClick={e => submit(e, true)} disabled={!message.trim() || busy}
                          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ border: '1px solid var(--border)', color: 'var(--color-success-500)' }}>
                          <CheckCircle2 size={13} /> Send &amp; Resolve
                        </button>
                        <button type="submit" disabled={!message.trim() || busy} className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40" style={{ background: 'linear-gradient(135deg,#22d3ee,#0891b2)', color: '#fff', boxShadow: '0 3px 10px rgba(6,182,212,0.35)' }}>
                          <Send size={12} /> {postReply.isPending ? 'Sending…' : 'Send Reply'}
                        </button>
                      </>
                    )}
                  </div>
                </div>
              </form>

              {/* Keyboard hints */}
              <p className="mt-2 text-[11px] flex items-center gap-3 px-1" style={{ color: 'var(--text-muted)', opacity: 0.7 }}>
                <span><Kbd>R</Kbd> reply</span><span><Kbd>N</Kbd> note</span><span><Kbd>E</Kbd> resolve</span><span><Kbd>⌘/Ctrl</Kbd>+<Kbd>↵</Kbd> send</span>
              </p>
            </div>
          )}
        </div>

        {/* Right panel */}
        {ticket && (
          <div className="w-full lg:w-80 shrink-0">
            <TicketIntelligencePanel ticketId={id} />
          </div>
        )}
      </div>

      {/* Link project — pick by name instead of typing an id */}
      <SearchPicker
        open={projectPickerOpen}
        onClose={() => setProjectPickerOpen(false)}
        onPick={(p) => linkProject.mutate(p ? p.id : null)}
        items={(Array.isArray(projectList) ? projectList : []).map(p => ({
          id: p.id, label: p.name, sublabel: p.status ? String(p.status).replace('_', ' ') : undefined,
        }))}
        loading={projectsLoading}
        title="Link a project"
        subtitle={ticket?.project_id ? `Currently linked to project #${ticket.project_id}` : 'Attach this ticket to a project'}
        placeholder="Search projects by name…"
        emptyText="No projects found."
        allowClear={!!ticket?.project_id}
        clearLabel="Unlink project"
      />

      {/* Merge ticket — pick by subject instead of typing an id */}
      <SearchPicker
        open={mergePickerOpen}
        onClose={() => setMergePickerOpen(false)}
        onPick={(t) => t && merge.mutate(t.id)}
        items={(Array.isArray(allTickets) ? allTickets : [])
          .filter(t => String(t.id) !== String(id) && t.status !== 'merged')
          .map(t => ({ id: t.id, label: t.subject, sublabel: `${t.status} · ${t.priority}` }))}
        loading={ticketsLoading}
        title="Merge a ticket into this one"
        subtitle={`Its replies and notes move onto ticket #${id}`}
        placeholder="Search tickets by subject or #id…"
        emptyText="No other tickets to merge."
        accent="var(--color-warning-500)"
      />

      {/* Convert-to-tasks modal */}
      {tasksOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
          onClick={() => setTasksOpen(false)}
        >
          <div
            className="w-full max-w-2xl rounded-2xl p-6 max-h-[85vh] overflow-y-auto"
            style={{
              background: 'var(--bg-card)',
              border: '1px solid var(--border)',
              boxShadow: 'var(--shadow-card-3d)',
            }}
            onClick={e => e.stopPropagation()}
          >
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2">
                <div
                  className="w-8 h-8 rounded-xl flex items-center justify-center"
                  style={{ background: 'rgba(219,39,119,0.12)' }}
                >
                  <ListTodo size={15} style={{ color: '#db2777' }} />
                </div>
                <div>
                  <h2 className="font-bold" style={{ color: 'var(--text-h)' }}>
                    Convert Ticket #{id} to Tasks
                  </h2>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    Each row becomes a task linked back to this ticket
                  </p>
                  <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)', opacity: 0.8 }}>
                    {ticket?.due_date
                      ? <>Priority, assignee and due date (<strong style={{ color: 'var(--text-body)' }}>{toDateInput(ticket.due_date)}</strong>) default to the ticket's — override any row below.</>
                      : <>Priority and assignee default to the ticket's. This ticket has no due date, so rows start blank.</>}
                  </p>
                </div>
              </div>
              <button onClick={() => setTasksOpen(false)} className="hover:opacity-60">
                <X size={18} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            <div className="space-y-2 mb-3">
              {taskRows.map((row, i) => (
                <div key={i} className="flex items-center gap-2">
                  <input
                    value={row.name}
                    onChange={e => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, name: e.target.value } : r))}
                    placeholder={`Task ${i + 1} name`}
                    className="flex-1 text-sm rounded-xl px-3 py-2 outline-none"
                    style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}
                  />
                  <Select
                    value={row.priority}
                    onChange={v => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, priority: v } : r))}
                    options={PRIORITY_OPTS}
                    size="sm"
                    className="w-28 shrink-0"
                    ariaLabel={`Priority for task ${i + 1}`}
                  />
                  {/* Assignee by NAME — this used to be a bare "user id" box that
                      made agents go look the number up somewhere else. */}
                  <Select
                    value={row.assigned_to ?? ''}
                    onChange={v => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, assigned_to: v } : r))}
                    options={[{ value: '', label: 'Unassigned' }, ...agents.map(a => ({ value: a.id, label: a.name }))]}
                    placeholder="Unassigned"
                    size="sm"
                    className="w-36 shrink-0"
                    ariaLabel={`Assignee for task ${i + 1}`}
                  />
                  <input
                    type="date"
                    value={row.due_date}
                    onChange={e => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, due_date: e.target.value } : r))}
                    className="text-xs rounded-xl px-2 py-2 outline-none"
                    style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}
                  />
                  <button
                    onClick={() => setTaskRows(rows => rows.length > 1 ? rows.filter((_, j) => j !== i) : rows)}
                    disabled={taskRows.length === 1}
                    className="disabled:opacity-20 hover:text-red-400 transition-colors"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    <Trash2 size={14} />
                  </button>
                </div>
              ))}
            </div>

            <button
              onClick={() => setTaskRows(rows => [...rows, makeRow()])}
              className="flex items-center gap-1 text-xs font-bold mb-5 hover:opacity-70 transition-opacity"
              style={{ color: '#db2777' }}
            >
              <Plus size={13} />
              Add another task
            </button>

            {createTasks.isError && (
              <p className="text-xs mb-3" style={{ color: '#ef4444' }}>{createTasks.error?.message}</p>
            )}
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setTasksOpen(false)}
                className="text-xs font-semibold px-4 py-2 rounded-xl"
                style={{ color: 'var(--text-muted)', border: '1px solid var(--border)', background: 'transparent' }}
              >
                Cancel
              </button>
              <button
                disabled={!taskRows.some(r => r.name.trim()) || createTasks.isPending}
                onClick={() => createTasks.mutate()}
                className="text-xs font-bold px-5 py-2 rounded-xl disabled:opacity-40 transition-all duration-200"
                style={{ background: 'linear-gradient(135deg,#db2777,#9d174d)', color: '#fff', boxShadow: '0 4px 12px rgba(219,39,119,0.3)' }}
              >
                {createTasks.isPending ? 'Creating…' : `Create ${taskRows.filter(r => r.name.trim()).length || ''} task(s)`}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Keyboard hint chip ────────────────────────────────────── */
function Kbd({ children }) {
  return <kbd className="px-1.5 py-0.5 rounded font-mono text-[10px] font-bold" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>{children}</kbd>
}

/* ── Small helper: action button in header ─────────────────── */
function ActionBtn({ icon: Icon, label, color, bg, border, onClick }) {
  return (
    <button
      onClick={onClick}
      className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl transition-all duration-150"
      style={{
        background: bg,
        color,
        border: `1px solid ${border}`,
      }}
    >
      <Icon size={12} />
      {label}
    </button>
  )
}
