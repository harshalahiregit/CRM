import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import { Paperclip, Send, X, ListTodo, FolderKanban, GitMerge, Plus, Trash2, Sparkles, RefreshCw, ArrowLeft } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import { useAuth } from '@/context/AuthContext'
import TicketIntelligencePanel from './TicketIntelligencePanel'

const fmtTime = ts =>
  ts ? new Date(ts).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

/* Light Freshdesk-style tokens (shared with the inbox + intelligence panel). */
const CARD = { background: '#fff', border: '1px solid #e7eaf2', borderRadius: 16, boxShadow: '0 1px 2px rgba(20,30,60,0.04)' }
const INPUT = { border: '1px solid #dfe4ee', color: '#16233d', background: '#fff' }
const MUTED = '#7a879e'

export default function TicketThread() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const queryClient = useQueryClient()

  const [message, setMessage] = useState('')
  const [files, setFiles] = useState([])
  const [cc, setCc] = useState('')
  const [tasksOpen, setTasksOpen] = useState(false)
  const emptyRow = { name: '', priority: 'medium', assigned_to: '', due_date: '' }
  const [taskRows, setTaskRows] = useState([{ ...emptyRow }])

  const createTasks = useMutation({
    mutationFn: () => {
      const clean = taskRows
        .filter(r => r.name.trim())
        .map(r => ({ name: r.name.trim(), priority: r.priority, assigned_to: r.assigned_to ? Number(r.assigned_to) : null, due_date: r.due_date || null }))
      return helpdeskApi.tickets.createTasks(id, clean)
    },
    onSuccess: () => { setTasksOpen(false); setTaskRows([{ ...emptyRow }]); navigate(`/app/tasks?rel_type=ticket&rel_id=${id}`) },
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

  // A merged ticket redirects to the surviving one if opened directly (Phase 3).
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

  // AI summary (Phase 6): generated once + cached server-side; Refresh regenerates.
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
      fd.append('sender_type', 'admin')          // staff view posts as admin
      if (user?.id) fd.append('sender_id', user.id)
      cc.split(',').map(e => e.trim()).filter(Boolean).forEach(email => fd.append('cc[]', email))
      files.forEach(f => fd.append('attachments[]', f))
      return helpdeskApi.tickets.reply(id, fd)
    },
    onSuccess: () => {
      setMessage('')
      setFiles([])
      setCc('')
      // Refresh both the thread and the ticket (status may have auto-changed).
      queryClient.invalidateQueries({ queryKey: ['helpdesk-ticket-replies', id] })
      queryClient.invalidateQueries({ queryKey: ['helpdesk-ticket', id] })
    },
  })

  const submit = (e) => {
    e.preventDefault()
    if (!message.trim() || postReply.isPending) return
    postReply.mutate()
  }

  const actionBtn = (bg, color) => ({ background: bg, color })

  return (
    <div className="-m-4 md:-m-6" style={{ background: '#f4f6fb', minHeight: 'calc(100vh - 120px)', color: '#16233d' }}>
      <div className="max-w-6xl mx-auto px-4 md:px-6 py-5">

        {/* Back to inbox */}
        <button onClick={() => navigate('/app/helpdesk/tickets')}
          className="inline-flex items-center gap-1.5 text-xs font-semibold mb-3" style={{ color: '#3b6fed' }}>
          <ArrowLeft size={14} /> All tickets
        </button>

        <header className="mb-5 flex items-start justify-between gap-3 flex-wrap">
          <div className="min-w-0">
            <h1 className="text-xl font-bold" style={{ color: '#16233d', letterSpacing: '-0.01em' }}>
              {ticket ? ticket.subject : 'Ticket Conversation'}
            </h1>
            {ticket && <p className="text-xs mt-1 capitalize" style={{ color: MUTED }}>
              Ticket #{ticket.id} · {ticket.status}{ticket.project_id ? ` · project #${ticket.project_id}` : ''}
            </p>}
          </div>
          {ticket && (
            <div className="flex items-center gap-2 shrink-0">
              <button onClick={() => setTasksOpen(true)}
                className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg" style={actionBtn('#fdeef6', '#db2777')}>
                <ListTodo size={13} /> Convert to tasks
              </button>
              <button onClick={() => { const p = window.prompt('Link to project id (blank to unlink):'); if (p !== null) linkProject.mutate(p.trim() ? Number(p.trim()) : null) }}
                className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg" style={actionBtn('#eef4ff', '#3b6fed')}>
                <FolderKanban size={13} /> Link project
              </button>
              <button onClick={() => { const m = window.prompt('Merge which ticket id INTO this one? (that ticket becomes "merged")'); if (m?.trim()) merge.mutate(Number(m.trim())) }}
                className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg" style={actionBtn('#f1f3f8', '#5a6b8c')}>
                <GitMerge size={13} /> Merge
              </button>
            </div>
          )}
        </header>

        <div className="flex flex-col lg:flex-row gap-5 items-start">
          <div className="flex-1 min-w-0 w-full">

            {/* AI summary (Phase 6) — shown above the thread */}
            {ticket && summary && (
              <div className="mb-4 rounded-2xl p-3.5" style={{ border: '1px solid #e6d9fb', background: '#f7f2fe' }}>
                <div className="flex items-center gap-1.5 mb-1.5">
                  <Sparkles size={14} style={{ color: '#7c3aed' }} />
                  <span className="text-xs font-bold" style={{ color: '#7c3aed' }}>AI Summary</span>
                  {summary.has_provider === false && (
                    <span className="text-[9px] px-1.5 py-0.5 rounded" style={{ background: '#fdf2e0', color: '#d97706' }}>placeholder · no LLM key</span>
                  )}
                  <button onClick={() => refreshSummary.mutate()} disabled={refreshSummary.isPending} title="Refresh summary"
                    className="ml-auto flex items-center gap-1 text-[10px] font-semibold disabled:opacity-40" style={{ color: '#7c3aed' }}>
                    <RefreshCw size={11} className={refreshSummary.isPending ? 'animate-spin' : ''} /> Refresh
                  </button>
                </div>
                <p className="text-xs leading-relaxed" style={{ color: '#38455f' }}>{summary.ai_summary}</p>
              </div>
            )}

            {isError && (
              <div className="p-6 rounded-2xl" style={{ border: '1px solid #f4c7c7', background: '#fdeeee' }}>
                <p className="font-semibold" style={{ color: '#dc2626' }}>Couldn’t load the conversation</p>
                <p className="text-sm mt-1" style={{ color: MUTED }}>{error?.message}</p>
              </div>
            )}

            {isLoading && (
              <div className="space-y-4 max-w-3xl mx-auto">
                {[1, 2, 3].map(i => <div key={i} className="h-16 rounded-2xl animate-pulse" style={{ background: '#e9edf5' }} />)}
              </div>
            )}

            {!isLoading && !isError && (
              <div className="max-w-3xl mx-auto">
                <div className="flex flex-col gap-4">
                  {replies.length === 0 && (
                    <p className="text-center text-sm py-8" style={{ color: MUTED }}>No replies yet. Start the conversation below.</p>
                  )}

                  {/* THE LOOP: one bubble per reply — client left, admin/agent right */}
                  {replies.map(msg => {
                    const isStaff = msg.sender_type !== 'client'
                    return (
                      <div key={msg.id} className={clsx('flex flex-col max-w-[75%]', isStaff ? 'self-end items-end' : 'self-start items-start')}>
                        <span className="text-[11px] mb-1 px-1 capitalize" style={{ color: '#9aa4ba' }}>
                          {msg.sender_type} · {fmtTime(msg.created_at)}
                        </span>

                        <div
                          className={clsx('rounded-2xl px-4 py-2.5 text-sm leading-relaxed', isStaff ? 'rounded-br-sm' : 'rounded-bl-sm')}
                          style={isStaff
                            ? { background: '#eef4ff', color: '#16233d', border: '1px solid #d9e4ff' }
                            : { background: '#fff', color: '#16233d', border: '1px solid #e7eaf2' }}
                        >
                          <p>{msg.message}</p>

                          {/* NESTED LOOP: attachment links (authenticated download) */}
                          {msg.has_attachments && msg.attachments?.length > 0 && (
                            <ul className="mt-2 pt-2 space-y-1" style={{ borderTop: '1px solid rgba(20,30,60,0.08)' }}>
                              {msg.attachments.map(file => (
                                <li key={file.id}>
                                  <button
                                    type="button"
                                    onClick={() => helpdeskApi.tickets.downloadAttachment(id, file.id, file.file_name)}
                                    className="flex items-center gap-1.5 text-xs hover:underline"
                                    style={{ color: '#3b6fed' }}
                                  >
                                    <Paperclip size={12} className="shrink-0" />
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

                {/* ── Reply composer ─────────────────────────────────── */}
                <form onSubmit={submit} className="mt-6 rounded-2xl p-3" style={CARD}>
                  <textarea
                    value={message}
                    onChange={e => setMessage(e.target.value)}
                    rows={3}
                    placeholder="Write a reply…"
                    className="w-full bg-transparent resize-none outline-none text-sm px-2 py-1"
                    style={{ color: '#16233d' }}
                  />

                  {/* CC field (comma-separated emails) */}
                  <input
                    value={cc}
                    onChange={e => setCc(e.target.value)}
                    placeholder="Cc: comma-separated emails (optional)"
                    className="w-full bg-transparent outline-none text-xs px-2 py-1.5"
                    style={{ color: '#16233d', borderTop: '1px solid #eef1f7' }}
                  />

                  {/* Selected files chips */}
                  {files.length > 0 && (
                    <div className="flex flex-wrap gap-2 px-2 py-2">
                      {files.map((f, i) => (
                        <span key={i} className="flex items-center gap-1 text-[11px] px-2 py-1 rounded-lg" style={{ background: '#eef4ff', color: '#3b6fed' }}>
                          <Paperclip size={11} />{f.name}
                          <button type="button" onClick={() => setFiles(files.filter((_, j) => j !== i))} className="hover:opacity-70"><X size={11} /></button>
                        </span>
                      ))}
                    </div>
                  )}

                  <div className="flex items-center justify-between mt-2 px-1">
                    <label className="flex items-center gap-1.5 text-xs cursor-pointer" style={{ color: MUTED }}>
                      <Paperclip size={14} />
                      Attach
                      <input
                        type="file"
                        multiple
                        className="hidden"
                        onChange={e => setFiles(prev => [...prev, ...Array.from(e.target.files)])}
                      />
                    </label>

                    <button
                      type="submit"
                      disabled={!message.trim() || postReply.isPending}
                      className="flex items-center gap-1.5 text-xs font-semibold px-4 py-2 rounded-xl transition-opacity disabled:opacity-40"
                      style={{ background: '#3b6fed', color: '#fff' }}
                    >
                      <Send size={13} />
                      {postReply.isPending ? 'Sending…' : 'Send reply'}
                    </button>
                  </div>

                  {postReply.isError && (
                    <p className="text-xs mt-2 px-1" style={{ color: '#dc2626' }}>{postReply.error?.message}</p>
                  )}
                </form>
              </div>
            )}
          </div>

          {ticket && (
            <div className="w-full lg:w-80 shrink-0">
              <TicketIntelligencePanel ticketId={id} />
            </div>
          )}
        </div>
      </div>

      {/* Convert-to-tasks modal (Phase 4): one ticket → many tasks */}
      {tasksOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: 'rgba(20,30,60,0.45)' }} onClick={() => setTasksOpen(false)}>
          <div className="w-full max-w-2xl rounded-2xl p-5 max-h-[85vh] overflow-y-auto" style={{ background: '#fff', border: '1px solid #e7eaf2', boxShadow: '0 20px 50px rgba(20,30,60,0.25)' }} onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-1">
              <h2 className="font-bold flex items-center gap-2" style={{ color: '#16233d' }}><ListTodo size={16} style={{ color: '#db2777' }} /> Convert ticket #{id} to tasks</h2>
              <button onClick={() => setTasksOpen(false)}><X size={18} style={{ color: MUTED }} /></button>
            </div>
            <p className="text-xs mb-4" style={{ color: MUTED }}>Break this ticket into separate pieces of work. Each becomes a task linked back to the ticket.</p>

            <div className="space-y-2 mb-3">
              {taskRows.map((row, i) => (
                <div key={i} className="flex items-center gap-2">
                  <input value={row.name} onChange={e => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, name: e.target.value } : r))} placeholder={`Task ${i + 1} name`}
                    className="flex-1 text-sm rounded-lg px-2.5 py-2 outline-none" style={INPUT} />
                  <select value={row.priority} onChange={e => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, priority: e.target.value } : r))}
                    className="text-xs rounded-lg px-2 py-2 outline-none" style={INPUT}>
                    {['low', 'medium', 'high', 'urgent'].map(p => <option key={p} value={p}>{p}</option>)}
                  </select>
                  <input value={row.assigned_to} onChange={e => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, assigned_to: e.target.value.replace(/\D/g, '') } : r))} placeholder="user id"
                    className="w-20 text-xs rounded-lg px-2 py-2 outline-none" style={INPUT} />
                  <input type="date" value={row.due_date} onChange={e => setTaskRows(rows => rows.map((r, j) => j === i ? { ...r, due_date: e.target.value } : r))}
                    className="text-xs rounded-lg px-2 py-2 outline-none" style={INPUT} />
                  <button onClick={() => setTaskRows(rows => rows.length > 1 ? rows.filter((_, j) => j !== i) : rows)} disabled={taskRows.length === 1}
                    className="disabled:opacity-20 hover:text-red-500" style={{ color: MUTED }}><Trash2 size={15} /></button>
                </div>
              ))}
            </div>

            <button onClick={() => setTaskRows(rows => [...rows, { ...emptyRow }])}
              className="flex items-center gap-1 text-xs font-semibold mb-4" style={{ color: '#db2777' }}><Plus size={13} /> Add another task</button>

            {createTasks.isError && <p className="text-xs mb-2" style={{ color: '#dc2626' }}>{createTasks.error?.message}</p>}
            <div className="flex justify-end gap-2">
              <button onClick={() => setTasksOpen(false)} className="text-xs font-semibold px-4 py-2 rounded-xl" style={{ color: '#5a6b8c', border: '1px solid #dfe4ee' }}>Cancel</button>
              <button disabled={!taskRows.some(r => r.name.trim()) || createTasks.isPending} onClick={() => createTasks.mutate()}
                className="text-xs font-semibold px-4 py-2 rounded-xl disabled:opacity-40" style={{ background: '#db2777', color: '#fff' }}>
                {createTasks.isPending ? 'Creating…' : `Create ${taskRows.filter(r => r.name.trim()).length || ''} task(s)`}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
