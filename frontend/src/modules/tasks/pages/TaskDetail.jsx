import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import ReactQuill from 'react-quill'
import 'react-quill/dist/quill.snow.css'
import { RICH_MODULES, RICH_FORMATS } from '@/lib/quillConfig'
import {
  ArrowLeft, Users, Eye, CheckSquare, Square, MessageSquare, Play, StopCircle,
  Clock, Pencil, Trash2, ExternalLink, Send, Plus, Copy, RefreshCw, BookmarkPlus, ListPlus,
  Lock, Globe, LifeBuoy, Info, Link2, X, EyeOff, FileText, Paperclip, Download, GitBranch, Building2,
} from 'lucide-react'
import SubtaskTree from '../components/SubtaskTree'
import RaiseTicketModal from '../../helpdesk/components/RaiseTicketModal'
import { tpvApi } from '@/services/tpvApi'
import { purchaseApi } from '@/services/purchaseApi'
import { taskApi, TASK_STATUS, TASK_PRIORITY, TASK_ACCENT, relLabel, fmtDuration } from '@/services/taskApi'
import Select from '@/components/ui/Select'
import SearchPicker, { InputModal } from '@/components/ui/SearchPicker'
import { useAuth } from '@/context/AuthContext'
import { useStatuses, statusOptions } from '@/hooks/useStatuses'
import TaskFormDrawer, { PeopleChips } from '../components/TaskFormDrawer'
import { FilesCard, RemindersCard } from '../components/TaskExtras'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const initials = (name) => name ? name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) : '–'

// Rich-text comment composer — mirrors the helpdesk reply editor (TicketThread's
// REPLY_MODULES/REPLY_FORMATS), trimmed to what a task comment needs. The value is
// HTML, sanitized server-side (TaskService::addComment → HtmlSanitizer::clean)
// before it is stored or rendered.
// Full "notepad" toolbar for the description + comment editors, shared with the
// create form so every task editor offers the same options.
const COMMENT_MODULES = RICH_MODULES
const COMMENT_FORMATS = RICH_FORMATS

// Quill never leaves its editor truly empty — an untouched editor still holds
// `<p><br></p>`. Treat that (and any tag-only/whitespace-only HTML) as blank so a
// bare comment can't be posted.
const isCommentEmpty = (html) => {
  if (!html) return true
  const text = html.replace(/<[^>]*>/g, '').replace(/&nbsp;/gi, ' ').replace(/​/g, '').trim()
  return text.length === 0
}

// Repaints Quill's hard-coded light skin from design tokens so the composer follows
// light/dark, plus prose styles for rendering stored (server-sanitized) comment HTML.
const COMMENT_EDITOR_CSS = `
  /* overflow is VISIBLE (not hidden) so Quill's link-URL popup isn't clipped; the
     toolbar carries the top rounded corners instead so the frame still looks clean. */
  .task-comment-editor{border:1px solid var(--border);border-radius:14px;overflow:visible;background:var(--bg-input)}
  .task-comment-editor:focus-within{border-color:var(--color-primary-500);box-shadow:0 0 0 3px color-mix(in srgb,var(--color-primary-500) 18%,transparent)}
  .task-comment-editor .ql-toolbar.ql-snow{border:0;border-bottom:1px solid var(--border);background:var(--bg-card);padding:8px 12px;border-radius:14px 14px 0 0}
  .task-comment-editor .ql-toolbar.ql-snow .ql-formats{margin-right:12px}
  .task-comment-editor .ql-container.ql-snow{border:0;background:transparent;font-family:inherit;font-size:13px;border-radius:0 0 14px 14px}
  .task-comment-editor .ql-editor{min-height:110px;max-height:360px;overflow-y:auto;color:var(--text-h);line-height:1.6;padding:12px 14px}
  /* The Description editor gets a taller canvas than the inline comment box. */
  .task-comment-editor.task-desc .ql-editor{min-height:260px;max-height:560px}
  .task-comment-editor .ql-snow .ql-tooltip{white-space:normal}
  .task-comment-editor .ql-editor p{margin-bottom:6px}
  .task-comment-editor .ql-editor.ql-blank::before{color:var(--text-muted);opacity:.6;font-style:normal;left:14px;right:14px}
  .task-comment-editor .ql-editor a{color:var(--color-primary-500)}
  .task-comment-editor .ql-editor pre.ql-syntax{background:var(--bg-global);color:var(--text-body);border:1px solid var(--border);border-radius:8px}
  .task-comment-editor .ql-snow .ql-stroke{stroke:var(--text-muted)}
  .task-comment-editor .ql-snow .ql-fill{fill:var(--text-muted)}
  .task-comment-editor .ql-snow button:hover .ql-stroke,.task-comment-editor .ql-snow button.ql-active .ql-stroke{stroke:var(--color-primary-500)}
  .task-comment-editor .ql-snow button:hover .ql-fill,.task-comment-editor .ql-snow button.ql-active .ql-fill{fill:var(--color-primary-500)}
  .task-comment-editor .ql-snow .ql-tooltip{background:var(--bg-card);border:1px solid var(--border);box-shadow:var(--shadow-card-3d);color:var(--text-body);border-radius:10px;z-index:20}
  .task-comment-editor .ql-snow .ql-tooltip input[type=text]{background:var(--bg-input);border:1px solid var(--border);color:var(--text-h);border-radius:6px}
  .task-comment-editor .ql-snow .ql-tooltip a.ql-action,.task-comment-editor .ql-snow .ql-tooltip a.ql-remove{color:var(--color-primary-500)}
  /* Rendered comment bodies (server-sanitized HTML) */
  .task-comment-html{word-break:break-word}
  .task-comment-html p{margin:0 0 .4rem}
  .task-comment-html p:last-child{margin-bottom:0}
  .task-comment-html ul,.task-comment-html ol{margin:.3rem 0 .4rem;padding-left:1.3rem}
  .task-comment-html ul{list-style:disc}
  .task-comment-html ol{list-style:decimal}
  .task-comment-html a{color:var(--color-primary-500);text-decoration:underline;word-break:break-word}
  .task-comment-html code{background:rgba(148,163,184,0.18);padding:.1rem .3rem;border-radius:4px;font-size:.9em}
  .task-comment-html pre{background:var(--bg-global);border:1px solid var(--border);border-radius:8px;padding:.5rem .7rem;overflow-x:auto;white-space:pre-wrap}
  .task-comment-html blockquote{border-left:3px solid var(--color-primary-500);margin:.4rem 0;padding:.2rem .8rem;opacity:.9}
  .task-comment-html img{max-width:100%;height:auto;border-radius:8px;margin:.3rem 0}
  .task-comment-html h2{font-size:1.15rem;font-weight:800;margin:.5rem 0 .3rem}
  .task-comment-html h3{font-size:1.02rem;font-weight:700;margin:.4rem 0 .3rem}
`

