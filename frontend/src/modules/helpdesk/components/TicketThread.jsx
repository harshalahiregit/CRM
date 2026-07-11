import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import { Paperclip, Send, X, ListTodo, FolderKanban, GitMerge } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import { useAuth } from '@/context/AuthContext'
import TicketIntelligencePanel from './TicketIntelligencePanel'

const fmtTime = ts =>
  ts ? new Date(ts).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

export default function TicketThread() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const queryClient = useQueryClient()

  const [message, setMessage] = useState('')
  const [files, setFiles] = useState([])
  const [cc, setCc] = useState('')

  const createTask = useMutation({
    mutationFn: (name) => helpdeskApi.tickets.createTask(id, { name }),
    onSuccess: (task) => navigate(`/app/tasks/${task.id}`),
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

  return (
    <div className="text-slate-200">
      <header className="mb-5 flex items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>
            {ticket ? ticket.subject : 'Ticket Conversation'}
          </h1>
          {ticket && <p className="text-xs mt-0.5 capitalize" style={{ color: 'var(--text-muted)' }}>
            Ticket #{ticket.id} · {ticket.status}{ticket.project_id ? ` · project #${ticket.project_id}` : ''}
          </p>}
        </div>
        {ticket && (
          <div className="flex items-center gap-2 shrink-0">
            <button onClick={() => { const n = window.prompt('Task name:'); if (n?.trim()) createTask.mutate(n.trim()) }}
              className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg" style={{ background: 'rgba(236,72,153,0.15)', color: '#ec4899' }}>
              <ListTodo size={13} /> Create task
            </button>
            <button onClick={() => { const p = window.prompt('Link to project id (blank to unlink):'); if (p !== null) linkProject.mutate(p.trim() ? Number(p.trim()) : null) }}
              className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg" style={{ background: 'rgba(59,130,246,0.15)', color: '#3b82f6' }}>
              <FolderKanban size={13} /> Link project
            </button>
            <button onClick={() => { const m = window.prompt('Merge which ticket id INTO this one? (that ticket becomes "merged")'); if (m?.trim()) merge.mutate(Number(m.trim())) }}
              className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg" style={{ background: 'rgba(107,114,128,0.18)', color: '#9ca3af' }}>
              <GitMerge size={13} /> Merge
            </button>
          </div>
        )}
      </header>

      <div className="flex flex-col lg:flex-row gap-5 items-start">
      <div className="flex-1 min-w-0 w-full">

      {isError && (
        <div className="p-6 rounded-2xl border" style={{ borderColor: 'rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)' }}>
          <p className="font-semibold text-red-400">Couldn’t load the conversation</p>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>{error?.message}</p>
        </div>
      )}

      {isLoading && (
        <div className="space-y-4 max-w-3xl mx-auto">
          {[1, 2, 3].map(i => <div key={i} className="skeleton h-16 rounded-2xl" style={{ background: 'var(--border)' }} />)}
        </div>
      )}

      {!isLoading && !isError && (
        <div className="max-w-3xl mx-auto">
          <div className="flex flex-col gap-4">
            {replies.length === 0 && (
              <p className="text-center text-sm py-8" style={{ color: 'var(--text-muted)' }}>No replies yet. Start the conversation below.</p>
            )}

            {/* THE LOOP: one bubble per reply — client left, admin/agent right */}
            {replies.map(msg => {
              const isStaff = msg.sender_type !== 'client'
              return (
                <div key={msg.id} className={clsx('flex flex-col max-w-[75%]', isStaff ? 'self-end items-end' : 'self-start items-start')}>
                  <span className="text-[11px] mb-1 px-1 capitalize" style={{ color: 'var(--text-muted)' }}>
                    {msg.sender_type} · {fmtTime(msg.created_at)}
                  </span>

                  <div
                    className={clsx('rounded-2xl px-4 py-2.5 text-sm leading-relaxed', isStaff ? 'rounded-br-sm' : 'rounded-bl-sm')}
                    style={isStaff
                      ? { background: 'rgba(6,182,212,0.15)', color: '#cffafe' }
                      : { background: 'var(--bg-card)', color: 'var(--text-h)', border: '1px solid var(--border)' }}
                  >
                    <p>{msg.message}</p>

                    {/* NESTED LOOP: attachment links (authenticated download) */}
                    {msg.has_attachments && msg.attachments?.length > 0 && (
                      <ul className="mt-2 pt-2 border-t border-white/10 space-y-1">
                        {msg.attachments.map(file => (
                          <li key={file.id}>
                            <button
                              type="button"
                              onClick={() => helpdeskApi.tickets.downloadAttachment(id, file.id, file.file_name)}
                              className="flex items-center gap-1.5 text-xs hover:underline"
                              style={{ color: '#67e8f9' }}
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
          <form onSubmit={submit} className="mt-6 rounded-2xl border p-3" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
            <textarea
              value={message}
              onChange={e => setMessage(e.target.value)}
              rows={3}
              placeholder="Write a reply…"
              className="w-full bg-transparent resize-none outline-none text-sm px-2 py-1"
              style={{ color: 'var(--text-h)' }}
            />

            {/* CC field (comma-separated emails) */}
            <input
              value={cc}
              onChange={e => setCc(e.target.value)}
              placeholder="Cc: comma-separated emails (optional)"
              className="w-full bg-transparent outline-none text-xs px-2 py-1.5 border-t"
              style={{ color: 'var(--text-h)', borderColor: 'var(--border)' }}
            />

            {/* Selected files chips */}
            {files.length > 0 && (
              <div className="flex flex-wrap gap-2 px-2 py-2">
                {files.map((f, i) => (
                  <span key={i} className="flex items-center gap-1 text-[11px] px-2 py-1 rounded-lg" style={{ background: 'rgba(6,182,212,0.12)', color: '#67e8f9' }}>
                    <Paperclip size={11} />{f.name}
                    <button type="button" onClick={() => setFiles(files.filter((_, j) => j !== i))} className="hover:text-white"><X size={11} /></button>
                  </span>
                ))}
              </div>
            )}

            <div className="flex items-center justify-between mt-2 px-1">
              <label className="flex items-center gap-1.5 text-xs cursor-pointer" style={{ color: 'var(--text-muted)' }}>
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
                style={{ background: 'linear-gradient(135deg,#22d3ee,#0891b2)', color: '#fff' }}
              >
                <Send size={13} />
                {postReply.isPending ? 'Sending…' : 'Send reply'}
              </button>
            </div>

            {postReply.isError && (
              <p className="text-xs text-red-400 mt-2 px-1">{postReply.error?.message}</p>
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
  )
}
