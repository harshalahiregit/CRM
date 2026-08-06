import { useState, useMemo, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import ReactQuill from 'react-quill'
import 'react-quill/dist/quill.snow.css'
import { Clock, StickyNote, Activity, Plus, Trash2, Eye, Download, Pencil, Search, Printer, X, Check, Receipt, IndianRupee, HardHat, Building2, Bell, Paperclip, UserPlus } from 'lucide-react'
import { projectApi, PROJECT_ACCENT } from '@/services/projectApi'
import { taskApi, fmtDuration } from '@/services/taskApi'
import { exportCsv, stampedName } from '@/lib/exportCsv'
import { RICH_MODULES, RICH_FORMATS } from '@/lib/quillConfig'
import { ConfirmModal } from '@/components/ui/SearchPicker'
import EditorActionBar from '@/components/editor/EditorActionBar'

const NOTE_PAGE_SIZES = [{ value: 25, label: '25' }, { value: 50, label: '50' }, { value: 100, label: '100' }, { value: 0, label: 'All' }]
const stripHtml = (html) => (html || '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/gi, ' ').replace(/\s+/g, ' ').trim()
const NOTE_CSS = `
  .proj-note-editor{border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--bg-input)}
  .proj-note-editor .ql-toolbar.ql-snow{border:0;border-bottom:1px solid var(--border);background:var(--bg-card);padding:6px 9px}
  .proj-note-editor .ql-container.ql-snow{border:0;background:transparent;font-family:inherit;font-size:13px}
  .proj-note-editor .ql-editor{min-height:90px;max-height:260px;overflow-y:auto;color:var(--text-h);line-height:1.6}
  .proj-note-editor .ql-editor.ql-blank::before{color:var(--text-muted);opacity:.6;font-style:normal}
  .proj-note-editor .ql-snow .ql-stroke{stroke:var(--text-muted)}
  .proj-note-editor .ql-snow .ql-fill{fill:var(--text-muted)}
  .proj-note-html{word-break:break-word}
  .proj-note-html p{margin:0 0 .4rem}
  .proj-note-html ul,.proj-note-html ol{margin:.3rem 0 .4rem;padding-left:1.3rem}
  .proj-note-html ul{list-style:disc}.proj-note-html ol{list-style:decimal}
  .proj-note-html a{color:var(--color-primary-500);text-decoration:underline}
  .proj-note-html img{max-width:100%;height:auto;border-radius:6px}
  .proj-note-html h2{font-size:1.1rem;font-weight:800;margin:.4rem 0 .3rem}
  .proj-note-html h3{font-size:1rem;font-weight:700;margin:.3rem 0 .2rem}
  .proj-note-html blockquote{border-left:3px solid var(--color-primary-500);margin:.4rem 0;padding:.2rem .8rem;opacity:.9}
`
const isNoteEmpty = (html) => stripHtml(html).length === 0

const fmtDateTime = d => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

/* ── Timesheets ───────────────────────────────────────────────── */

export function TimesheetsTab({ projectId }) {
  const { data, isLoading } = useQuery({ queryKey: ['project-timesheets', projectId], queryFn: () => projectApi.timesheets(projectId) })
  const rows = data?.rows || []

  const doExport = () => exportCsv(stampedName(`project-${projectId}-timesheets`), rows, [
    { key: 'member_name', label: 'Member' },
    { key: 'task_name', label: 'Task' },
    { key: 'start_time', label: 'Start' },
    { key: 'end_time', label: 'End' },
    { key: 'hours', label: 'Hours' },
    { key: 'cost', label: 'Cost' },
    { key: 'note', label: 'Note' },
  ])

  if (isLoading) return <Skeleton />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-3">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <Clock size={14} style={{ color: PROJECT_ACCENT }} /> Timesheets
        </h2>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
          {data?.total_hours ?? 0}h logged across {rows.length} {rows.length === 1 ? 'entry' : 'entries'}
        </span>
        {rows.length > 0 && (
          <button onClick={doExport} className="ml-auto flex items-center gap-1 text-[10px] font-bold" style={{ color: PROJECT_ACCENT }}>
            <Download size={11} /> Export
          </button>
        )}
      </div>

      {rows.length === 0 && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No time logged on this project's tasks yet.</p>}
      {rows.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-xs" style={{ minWidth: 560 }}>
            <thead>
              <tr className="text-left text-[10px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                <th className="px-2 py-2 font-bold">Member</th>
                <th className="px-2 py-2 font-bold">Task</th>
                <th className="px-2 py-2 font-bold">When</th>
                <th className="px-2 py-2 font-bold text-right">Time</th>
                <th className="px-2 py-2 font-bold text-right">Cost</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-2 py-2" style={{ color: 'var(--text-h)' }}>{r.member_name || '—'}</td>
                  <td className="px-2 py-2" style={{ color: 'var(--text-muted)' }}>{r.task_name}</td>
                  <td className="px-2 py-2" style={{ color: 'var(--text-muted)' }}>{fmtDateTime(r.start_time)}</td>
                  <td className="px-2 py-2 text-right tabular-nums" style={{ color: 'var(--text-h)' }}>{fmtDuration(r.seconds)}</td>
                  <td className="px-2 py-2 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{r.cost ? '₹' + r.cost.toLocaleString('en-IN') : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

/* ── Notes ────────────────────────────────────────────────────── */

export function NotesTab({ projectId }) {
  const qc = useQueryClient()
  const [title, setTitle] = useState('')
  const [content, setContent] = useState('')
  const [assignedTo, setAssignedTo] = useState('')
  const [remindAt, setRemindAt] = useState('')
  const [search, setSearch] = useState('')
  const [pageSize, setPageSize] = useState(25)
  const [editId, setEditId] = useState(null)
  const [editDraft, setEditDraft] = useState({ title: '', content: '', assigned_to: '', remind_at: '' })
  const [confirmDelete, setConfirmDelete] = useState(null)
  const newNoteQuill = useRef(null)
  const editNoteQuill = useRef(null)
  const { data: notes = [], isLoading } = useQuery({ queryKey: ['project-notes', projectId], queryFn: () => projectApi.notes(projectId) })
  const { data: staff = [] } = useQuery({ queryKey: ['task-staff'], queryFn: taskApi.staff })
  const bust = () => qc.invalidateQueries({ queryKey: ['project-notes', projectId] })

  const add = useMutation({
    mutationFn: () => projectApi.addNote(projectId, {
      title: title.trim(),
      content: isNoteEmpty(content) ? null : content,
      assigned_to: assignedTo ? Number(assignedTo) : null,
      remind_at: remindAt || null,
    }),
    onSuccess: () => { setTitle(''); setContent(''); setAssignedTo(''); setRemindAt(''); bust() },
  })
  const edit = useMutation({
    mutationFn: ({ id, data }) => projectApi.updateNote(projectId, id, data),
    onSuccess: () => { setEditId(null); bust() },
  })
  const del = useMutation({ mutationFn: (nid) => projectApi.deleteNote(projectId, nid), onSuccess: () => { setConfirmDelete(null); bust() } })
  const upload = useMutation({ mutationFn: ({ noteId, file }) => projectApi.addNoteAttachment(projectId, noteId, file), onSuccess: bust })
  const removeAtt = useMutation({ mutationFn: (attId) => projectApi.deleteNoteAttachment(projectId, attId), onSuccess: bust })

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase()
    const list = term
      ? notes.filter(n => `${n.title} ${stripHtml(n.content)}`.toLowerCase().includes(term))
      : notes
    return pageSize === 0 ? list : list.slice(0, pageSize)
  }, [notes, search, pageSize])

  const startEdit = (n) => setEditId(n.id) || setEditDraft({
    title: n.title || '', content: n.content || '',
    assigned_to: n.assigned_to ? String(n.assigned_to) : '',
    remind_at: n.remind_at ? String(n.remind_at).slice(0, 16) : '',
  })
  const saveEdit = () => {
    if (!editDraft.title.trim()) return
    edit.mutate({ id: editId, data: {
      title: editDraft.title.trim(),
      content: isNoteEmpty(editDraft.content) ? null : editDraft.content,
      assigned_to: editDraft.assigned_to ? Number(editDraft.assigned_to) : null,
      remind_at: editDraft.remind_at || null,
    } })
  }
  const doExport = () => exportCsv(stampedName(`project-${projectId}-notes`), notes, [
    { key: 'title', label: 'Title' },
    { key: 'content', label: 'Note', value: n => stripHtml(n.content) },
    { key: 'author', label: 'Author', value: n => n.author?.name || '' },
    { key: 'created_at', label: 'Created', value: n => fmtDateTime(n.created_at) },
  ])
  const printNote = (n) => {
    const w = window.open('', '_blank', 'width=720,height=800')
    if (!w) return
    w.document.write(`<html><head><title>${n.title || 'Note'}</title><style>body{font-family:system-ui,Arial,sans-serif;padding:32px;color:#111;line-height:1.6}h1{font-size:20px}small{color:#666}img{max-width:100%}</style></head><body><h1>${n.title || ''}</h1><small>${n.author?.name || ''} · ${fmtDateTime(n.created_at)}</small><hr/>${n.content || ''}</body></html>`)
    w.document.close(); w.focus(); w.print()
  }

  if (isLoading) return <Skeleton />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <style>{NOTE_CSS}</style>
      <div className="flex items-center gap-2 mb-3 flex-wrap">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <StickyNote size={14} style={{ color: PROJECT_ACCENT }} /> Notes
        </h2>
        <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{notes.length} total</span>
        {notes.length > 0 && (
          <button onClick={doExport} className="ml-auto flex items-center gap-1 text-[10px] font-bold" style={{ color: PROJECT_ACCENT }}>
            <Download size={11} /> Export
          </button>
        )}
      </div>

      {/* New note — title + notepad editor */}
      <form onSubmit={e => { e.preventDefault(); if (title.trim()) add.mutate() }} className="space-y-2 mb-4">
        <input value={title} onChange={e => setTitle(e.target.value)} placeholder="Note title"
          className="w-full rounded-lg outline-none"
          style={{ padding: '8px 11px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <div className="proj-note-editor">
          <ReactQuill ref={newNoteQuill} theme="snow" modules={RICH_MODULES} formats={RICH_FORMATS} value={content} onChange={setContent} placeholder="Write a note…" />
        </div>
        <EditorActionBar quillRef={newNoteQuill} people={staff} accent={PROJECT_ACCENT} meeting />
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <label className="flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            <UserPlus size={12} /> Assign
            <select value={assignedTo} onChange={e => setAssignedTo(e.target.value)}
              className="flex-1 rounded-lg outline-none text-xs" style={{ padding: '6px 8px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
              <option value="">— nobody —</option>
              {staff.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </label>
          <label className="flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            <Bell size={12} /> Remind
            <input type="datetime-local" value={remindAt} onChange={e => setRemindAt(e.target.value)}
              className="flex-1 rounded-lg outline-none text-xs" style={{ padding: '6px 8px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          </label>
        </div>
        <button type="submit" disabled={!title.trim() || add.isPending}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40"
          style={{ background: PROJECT_ACCENT, color: '#fff' }}><Plus size={12} /> Add note</button>
      </form>

      {/* Search + page size */}
      {notes.length > 0 && (
        <div className="flex items-center gap-2 mb-3 flex-wrap">
          <div className="relative flex-1" style={{ maxWidth: 260 }}>
            <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
            <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search notes…"
              className="w-full rounded-lg outline-none" style={{ padding: '7px 10px 7px 30px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          </div>
          <select value={pageSize} onChange={e => setPageSize(Number(e.target.value))}
            className="rounded-lg outline-none text-xs" style={{ padding: '7px 8px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
            {NOTE_PAGE_SIZES.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </div>
      )}

      <ul className="space-y-2">
        {filtered.map(n => (
          editId === n.id ? (
            <li key={n.id} className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: `1px solid ${PROJECT_ACCENT}` }}>
              <input value={editDraft.title} onChange={e => setEditDraft(d => ({ ...d, title: e.target.value }))} placeholder="Note title"
                className="w-full rounded-lg outline-none mb-2" style={{ padding: '7px 10px', fontSize: 13, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              <div className="proj-note-editor mb-2">
                <ReactQuill ref={editNoteQuill} theme="snow" modules={RICH_MODULES} formats={RICH_FORMATS} value={editDraft.content} onChange={v => setEditDraft(d => ({ ...d, content: v }))} />
              </div>
              <EditorActionBar quillRef={editNoteQuill} people={staff} accent={PROJECT_ACCENT} className="mb-2" meeting />
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2">
                <label className="flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  <UserPlus size={12} /> Assign
                  <select value={editDraft.assigned_to} onChange={e => setEditDraft(d => ({ ...d, assigned_to: e.target.value }))}
                    className="flex-1 rounded-lg outline-none text-xs" style={{ padding: '6px 8px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
                    <option value="">— nobody —</option>
                    {staff.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                  </select>
                </label>
                <label className="flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  <Bell size={12} /> Remind
                  <input type="datetime-local" value={editDraft.remind_at} onChange={e => setEditDraft(d => ({ ...d, remind_at: e.target.value }))}
                    className="flex-1 rounded-lg outline-none text-xs" style={{ padding: '6px 8px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
                </label>
              </div>
              <div className="flex items-center justify-end gap-2">
                <button onClick={() => setEditId(null)} className="text-[11px] font-semibold px-2.5 py-1.5 rounded-lg" style={{ color: 'var(--text-muted)' }}>Cancel</button>
                <button onClick={saveEdit} disabled={!editDraft.title.trim() || edit.isPending}
                  className="flex items-center gap-1 text-[11px] font-bold px-2.5 py-1.5 rounded-lg disabled:opacity-40" style={{ background: PROJECT_ACCENT, color: '#fff' }}>
                  <Check size={12} /> Save
                </button>
              </div>
            </li>
          ) : (
            <li key={n.id} className="rounded-xl p-3 group" style={{ background: 'var(--bg-input)' }}>
              <div className="flex items-start gap-2">
                <span className="flex-1 min-w-0">
                  <span className="block text-xs font-bold" style={{ color: 'var(--text-h)' }}>{n.title}</span>
                  {n.content && <div className="proj-note-html text-xs mt-1" style={{ color: 'var(--text-body)' }} dangerouslySetInnerHTML={{ __html: n.content }} />}
                  {/* Assignee + reminder badges */}
                  {(n.assignee || n.remind_at) && (
                    <span className="flex flex-wrap items-center gap-1.5 mt-1.5">
                      {n.assignee && <span className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full" style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 14%, transparent)`, color: PROJECT_ACCENT }}><UserPlus size={9} />{n.assignee.name}</span>}
                      {n.remind_at && <span className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full" style={{ background: 'color-mix(in srgb, var(--color-warning-500) 16%, transparent)', color: 'var(--color-warning-600)' }}><Bell size={9} />{fmtDateTime(n.remind_at)}</span>}
                    </span>
                  )}
                  {/* Attachments */}
                  {(n.attachments || []).length > 0 && (
                    <span className="flex flex-col gap-1 mt-1.5">
                      {n.attachments.map(a => (
                        <span key={a.id} className="inline-flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                          <Paperclip size={10} />
                          <button onClick={() => projectApi.downloadNoteAttachment(projectId, a.id, a.original_name)} className="hover:underline" style={{ color: 'var(--text-body)' }}>{a.original_name}</button>
                          <button onClick={() => removeAtt.mutate(a.id)} aria-label="Remove attachment" className="hover:opacity-60"><X size={10} style={{ color: 'var(--color-danger-500)' }} /></button>
                        </span>
                      ))}
                    </span>
                  )}
                  <span className="block text-[10px] mt-1.5" style={{ color: 'var(--text-muted)' }}>
                    {n.author?.name || 'Someone'} · {fmtDateTime(n.created_at)}
                  </span>
                </span>
                <div className="flex items-center gap-1.5 shrink-0">
                  <label aria-label="Attach file" className="hover:opacity-60 cursor-pointer" title="Attach file">
                    <Paperclip size={12} style={{ color: 'var(--text-muted)' }} />
                    <input type="file" hidden onChange={e => { const f = e.target.files?.[0]; if (f) upload.mutate({ noteId: n.id, file: f }); e.target.value = '' }} />
                  </label>
                  <button onClick={() => startEdit(n)} aria-label="Edit note" className="hover:opacity-60"><Pencil size={12} style={{ color: 'var(--text-muted)' }} /></button>
                  <button onClick={() => printNote(n)} aria-label="Print note" className="hover:opacity-60"><Printer size={12} style={{ color: 'var(--text-muted)' }} /></button>
                  <button onClick={() => setConfirmDelete(n)} aria-label="Delete note" className="hover:opacity-60"><Trash2 size={12} style={{ color: 'var(--color-danger-500)' }} /></button>
                </div>
              </div>
            </li>
          )
        ))}
        {notes.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No notes yet.</li>}
        {notes.length > 0 && filtered.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No notes match “{search}”.</li>}
      </ul>

      <ConfirmModal open={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)}
        onConfirm={() => del.mutate(confirmDelete.id)}
        title="Delete this note?" message={`“${confirmDelete?.title}” will be removed.`} confirmLabel="Delete" danger />
    </section>
  )
}

/* ── Activity ─────────────────────────────────────────────────── */

const ACTIVITY_DOT = {
  project_created: 'var(--color-primary-500)',
  status_changed:  'var(--color-info-500)',
  member_added:    'var(--color-success-500)',
  note_added:      'var(--color-warning-500)',
}

export function ActivityTab({ projectId }) {
  const { data: activity = [], isLoading } = useQuery({ queryKey: ['project-activity', projectId], queryFn: () => projectApi.activity(projectId) })
  if (isLoading) return <Skeleton />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        <Activity size={14} style={{ color: PROJECT_ACCENT }} /> Activity
      </h2>
      <ul className="space-y-0">
        {activity.map((a, i) => (
          <li key={a.id} className="flex gap-3 pb-3">
            <div className="flex flex-col items-center">
              <span className="w-2 h-2 rounded-full mt-1 shrink-0" style={{ background: ACTIVITY_DOT[a.type] || 'var(--text-muted)' }} />
              {i < activity.length - 1 && <span className="w-px flex-1 mt-1" style={{ background: 'var(--border)' }} />}
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-xs" style={{ color: 'var(--text-h)' }}>{a.description}</p>
              <p className="text-[10px] mt-0.5 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
                {a.actor?.name || 'System'} · {fmtDateTime(a.created_at)}
                {a.visible_to_customer && <span className="inline-flex items-center gap-0.5"><Eye size={9} /> customer-visible</span>}
              </p>
            </div>
          </li>
        ))}
        {activity.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No activity recorded yet.</li>}
      </ul>
    </section>
  )
}

/* ── Expenses ─────────────────────────────────────────────────── */

const todayStr = () => new Date().toISOString().split('T')[0]
const EMPTY_EXPENSE = () => ({ title: '', category: '', amount: '', expense_date: todayStr(), note: '', billable: false })

export function ExpensesTab({ projectId }) {
  const qc = useQueryClient()
  const [form, setForm] = useState(EMPTY_EXPENSE())
  const [editId, setEditId] = useState(null)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const { data, isLoading } = useQuery({ queryKey: ['project-expenses', projectId], queryFn: () => projectApi.expenses(projectId) })
  const rows = data?.rows || []
  const total = data?.total || 0
  const bust = () => qc.invalidateQueries({ queryKey: ['project-expenses', projectId] })
  const reset = () => { setEditId(null); setForm(EMPTY_EXPENSE()) }
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, amount: Number(form.amount) || 0 }
      return editId ? projectApi.updateExpense(projectId, editId, payload) : projectApi.addExpense(projectId, payload)
    },
    onSuccess: () => { reset(); bust() },
  })
  const del = useMutation({ mutationFn: (eid) => projectApi.deleteExpense(projectId, eid), onSuccess: () => { setConfirmDelete(null); bust() } })
  const startEdit = (e) => { setEditId(e.id); setForm({ title: e.title || '', category: e.category || '', amount: e.amount ?? '', expense_date: e.expense_date ? String(e.expense_date).split('T')[0] : todayStr(), note: e.note || '', billable: !!e.billable }) }
  const doExport = () => exportCsv(stampedName(`project-${projectId}-expenses`), rows, [
    { key: 'expense_date', label: 'Date', value: r => String(r.expense_date).split('T')[0] },
    { key: 'title', label: 'Title' },
    { key: 'category', label: 'Category' },
    { key: 'amount', label: 'Amount' },
    { key: 'billable', label: 'Billable', value: r => r.billable ? 'Yes' : 'No' },
    { key: 'note', label: 'Note' },
  ])

  const INP = { width: '100%', padding: '8px 10px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }
  if (isLoading) return <Skeleton />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-3 flex-wrap">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <Receipt size={14} style={{ color: PROJECT_ACCENT }} /> Expenses
        </h2>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
          ₹{Number(total).toLocaleString('en-IN')} across {rows.length} {rows.length === 1 ? 'entry' : 'entries'}
        </span>
        {rows.length > 0 && (
          <button onClick={doExport} className="ml-auto flex items-center gap-1 text-[10px] font-bold" style={{ color: PROJECT_ACCENT }}>
            <Download size={11} /> Export
          </button>
        )}
      </div>

      {/* Add / edit form */}
      <form onSubmit={e => { e.preventDefault(); if (form.title.trim() && form.expense_date) save.mutate() }}
        className="rounded-xl p-3 mb-4" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
          <input value={form.title} onChange={e => sf('title', e.target.value)} placeholder="Title *" style={INP} className="col-span-2" />
          <input value={form.category} onChange={e => sf('category', e.target.value)} placeholder="Category" style={INP} />
          <div className="relative">
            <IndianRupee size={13} style={{ position: 'absolute', left: 9, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
            <input type="number" min="0" step="0.01" value={form.amount} onChange={e => sf('amount', e.target.value)} placeholder="Amount *" style={{ ...INP, paddingLeft: 26 }} />
          </div>
          <input type="date" value={form.expense_date} onChange={e => sf('expense_date', e.target.value)} style={INP} />
          <input value={form.note} onChange={e => sf('note', e.target.value)} placeholder="Note (optional)" style={INP} className="col-span-2 sm:col-span-3" />
        </div>
        <div className="flex items-center justify-between mt-2">
          <label className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-body)', cursor: 'pointer' }}>
            <input type="checkbox" checked={form.billable} onChange={e => sf('billable', e.target.checked)} style={{ accentColor: PROJECT_ACCENT, width: 14, height: 14 }} />
            Billable
          </label>
          <div className="flex items-center gap-2">
            {editId && <button type="button" onClick={reset} className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>Cancel</button>}
            <button type="submit" disabled={!form.title.trim() || !form.amount || save.isPending}
              className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: PROJECT_ACCENT, color: '#fff' }}>
              <Plus size={12} /> {editId ? 'Save expense' : 'Add expense'}
            </button>
          </div>
        </div>
      </form>

      {rows.length === 0 && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No expenses logged yet.</p>}
      {rows.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-xs" style={{ minWidth: 560 }}>
            <thead>
              <tr className="text-left text-[10px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                <th className="px-2 py-2 font-bold">Date</th>
                <th className="px-2 py-2 font-bold">Title</th>
                <th className="px-2 py-2 font-bold">Category</th>
                <th className="px-2 py-2 font-bold text-right">Amount</th>
                <th className="px-2 py-2 font-bold" />
              </tr>
            </thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} className="group" style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-2 py-2 tabular-nums" style={{ color: 'var(--text-muted)' }}>{String(r.expense_date).split('T')[0]}</td>
                  <td className="px-2 py-2" style={{ color: 'var(--text-h)' }}>
                    {r.title}
                    {r.billable && <span className="ml-1.5 text-[9px] font-bold px-1 py-0.5 rounded" style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 14%, transparent)`, color: PROJECT_ACCENT }}>billable</span>}
                    {r.note && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.note}</span>}
                  </td>
                  <td className="px-2 py-2" style={{ color: 'var(--text-muted)' }}>{r.category || '—'}</td>
                  <td className="px-2 py-2 text-right tabular-nums font-semibold" style={{ color: 'var(--text-h)' }}>₹{Number(r.amount).toLocaleString('en-IN')}</td>
                  <td className="px-2 py-2 text-right whitespace-nowrap">
                    <button onClick={() => startEdit(r)} className="hover:opacity-60 mr-2" aria-label="Edit expense"><Pencil size={12} style={{ color: 'var(--text-muted)' }} /></button>
                    <button onClick={() => setConfirmDelete(r)} className="hover:opacity-60" aria-label="Delete expense"><Trash2 size={12} style={{ color: 'var(--color-danger-500)' }} /></button>
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={3} className="px-2 py-2 text-right text-[11px] font-bold" style={{ color: 'var(--text-muted)' }}>Total</td>
                <td className="px-2 py-2 text-right tabular-nums font-black" style={{ color: 'var(--text-h)' }}>₹{Number(total).toLocaleString('en-IN')}</td>
                <td />
              </tr>
            </tfoot>
          </table>
        </div>
      )}

      <ConfirmModal open={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)}
        onConfirm={() => del.mutate(confirmDelete.id)}
        title="Delete this expense?" message={`“${confirmDelete?.title}” will be removed.`} confirmLabel="Delete" danger />
    </section>
  )
}

/* ── Vendors / TPV ────────────────────────────────────────────── */

export function VendorTab({ project }) {
  const projectId = project.id
  const filters = { rel_type: 'project', rel_id: projectId, include_subtasks: 1 }
  const { data: tasks = [] } = useQuery({ queryKey: ['tasks', filters], queryFn: () => taskApi.list(filters) })
  const { data: vendors = [] } = useQuery({ queryKey: ['task-vendors', 'vendor'], queryFn: () => taskApi.vendors('vendor') })
  const { data: tpvs = [] } = useQuery({ queryKey: ['task-vendors', 'tpv'], queryFn: () => taskApi.vendors('tpv') })

  const dir = useMemo(() => {
    const m = {}
    vendors.forEach(v => { m[v.id] = { ...v, kind: 'vendor' } })
    tpvs.forEach(t => { m[t.id] = { ...t, kind: 'tpv' } })
    return m
  }, [vendors, tpvs])

  // Group this project's tasks by the vendor / TPV they're assigned to.
  const groups = useMemo(() => {
    const g = {}
    tasks.forEach(t => (t.assignees || []).forEach(a => {
      const p = dir[a.user_id]
      if (!p) return
      if (!g[a.user_id]) g[a.user_id] = { person: p, tasks: [] }
      g[a.user_id].tasks.push(t)
    }))
    return Object.values(g)
  }, [tasks, dir])

  const linked = project.vendor
  const kindLabel = project.link_type === 'tpv' ? 'Third-party vendor' : 'Vendor'

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        <HardHat size={14} style={{ color: PROJECT_ACCENT }} /> Vendors / Third-party vendors
      </h2>

      {/* The party this project is linked to */}
      {linked ? (
        <div className="rounded-xl p-3 mb-4 flex items-center gap-3" style={{ background: 'var(--bg-input)', border: `1px solid ${PROJECT_ACCENT}` }}>
          <span className="w-9 h-9 rounded-full flex items-center justify-center shrink-0" style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 16%, transparent)`, color: PROJECT_ACCENT }}>
            <Building2 size={16} />
          </span>
          <div className="min-w-0">
            <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{linked.name}</p>
            <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Linked {kindLabel}{linked.email ? ` · ${linked.email}` : ''}</p>
          </div>
        </div>
      ) : (
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>This project isn't linked to a vendor or third-party vendor.</p>
      )}

      {/* Everyone external working on the project's tasks */}
      <p className="text-[10px] font-bold uppercase tracking-wide mb-2" style={{ color: 'var(--text-muted)' }}>Working on this project</p>
      {groups.length === 0 && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No vendors or third-party vendors are assigned to this project's tasks yet.</p>}
      <ul className="space-y-2">
        {groups.map(({ person, tasks: ts }) => (
          <li key={person.id} className="rounded-xl p-3" style={{ background: 'var(--bg-input)' }}>
            <div className="flex items-center gap-2 mb-1.5">
              <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{person.name}</span>
              <span className="text-[9px] font-bold px-1.5 py-0.5 rounded-full" style={{ background: 'var(--bg-card)', color: person.kind === 'tpv' ? 'var(--color-warning-600)' : PROJECT_ACCENT }}>
                {person.kind === 'tpv' ? 'TPV' : 'Vendor'}
              </span>
              <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{ts.length} task{ts.length === 1 ? '' : 's'}</span>
            </div>
            <ul className="space-y-0.5">
              {ts.map(t => (
                <li key={t.id} className="text-[11px] flex items-center gap-1.5" style={{ color: 'var(--text-body)' }}>
                  <span className="w-1 h-1 rounded-full" style={{ background: 'var(--text-muted)' }} /> {t.name}
                  <span className="text-[9px] capitalize" style={{ color: 'var(--text-muted)' }}>· {String(t.status || '').replace(/_/g, ' ')}</span>
                </li>
              ))}
            </ul>
          </li>
        ))}
      </ul>
    </section>
  )
}

const Skeleton = () => <div className="rounded-2xl animate-pulse" style={{ height: 160, background: 'var(--bg-card)' }} />