export default function TaskDetail({ idProp = null, onClose = null }) {
  const params = useParams()
  const id = idProp ?? params.id
  const embedded = Boolean(onClose)
  const navigate = useNavigate()
  const { user } = useAuth()
  const qc = useQueryClient()

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['task', id] })
    qc.invalidateQueries({ queryKey: ['tasks'] })
  }
  const invalidateTime = () => { invalidate(); qc.invalidateQueries({ queryKey: ['task-time', id] }) }

  const { data: task, isLoading, isError, error } = useQuery({ queryKey: ['task', id], queryFn: () => taskApi.get(id) })
  const { data: time } = useQuery({ queryKey: ['task-time', id], queryFn: () => taskApi.totalTime(id) })
  const { data: staff = [] } = useQuery({ queryKey: ['task-staff'], queryFn: taskApi.staff })
  // Vendors & TPVs can own a checklist item too (all are Users) — pull them so a
  // line can be handed to anyone, and so a chip resolves whoever it is.
  const { data: vendors = [] } = useQuery({ queryKey: ['task-vendors', 'vendor'], queryFn: () => taskApi.vendors('vendor') })
  const { data: tpvs = [] } = useQuery({ queryKey: ['task-vendors', 'tpv'], queryFn: () => taskApi.vendors('tpv') })
  const people = useMemo(() => [...staff, ...vendors, ...tpvs], [staff, vendors, tpvs])
  const peopleById = useMemo(() => Object.fromEntries(people.map(p => [p.id, p])), [people])
  // Split one assignee list into staff / vendors / TPVs for display (they all live
  // in the same task_assignees pivot — only their role tells them apart).
  const staffIds = useMemo(() => new Set(staff.map(s => s.id)), [staff])
  const vendorIds = useMemo(() => new Set(vendors.map(v => v.id)), [vendors])
  const tpvIds = useMemo(() => new Set(tpvs.map(t => t.id)), [tpvs])
  const { map: statusMap, list: statusList } = useStatuses('task')

  const [editing, setEditing] = useState(false)
  const [raising, setRaising] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [picker, setPicker] = useState(null)      // 'assignee' | 'follower' | 'template'
  const [assignItemId, setAssignItemId] = useState(null)  // checklist item being (re)assigned
  const [savingTpl, setSavingTpl] = useState(false)
  const [newItem, setNewItem] = useState('')
  const [newSubtask, setNewSubtask] = useState('')
  const [comment, setComment] = useState('')
  const [commentFiles, setCommentFiles] = useState([])
  const commentFileInput = useRef(null)
  const [actionErr, setActionErr] = useState('')
  const [hideCompleted, setHideCompleted] = useState(false)
  const [editingDesc, setEditingDesc] = useState(false)
  const [descDraft, setDescDraft] = useState('')

  const onErr = (e) => setActionErr(e?.message || 'That action failed.')
  const mut = (fn, after = invalidate) => ({ mutationFn: fn, onSuccess: () => { setActionErr(''); after() }, onError: onErr })

  const setStatus   = useMutation(mut((s) => taskApi.setStatus(id, s)))
  const togglePublic = useMutation(mut((next) => taskApi.update(id, { is_public: next })))
  const syncAssign  = useMutation(mut((ids) => taskApi.assignees(id, ids)))
  const syncFollow  = useMutation(mut((ids) => taskApi.followers(id, ids)))
  // Linking a vendor is a plain task update: rel_type + rel_id. Clearing sends
  // 'standalone' so the backend nulls rel_id rather than leaving a dangling link.
  const setVendorLink = useMutation(mut((payload) => taskApi.update(id, payload)))
  const addItem     = useMutation(mut((desc) => taskApi.addChecklist(id, desc)))
  const toggleItem  = useMutation(mut((iid) => taskApi.toggleChecklist(iid)))
  // (Re)assign a single checklist line to a person, or clear it (userId = null).
  const assignItem  = useMutation(mut(({ itemId, userId }) => taskApi.updateChecklistItem(itemId, { assigned_to: userId })))
  // Subtasks. Every write invalidates the tree AND the task itself, because
  // ticking a leaf five levels down changes the bar at the top of this modal.
  const afterTree = () => { invalidate(); qc.invalidateQueries({ queryKey: ['task-tree', id] }) }
  const addSubtask   = useMutation(mut(({ parentId, name }) => taskApi.addSubtask(parentId, { name }), afterTree))
  const toggleSubtask = useMutation(mut(
    ({ nodeId, next }) => taskApi.setStatus(nodeId, next), afterTree,
  ))
  const addComment  = useMutation(mut(({ html, files }) => taskApi.addComment(id, html, files)))
  const saveDesc    = useMutation(mut((html) => taskApi.update(id, { description: html }), () => { invalidate(); setEditingDesc(false) }))
  const startTimer  = useMutation(mut(() => taskApi.startTimer(id), invalidateTime))
  const stopTimer   = useMutation(mut(() => taskApi.stopTimer(id), invalidateTime))
  const applyTpl    = useMutation(mut((tid) => taskApi.applyTemplate(id, tid)))
  const saveTpl     = useMutation({
    mutationFn: (name) => taskApi.saveChecklistAsTemplate(id, name),
    onSuccess: () => { setActionErr(''); qc.invalidateQueries({ queryKey: ['task-templates'] }) },
    onError: onErr,
  })
  const remove      = useMutation({
    mutationFn: () => taskApi.remove(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['tasks'] }); embedded ? onClose?.() : navigate('/app/tasks') },
    onError: onErr,
  })
  const copy        = useMutation({
    mutationFn: () => taskApi.copy(id, { copy_checklist: true, copy_assignees: true, copy_followers: true }),
    onSuccess: (t) => { qc.invalidateQueries({ queryKey: ['tasks'] }); navigate(`/app/tasks/${t.id}`) },
    onError: onErr,
  })

  const { data: templates = [] } = useQuery({ queryKey: ['task-templates'], queryFn: taskApi.templates })

  // The whole subtask tree in one request — the server carries root_id, so depth
  // costs nothing here and there's no per-level fetching to orchestrate.
  const { data: subtree } = useQuery({ queryKey: ['task-tree', id], queryFn: () => taskApi.tree(id) })

  // ── Vendor link ──────────────────────────────────────────────────────────
  // These three MUST sit above the isLoading/isError returns below. They used to
  // live further down, next to the markup that uses them, which meant the first
  // render (task still loading) bailed out before reaching them and the next one
  // ran three extra hooks — "Rendered more hooks than during the previous
  // render". It only reproduced on a cold load: with the task already in the
  // React Query cache, isLoading is false on the very first render and the count
  // never changes, which is why a refresh appeared to fix it.
  //
  // None of them depend on `task`, so hoisting them changes no behaviour.
  // `isVendorLinked` DOES read task.rel_type, so it stays below the guard.
  //
  // 'vendor-link', not 'vendor': master added an ASSIGN-a-vendor picker on the
  // same key while this branch added the LINK-a-vendor one. Sharing a key opened
  // both modals at once — they are different actions (assignee pivot vs the
  // task's rel_type/rel_id), so they get different keys.
  const vendorPickerOpen = picker === 'vendor-link'
  // Lists load only while the picker is open, so opening a task never fetches
  // two vendor rosters.
  const { data: pvList = [], isLoading: pvLoading } = useQuery({
    queryKey: ['task-link-purchase-vendors'], queryFn: () => purchaseApi.vendors.list(), enabled: vendorPickerOpen,
  })
  const { data: tvList = [], isLoading: tvLoading } = useQuery({
    queryKey: ['task-link-tpv-vendors'], queryFn: () => tpvApi.vendors.list(), enabled: vendorPickerOpen,
  })
  const vendorPickerItems = useMemo(() => {
    const rows = (x) => (Array.isArray(x) ? x : x?.data ?? [])
    // SearchPicker keys on `id`, but the two modules have overlapping ids — a
    // prefixed key keeps them distinct while realId/relType carry what to save.
    return [
      ...rows(tvList).map(v => ({ id: `tpv-${v.id}`, realId: v.id, relType: 'tpv_vendor',
        label: v.company_name || v.name, sublabel: `TPV · ${v.vendor_code || v.status || ''}`.trim() })),
      ...rows(pvList).map(v => ({ id: `pur-${v.id}`, realId: v.id, relType: 'purchase_vendor',
        label: v.company_name || v.name, sublabel: `Purchase · ${v.purchase_vendor_code || v.status || ''}`.trim() })),
    ]
  }, [tvList, pvList])

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 200, background: 'var(--bg-card)' }} />
  if (isError) {
    return (
      <div className="p-6 rounded-2xl" style={{ border: '1px solid color-mix(in srgb, var(--color-danger-500) 30%, transparent)', background: 'var(--bg-card)' }}>
        <p className="text-sm" style={{ color: 'var(--color-danger-500)' }}>{error?.message}</p>
        <button onClick={() => navigate('/app/tasks')} className="text-xs mt-3 underline" style={{ color: 'var(--text-muted)' }}>Back to tasks</button>
      </div>
    )
  }

  // Guarded: an unrecognised status/priority used to throw on .color here.
  const st = statusMap[task.status] || { label: task.status, color: 'var(--text-muted)' }
  const pr = TASK_PRIORITY[task.priority] || 'var(--text-muted)'
  const assignees = task.assignees || []
  const followers = task.followers || []
  // The API serialises the relation snake_cased (checklist_items); reading the
  // camelCase key left the checklist permanently empty even when items existed.
  const checklist = task.checklist_items || task.checklistItems || []
  const comments = task.comments || []
  const myTimer = (task.timers || []).find(t => !t.end_time && t.user_id === user?.id)
  const link = relLabel(task)
  const subtasks = subtree?.children || []
  const ancestry = task.ancestry || []
  // The bar now comes from the SERVER's roll-up, which counts checklist ticks and
  // every leaf of the subtask tree together. Computing it here from the checklist
  // alone would ignore work nested below and report a number the project page
  // disagrees with. Falls back to local maths until the tree request lands.
  const rolled = subtree?.progress || task.progress
  const done = rolled ? rolled.done : checklist.filter(c => c.finished).length
  const total = rolled ? rolled.total : checklist.length
  const pct = rolled ? rolled.percent : (checklist.length ? Math.round((done / checklist.length) * 100) : 0)
  // "Hide completed items" filters what's rendered; the progress bar + count above
  // stay based on ALL items so the ratio still reflects the whole checklist.
  const visibleChecklist = hideCompleted ? checklist.filter(c => !c.finished) : checklist

  const isPublic = Boolean(task.is_public)
  const assigneeIds = assignees.map(a => a.user_id)

  // ── Vendor link ──────────────────────────────────────────────────────────
  // A task relates to ONE vendor via rel_type/rel_id. Lists load only while the
  // picker is open so opening a task never fetches two vendor rosters.
  const isVendorLinked = ['tpv_vendor', 'purchase_vendor'].includes(task.rel_type) && !!task.rel_id
  const followerIds = followers.map(f => f.user_id)

  const submitComment = () => {
    const html = comment
    // A comment can be text, files, or both.
    if (isCommentEmpty(html) && commentFiles.length === 0) return
    addComment.mutate({ html, files: commentFiles })
    setComment('')
    setCommentFiles([])
  }
  const stageCommentFiles = (list) => {
    const picked = Array.from(list || [])
    if (picked.length) setCommentFiles(prev => [...prev, ...picked].slice(0, 10))
  }

  return (
    <div className={embedded ? '' : 'max-w-4xl mx-auto'}>
      {!embedded && (
        <button onClick={() => navigate('/app/tasks')} className="flex items-center gap-1.5 text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
          <ArrowLeft size={13} /> Back to tasks
        </button>
      )}

      {/* ── Gradient header ─────────────────────────────────────── */}
      <div className={`flex items-start justify-between gap-3 flex-wrap px-5 py-4 ${embedded ? '' : 'rounded-2xl'}`}
        style={{ background: 'linear-gradient(120deg, var(--color-primary-600), var(--color-primary-500))' }}>
        <div className="min-w-0">
          {/* Where this sits in the tree. Only shown for a subtask — on a
              top-level task the trail would just repeat the title below it. */}
          {ancestry.length > 1 && (
            <p className="flex items-center gap-1 flex-wrap mb-1" style={{ fontSize: 11, color: 'rgba(255,255,255,0.85)' }}>
              {ancestry.slice(0, -1).map((a, i) => (
                <span key={a.id} className="inline-flex items-center gap-1">
                  {i > 0 && <span style={{ opacity: 0.55 }}>›</span>}
                  <button onClick={() => navigate(`/app/tasks/${a.id}`)} className="hover:underline font-semibold truncate"
                    style={{ maxWidth: 180 }} title={a.name}>{a.name}</button>
                </span>
              ))}
            </p>
          )}
          <h1 className="font-black text-white truncate" style={{ fontSize: 18, letterSpacing: '-0.01em' }} title={task.name}>{task.name}</h1>
          <div className="flex items-center gap-1.5 mt-1.5 flex-wrap">
            <span className="text-[11px] font-bold px-2 py-0.5 rounded-lg" style={{ background: 'rgba(255,255,255,0.22)', color: '#fff' }}>
              {st.label}
            </span>
            <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-lg" style={{ background: 'rgba(255,255,255,0.14)', color: '#fff' }}>
              {isPublic ? <Globe size={11} /> : <Lock size={11} />}{isPublic ? 'Public' : 'Private'}
            </span>
            <span className="inline-flex items-center gap-1 text-[11px] font-bold" style={{ color: '#fff', opacity: 0.92 }}>
              <span className="w-2 h-2 rounded-full" style={{ background: pr, boxShadow: '0 0 0 2px rgba(255,255,255,0.4)' }} />
              {task.priority}
            </span>
            <span className="text-[11px]" style={{ color: 'rgba(255,255,255,0.85)' }}>· Due {fmtDate(task.due_date)}</span>
            {task.billable && (
              <span className="text-[11px] font-bold px-1.5 py-0.5 rounded-md" style={{ background: 'rgba(255,255,255,0.16)', color: '#fff' }}>Billable</span>
            )}
            {task.recurring && (
              <span className="inline-flex items-center gap-1 text-[11px] font-bold px-1.5 py-0.5 rounded-md" style={{ background: 'rgba(255,255,255,0.16)', color: '#fff' }}>
                <RefreshCw size={9} /> Every {task.repeat_every > 1 ? `${task.repeat_every} ` : ''}{task.recurring_type}
                {task.cycles > 0 && ` · ${task.total_cycles}/${task.cycles}`}
              </span>
            )}
          </div>
        </div>

        <div className="flex items-center gap-2 flex-wrap justify-end">
          <button onClick={() => togglePublic.mutate(!isPublic)} disabled={togglePublic.isPending}
            title={isPublic ? 'Make private' : 'Make public'}
            className="flex items-center gap-1.5 text-xs font-semibold px-2.5 py-2 rounded-xl disabled:opacity-40"
            style={{ background: 'rgba(255,255,255,0.16)', color: '#fff' }}>
            {isPublic ? <Globe size={13} /> : <Lock size={13} />}
            {isPublic ? 'Public' : 'Private'}
          </button>
          <div style={{ minWidth: 150 }}>
            <Select value={task.status} onChange={v => setStatus.mutate(v)} ariaLabel="Task status"
              options={statusOptions(statusList, user?.role)}
              buttonStyle={{ borderColor: st.color, color: st.color, fontWeight: 700, background: 'var(--bg-card)' }} />
          </div>
          <button onClick={() => setRaising(true)} title="Raise a support ticket for this task"
            className="flex items-center gap-1.5 text-xs font-semibold px-2.5 py-2 rounded-xl"
            style={{ background: 'rgba(255,255,255,0.16)', color: '#fff' }}>
            <LifeBuoy size={13} /> Raise Ticket
          </button>
          <HeaderIconBtn onClick={() => setEditing(true)} label="Edit task"><Pencil size={14} /></HeaderIconBtn>
          <HeaderIconBtn onClick={() => copy.mutate()} label="Duplicate task"><Copy size={14} /></HeaderIconBtn>
          <HeaderIconBtn onClick={() => setConfirmDelete(true)} label="Delete task"><Trash2 size={14} /></HeaderIconBtn>
          {embedded && (
            <button onClick={onClose} aria-label="Close" title="Close"
              className="w-9 h-9 rounded-xl flex items-center justify-center"
              style={{ background: 'rgba(255,255,255,0.22)', color: '#fff' }}>
              <X size={16} />
            </button>
          )}
        </div>
      </div>

      <div className={embedded ? 'p-4' : 'mt-4'}>
        {actionErr && (
          <p className="text-xs px-3 py-2 rounded-lg mb-3"
            style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{actionErr}</p>
        )}

        {/* ── Related project + timer row ─────────────────────────── */}
        <div className="flex flex-col sm:flex-row sm:items-center gap-3 mb-4">
          <div className="flex items-center gap-1.5 text-xs px-3 py-2.5 rounded-2xl shrink-0"
            style={{ border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)' }}>
            <Link2 size={13} style={{ color: TASK_ACCENT }} />
            {link ? (
              <>
                <span>Linked to {link}</span>
                {task.rel_url && (
                  <button onClick={() => navigate(task.rel_url)} className="inline-flex items-center gap-0.5 font-semibold hover:underline" style={{ color: TASK_ACCENT }}>
                    open <ExternalLink size={10} />
                  </button>
                )}
              </>
            ) : <span>Standalone task</span>}
            {task.is_recurring_from && (
              <span className="px-1.5 py-0.5 rounded-md" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                from #{task.is_recurring_from}
              </span>
            )}
          </div>
          <div className="flex-1 min-w-0">
            <TimerBar total={time?.total_seconds} timer={myTimer}
              onStart={() => startTimer.mutate()} onStop={() => stopTimer.mutate()}
              busy={startTimer.isPending || stopTimer.isPending} />
          </div>
        </div>

        {/* Additional "Related To" links — a task can relate to many things. */}
        {Array.isArray(task.relations) && task.relations.length > 0 && (
          <div className="flex flex-wrap items-center gap-1.5 mb-4 -mt-1">
            <span className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>Also related to:</span>
            {task.relations.map(r => (
              <button key={`${r.rel_type}:${r.rel_id}`} onClick={() => r.url && navigate(r.url)}
                className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg"
                style={{ border: '1px solid var(--border)', background: 'var(--bg-card)', color: r.url ? TASK_ACCENT : 'var(--text-muted)', cursor: r.url ? 'pointer' : 'default' }}>
                <Link2 size={10} />
                <span style={{ opacity: 0.6 }}>{r.rel_type}:</span> {r.label || `#${r.rel_id}`}
                {r.url && <ExternalLink size={9} />}
              </button>
            ))}
          </div>
        )}

        {/* ── Two-column body ─────────────────────────────────────── */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
          {/* LEFT (wider) */}
          <div className="lg:col-span-7 space-y-4">
            <Card title="Description" icon={FileText}
              action={!editingDesc && (
                <button onClick={() => { setDescDraft(task.description || ''); setEditingDesc(true) }}
                  className="flex items-center gap-1 text-[10px] font-bold" style={{ color: TASK_ACCENT }}>
                  <Pencil size={11} /> {task.description ? 'Edit' : 'Add'}
                </button>
              )}>
              {editingDesc ? (
                <div>
                  <style>{COMMENT_EDITOR_CSS}</style>
                  <div className="task-comment-editor task-desc">
                    <ReactQuill theme="snow" modules={COMMENT_MODULES} formats={COMMENT_FORMATS}
                      value={descDraft} onChange={setDescDraft} placeholder="Describe the task…" />
                  </div>
                  <div className="flex items-center justify-end gap-2 mt-2">
                    <button onClick={() => setEditingDesc(false)} className="text-xs font-semibold px-3 py-1.5 rounded-lg"
                      style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
                    <button onClick={() => saveDesc.mutate(descDraft)} disabled={saveDesc.isPending}
                      className="text-xs font-bold px-4 py-1.5 rounded-lg disabled:opacity-40" style={{ background: TASK_ACCENT, color: '#fff' }}>
                      {saveDesc.isPending ? 'Saving…' : 'Save'}
                    </button>
                  </div>
                </div>
              ) : task.description ? (
                <div className="task-comment-html text-xs break-words leading-relaxed" style={{ color: 'var(--text-body)' }}
                  dangerouslySetInnerHTML={{ __html: task.description }} />
              ) : (
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No description for this task.</p>
              )}
            </Card>

            <Card
              title={`Subtasks & checklist${total ? ` · ${done}/${total}` : ''}`}
              icon={CheckSquare}
              action={checklist.some(c => c.finished) && (
                <button onClick={() => setHideCompleted(v => !v)}
                  className="flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-lg"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: hideCompleted ? TASK_ACCENT : 'var(--text-muted)' }}>
                  {hideCompleted ? <Eye size={11} /> : <EyeOff size={11} />}
                  {hideCompleted ? 'Show completed' : 'Hide completed items'}
                </button>
              )}
            >
              {total > 0 && (
                <div className="h-1 rounded-full mb-3 overflow-hidden" style={{ background: 'var(--bg-input)' }}>
                  <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: 'var(--color-success-500)' }} />
                </div>
              )}

              {/* ── SUBTASKS — its own clearly-labelled section so users can see
                  it exists. A subtask is a real task with its own owner + deadline;
                  open it to assign staff / vendor / TPV. ── */}
              <div className="rounded-xl p-3 mb-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div className="flex items-center gap-1.5 mb-2">
                  <GitBranch size={14} style={{ color: TASK_ACCENT }} />
                  <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Subtasks</span>
                  {subtasks.length > 0 && (
                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>{subtasks.length}</span>
                  )}
                </div>
                {subtasks.length > 0 && (
                  <div className="mb-2">
                    <SubtaskTree
                      nodes={subtasks}
                      busyId={toggleSubtask.isPending ? toggleSubtask.variables?.nodeId : null}
                      onToggle={(n) => toggleSubtask.mutate({ nodeId: n.id, next: n.is_done ? 'in_progress' : 'complete' })}
                      onAddChild={(parentId, name) => addSubtask.mutate({ parentId, name })}
                      onOpen={(tid) => navigate(`/app/tasks/${tid}`)}
                    />
                  </div>
                )}
                <InlineAdd value={newSubtask} onChange={setNewSubtask} placeholder="+ Add a subtask…"
                  onSubmit={() => { const t = newSubtask.trim(); if (t) { addSubtask.mutate({ parentId: id, name: t }); setNewSubtask('') } }}
                  icon={Plus} />
                <p className="text-[10px] mt-1.5" style={{ color: 'var(--text-muted)' }}>
                  A subtask is a smaller task with its own owner &amp; deadline — <b>click it to open</b> and assign a staff member, vendor or third-party vendor.
                </p>
              </div>

              {/* ── CHECKLIST — quick ticks; each line can be handed to a person. ── */}
              <div className="flex items-center gap-1.5 mb-2">
                <CheckSquare size={14} style={{ color: TASK_ACCENT }} />
                <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Checklist</span>
              </div>
              <ul className="space-y-1.5 mb-3">
                {visibleChecklist.map(c => {
                  const owner = c.assignee?.name || peopleById[c.assigned_to]?.name
                  return (
                  <li key={c.id} className="flex items-center gap-2 group">
                    <button onClick={() => toggleItem.mutate(c.id)} className="flex items-start gap-2 text-left flex-1 min-w-0">
                      {c.finished
                        ? <CheckSquare size={14} className="mt-0.5 shrink-0" style={{ color: 'var(--color-success-500)' }} />
                        : <Square size={14} className="mt-0.5 shrink-0" style={{ color: 'var(--text-muted)' }} />}
                      <span className="text-xs" style={{ color: c.finished ? 'var(--text-muted)' : 'var(--text-h)', textDecoration: c.finished ? 'line-through' : 'none' }}>
                        {c.description}
                      </span>
                    </button>
                    <button onClick={() => setAssignItemId(c.id)}
                      className="flex items-center gap-1 text-[10px] font-semibold px-1.5 py-1 rounded-lg shrink-0 transition-opacity"
                      title={owner ? `Assigned to ${owner} — click to change` : 'Assign this item'}
                      style={owner
                        ? { background: `color-mix(in srgb, ${TASK_ACCENT} 14%, transparent)`, color: TASK_ACCENT }
                        : { border: '1px dashed var(--border)', color: 'var(--text-muted)', opacity: 0.75 }}>
                      {owner
                        ? <><span className="w-4 h-4 rounded-full flex items-center justify-center text-[8px] font-bold" style={{ background: TASK_ACCENT, color: '#fff' }}>{initials(owner)}</span>{owner.split(' ')[0]}</>
                        : <><Users size={11} /> Assign</>}
                    </button>
                  </li>
                )})}
                {checklist.length === 0 && (
                  <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No checklist items yet — add a quick tick below.</li>
                )}
                {checklist.length > 0 && visibleChecklist.length === 0 && (
                  <li className="text-xs" style={{ color: 'var(--text-muted)' }}>All items completed — nothing to show.</li>
                )}
              </ul>

              <InlineAdd value={newItem} onChange={setNewItem} placeholder="+ Add checklist item…"
                onSubmit={() => { const t = newItem.trim(); if (t) { addItem.mutate(t); setNewItem('') } }} icon={Plus} />

              <div className="flex items-center gap-2 mt-2.5 pt-2.5" style={{ borderTop: '1px solid var(--border)' }}>
                <button onClick={() => setPicker('template')} className="flex items-center gap-1 text-[10px] font-bold"
                  style={{ color: TASK_ACCENT }}>
                  <ListPlus size={11} /> Use template
                </button>
                {checklist.length > 0 && (
                  <button onClick={() => setSavingTpl(true)} className="flex items-center gap-1 text-[10px] font-bold ml-auto"
                    style={{ color: 'var(--text-muted)' }}>
                    <BookmarkPlus size={11} /> Save as template
                  </button>
                )}
              </div>
            </Card>

            <Card title={`Comments${comments.length ? ` · ${comments.length}` : ''}`} icon={MessageSquare}>
              <style>{COMMENT_EDITOR_CSS}</style>
              <ul className="space-y-3 mb-4">
                {comments.map(c => (
                  <li key={c.id} className="flex gap-2.5">
                    <span className="w-7 h-7 rounded-lg flex items-center justify-center text-[10px] font-black shrink-0"
                      style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 14%, transparent)`, color: TASK_ACCENT }}>
                      {(c.user?.name || '?').slice(0, 1).toUpperCase()}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="text-xs">
                        <span className="font-bold" style={{ color: 'var(--text-h)' }}>{c.user?.name || 'Unknown'}</span>
                        <span className="ml-2 text-[10px]" style={{ color: 'var(--text-muted)' }}>
                          {new Date(c.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                        </span>
                      </p>
                      {/* Content is sanitized server-side (TaskService::addComment →
                          HtmlSanitizer::clean) before it is stored, so rendering the
                          stored string as HTML here is safe. */}
                      {c.content && (
                        <div className="task-comment-html text-xs mt-0.5" style={{ color: 'var(--text-body)' }}
                          dangerouslySetInnerHTML={{ __html: c.content }} />
                      )}
                      {(c.attachments || []).length > 0 && (
                        <div className="flex flex-wrap gap-1.5 mt-1.5">
                          {c.attachments.map(f => (
                            <button key={f.id} onClick={() => taskApi.downloadFile(id, f.id, f.file_name)}
                              className="flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg hover:opacity-80"
                              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-body)' }}>
                              <Paperclip size={11} style={{ color: 'var(--text-muted)' }} />
                              <span className="truncate" style={{ maxWidth: 160 }}>{f.file_name}</span>
                              <Download size={11} style={{ color: TASK_ACCENT }} />
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  </li>
                ))}
                {comments.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No comments yet.</li>}
              </ul>

              <div className="task-comment-editor"
                onKeyDown={e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); submitComment() } }}>
                <ReactQuill
                  theme="snow"
                  modules={COMMENT_MODULES}
                  formats={COMMENT_FORMATS}
                  value={comment}
                  onChange={setComment}
                  placeholder="Write a comment… use @name to notify someone"
                />
              </div>
              {/* Staged attachments for the comment being written */}
              {commentFiles.length > 0 && (
                <ul className="flex flex-wrap gap-1.5 mt-2">
                  {commentFiles.map((f, i) => (
                    <li key={i} className="flex items-center gap-1.5 text-[11px] px-2 py-1 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-h)' }}>
                      <Paperclip size={11} style={{ color: 'var(--text-muted)' }} />
                      <span className="truncate" style={{ maxWidth: 150 }}>{f.name}</span>
                      <button onClick={() => setCommentFiles(prev => prev.filter((_, j) => j !== i))} aria-label={`Remove ${f.name}`}>
                        <X size={11} style={{ color: 'var(--text-muted)' }} />
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              <div className="flex items-center justify-between gap-2 mt-2">
                <div className="flex items-center gap-3">
                  <button onClick={() => commentFileInput.current?.click()} className="flex items-center gap-1 text-[11px] font-bold" style={{ color: TASK_ACCENT }}>
                    <Paperclip size={12} /> Attach
                  </button>
                  <input ref={commentFileInput} type="file" multiple hidden onChange={e => { stageCommentFiles(e.target.files); e.target.value = '' }} />
                  <span className="text-[11px]" style={{ color: 'var(--text-muted)', opacity: 0.8 }}>
                    <span className="font-semibold">@name</span> to notify · ⌘/Ctrl+↵ to post
                  </span>
                </div>
                <button onClick={submitComment} disabled={(isCommentEmpty(comment) && commentFiles.length === 0) || addComment.isPending}
                  className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40"
                  style={{ background: TASK_ACCENT, color: '#fff' }}>
                  <Send size={13} /> {addComment.isPending ? 'Posting…' : 'Comment'}
                </button>
              </div>
            </Card>
          </div>

          {/* RIGHT (narrower) */}
          <div className="lg:col-span-5 space-y-4">
            <Card title="Task Info" icon={Info}>
              <div className="space-y-1">
                <InfoRow label="Status">
                  <span className="font-bold" style={{ color: st.color }}>{st.label}</span>
                </InfoRow>
                <InfoRow label="Start Date"><span style={{ color: 'var(--text-body)' }}>{fmtDate(task.start_date)}</span></InfoRow>
                <InfoRow label="Due Date"><span style={{ color: 'var(--text-body)' }}>{fmtDate(task.due_date)}</span></InfoRow>
                <InfoRow label="Priority">
                  <span className="inline-flex items-center gap-1.5 font-semibold" style={{ color: pr }}>
                    <span className="w-2 h-2 rounded-full" style={{ background: pr }} />
                    {task.priority ? task.priority[0].toUpperCase() + task.priority.slice(1) : '—'}
                  </span>
                </InfoRow>
                <InfoRow label="Hourly Rate">
                  <span style={{ color: 'var(--text-body)' }}>{task.hourly_rate != null && task.hourly_rate !== '' ? `₹${Number(task.hourly_rate).toLocaleString('en-IN')}` : '—'}</span>
                </InfoRow>
                <InfoRow label="Billable">
                  <span style={{ color: task.billable ? 'var(--color-success-500)' : 'var(--text-muted)' }}>
                    {task.billable ? (task.billed ? 'Billed' : 'Billable (Not billed)') : 'Non-billable'}
                  </span>
                </InfoRow>
                {(time?.my_seconds != null || time?.user_seconds != null) && (
                  <InfoRow label="Your logged time">
                    <span className="tabular-nums" style={{ color: 'var(--text-body)' }}>{fmtDuration(time?.my_seconds ?? time?.user_seconds)}</span>
                  </InfoRow>
                )}
                <InfoRow label="Total logged time">
                  <span className="tabular-nums font-semibold" style={{ color: 'var(--text-h)' }}>{fmtDuration(time?.total_seconds)}</span>
                </InfoRow>
              </div>
            </Card>

            <Card title="People" icon={Users}>
              <p className="text-[10px] font-bold uppercase tracking-wide mb-1.5" style={{ color: 'var(--text-muted)' }}>Assignees (staff)</p>
              <PeopleChips ids={assigneeIds.filter(i => staffIds.has(i))} staff={people} addLabel="Assign"
                onAdd={() => setPicker('assignee')}
                onRemove={uid => syncAssign.mutate(assigneeIds.filter(i => i !== uid))} />

              <p className="text-[10px] font-bold uppercase tracking-wide mt-4 mb-1.5" style={{ color: 'var(--text-muted)' }}>Vendors</p>
              <PeopleChips ids={assigneeIds.filter(i => vendorIds.has(i))} staff={people} addLabel="Add vendor"
                onAdd={() => setPicker('vendor')}
                onRemove={uid => syncAssign.mutate(assigneeIds.filter(i => i !== uid))} />

              <p className="text-[10px] font-bold uppercase tracking-wide mt-4 mb-1.5" style={{ color: 'var(--text-muted)' }}>Third-party vendors</p>
              <PeopleChips ids={assigneeIds.filter(i => tpvIds.has(i))} staff={people} addLabel="Add TPV"
                onAdd={() => setPicker('tpv')}
                onRemove={uid => syncAssign.mutate(assigneeIds.filter(i => i !== uid))} />

              <p className="text-[10px] font-bold uppercase tracking-wide mt-4 mb-1.5" style={{ color: 'var(--text-muted)' }}>
                <Eye size={10} className="inline mr-1" />Followers
              </p>
              <PeopleChips ids={followerIds} staff={people} addLabel="Follow"
                onAdd={() => setPicker('follower')}
                onRemove={uid => syncFollow.mutate(followerIds.filter(i => i !== uid))} />
              <p className="text-[10px] mt-3" style={{ color: 'var(--text-muted)' }}>
                Vendors &amp; third-party vendors see tasks assigned to them on their portal dashboard.
              </p>

              {/* Vendor. Shown here beside the people because that is where you look
                  for "who is this task for" -- but it is NOT an assignee list. A
                  Purchase Vendor has no User account and can never be assigned, so
                  the link is the task's rel_type/rel_id, and a task carries one. */}
              <p className="text-[10px] font-bold uppercase tracking-wide mt-4 mb-1.5" style={{ color: 'var(--text-muted)' }}>
                <Building2 size={10} className="inline mr-1" />Vendor
              </p>
              <div className="flex flex-wrap items-center gap-1.5 rounded-xl px-2 py-2"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', minHeight: 44 }}>
                {isVendorLinked && (
                  <span className="flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-lg"
                    style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 14%, transparent)`, color: TASK_ACCENT }}>
                    {task.rel_label || `#${task.rel_id}`}
                    <span style={{ opacity: 0.65 }}>· {task.rel_type === 'tpv_vendor' ? 'TPV' : 'Purchase'}</span>
                    <button type="button" aria-label="Unlink vendor"
                      onClick={() => setVendorLink.mutate({ rel_type: 'standalone', rel_id: null })} className="hover:opacity-60">
                      <X size={12} />
                    </button>
                  </span>
                )}
                <button type="button" onClick={() => setPicker('vendor-link')} className="text-xs font-bold px-2 py-1 rounded-lg"
                  style={{ border: '1px dashed var(--border)', color: 'var(--text-muted)' }}>
                  + {isVendorLinked ? 'Change' : 'Link vendor'}
                </button>
              </div>
            </Card>

            <RemindersCard taskId={id} staff={staff} currentUserId={user?.id} />
            <FilesCard taskId={id} />
          </div>
        </div>
      </div>

      <TaskFormDrawer open={editing} onClose={() => setEditing(false)} task={task} onSaved={invalidate} />

      {/* Raise a helpdesk ticket for this task — linked to its project when it has one. */}
      <RaiseTicketModal open={raising} onClose={() => setRaising(false)} source="task"
        projectId={task.rel_type === 'project' ? task.rel_id : null}
        defaultSubject={`[Task] ${task.name}`}
        defaultDescription={link ? `Raised from ${link}.` : ''} />

      <DeleteTaskModal open={confirmDelete} onClose={() => setConfirmDelete(false)} onConfirm={() => remove.mutate()}
        taskName={task.name} link={link} subtaskCount={subtasks.length} busy={remove.isPending} />

      <SearchPicker
        open={picker === 'assignee'} onClose={() => setPicker(null)}
        onPick={it => it && syncAssign.mutate([...new Set([...assigneeIds, it.id])])}
        items={staff.filter(s => !assigneeIds.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.role }))}
        title="Assign to" subtitle="They'll get a notification." emptyText="Everyone is already assigned." accent={TASK_ACCENT}
      />
      <SearchPicker
        open={picker === 'vendor'} onClose={() => setPicker(null)}
        onPick={it => it && syncAssign.mutate([...new Set([...assigneeIds, it.id])])}
        items={vendors.filter(v => !assigneeIds.includes(v.id)).map(v => ({ id: v.id, label: v.name, sublabel: v.email }))}
        title="Assign a vendor" subtitle="They'll see it on their vendor portal." emptyText="No vendors available." accent={TASK_ACCENT}
      />
      <SearchPicker
        open={picker === 'tpv'} onClose={() => setPicker(null)}
        onPick={it => it && syncAssign.mutate([...new Set([...assigneeIds, it.id])])}
        items={tpvs.filter(t => !assigneeIds.includes(t.id)).map(t => ({ id: t.id, label: t.name, sublabel: t.email }))}
        title="Assign a third-party vendor" subtitle="They'll see it on their portal." emptyText="No third-party vendors available." accent={TASK_ACCENT}
      />
      <SearchPicker
        open={picker === 'follower'} onClose={() => setPicker(null)}
        onPick={it => it && syncFollow.mutate([...new Set([...followerIds, it.id])])}
        items={staff.filter(s => !followerIds.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.role }))}
        title="Add follower" subtitle="Followers get updates but aren't doing the work."
        emptyText="Everyone is already following." accent={TASK_ACCENT}
      />
      <SearchPicker
        open={picker === 'vendor-link'} onClose={() => setPicker(null)}
        onPick={it => it && setVendorLink.mutate({ rel_type: it.relType, rel_id: it.realId })}
        items={vendorPickerItems}
        loading={pvLoading || tvLoading}
        title="Link a vendor" subtitle="TPV and Purchase vendors. The task shows on that vendor's Tasks tab."
        emptyText="No vendors found." accent={TASK_ACCENT} allowClear
      />
      <SearchPicker
        open={picker === 'template'} onClose={() => setPicker(null)}
        onPick={it => it && applyTpl.mutate(it.id)}
        items={templates.map(t => ({ id: t.id, label: t.name, sublabel: `${(t.items || []).length} items` }))}
        title="Apply a checklist template" subtitle="Items are added to this task's checklist."
        emptyText="No templates yet — save a checklist as one first." accent={TASK_ACCENT}
      />
      <InputModal
        open={savingTpl} onClose={() => setSavingTpl(false)}
        onSubmit={name => saveTpl.mutate(name)}
        title="Save checklist as template"
        subtitle={`Reuse these ${checklist.length} items on any task.`}
        placeholder="e.g. Code Review Checklist" submitLabel="Save" accent={TASK_ACCENT}
      />
      <SearchPicker
        open={assignItemId !== null} onClose={() => setAssignItemId(null)}
        onPick={it => { assignItem.mutate({ itemId: assignItemId, userId: it ? it.id : null }); setAssignItemId(null) }}
        items={people.map(p => ({ id: p.id, label: p.name, sublabel: p.role || p.email }))}
        title="Assign this item" subtitle="Hand this line to a staff member, vendor or third-party vendor."
        emptyText="No people available." accent={TASK_ACCENT} allowClear
      />
    </div>
  )
}

