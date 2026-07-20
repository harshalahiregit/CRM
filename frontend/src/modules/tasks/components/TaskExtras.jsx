import { useState, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Paperclip, Upload, Download, Trash2, AlarmClock, Plus, X } from 'lucide-react'
import { taskApi, TASK_ACCENT, fmtBytes } from '@/services/taskApi'
import { openGoogleDrivePicker } from '@/lib/googleDrivePicker'
import Select from '@/components/ui/Select'

/** The multi-colour Google "G", inline so it needs no external asset. */
function GoogleG({ size = 13 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" aria-hidden="true">
      <path fill="#4285F4" d="M45.12 24.5c0-1.56-.14-3.06-.4-4.5H24v8.51h11.84c-.51 2.75-2.06 5.08-4.39 6.64v5.52h7.11c4.16-3.83 6.56-9.47 6.56-16.17z" />
      <path fill="#34A853" d="M24 46c5.94 0 10.92-1.97 14.56-5.33l-7.11-5.52c-1.97 1.32-4.49 2.1-7.45 2.1-5.73 0-10.58-3.87-12.31-9.07H4.34v5.7C7.96 41.07 15.4 46 24 46z" />
      <path fill="#FBBC05" d="M11.69 28.18C11.25 26.86 11 25.45 11 24s.25-2.86.69-4.18v-5.7H4.34C2.85 17.09 2 20.45 2 24s.85 6.91 2.34 9.88l7.35-5.7z" />
      <path fill="#EA4335" d="M24 10.75c3.23 0 6.13 1.11 8.41 3.29l6.31-6.31C34.91 4.18 29.93 2 24 2 15.4 2 7.96 6.93 4.34 14.12l7.35 5.7c1.73-5.2 6.58-9.07 12.31-9.07z" />
    </svg>
  )
}

/** Attachments and reminders — the two task features with their own sub-resources. */

export function FilesCard({ taskId }) {
  const qc = useQueryClient()
  const input = useRef(null)
  const [err, setErr] = useState('')
  const [dragOver, setDragOver] = useState(false)

  const { data: files = [] } = useQuery({ queryKey: ['task-files', taskId], queryFn: () => taskApi.files(taskId) })
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['task-files', taskId] })
    qc.invalidateQueries({ queryKey: ['task', String(taskId)] })
  }

  const upload = useMutation({
    mutationFn: (list) => taskApi.uploadFiles(taskId, list),
    onSuccess: () => { setErr(''); refresh() },
    onError: (e) => setErr(e?.message || 'Upload failed.'),
  })
  const remove = useMutation({
    mutationFn: (fid) => taskApi.deleteFile(taskId, fid),
    onSuccess: refresh,
    onError: (e) => setErr(e?.message || 'Could not delete that file.'),
  })

  // Snapshot the FileList into a plain array RIGHT NOW. The <input> onChange clears
  // e.target.value immediately after this call, which empties the live FileList
  // before the async upload runs — so passing the FileList straight through sent
  // zero files. Array.from copies the File references, which survive the reset.
  const pick = (list) => { const files = Array.from(list || []); if (files.length) upload.mutate(files) }
  const pickFromDrive = async () => {
    setErr('')
    try {
      const files = await openGoogleDrivePicker()
      if (files.length) upload.mutate(files)
    } catch (e) {
      setErr(e?.message || 'Could not open Google Drive.')
    }
  }

  return (
    <Card title={`Files${files.length ? ` · ${files.length}` : ''}`} icon={Paperclip}>
      <div
        onDragOver={e => { e.preventDefault(); setDragOver(true) }}
        onDragLeave={() => setDragOver(false)}
        onDrop={e => { e.preventDefault(); setDragOver(false); pick(e.dataTransfer.files) }}
        onClick={() => input.current?.click()}
        className="rounded-xl py-4 px-3 text-center cursor-pointer mb-3 transition-colors"
        style={{
          border: `1px dashed ${dragOver ? TASK_ACCENT : 'var(--border)'}`,
          background: dragOver ? `color-mix(in srgb, ${TASK_ACCENT} 8%, transparent)` : 'transparent',
        }}>
        <Upload size={15} className="mx-auto mb-1" style={{ color: 'var(--text-muted)' }} />
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          {upload.isPending ? 'Uploading…' : 'Drop files here or click to browse'}
        </p>
        <p className="text-[9px] mt-0.5" style={{ color: 'var(--text-muted)', opacity: 0.7 }}>Up to 10 files, 10 MB each</p>
        <input ref={input} type="file" multiple hidden onChange={e => { pick(e.target.files); e.target.value = '' }} />
      </div>

      {/* Import straight from Google Drive (needs Google API credentials configured). */}
      <button type="button" onClick={pickFromDrive}
        className="w-full flex items-center justify-center gap-2 text-[11px] font-semibold py-2 rounded-lg mb-3 transition-colors hover:opacity-80"
        style={{ border: '1px solid var(--border)', color: 'var(--text-body)', background: 'var(--bg-input)' }}>
        <GoogleG /> Choose from Google Drive
      </button>

      {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <ul className="space-y-1.5">
        {files.map(f => (
          <li key={f.id} className="flex items-center gap-2 px-2 py-1.5 rounded-lg" style={{ background: 'var(--bg-input)' }}>
            <Paperclip size={12} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
            <span className="flex-1 min-w-0">
              <span className="block text-[11px] font-semibold truncate" style={{ color: 'var(--text-h)' }}>{f.file_name}</span>
              <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>
                {fmtBytes(f.file_size)}{f.uploader?.name ? ` · ${f.uploader.name}` : ''}
              </span>
            </span>
            <button onClick={() => taskApi.downloadFile(taskId, f.id, f.file_name)} aria-label={`Download ${f.file_name}`} className="hover:opacity-60">
              <Download size={12} style={{ color: 'var(--text-muted)' }} />
            </button>
            <button onClick={() => remove.mutate(f.id)} aria-label={`Delete ${f.file_name}`} className="hover:opacity-60">
              <Trash2 size={12} style={{ color: 'var(--color-danger-500)' }} />
            </button>
          </li>
        ))}
        {files.length === 0 && <li className="text-[11px]" style={{ color: 'var(--text-muted)' }}>No files attached.</li>}
      </ul>
    </Card>
  )
}

