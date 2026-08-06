import { useState, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { MessagesSquare, Plus, Send, MessageCircle, Eye, ChevronDown, ChevronRight, X, ListTodo } from 'lucide-react'
import { projectApi, PROJECT_ACCENT } from '@/services/projectApi'
import { taskApi } from '@/services/taskApi'
import EditorActionBar from '@/components/editor/EditorActionBar'
import PollList from '@/components/poll/PollList'
import PollComposerModal from '@/components/poll/PollComposerModal'
import QuickTaskModal from '@/components/task/QuickTaskModal'

const fmtDateTime = d => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

const EMPTY = { subject: '', body: '', visible_to_customer: false }

/* ── Discussions tab ──────────────────────────────────────────── */

export function DiscussionsTab({ projectId }) {
  const qc = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [openId, setOpenId] = useState(null)
  const [err, setErr] = useState('')
  const [pollOpen, setPollOpen] = useState(false)
  const [quickTaskOpen, setQuickTaskOpen] = useState(false)
  const bodyRef = useRef(null)

  const { data: staff = [] } = useQuery({ queryKey: ['task-staff'], queryFn: taskApi.staff })
  const { data: discussions = [], isLoading } = useQuery({ queryKey: ['project-discussions', projectId], queryFn: () => projectApi.discussions(projectId) })
  const bust = () => qc.invalidateQueries({ queryKey: ['project-discussions', projectId] })
  const onErr = (e) => setErr(e?.message || 'That action failed.')

  const create = useMutation({
    mutationFn: () => projectApi.createDiscussion(projectId, {
      subject: form.subject.trim(),
      body: form.body.trim() || null,
      visible_to_customer: form.visible_to_customer,
    }),
    onSuccess: () => { setForm(EMPTY); setShowForm(false); setErr(''); bust() },
    onError: onErr,
  })

  if (isLoading) return <Skeleton />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-3">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <MessagesSquare size={14} style={{ color: PROJECT_ACCENT }} /> Discussions
        </h2>
        <button onClick={() => { setShowForm(s => !s); setErr('') }}
          className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: PROJECT_ACCENT, color: '#fff' }}>
          {showForm ? <X size={12} /> : <Plus size={12} />} {showForm ? 'Cancel' : 'Create Discussion'}
        </button>
      </div>

      {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {/* Project polls — vote inline; create from a discussion composer or here. */}
      <div className="mb-4">
        <PollList contextType="project" contextId={projectId} accent={PROJECT_ACCENT} onNew={() => setPollOpen(true)} />
      </div>

      {showForm && (
        <form onSubmit={e => { e.preventDefault(); if (form.subject.trim()) create.mutate() }}
          className="rounded-xl p-3 mb-4 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <input value={form.subject} onChange={e => setForm({ ...form, subject: e.target.value })} placeholder="Subject *"
            className="w-full rounded-lg outline-none"
            style={{ padding: '8px 11px', fontSize: 13, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          <textarea ref={bodyRef} value={form.body} onChange={e => setForm({ ...form, body: e.target.value })} placeholder="Start the discussion… (optional)" rows={2}
            className="w-full rounded-lg outline-none"
            style={{ padding: '8px 11px', fontSize: 12.5, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          <EditorActionBar textareaRef={bodyRef} value={form.body} onChange={v => setForm(f => ({ ...f, body: v }))} people={staff} accent={PROJECT_ACCENT} onPoll={() => setPollOpen(true)} meeting />
          <label className="flex items-center gap-2 text-xs cursor-pointer select-none" style={{ color: 'var(--text-body)' }}>
            <input type="checkbox" checked={form.visible_to_customer} onChange={e => setForm({ ...form, visible_to_customer: e.target.checked })} />
            Visible to customer
          </label>
          <button type="submit" disabled={!form.subject.trim() || create.isPending}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40"
            style={{ background: PROJECT_ACCENT, color: '#fff' }}><Plus size={12} /> Create</button>
        </form>
      )}

      <ul className="space-y-2">
        {discussions.map(d => (
          <li key={d.id} className="rounded-xl overflow-hidden" style={{ background: 'var(--bg-input)' }}>
            <button onClick={() => setOpenId(o => o === d.id ? null : d.id)}
              className="w-full flex items-center gap-2 px-3 py-2.5 text-left">
              {openId === d.id ? <ChevronDown size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} /> : <ChevronRight size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />}
              <span className="flex-1 min-w-0">
                <span className="block text-xs font-bold truncate" style={{ color: 'var(--text-h)' }}>{d.subject}</span>
                <span className="block text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  {d.author?.name || 'Someone'} · last activity {fmtDateTime(d.last_activity)}
                </span>
              </span>
              {d.visible_to_customer && (
                <span className="inline-flex items-center gap-0.5 text-[9px] px-1.5 py-0.5 rounded font-bold"
                  style={{ background: 'color-mix(in srgb, var(--color-success-500) 14%, transparent)', color: 'var(--color-success-500)' }}>
                  <Eye size={9} /> client
                </span>
              )}
              <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ color: 'var(--text-muted)' }}>
                <MessageCircle size={11} /> {d.comment_count ?? 0}
              </span>
            </button>

            {openId === d.id && <Thread projectId={projectId} discussion={d} onCommented={bust} people={staff}
              onPoll={() => setPollOpen(true)} onQuickTask={() => setQuickTaskOpen(true)} onNewTopic={() => { setShowForm(true); setErr('') }} />}
          </li>
        ))}
        {discussions.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No discussions yet.</li>}
      </ul>

      <PollComposerModal open={pollOpen} onClose={() => setPollOpen(false)} contextType="project" contextId={projectId} accent={PROJECT_ACCENT} />

      <QuickTaskModal open={quickTaskOpen} onClose={() => setQuickTaskOpen(false)} accent={PROJECT_ACCENT}
        title="New task (linked to this project)" placeholder="Task name…"
        onSubmit={(name) => taskApi.create({ name, rel_type: 'project', rel_id: projectId })} />
    </section>
  )
}