/* ── Delete confirmation ──────────────────────────────────────── */

/**
 * A type-"delete"-to-confirm guard for a destructive action, showing what else
 * goes with the task (its link and its subtree) so the delete is never a surprise.
 * The task soft-deletes, so it can still be recovered from Tasks → Trash.
 */
function DeleteTaskModal({ open, onClose, onConfirm, taskName, link, subtaskCount = 0, busy }) {
  const [text, setText] = useState('')
  useEffect(() => { if (open) setText('') }, [open])
  if (!open) return null
  const ok = text.trim().toLowerCase() === 'delete'

  return (
    <div className="fixed inset-0 z-[80] flex items-center justify-center p-4" style={{ background: 'rgba(15,23,42,0.55)' }} onClick={onClose}>
      <div className="w-full rounded-2xl p-5" style={{ maxWidth: 440, background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }} onClick={e => e.stopPropagation()}>
        <div className="flex items-center gap-2 mb-2">
          <Trash2 size={16} style={{ color: 'var(--color-danger-500)' }} />
          <h3 className="font-black text-sm" style={{ color: 'var(--text-h)' }}>Delete this task?</h3>
        </div>
        <p className="text-xs mb-3" style={{ color: 'var(--text-body)' }}>
          <span className="font-bold">“{taskName}”</span> will be removed from the board.
        </p>

        <div className="rounded-xl p-3 mb-3 space-y-1" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <p className="text-[11px] font-bold mb-1" style={{ color: 'var(--text-muted)' }}>What this affects</p>
          {link
            ? <p className="text-xs" style={{ color: 'var(--text-body)' }}>• Linked to <span className="font-semibold">{link}</span></p>
            : <p className="text-xs" style={{ color: 'var(--text-body)' }}>• Standalone task (no linked record)</p>}
          {subtaskCount > 0 && (
            <p className="text-xs" style={{ color: 'var(--color-danger-500)' }}>• {subtaskCount} subtask{subtaskCount === 1 ? '' : 's'} (and anything nested under them) will be deleted too</p>
          )}
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>• Recoverable afterwards from Tasks → Trash</p>
        </div>

        <label className="block text-[11px] font-bold mb-1.5" style={{ color: 'var(--text-body)' }}>
          Type <span style={{ color: 'var(--color-danger-500)' }}>delete</span> to confirm
        </label>
        <input value={text} onChange={e => setText(e.target.value)} autoFocus placeholder="delete"
          onKeyDown={e => { if (e.key === 'Enter' && ok && !busy) onConfirm() }}
          className="w-full rounded-xl outline-none mb-3" style={{ padding: '10px 12px', fontSize: 14, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />

        <div className="flex items-center justify-end gap-2">
          <button onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          <button onClick={onConfirm} disabled={!ok || busy}
            className="text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40"
            style={{ background: 'var(--color-danger-500)', color: '#fff' }}>
            {busy ? 'Deleting…' : 'Delete task'}
          </button>
        </div>
      </div>
    </div>
  )
}

/* ── Timer ────────────────────────────────────────────────────── */

/**
 * The running timer ticks. It used to render a frozen duration that only moved
 * on refetch, which read as a broken timer.
 */
function TimerBar({ total = 0, timer, onStart, onStop, busy }) {
  const [now, setNow] = useState(() => Date.now())
  const running = Boolean(timer)

  useEffect(() => {
    if (!running) return
    const t = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(t)
  }, [running])

  const live = useMemo(() => {
    if (!running) return Number(total) || 0
    const started = new Date(timer.start_time).getTime()
    return (Number(total) || 0) + Math.max(0, Math.floor((now - started) / 1000))
  }, [running, total, timer, now])

  return (
    <div className="flex items-center gap-3 p-3 rounded-2xl" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <Clock size={15} style={{ color: running ? 'var(--color-success-500)' : TASK_ACCENT }} />
      <span className="text-sm font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{fmtDuration(live)}</span>
      <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{running ? 'running' : 'logged'}</span>
      {running && <span className="w-1.5 h-1.5 rounded-full animate-pulse" style={{ background: 'var(--color-success-500)' }} />}
      <button onClick={running ? onStop : onStart} disabled={busy}
        className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-lg disabled:opacity-40"
        style={running
          ? { background: 'color-mix(in srgb, var(--color-danger-500) 15%, transparent)', color: 'var(--color-danger-500)' }
          : { background: 'color-mix(in srgb, var(--color-success-500) 15%, transparent)', color: 'var(--color-success-500)' }}>
        {running ? <><StopCircle size={13} /> Stop</> : <><Play size={13} /> Start timer</>}
      </button>
    </div>
  )
}

/* ── Bits ─────────────────────────────────────────────────────── */

function InlineAdd({ value, onChange, onSubmit, placeholder, icon: Icon }) {
  const ref = useRef(null)
  return (
    <form onSubmit={e => { e.preventDefault(); onSubmit() }} className="flex gap-2">
      <input ref={ref} value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder}
        className="flex-1 rounded-xl outline-none"
        style={{ padding: '8px 11px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
      <button type="submit" disabled={!value.trim()}
        className="px-3 rounded-xl disabled:opacity-30" style={{ background: TASK_ACCENT, color: '#fff' }} aria-label="Add">
        <Icon size={13} />
      </button>
    </form>
  )
}

function InfoRow({ label, children }) {
  return (
    <div className="flex items-center justify-between gap-3 py-1 text-xs">
      <span className="shrink-0" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span className="text-right min-w-0 truncate">{children}</span>
    </div>
  )
}

// Header action button — sits on the purple gradient, so it uses a translucent
// white chip rather than the page's bg-input.
function HeaderIconBtn({ onClick, label, children }) {
  return (
    <button onClick={onClick} aria-label={label} title={label}
      className="w-9 h-9 rounded-xl flex items-center justify-center"
      style={{ background: 'rgba(255,255,255,0.16)', color: '#fff' }}>
      {children}
    </button>
  )
}

function Card({ title, icon: Icon, children, className = '', action = null }) {
  return (
    <section className={`rounded-2xl p-4 ${className}`} style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <div className="flex items-center gap-2 mb-3">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          {Icon && <Icon size={14} style={{ color: TASK_ACCENT }} />}{title}
        </h2>
        {action && <div className="ml-auto">{action}</div>}
      </div>
      {children}
    </section>
  )
}