export function RemindersCard({ taskId, staff = [], currentUserId }) {
  const qc = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [when, setWhen] = useState('')
  const [who, setWho] = useState(String(currentUserId ?? ''))
  const [note, setNote] = useState('')
  const [err, setErr] = useState('')

  const { data: reminders = [] } = useQuery({ queryKey: ['task-reminders', taskId], queryFn: () => taskApi.reminders(taskId) })
  const refresh = () => qc.invalidateQueries({ queryKey: ['task-reminders', taskId] })

  const add = useMutation({
    mutationFn: () => taskApi.addReminder(taskId, { user_id: Number(who), remind_at: when, description: note.trim() || null }),
    onSuccess: () => { setAdding(false); setWhen(''); setNote(''); setErr(''); refresh() },
    onError: (e) => setErr(e?.message || 'Could not set that reminder.'),
  })
  const remove = useMutation({ mutationFn: (rid) => taskApi.deleteReminder(taskId, rid), onSuccess: refresh })

  // The input is local-time; a past value would be rejected server-side anyway.
  const minWhen = new Date(Date.now() + 60000).toISOString().slice(0, 16)

  return (
    <Card title={`Reminders${reminders.length ? ` · ${reminders.length}` : ''}`} icon={AlarmClock}>
      <ul className="space-y-1.5 mb-2.5">
        {reminders.map(r => {
          const fired = Boolean(r.notified_at)
          return (
            <li key={r.id} className="flex items-start gap-2 px-2 py-1.5 rounded-lg" style={{ background: 'var(--bg-input)' }}>
              <AlarmClock size={12} className="mt-0.5 shrink-0" style={{ color: fired ? 'var(--text-muted)' : 'var(--color-warning-500)' }} />
              <span className="flex-1 min-w-0">
                <span className="block text-[11px] font-semibold" style={{ color: 'var(--text-h)' }}>
                  {new Date(r.remind_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                  {fired && <span className="ml-1.5 font-normal" style={{ color: 'var(--text-muted)' }}>· sent</span>}
                </span>
                <span className="block text-[9px] truncate" style={{ color: 'var(--text-muted)' }}>
                  {r.user?.name || 'Someone'}{r.description ? ` — ${r.description}` : ''}
                </span>
              </span>
              <button onClick={() => remove.mutate(r.id)} aria-label="Remove reminder" className="hover:opacity-60">
                <X size={12} style={{ color: 'var(--text-muted)' }} />
              </button>
            </li>
          )
        })}
        {reminders.length === 0 && <li className="text-[11px]" style={{ color: 'var(--text-muted)' }}>No reminders set.</li>}
      </ul>

      {!adding && (
        <button onClick={() => setAdding(true)} className="text-[11px] font-bold px-2 py-1 rounded-lg"
          style={{ border: '1px dashed var(--border)', color: 'var(--text-muted)' }}>+ Add reminder</button>
      )}

      {adding && (
        <form onSubmit={e => { e.preventDefault(); if (when) add.mutate() }} className="space-y-2">
          <input type="datetime-local" value={when} min={minWhen} onChange={e => setWhen(e.target.value)} className="w-full rounded-lg outline-none"
            style={{ padding: '7px 10px', fontSize: 11.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          <Select size="sm" value={who} onChange={setWho} options={staff.map(s => ({ value: String(s.id), label: s.name }))} placeholder="Remind who?" />
          <input value={note} onChange={e => setNote(e.target.value)} placeholder="What about? (optional)" className="w-full rounded-lg outline-none"
            style={{ padding: '7px 10px', fontSize: 11.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          {err && <p className="text-[10px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
          <div className="flex gap-2">
            <button type="submit" disabled={!when || add.isPending}
              className="flex-1 flex items-center justify-center gap-1 text-[11px] font-bold py-1.5 rounded-lg disabled:opacity-40"
              style={{ background: TASK_ACCENT, color: '#fff' }}><Plus size={11} /> Set</button>
            <button type="button" onClick={() => { setAdding(false); setErr('') }}
              className="text-[11px] font-semibold px-3 py-1.5 rounded-lg"
              style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          </div>
        </form>
      )}
    </Card>
  )
}

function Card({ title, icon: Icon, children }) {
  return (
    <section className="rounded-2xl p-4" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        {Icon && <Icon size={14} style={{ color: TASK_ACCENT }} />}{title}
      </h2>
      {children}
    </section>
  )
}