/* ── One discussion's comment thread ──────────────────────────── */

function Thread({ projectId, discussion, onCommented, people = [], onPoll, onQuickTask, onNewTopic }) {
  const qc = useQueryClient()
  const [text, setText] = useState('')
  const [err, setErr] = useState('')
  const commentRef = useRef(null)
  const key = ['project-discussion-comments', projectId, discussion.id]

  const { data: comments = [], isLoading } = useQuery({ queryKey: key, queryFn: () => projectApi.discussionComments(projectId, discussion.id) })

  const add = useMutation({
    mutationFn: () => projectApi.addDiscussionComment(projectId, discussion.id, text.trim()),
    onSuccess: () => { setText(''); setErr(''); qc.invalidateQueries({ queryKey: key }); onCommented?.() },
    onError: (e) => setErr(e?.message || 'Could not post comment.'),
  })

  return (
    <div className="px-3 pb-3" style={{ borderTop: '1px solid var(--border)' }}>
      {discussion.body && (
        <p className="text-xs whitespace-pre-wrap break-words leading-relaxed pt-3 pb-1" style={{ color: 'var(--text-body)' }}>{discussion.body}</p>
      )}

      {isLoading && <p className="text-[11px] py-2" style={{ color: 'var(--text-muted)' }}>Loading comments…</p>}

      <ul className="space-y-2 py-2">
        {comments.map(c => (
          <li key={c.id} className="rounded-lg p-2.5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <p className="text-xs whitespace-pre-wrap break-words" style={{ color: 'var(--text-body)' }}>{c.content}</p>
            <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>{c.user?.name || 'Someone'} · {fmtDateTime(c.created_at)}</p>
          </li>
        ))}
        {!isLoading && comments.length === 0 && <li className="text-[11px]" style={{ color: 'var(--text-muted)' }}>No comments yet — start the conversation.</li>}
      </ul>

      {err && <p className="text-[11px] mb-1" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <form onSubmit={e => { e.preventDefault(); if (text.trim()) add.mutate() }} className="flex items-end gap-2">
        <div className="flex-1">
          <textarea ref={commentRef} value={text} onChange={e => setText(e.target.value)} placeholder="Write a comment…" rows={1}
            className="w-full rounded-lg outline-none resize-none"
            style={{ padding: '8px 11px', fontSize: 12.5, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          <EditorActionBar textareaRef={commentRef} value={text} onChange={setText} people={people} accent={PROJECT_ACCENT} className="mt-1" onPoll={onPoll} meeting
            quickCreate={[
              { label: 'Task', icon: ListTodo, onClick: onQuickTask },
              { label: 'Topic', icon: MessagesSquare, onClick: onNewTopic },
            ]} />
        </div>
        <button type="submit" disabled={!text.trim() || add.isPending}
          className="flex items-center gap-1 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40"
          style={{ background: PROJECT_ACCENT, color: '#fff' }}><Send size={12} /> Post</button>
      </form>
    </div>
  )
}

const Skeleton = () => <div className="rounded-2xl animate-pulse" style={{ height: 160, background: 'var(--bg-card)' }} />

export default DiscussionsTab
