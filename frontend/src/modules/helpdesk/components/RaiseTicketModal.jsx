import { useState, useEffect, useMemo, useRef } from 'react'
import { useDiscardGuard } from '@/lib/confirmClose'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { X, LifeBuoy, AlertCircle, CheckCircle2, ArrowRight, Users, Check } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import Select from '@/components/ui/Select'

/**
 * Raise a helpdesk ticket from anywhere (a project, a task) through the SAME
 * create flow the Helpdesk module uses — so the raised ticket gets the normal
 * acknowledgement email, the ticket-manager/admin notifications and the standard
 * visibility rules, no special-casing. When a projectId is passed the ticket is
 * linked to that project (shows up under the project's Tickets tab).
 */

const SUPPORT = 'var(--color-support-500)'
const INP = { width: '100%', padding: '10px 13px', borderRadius: 10, border: '1px solid var(--border)', fontSize: 14, outline: 'none', color: 'var(--text-h)', background: 'var(--bg-input)' }
const LBL = { display: 'block', fontSize: 11, fontWeight: 700, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 5 }

export default function RaiseTicketModal({ open, onClose, projectId = null, source = null, defaultSubject = '', defaultDescription = '', onCreated }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [form, setForm] = useState({ subject: '', description: '', priority: 'medium', department_id: '', assigned_to: '', requester_name: '', requester_email: '' })
  const [created, setCreated] = useState(null)

  const { data: settings } = useQuery({ queryKey: ['helpdesk-settings'], queryFn: helpdeskApi.settings.all, enabled: open })

  const snapshotRef = useRef('')
  useEffect(() => {
    if (!open) return
    setCreated(null)
    const init = { subject: defaultSubject, description: defaultDescription, priority: 'medium', department_id: '', assigned_to: '', requester_name: '', requester_email: '' }
    setForm(init)
    snapshotRef.current = JSON.stringify(init)
  }, [open, defaultSubject, defaultDescription])

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const { guard, dialog } = useDiscardGuard()
  // Only guard while still filling the form — once created, closing is harmless.
  const requestClose = () => guard(onClose, !created && snapshotRef.current && JSON.stringify(form) !== snapshotRef.current)

  // The people the admin assigned to the chosen department. Picking a department
  // is what routes the ticket to them (they're notified and can see it); showing
  // them here makes that routing visible, and lets the raiser optionally assign
  // one of them directly.
  const deptAgents = useMemo(() => {
    if (!form.department_id) return []
    const dep = (settings?.departments || []).find(d => String(d.id) === String(form.department_id))
    return dep?.managers || []
  }, [form.department_id, settings])

  const create = useMutation({
    mutationFn: () => {
      const p = { ...form }
      Object.keys(p).forEach(k => p[k] === '' && delete p[k])
      if (projectId) p.project_id = Number(projectId)
      // Stamp the origin module so the ticket grid can badge where it came from.
      if (source) p.source = source
      return helpdeskApi.tickets.create(p)
    },
    onSuccess: (t) => {
      setCreated(t)
      qc.invalidateQueries({ queryKey: ['helpdesk-tickets'] })
      if (projectId) qc.invalidateQueries({ queryKey: ['project-tickets', String(projectId)] })
      onCreated?.(t)
    },
  })

  if (!open) return null
  const priorities = settings?.priorities || [{ name: 'medium' }]
  const departments = settings?.departments || []

  return (
    <>
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={requestClose}>
      <div className="w-full max-w-[500px] rounded-2xl mt-[8vh] max-h-[85vh] overflow-y-auto"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)', padding: 24 }} onClick={e => e.stopPropagation()}>

        <div className="flex items-center justify-between mb-5">
          <h2 className="font-black text-base flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <LifeBuoy size={17} style={{ color: SUPPORT }} /> Raise a Ticket
          </h2>
          <button onClick={requestClose} className="hover:opacity-70"><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        {created ? (
          <div className="text-center py-6">
            <CheckCircle2 size={34} style={{ color: 'var(--color-success-500)', margin: '0 auto 10px' }} />
            <p className="font-bold" style={{ color: 'var(--text-h)' }}>Ticket #{created.id} raised</p>
            <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
              {created.subject}. The support team has been notified{projectId ? ' and it’s linked to this project' : ''}.
            </p>
            <div className="flex items-center justify-center gap-2 mt-5">
              <button onClick={onClose} className="px-4 py-2 rounded-xl text-sm font-semibold" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Done</button>
              <button onClick={() => { onClose?.(); navigate(`/app/helpdesk/tickets/${created.id}`) }}
                className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-bold" style={{ background: SUPPORT, color: '#fff' }}>
                View ticket <ArrowRight size={14} />
              </button>
            </div>
          </div>
        ) : (
          <div className="space-y-3.5">
            <div><label style={LBL}>Subject *</label><input style={INP} value={form.subject} onChange={e => set('subject', e.target.value)} placeholder="What's the issue?" autoFocus /></div>
            <div><label style={LBL}>Description</label><textarea style={{ ...INP, minHeight: 90, resize: 'vertical' }} value={form.description} onChange={e => set('description', e.target.value)} placeholder="Describe in detail…" /></div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label style={LBL}>Priority</label>
                <Select value={form.priority} onChange={v => set('priority', v)} ariaLabel="Priority"
                  options={priorities.map(p => ({ value: p.name, label: p.name, dot: p.color }))} />
              </div>
              <div>
                <label style={LBL}>Department</label>
                <Select value={form.department_id ?? ''} onChange={v => setForm(f => ({ ...f, department_id: v, assigned_to: '' }))} placeholder="— none —" ariaLabel="Department"
                  options={[{ value: '', label: '— none —' }, ...departments.map(d => ({ value: d.id, label: d.name }))]} />
              </div>
            </div>

            {/* People the admin assigned to the chosen department — the ticket routes
                to all of them; optionally assign it straight to one. */}
            {form.department_id && (
              <div className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <p className="flex items-center gap-1.5 mb-2" style={{ ...LBL, marginBottom: 8 }}>
                  <Users size={12} style={{ color: SUPPORT }} /> Handled by this department
                </p>
                {deptAgents.length === 0 ? (
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    No one is assigned to this department yet — it’ll go to the ticket managers.
                  </p>
                ) : (
                  <>
                    <div className="flex flex-wrap gap-1.5">
                      {deptAgents.map(a => {
                        const on = String(form.assigned_to) === String(a.id)
                        return (
                          <button type="button" key={a.id} onClick={() => set('assigned_to', on ? '' : a.id)}
                            className="flex items-center gap-1 text-xs font-semibold px-2.5 py-1.5 rounded-lg transition-colors"
                            style={on
                              ? { background: `color-mix(in srgb, ${SUPPORT} 16%, transparent)`, color: SUPPORT, border: `1px solid ${SUPPORT}` }
                              : { background: 'var(--bg-card)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
                            {on && <Check size={12} />} {a.name}
                          </button>
                        )
                      })}
                    </div>
                    <p className="text-[11px] mt-2" style={{ color: 'var(--text-muted)' }}>
                      Everyone above is notified and can see it. Tap one to assign it to them directly.
                    </p>
                  </>
                )}
              </div>
            )}

            <div className="grid grid-cols-2 gap-3">
              <div><label style={LBL}>Requester name</label><input style={INP} value={form.requester_name} onChange={e => set('requester_name', e.target.value)} /></div>
              <div><label style={LBL}>Requester email</label><input style={INP} value={form.requester_email} onChange={e => set('requester_email', e.target.value)} placeholder="for email updates" /></div>
            </div>

            {create.isError && (
              <div className="flex items-center gap-2 p-3 rounded-xl text-sm" style={{ background: 'rgba(239,68,68,0.08)', color: 'var(--color-danger-500)', border: '1px solid rgba(239,68,68,0.2)' }}>
                <AlertCircle size={14} />{create.error?.message}
              </div>
            )}

            <div className="flex items-center justify-end gap-2 pt-1">
              <button onClick={requestClose} className="px-4 py-2 rounded-xl text-sm font-semibold" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
              <button disabled={!form.subject.trim() || create.isPending} onClick={() => create.mutate()}
                className="px-5 py-2 rounded-xl text-sm font-bold disabled:opacity-50" style={{ background: SUPPORT, color: '#fff' }}>
                {create.isPending ? 'Raising…' : 'Raise Ticket'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
    {dialog}
    </>
  )
}
