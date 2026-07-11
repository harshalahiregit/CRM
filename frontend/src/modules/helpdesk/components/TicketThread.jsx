import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import {
  Paperclip, Send, X, ListTodo, FolderKanban, GitMerge,
  Plus, Trash2, Sparkles, RefreshCw, ArrowLeft, AlertCircle,
  MessageSquare, Activity
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import { useAuth } from '@/context/AuthContext'
import TicketIntelligencePanel from './TicketIntelligencePanel'
import TicketTimeline from './TicketTimeline'
import KnowledgeSuggestions from './KnowledgeSuggestions'

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
  const emptyRow = { name: '', priority: 'medium', assigned_to: '', due_date: '' }
  const [taskRows, setTaskRows] = useState([{ ...emptyRow }])

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
      setTaskRows([{ ...emptyRow }])
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

  const { data: summary } = useQuery({
    queryKey: ['helpdesk-ticket-summary', id],
    queryFn: () => helpdeskApi.tickets.summarize(id),
    enabled: !!id,
  })
  const refreshSummary = useMutation({
    mutationFn: () => helpdeskApi.tickets.summarize(id, true),
    onSuccess: (d) => queryClient.setQueryData(['helpdesk-ticket-summary', id], d),
  })

  const postReply = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      fd.append('message', message)
      fd.append('sender_type', 'admin')
      if (user?.id) fd.append('sender_id', user.id)
      cc.split(',').map(e => e.trim()).filter(Boolean).forEach(email => fd.append('cc[]', email))
      files.forEach(f => fd.append('attachments[]', f))
      return helpdeskApi.tickets.reply(id, fd)
    },
    onSuccess: () => {
      setMessage('')
      setFiles([])
      setCc('')
      queryClient.invalidateQueries({ queryKey: ['helpdesk-ticket-replies', id] })
      queryClient.invalidateQueries({ queryKey: ['helpdesk-ticket', id] })
    },
  })

  const submit = (e) => {
    e.preventDefault()
    if (!message.trim() || postReply.isPending) return
    postReply.mutate()
  }

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
        </div>

        {/* Action buttons */}
        {ticket && (
          <div className="flex items-center gap-2 shrink-0 flex-wrap">
            <ActionBtn
              icon={ListTodo}
              label="Convert to tasks"
              color="#db2777"
              bg="rgba(219,39,119,0.1)"
              border="rgba(219,39,119,0.2)"
              onClick={() => setTasksOpen(true)}
            />
            <ActionBtn
              icon={FolderKanban}
              label="Link project"
              color="#22d3ee"
              bg="rgba(34,211,238,0.1)"
              border="rgba(34,211,238,0.2)"
              onClick={() => {
                const p = window.prompt('Link to project id (blank to unlink):')
                if (p !== null) linkProject.mutate(p.trim() ? Number(p.trim()) : null)
              }}
            />
            <ActionBtn
              icon={GitMerge}
              label="Merge"
              color="var(--text-muted)"
              bg="var(--bg-input)"
              border="var(--border)"
              onClick={() => {
                const m = window.prompt('Merge which ticket id INTO this one?')
                if (m?.trim()) merge.mutate(Number(m.trim()))
              }}
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
              {replies.length === 0 && (
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
                {replies.map(msg => {
                  const isStaff = msg.sender_type !== 'client'
                  return (
                    <div
                      key={msg.id}
                      className={clsx('flex flex-col max-w-[78%]', isStaff ? 'self-end items-end' : 'self-start items-start')}
                    >
                      {/* Sender info */}
                      <div className="flex items-center gap-1.5 mb-1 px-1">
                        {!isStaff && (
                          <div
                            className="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold"
                            style={{ background: 'rgba(34,211,238,0.15)', color: '#22d3ee' }}
                          >
                            {initials(msg.sender?.name || 'C')}
                          </div>
                        )}
                        <span className="text-[11px] capitalize" style={{ color: 'var(--text-muted)' }}>
                          {msg.sender_type} · {fmtTime(msg.created_at)}
                        </span>
                        {isStaff && (
                          <div
                            className="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold"
                            style={{ background: 'linear-gradient(135deg,#22d3ee,#0891b2)', color: '#fff' }}
                          >
                            {initials(msg.sender?.name || 'A')}
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

              {/* Reply composer */}
              <form
                onSubmit={submit}
                className="rounded-2xl overflow-hidden"
                style={{
                  background: 'var(--bg-card)',
                  border: '1px solid var(--border)',
                  boxShadow: 'var(--shadow-card)',
                }}
              >
                <textarea
                  value={message}
                  onChange={e => setMessage(e.target.value)}
                  rows={4}
                  placeholder="Write a reply…"
                  className="w-full bg-transparent resize-none outline-none text-sm px-4 py-3.5"
                  style={{ color: 'var(--text-h)' }}
                />

                {/* CC field */}
                <div style={{ borderTop: '1px solid var(--border)' }}>
                  <input
                    value={cc}
                    onChange={e => setCc(e.target.value)}
                    placeholder="Cc: comma-separated emails (optional)"
                    className="w-full bg-transparent outline-none text-xs px-4 py-2.5"
                    style={{ color: 'var(--text-muted)' }}
                  />
                </div>

                {/* File chips */}
                {files.length > 0 && (
                  <div
                    className="flex flex-wrap gap-2 px-4 py-2"
                    style={{ borderTop: '1px solid var(--border)' }}
                  >
                    {files.map((f, i) => (
                      <span
                        key={i}
                        className="flex items-center gap-1.5 text-[11px] px-2.5 py-1 rounded-lg font-semibold"
                        style={{ background: 'rgba(34,211,238,0.1)', color: '#22d3ee' }}
                      >
                        <Paperclip size={10} />
                        {f.name}
                        <button
                          type="button"
                          onClick={() => setFiles(files.filter((_, j) => j !== i))}
                          className="hover:opacity-60"
                        >
                          <X size={10} />
                        </button>
                      </span>
                    ))}
                  </div>
                )}

                {/* Toolbar */}
                <div
                  className="flex items-center justify-between px-4 py-3"
                  style={{ borderTop: '1px solid var(--border)', background: 'var(--bg-input)' }}
                >
                  <label
                    className="flex items-center gap-1.5 text-xs font-semibold cursor-pointer hover:opacity-70 transition-opacity"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    <Paperclip size={14} />
                    Attach
                    <input
                      type="file"
                      multiple
                      className="hidden"
                      onChange={e => setFiles(prev => [...prev, ...Array.from(e.target.files)])}
                    />
                  </label>

                  <div className="flex items-center gap-2">
                    {postReply.isError && (
                      <span className="text-xs" style={{ color: '#ef4444' }}>
                        {postReply.error?.message}
                      </span>
                    )}
                    <button
                      type="submit"
                      disabled={!message.trim() || postReply.isPending}
                      className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl transition-all duration-200 disabled:opacity-40"
                      style={{
                        background: 'linear-gradient(135deg,#22d3ee,#0891b2)',
                        color: '#fff',
                        boxShadow: '0 3px 10px rgba(6,182,212,0.35)',
                      }}
                    >
                      <Send size={12} />
                      {postReply.isPending ? 'Sending…' : 'Send Reply'}
                    </button>
                  </div>
                </div>
              </form>
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
                  <select
                    value={row.priority}
                    onChange={e => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, priority: e.target.value } : r))}
                    className="text-xs rounded-xl px-2 py-2 outline-none"
                    style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}
                  >
                    {['low', 'medium', 'high', 'urgent'].map(p => <option key={p} value={p}>{p}</option>)}
                  </select>
                  <input
                    value={row.assigned_to}
                    onChange={e => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, assigned_to: e.target.value.replace(/\D/g, '') } : r))}
                    placeholder="user id"
                    className="w-20 text-xs rounded-xl px-2 py-2 outline-none"
                    style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}
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
              onClick={() => setTaskRows(rows => [...rows, { ...emptyRow }])}
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
