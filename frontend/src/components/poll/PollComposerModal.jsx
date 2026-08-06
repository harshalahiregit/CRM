// Create-a-poll modal. Lifted into each surface (task / ticket / project) so the
// composer's "Poll" button and a Polls panel's "New poll" button can share it.
import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Plus, Trash2, BarChart3 } from 'lucide-react'
import { pollApi } from '@/services/pollApi'

const EMPTY = { question: '', options: ['', ''], allow_multiple: false, is_anonymous: false, closes_at: '' }

export default function PollComposerModal({ open, onClose, contextType, contextId, accent = 'var(--color-primary-500)', onCreated }) {
  const qc = useQueryClient()
  const [form, setForm] = useState(EMPTY)
  const [err, setErr] = useState('')

  const reset = () => { setForm(EMPTY); setErr('') }

  const create = useMutation({
    mutationFn: () => pollApi.create({
      context_type: contextType,
      context_id: contextId,
      question: form.question.trim(),
      options: form.options.map(o => o.trim()).filter(Boolean),
      allow_multiple: form.allow_multiple,
      is_anonymous: form.is_anonymous,
      closes_at: form.closes_at || null,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['polls', contextType, contextId] })
      onCreated?.(); reset(); onClose()
    },
    onError: (e) => setErr(e?.message || 'Could not create the poll.'),
  })

  if (!open) return null

  const cleanOptions = form.options.map(o => o.trim()).filter(Boolean)
  const canSubmit = form.question.trim() && new Set(cleanOptions).size >= 2

  const setOption = (i, v) => setForm(f => ({ ...f, options: f.options.map((o, j) => j === i ? v : o) }))
  const addOption = () => setForm(f => f.options.length >= 12 ? f : ({ ...f, options: [...f.options, ''] }))
  const removeOption = (i) => setForm(f => f.options.length <= 2 ? f : ({ ...f, options: f.options.filter((_, j) => j !== i) }))

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.5)' }}
      onMouseDown={e => { if (e.target === e.currentTarget) { reset(); onClose() } }}>
      <div className="w-full max-w-md rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center gap-2 mb-4">
          <BarChart3 size={16} style={{ color: accent }} />
          <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>New poll</h3>
          <button onClick={() => { reset(); onClose() }} className="ml-auto" aria-label="Close"><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

        <input value={form.question} onChange={e => setForm(f => ({ ...f, question: e.target.value }))} placeholder="Ask a question…"
          className="w-full rounded-lg outline-none mb-3"
          style={{ padding: '9px 12px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />

        <div className="space-y-2 mb-3">
          {form.options.map((o, i) => (
            <div key={i} className="flex items-center gap-2">
              <input value={o} onChange={e => setOption(i, e.target.value)} placeholder={`Option ${i + 1}`}
                className="flex-1 rounded-lg outline-none"
                style={{ padding: '8px 11px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              {form.options.length > 2 && (
                <button onClick={() => removeOption(i)} aria-label={`Remove option ${i + 1}`}><Trash2 size={14} style={{ color: 'var(--text-muted)' }} /></button>
              )}
            </div>
          ))}
          {form.options.length < 12 && (
            <button onClick={addOption} className="flex items-center gap-1 text-[11px] font-bold" style={{ color: accent }}>
              <Plus size={12} /> Add option
            </button>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-4 mb-4">
          <label className="flex items-center gap-1.5 text-xs font-semibold cursor-pointer select-none" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={form.allow_multiple} onChange={e => setForm(f => ({ ...f, allow_multiple: e.target.checked }))} style={{ accentColor: accent }} />
            Allow multiple choices
          </label>
          <label className="flex items-center gap-1.5 text-xs font-semibold cursor-pointer select-none" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={form.is_anonymous} onChange={e => setForm(f => ({ ...f, is_anonymous: e.target.checked }))} style={{ accentColor: accent }} />
            Anonymous
          </label>
          <label className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
            Closes
            <input type="datetime-local" value={form.closes_at} onChange={e => setForm(f => ({ ...f, closes_at: e.target.value }))}
              className="rounded-lg outline-none text-xs" style={{ padding: '5px 7px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          </label>
        </div>

        <div className="flex items-center justify-end gap-2">
          <button onClick={() => { reset(); onClose() }} className="text-xs font-semibold px-3 py-1.5 rounded-lg"
            style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          <button onClick={() => create.mutate()} disabled={!canSubmit || create.isPending}
            className="text-xs font-bold px-4 py-1.5 rounded-lg disabled:opacity-40" style={{ background: accent, color: '#fff' }}>
            {create.isPending ? 'Creating…' : 'Create poll'}
          </button>
        </div>
      </div>
    </div>
  )
}
