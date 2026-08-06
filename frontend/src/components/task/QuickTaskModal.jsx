// A one-field "create a task" pop-up used by the composer's +Task quick action.
// It is deliberately generic: the caller supplies onSubmit(name) — for a ticket
// that creates a task linked to the ticket, for a project a task linked to the
// project, for a task detail a subtask. The modal just owns the input, the
// pending state and errors, so every surface's +Task behaves identically.
import { useState } from 'react'
import { X, ListTodo } from 'lucide-react'

export default function QuickTaskModal({
  open, onClose, onSubmit, onCreated,
  title = 'New task', placeholder = 'Task name…', accent = 'var(--color-primary-500)',
}) {
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  if (!open) return null

  const close = () => { setName(''); setErr(''); setBusy(false); onClose() }

  const submit = async () => {
    const n = name.trim()
    if (!n || busy) return
    setBusy(true); setErr('')
    try {
      await onSubmit(n)
      onCreated?.()
      close()
    } catch (e) {
      setErr(e?.message || 'Could not create that. Try the full form.')
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.5)' }}
      onMouseDown={e => { if (e.target === e.currentTarget) close() }}>
      <div className="w-full max-w-sm rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center gap-2 mb-4">
          <ListTodo size={16} style={{ color: accent }} />
          <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{title}</h3>
          <button onClick={close} className="ml-auto" aria-label="Close"><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

        <input autoFocus value={name} onChange={e => setName(e.target.value)}
          onKeyDown={e => { if (e.key === 'Enter') submit() }} placeholder={placeholder}
          className="w-full rounded-lg outline-none mb-4"
          style={{ padding: '9px 12px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />

        <div className="flex items-center justify-end gap-2">
          <button onClick={close} className="text-xs font-semibold px-3 py-1.5 rounded-lg"
            style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          <button onClick={submit} disabled={!name.trim() || busy}
            className="text-xs font-bold px-4 py-1.5 rounded-lg disabled:opacity-40" style={{ background: accent, color: '#fff' }}>
            {busy ? 'Creating…' : 'Create'}
          </button>
        </div>
      </div>
    </div>
  )
}
