import { useState, useRef } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useDiscardGuard } from '@/lib/confirmClose'
import { X, UserCheck, AlertCircle, Check, FolderKanban } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import Select from '@/components/ui/Select'

/**
 * The Helpdesk's ticket-create form. Lifted out of TicketGrid unchanged so a
 * second screen can raise a ticket without a second form — every field, the
 * department routing, the discard guard and the create call are the originals.
 *
 * `draft` carries context from another module ("Raise ticket" on a lead), so the
 * form opens part-filled. customer_id is not an input — it rides along hidden so
 * the ticket stays linked to whoever it was raised for.
 *
 * `projects` turns on the Project field. A ticket has no vendor of its own: it
 * reaches one THROUGH its project (tickets.project_id → projects.vendor_id), so
 * the TPV vendor screen passes that vendor's own projects and the field becomes
 * required. The caller decides which projects are offered — this form never
 * fetches them, so it cannot widen the choice beyond what it was handed.
 */
const inp = { width: '100%', padding: '10px 13px', borderRadius: 10, border: '1px solid var(--border)', fontSize: 14, outline: 'none', color: 'var(--text-h)', background: 'var(--bg-input)' }

export default function NewTicketModal({ settings, onClose, onCreated, draft = null, projects = null, title = 'New Ticket' }) {
  const scoped = Array.isArray(projects)

  const [form, setForm] = useState({
    subject: draft?.subject || '', description: draft?.description || '',
    priority: draft?.priority || 'medium', status: 'open',
    department_id: '', assigned_to: '',
    requester_name: draft?.requester_name || '', requester_email: draft?.requester_email || '',
    project_id: draft?.project_id || '',
  })
  const create = useMutation({
    mutationFn: () => {
      const p = { ...form }
      Object.keys(p).forEach(k => p[k] === '' && delete p[k])
      if (draft?.customer_id) p.customer_id = Number(draft.customer_id)
      if (draft?.source) p.source = draft.source
      return helpdeskApi.tickets.create(p)
    },
    onSuccess: (t) => onCreated(t.id),
  })
  const snapRef = useRef(null)
  if (snapRef.current === null) snapRef.current = JSON.stringify(form)
  const { guard, dialog } = useDiscardGuard()
  const requestClose = () => guard(onClose, JSON.stringify(form) !== snapRef.current)
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const LBL = { display: 'block', fontSize: 11, fontWeight: 700, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 5 }
  // People the admin assigned to the chosen department (the ticket routes to them).
  const deptAgents = (settings?.departments || []).find(d => String(d.id) === String(form.department_id))?.managers || []

  // When the form is project-scoped the link is what files the ticket where it
  // belongs, so it is as mandatory as the subject.
  const incomplete = !form.subject.trim() || (scoped && !form.project_id)

  return (
    <>
    <div className="fixed inset-0 z-[70] flex items-start justify-center bg-black/50" style={{ paddingTop: '8vh' }} onClick={requestClose}>
      <div className="w-full max-w-[500px] rounded-2xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)', padding: 24, maxHeight: '85vh', overflowY: 'auto' }} onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="font-black text-base" style={{ color: 'var(--text-h)' }}>{title}</h2>
          <button onClick={requestClose} className="hover:opacity-70"><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div className="space-y-3.5">
          <div><label style={LBL}>Subject *</label><input style={inp} value={form.subject} onChange={e => set('subject', e.target.value)} placeholder="What's the issue?" /></div>

          {scoped && (
            <div>
              <label style={LBL} className="flex items-center gap-1.5">
                <FolderKanban size={12} style={{ color: 'var(--color-support-500)' }} /> Project *
              </label>

              {projects.length === 0 ? (
                /* Say WHY rather than offering an empty dropdown. A ticket has no
                   vendor column — it reaches this vendor through a project — so
                   with no projects there is nothing to attach it to. */
                <div className="flex items-start gap-2 p-3 rounded-xl text-sm"
                  style={{ background: 'rgba(245,158,11,0.08)', color: 'var(--text-body)', border: '1px solid rgba(245,158,11,0.25)' }}>
                  <AlertCircle size={14} style={{ color: 'var(--color-warning-500)', flexShrink: 0, marginTop: 2 }} />
                  <span>
                    This vendor has no projects yet. A ticket reaches a vendor through its
                    project, so add one on the <strong>Projects</strong> tab first.
                  </span>
                </div>
              ) : (
                <>
                  <Select value={form.project_id ?? ''} onChange={v => set('project_id', v)} ariaLabel="Project"
                    placeholder="Choose a project…"
                    options={projects.map(p => ({ value: p.id, label: p.name }))} />
                  <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
                    Only this vendor’s projects — that link is what files the ticket against it.
                  </p>
                </>
              )}
            </div>
          )}

          <div><label style={LBL}>Description</label><textarea style={{ ...inp, minHeight: 80, resize: 'vertical' }} value={form.description} onChange={e => set('description', e.target.value)} placeholder="Describe in detail…" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label style={LBL}>Priority</label>
              <Select value={form.priority} onChange={v => set('priority', v)} ariaLabel="Priority"
                options={(settings?.priorities || [{ name: 'medium' }]).map(p => ({ value: p.name, label: p.name, dot: p.color }))} />
            </div>
            <div>
              <label style={LBL}>Status</label>
              <Select value={form.status} onChange={v => set('status', v)} ariaLabel="Status"
                options={(settings?.statuses || [{ name: 'open' }]).filter(s => s.name !== 'merged').map(s => ({ value: s.name, label: s.name, dot: s.color }))} />
            </div>
          </div>
          <div>
            <label style={LBL}>Department</label>
            <Select value={form.department_id ?? ''} onChange={v => setForm(f => ({ ...f, department_id: v, assigned_to: '' }))} placeholder="— none —" ariaLabel="Department"
              options={[{ value: '', label: '— none —' }, ...(settings?.departments || []).map(d => ({ value: d.id, label: d.name }))]} />
          </div>

          {/* People the admin assigned to this department — the ticket routes to all
              of them; tap one to assign it directly. */}
          {form.department_id && (
            <div className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <p style={{ ...LBL, marginBottom: 8 }} className="flex items-center gap-1.5">
                <UserCheck size={12} style={{ color: 'var(--color-support-500)' }} /> Handled by this department
              </p>
              {deptAgents.length === 0 ? (
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No one assigned yet — it’ll go to the ticket managers.</p>
              ) : (
                <div className="flex flex-wrap gap-1.5">
                  {deptAgents.map(a => {
                    const on = String(form.assigned_to) === String(a.id)
                    return (
                      <button type="button" key={a.id} onClick={() => set('assigned_to', on ? '' : a.id)}
                        className="flex items-center gap-1 text-xs font-semibold px-2.5 py-1.5 rounded-lg"
                        style={on
                          ? { background: 'color-mix(in srgb, var(--color-support-500) 16%, transparent)', color: 'var(--color-support-500)', border: '1px solid var(--color-support-500)' }
                          : { background: 'var(--bg-card)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
                        {on && <Check size={12} />} {a.name}
                      </button>
                    )
                  })}
                </div>
              )}
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div><label style={LBL}>Requester name</label><input style={inp} value={form.requester_name} onChange={e => set('requester_name', e.target.value)} /></div>
            <div><label style={LBL}>Requester email</label><input style={inp} value={form.requester_email} onChange={e => set('requester_email', e.target.value)} /></div>
          </div>
          {create.isError && <div className="flex items-center gap-2 p-3 rounded-xl text-sm" style={{ background: 'rgba(239,68,68,0.08)', color: 'var(--color-danger-500)', border: '1px solid rgba(239,68,68,0.2)' }}><AlertCircle size={14} />{create.error?.message}</div>}
          <div className="flex items-center justify-end gap-2 pt-1">
            <button onClick={requestClose} className="px-4 py-2 rounded-xl text-sm font-semibold hover:opacity-80" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
            <button disabled={incomplete || create.isPending} onClick={() => create.mutate()} className="px-5 py-2 rounded-xl text-sm font-bold disabled:opacity-50" style={{ background: `linear-gradient(135deg,var(--color-support-400),var(--color-support-600))`, color: '#fff' }}>{create.isPending ? 'Creating…' : 'Create Ticket'}</button>
          </div>
        </div>
      </div>
    </div>
    {dialog}
    </>
  )
}
