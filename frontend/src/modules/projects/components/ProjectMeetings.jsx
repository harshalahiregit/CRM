import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Plus, Trash2, Check, Users, Mail, X, Video, Link2, AtSign, ExternalLink } from 'lucide-react'
import { projectApi, PROJECT_ACCENT } from '@/services/projectApi'
import { kickoffApi } from '@/services/kickoffApi'
import { taskApi } from '@/services/taskApi'
import { meetingLinkApi } from '@/services/meetingLinkApi'
import { ConfirmModal } from '@/components/ui/SearchPicker'
import Select from '@/components/ui/Select'

const MEET_PLATFORMS = [
  { key: 'google_meet', label: 'Google Meet' },
  { key: 'zoom', label: 'Zoom' },
  { key: 'jitsi', label: 'Jitsi' },
]

const fmtDateTime = d => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

const MODE_OPTIONS = [
  { value: 'online',  label: 'Online' },
  { value: 'offline', label: 'Offline' },
  { value: 'hybrid',  label: 'Hybrid' },
]

const MODE_LABEL = { online: 'Online', offline: 'Offline', hybrid: 'Hybrid' }

const EMPTY = { title: '', mode: 'online', meeting_link: '', participants: '', planned_date: '', meeting_date: '', notes: '' }

/* ── Meeting (Kickoff) tab ────────────────────────────────────── */

