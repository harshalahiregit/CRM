import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import clsx from 'clsx'
import { Paperclip, Send, X } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import { useAuth } from '@/context/AuthContext'

const fmtTime = ts =>
  ts ? new Date(ts).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

export default function TicketThread() {
  const { id } = useParams()
  const { user } = useAuth()
  const queryClient = useQueryClient()

  const [message, setMessage] = useState('')
  const [files, setFiles] = useState([])

  const { data: ticket } = useQuery({
    queryKey: ['helpdesk-ticket', id],
    queryFn: () => helpdeskApi.tickets.get(id),
    enabled: !!id,
  })

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
      files.forEach(f => fd.append('attachments[]', f))
      return helpdeskApi.tickets.reply(id, fd)
    },
    onSuccess: () => {
      setMessage('')
      setFiles([])
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
      <header className="mb-5">
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>
          {ticket ? ticket.subject : 'Ticket Conversation'}
        </h1>
        {ticket && <p className="text-xs mt-0.5 capitalize" style={{ color: 'var(--text-muted)' }}>Ticket #{ticket.id} · {ticket.status}</p>}
      </header>

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
  )
}
