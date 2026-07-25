import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Plus, Trash2, Check, Users, Mail, X } from 'lucide-react'
import { projectApi, PROJECT_ACCENT } from '@/services/projectApi'
import { ConfirmModal } from '@/components/ui/SearchPicker'
import Select from '@/components/ui/Select'

const fmtDateTime = d => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

const MODE_OPTIONS = [
  { value: 'online',  label: 'Online' },
  { value: 'offline', label: 'Offline' },
  { value: 'hybrid',  label: 'Hybrid' },
]

const MODE_LABEL = { online: 'Online', offline: 'Offline', hybrid: 'Hybrid' }

const EMPTY = { title: '', mode: 'online', participants: '', planned_date: '', meeting_date: '', notes: '' }

/* ── Meeting (Kickoff) tab ────────────────────────────────────── */

export function MeetingsTab({ projectId, canManage = false }) {
  const qc = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [err, setErr] = useState('')

  const { data, isLoading } = useQuery({ queryKey: ['project-meetings', projectId], queryFn: () => projectApi.meetings(projectId) })
  const meetings = data?.meetings || []
  const counters = data?.counters || { total: 0, completed: 0, pending: 0 }
  const bust = () => qc.invalidateQueries({ queryKey: ['project-meetings', projectId] })
  const onErr = (e) => setErr(e?.message || 'That action failed.')

  const create = useMutation({
    mutationFn: () => projectApi.createMeeting(projectId, {
      title: form.title.trim(),
      mode: form.mode,
      participants: form.participants.trim() || null,
      planned_date: form.planned_date || null,
      meeting_date: form.meeting_date || null,
      notes: form.notes.trim() || null,
    }),
    onSuccess: () => { setForm(EMPTY); setShowForm(false); setErr(''); bust() },
    onError: onErr,
  })
  const patch = useMutation({ mutationFn: ({ mid, data }) => projectApi.updateMeeting(projectId, mid, data), onSuccess: () => { setErr(''); bust() }, onError: onErr })
  const del = useMutation({ mutationFn: (mid) => projectApi.deleteMeeting(projectId, mid), onSuccess: () => { setConfirmDelete(null); bust() }, onError: onErr })

  if (isLoading) return <Skeleton />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-3 flex-wrap">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <CalendarClock size={14} style={{ color: PROJECT_ACCENT }} /> Meetings
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
              <Label>Participants</Label>
              <input value={form.participants} onChange={e => setForm({ ...form, participants: e.target.value })} placeholder="Comma-separated names"
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