export function MeetingsTab({ projectId, canManage = false }) {
  const qc = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [err, setErr] = useState('')
  const [linkMenu, setLinkMenu] = useState(false)
  const [linkBusy, setLinkBusy] = useState(null)
  const [tagMenu, setTagMenu] = useState(false)

  const { data: staff = [] } = useQuery({ queryKey: ['task-staff'], queryFn: taskApi.staff })
  const { data, isLoading } = useQuery({ queryKey: ['project-meetings', projectId], queryFn: () => projectApi.meetings(projectId) })
  const meetings = data?.meetings || []
  const counters = data?.counters || { total: 0, completed: 0, pending: 0 }
  const bust = () => qc.invalidateQueries({ queryKey: ['project-meetings', projectId] })
  const onErr = (e) => setErr(e?.message || 'That action failed.')

  const create = useMutation({
    mutationFn: () => projectApi.createMeeting(projectId, {
      title: form.title.trim(),
      mode: form.mode,
      meeting_link: form.meeting_link.trim() || null,
      participants: form.participants.trim() || null,
      planned_date: form.planned_date || null,
      meeting_date: form.meeting_date || null,
      notes: form.notes.trim() || null,
    }),
    onSuccess: () => { setForm(EMPTY); setShowForm(false); setErr(''); bust() },
    onError: onErr,
  })

  // Generate a real Zoom / Google Meet / Jitsi link into the form.
  const genLink = async (platform) => {
    if (linkBusy) return
    setLinkBusy(platform)
    try {
      const res = await meetingLinkApi.create(platform, form.title.trim() || 'Project meeting')
      if (res?.link) setForm(f => ({ ...f, meeting_link: res.link }))
      setLinkMenu(false)
    } catch (e) { onErr(e) }
    finally { setLinkBusy(null) }
  }

  // Tag a person into the participants list (comma-separated names).
  const tagPerson = (name) => {
    setForm(f => {
      const existing = f.participants.split(',').map(s => s.trim()).filter(Boolean)
      if (existing.includes(name)) return f
      return { ...f, participants: [...existing, name].join(', ') }
    })
    setTagMenu(false)
  }
  const patch = useMutation({ mutationFn: ({ mid, data }) => projectApi.updateMeeting(projectId, mid, data), onSuccess: () => { setErr(''); bust() }, onError: onErr })
  const del = useMutation({ mutationFn: (mid) => projectApi.deleteMeeting(projectId, mid), onSuccess: () => { setConfirmDelete(null); bust() }, onError: onErr })

  if (isLoading) return <Skeleton />

  return (
   <div className="flex flex-col gap-4">
    {/* Governance rollup of the shared TPV/kickoff meetings tagged to this
        project (Meeting.docx §16) — distinct from the project's own ad-hoc
        meetings listed below. */}
    <ProjectKickoffRollup projectId={projectId} />

    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-3 flex-wrap">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <CalendarClock size={14} style={{ color: PROJECT_ACCENT }} /> Project meetings
        </h2>
        <div className="flex items-center gap-1.5 ml-1">
          <Chip label="Total" value={counters.total} color="var(--text-muted)" />
          <Chip label="Completed" value={counters.completed} color="var(--color-success-500)" />
          <Chip label="Pending" value={counters.pending} color="var(--color-warning-500)" />
        </div>
        {canManage && (
          <button onClick={() => { setShowForm(s => !s); setErr('') }}
            className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: PROJECT_ACCENT, color: '#fff' }}>
            {showForm ? <X size={12} /> : <Plus size={12} />} {showForm ? 'Cancel' : 'New Meeting'}
          </button>
        )}
      </div>

      {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {canManage && showForm && (
        <form onSubmit={e => { e.preventDefault(); if (form.title.trim()) create.mutate() }}
          className="rounded-xl p-3 mb-4 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <input value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} placeholder="Meeting title *"
            className="w-full rounded-lg outline-none"
            style={{ padding: '8px 11px', fontSize: 13, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          <div className="grid gap-2 sm:grid-cols-2">
            <div>
              <Label>Mode</Label>
              <Select value={form.mode} onChange={v => setForm({ ...form, mode: v })} options={MODE_OPTIONS} size="sm" ariaLabel="Meeting mode" />
            </div>
            <div>
              <div className="flex items-center justify-between">
                <Label>Participants</Label>
                <div className="relative">
                  <button type="button" onClick={() => { setTagMenu(v => !v); setLinkMenu(false) }}
                    className="flex items-center gap-0.5 text-[10px] font-bold mb-1" style={{ color: PROJECT_ACCENT }}>
                    <AtSign size={11} /> Tag
                  </button>
                  {tagMenu && (
                    <div className="absolute right-0 top-full z-30 mt-1 w-52 rounded-xl p-1 max-h-56 overflow-y-auto"
                      style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
                      {staff.length === 0 && <p className="text-[11px] px-2 py-1.5" style={{ color: 'var(--text-muted)' }}>No people.</p>}
                      {staff.map(s => (
                        <button key={s.id} type="button" onClick={() => tagPerson(s.name)}
                          className="w-full text-left text-xs px-2 py-1.5 rounded-lg hover:opacity-90"
                          style={{ color: 'var(--text-body)' }}
                          onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                          onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                          <span className="font-semibold">{s.name}</span>
                          {s.role && <span className="ml-1" style={{ color: 'var(--text-muted)' }}>· {s.role}</span>}
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              </div>
              <input value={form.participants} onChange={e => setForm({ ...form, participants: e.target.value })} placeholder="Comma-separated names — or use Tag"
                className="w-full rounded-lg outline-none"
                style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            </div>
            <div>
              <Label>Planned date</Label>
              <input type="datetime-local" value={form.planned_date} onChange={e => setForm({ ...form, planned_date: e.target.value })}
                className="w-full rounded-lg outline-none"
                style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            </div>
            <div>
              <Label>Meeting date</Label>
              <input type="datetime-local" value={form.meeting_date} onChange={e => setForm({ ...form, meeting_date: e.target.value })}
                className="w-full rounded-lg outline-none"
                style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            </div>
          </div>
          {/* Online meeting link — generate a real Zoom / Meet / Jitsi link or paste one. */}
          <div>
            <Label>Meeting link</Label>
            <div className="flex items-center gap-2">
              <input value={form.meeting_link} onChange={e => setForm({ ...form, meeting_link: e.target.value })} placeholder="https://…  or click Generate"
                className="flex-1 rounded-lg outline-none"
                style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              {form.meeting_link && (
                <a href={form.meeting_link} target="_blank" rel="noreferrer" title="Open link"
                  className="shrink-0 p-1.5 rounded-lg" style={{ color: PROJECT_ACCENT, border: '1px solid var(--border)' }}>
                  <ExternalLink size={13} />
                </a>
              )}
              <div className="relative shrink-0">
                <button type="button" onClick={() => { setLinkMenu(v => !v); setTagMenu(false) }}
                  className="flex items-center gap-1 text-xs font-bold px-2.5 py-2 rounded-lg"
                  style={{ background: 'color-mix(in srgb, ' + PROJECT_ACCENT + ' 14%, transparent)', color: PROJECT_ACCENT }}>
                  <Video size={13} /> Generate
                </button>
                {linkMenu && (
                  <div className="absolute right-0 top-full z-30 mt-1 w-40 rounded-xl p-1"
                    style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
                    {MEET_PLATFORMS.map(p => (
                      <button key={p.key} type="button" disabled={!!linkBusy} onClick={() => genLink(p.key)}
                        className="w-full flex items-center gap-2 text-left text-xs font-semibold px-2 py-1.5 rounded-lg disabled:opacity-50"
                        style={{ color: 'var(--text-body)' }}
                        onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                        <Video size={13} style={{ color: PROJECT_ACCENT }} /> {p.label}
                        {linkBusy === p.key && <span className="ml-auto text-[10px]" style={{ color: 'var(--text-muted)' }}>…</span>}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
          <textarea value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} placeholder="Notes (optional)" rows={2}
            className="w-full rounded-lg outline-none"
            style={{ padding: '8px 11px', fontSize: 12.5, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          <button type="submit" disabled={!form.title.trim() || create.isPending}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40"
            style={{ background: PROJECT_ACCENT, color: '#fff' }}><Plus size={12} /> Add meeting</button>
        </form>
      )}

      {meetings.length === 0 && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No meetings scheduled yet.</p>}

      {meetings.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-xs" style={{ minWidth: 640 }}>
            <thead>
              <tr className="text-left text-[10px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                <th className="px-2 py-2 font-bold">Title</th>
                <th className="px-2 py-2 font-bold">Mode</th>
                <th className="px-2 py-2 font-bold">Participants</th>
                <th className="px-2 py-2 font-bold">Planned</th>
                <th className="px-2 py-2 font-bold">Status</th>
                <th className="px-2 py-2 font-bold">MOM</th>
                {canManage && <th className="px-2 py-2 font-bold text-right">Actions</th>}
              </tr>
            </thead>
            <tbody>
              {meetings.map(m => (
                <tr key={m.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-2 py-2" style={{ color: 'var(--text-h)' }}>
                    <span className="font-semibold">{m.title}</span>
                    {m.meeting_link && (
                      <a href={m.meeting_link} target="_blank" rel="noreferrer"
                        className="flex items-center gap-1 text-[10px] font-bold mt-0.5" style={{ color: PROJECT_ACCENT }}>
                        <Video size={10} /> Join meeting <ExternalLink size={9} />
                      </a>
                    )}
                    {m.notes && <span className="block text-[10px] mt-0.5 whitespace-pre-wrap break-words" style={{ color: 'var(--text-muted)' }}>{m.notes}</span>}
                  </td>
                  <td className="px-2 py-2 capitalize" style={{ color: 'var(--text-muted)' }}>{MODE_LABEL[m.mode] || m.mode}</td>
                  <td className="px-2 py-2" style={{ color: 'var(--text-muted)' }}>
                    {m.participants
                      ? <span className="inline-flex items-center gap-1"><Users size={11} /> {m.participants}</span>
                      : '—'}
                  </td>
                  <td className="px-2 py-2" style={{ color: 'var(--text-muted)' }}>{fmtDateTime(m.planned_date)}</td>
                  <td className="px-2 py-2">
                    <StatusBadge status={m.status} />
                  </td>
                  <td className="px-2 py-2">
                    {m.mom_sent
                      ? <span className="inline-flex items-center gap-1 text-[10px] font-bold" style={{ color: 'var(--color-success-500)' }}><Mail size={11} /> Sent</span>
                      : <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>—</span>}
                  </td>
                  {canManage && (
                    <td className="px-2 py-2">
                      <div className="flex items-center justify-end gap-2">
                        {m.status !== 'completed' && (
                          <button onClick={() => patch.mutate({ mid: m.id, data: { status: 'completed' } })} title="Mark completed" className="hover:opacity-60">
                            <Check size={13} style={{ color: 'var(--color-success-500)' }} />
                          </button>
                        )}
                        <button onClick={() => patch.mutate({ mid: m.id, data: { mom_sent: !m.mom_sent } })}
                          title={m.mom_sent ? 'Mark MOM not sent' : 'Mark MOM sent'} className="hover:opacity-60">
                          <Mail size={13} style={{ color: m.mom_sent ? 'var(--text-muted)' : PROJECT_ACCENT }} />
                        </button>
                        <button onClick={() => setConfirmDelete(m)} title="Delete meeting" className="hover:opacity-60">
                          <Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} />
                        </button>
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <ConfirmModal open={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)}
        onConfirm={() => del.mutate(confirmDelete.id)}
        title="Delete this meeting?" message={`“${confirmDelete?.title}” will be removed.`} confirmLabel="Delete" danger />
    </section>
   </div>
  )
}

/* ── §16 governance rollup: TPV/kickoff meetings tagged to this project ──────── */
function ProjectKickoffRollup({ projectId }) {
  const navigate = useNavigate()
  const { data } = useQuery({
    queryKey: ['project-kickoff-rollup', projectId],
    queryFn: () => kickoffApi.projectMeetings(projectId),
  })
  const totals = data?.totals
  const meetings = data?.meetings || []

  // Nothing tagged yet — keep the tab focused on the project's own meetings.
  if (!totals || totals.meetings === 0) return null

  const tiles = [
    ['Meetings', totals.meetings, 'var(--text-h)'],
    ['MOMs', totals.moms, 'var(--text-h)'],
    ['Actions', totals.total_actions, 'var(--text-h)'],
    ['Open', totals.open_actions, '#f59e0b'],
    ['Overdue', totals.overdue_actions, totals.overdue_actions ? '#ef4444' : 'var(--text-muted)'],
    ['Decisions', totals.decisions, '#0ea5e9'],
  ]

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs flex items-center gap-1.5 mb-3" style={{ color: 'var(--text-h)' }}>
        <CalendarClock size={14} style={{ color: PROJECT_ACCENT }} /> TPV / Governance meetings
      </h2>
      <div className="grid gap-2 mb-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(90px,1fr))' }}>
        {tiles.map(([label, val, color]) => (
          <div key={label} className="rounded-xl p-2.5 text-center" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <div className="font-black leading-none" style={{ fontSize: 20, color }}>{val ?? 0}</div>
            <div className="text-[10px] font-bold uppercase mt-1" style={{ color: 'var(--text-muted)', letterSpacing: '0.03em' }}>{label}</div>
          </div>
        ))}
      </div>
      <div className="flex flex-col gap-1.5">
        {meetings.slice(0, 8).map(m => (
          <button key={m.id} onClick={() => navigate(`/app/tpv/kickoff/${m.id}`)}
            className="flex items-center gap-2 text-left rounded-lg px-2.5 py-2 w-full"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <span className="text-[11px] font-semibold flex-1 min-w-0 truncate" style={{ color: 'var(--text-h)' }}>
              {m.subject?.name || m.title}
              <span className="ml-1.5 text-[10px]" style={{ color: 'var(--text-muted)' }}>{m.meeting_type_label}</span>
            </span>
            {m.open_actions > 0 && <span className="text-[10px] font-bold px-1.5 py-0.5 rounded" style={{ background: 'rgba(245,158,11,0.15)', color: '#d97706' }}>{m.open_actions} open</span>}
            <span className="text-[10px] font-bold px-1.5 py-0.5 rounded capitalize" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>{m.status_label}</span>
          </button>
        ))}
        {meetings.length > 8 && <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>+{meetings.length - 8} more</span>}
      </div>
    </section>
  )
}

const Chip = ({ label, value, color }) => (
  <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-lg"
    style={{ background: `color-mix(in srgb, ${color} 12%, transparent)`, color }}>
    {value} <span style={{ opacity: 0.75 }}>{label}</span>
  </span>
)

const StatusBadge = ({ status }) => {
  const done = status === 'completed'
  const color = done ? 'var(--color-success-500)' : 'var(--color-warning-500)'
  return (
    <span className="inline-flex items-center text-[10px] font-bold px-2 py-0.5 rounded-lg capitalize"
      style={{ background: `color-mix(in srgb, ${color} 14%, transparent)`, color }}>
      {done ? 'Completed' : 'Pending'}
    </span>
  )
}

const Label = ({ children }) => (
  <label className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{children}</label>
)

const Skeleton = () => <div className="rounded-2xl animate-pulse" style={{ height: 160, background: 'var(--bg-card)' }} />

export default MeetingsTab
