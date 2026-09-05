/**
 * Compose an announcement and send it to the people you choose.
 *
 * Deliberately a tab inside the Notification Center rather than a screen of its
 * own. A second "announcements" page beside this one is how somebody sends the
 * same holiday notice twice from two places and nobody can tell which went out —
 * and it goes through the same engine and queue as every automatic notification,
 * so the Queue Monitor beside this tab is where you look when one did not land.
 *
 * The reachable count is stated plainly. "Sent to 40" and "40 people can receive
 * it" are different numbers — an employee with no linked account has no bell to
 * ring and no phone to reach — and the difference is exactly what somebody needs
 * to know before announcing a holiday.
 */

import { useState, useEffect, useMemo } from 'react'
import { Megaphone, Paperclip, Users, Building2, UserRound, X, Send } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading } from '@/components/ui/HrState'
import { GRAD } from './ui'

const AUDIENCES = [
  { key: 'all',        label: 'Everyone',        icon: Users,     hint: 'Every active employee with an account' },
  { key: 'department', label: 'A department',    icon: Building2, hint: 'Everyone in one department' },
  { key: 'employees',  label: 'Specific people', icon: UserRound, hint: 'Pick them yourself' },
]

// in_app and push by default. Email is opt-in on purpose: an announcement to the
// whole company should not silently become a company-wide email.
const CHANNELS = [
  { key: 'in_app', label: 'In the app & bell', locked: true },
  { key: 'push',   label: 'Push notification', locked: false },
  { key: 'email',  label: 'Email',             locked: false },
]

export default function ComposeTab({ showToast }) {
  const [audience, setAudience] = useState('all')
  const [department, setDepartment] = useState('')
  const [userIds, setUserIds] = useState([])
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [file, setFile] = useState(null)
  const [channels, setChannels] = useState(['in_app', 'push'])
  const [search, setSearch] = useState('')
  const [data, setData] = useState(null)
  const [sending, setSending] = useState(false)

  useEffect(() => {
    hrApi.notifications.announcements.audience()
      .then(setData)
      .catch(() => showToast('Could not load the employee list', 'error'))
  }, [showToast])

  const people = useMemo(() => {
    const q = search.trim().toLowerCase()
    return (data?.employees || []).filter(e =>
      !q || e.name?.toLowerCase().includes(q) || e.employee_code?.toLowerCase().includes(q))
  }, [data, search])

  // What this will actually reach, before it is sent.
  const willReach = useMemo(() => {
    if (!data) return 0
    if (audience === 'all') return data.reachable
    if (audience === 'department') return data.employees.filter(e => e.department === department).length
    return userIds.length
  }, [data, audience, department, userIds])

  const toggleChannel = (k) => setChannels(c =>
    c.includes(k) ? c.filter(x => x !== k) : [...c, k])

  const toggleAll = () => setUserIds(
    userIds.length === people.length ? [] : people.map(p => p.user_id))

  const send = async () => {
    if (!title.trim() || !body.trim()) return showToast('A title and a message are required', 'error')
    if (audience === 'department' && !department) return showToast('Choose a department', 'error')
    if (audience === 'employees' && userIds.length === 0) return showToast('Choose at least one person', 'error')

    const form = new FormData()
    form.append('title', title.trim())
    form.append('body', body.trim())
    form.append('audience', audience)
    if (audience === 'department') form.append('department', department)
    if (audience === 'employees') userIds.forEach(id => form.append('user_ids[]', id))
    channels.forEach(c => form.append('channels[]', c))
    if (file) form.append('attachment', file)

    setSending(true)
    try {
      const res = await hrApi.notifications.announcements.send(form)
      showToast(res.message || 'Announcement sent')
      setTitle(''); setBody(''); setFile(null); setUserIds([])
    } catch (e) {
      showToast(e.response?.data?.message || 'Could not send the announcement', 'error')
    } finally { setSending(false) }
  }

  if (!data) return <HrLoading label="Loading employees…" />

  return (
    <div className="grid gap-4" style={{ gridTemplateColumns: 'minmax(0,1fr) 320px' }}>
      {/* ── the message ── */}
      <div className="card-3d" style={{ padding: 20 }}>
        <div className="flex items-center gap-2 mb-4">
          <Megaphone size={16} style={{ color: '#a78bfa' }} />
          <h2 className="text-sm font-black" style={{ color: 'var(--text-h)' }}>Write the announcement</h2>
        </div>

        <label className="label">Title *</label>
        <input className="input-3d text-sm" maxLength={150} value={title}
          onChange={e => setTitle(e.target.value)}
          placeholder="Diwali holiday — office closed 20 Sept" />

        <label className="label mt-3">Message *</label>
        <textarea className="input-3d text-sm" rows={7} maxLength={4000} value={body}
          onChange={e => setBody(e.target.value)}
          placeholder="What people need to know. This is what appears on their phone." />

        <label className="label mt-3">Attach a PDF</label>
        {file ? (
          <div className="flex items-center gap-2 px-3 py-2 rounded-xl text-sm"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <Paperclip size={13} style={{ color: '#a78bfa' }} />
            <span className="flex-1 truncate" style={{ color: 'var(--text-h)' }}>{file.name}</span>
            <button onClick={() => setFile(null)} title="Remove"><X size={14} style={{ color: '#f87171' }} /></button>
          </div>
        ) : (
          <input type="file" accept="application/pdf" className="input-3d text-sm"
            onChange={e => setFile(e.target.files?.[0] || null)} />
        )}
        <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
          A policy or a notice. PDF, up to 10 MB.
        </p>
      </div>

      {/* ── who gets it ── */}
      <div className="flex flex-col gap-4">
        <div className="card-3d" style={{ padding: 16 }}>
          <h3 className="text-xs font-black mb-3" style={{ color: 'var(--text-h)' }}>Send to</h3>

          <div className="flex flex-col gap-1.5">
            {AUDIENCES.map(a => (
              <button key={a.key} onClick={() => setAudience(a.key)}
                className="flex items-start gap-2 px-3 py-2 rounded-xl text-left"
                style={audience === a.key
                  ? { background: GRAD, color: '#fff' }
                  : { background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                <a.icon size={14} className="mt-0.5" />
                <span>
                  <span className="text-xs font-bold block">{a.label}</span>
                  <span className="text-[10px] opacity-80">{a.hint}</span>
                </span>
              </button>
            ))}
          </div>

          {audience === 'department' && (
            <select className="input-3d text-sm mt-3" value={department}
              onChange={e => setDepartment(e.target.value)}>
              <option value="">Choose a department…</option>
              {data.departments.map(d => <option key={d} value={d}>{d}</option>)}
            </select>
          )}

          {audience === 'employees' && (
            <div className="mt-3">
              <input className="input-3d text-sm" placeholder="Search name or code…"
                value={search} onChange={e => setSearch(e.target.value)} />
              <button onClick={toggleAll} className="text-[10px] underline mt-1.5" style={{ color: '#a78bfa' }}>
                {userIds.length === people.length ? 'Clear all' : `Select all ${people.length}`}
              </button>
              <div className="mt-2 overflow-y-auto flex flex-col gap-0.5" style={{ maxHeight: 220 }}>
                {people.map(p => (
                  <label key={p.user_id} className="flex items-center gap-2 px-2 py-1 rounded-lg cursor-pointer text-xs"
                    style={{ color: 'var(--text-h)' }}>
                    <input type="checkbox" checked={userIds.includes(p.user_id)}
                      onChange={() => setUserIds(ids => ids.includes(p.user_id)
                        ? ids.filter(i => i !== p.user_id) : [...ids, p.user_id])} />
                    <span className="flex-1 truncate">{p.name}</span>
                    <span className="text-[9px] font-mono" style={{ color: '#a78bfa' }}>{p.employee_code}</span>
                  </label>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="card-3d" style={{ padding: 16 }}>
          <h3 className="text-xs font-black mb-2" style={{ color: 'var(--text-h)' }}>How it reaches them</h3>
          {CHANNELS.map(c => (
            <label key={c.key} className="flex items-center gap-2 text-xs py-1"
              style={{ color: c.locked ? 'var(--text-muted)' : 'var(--text-h)' }}>
              <input type="checkbox" checked={channels.includes(c.key)} disabled={c.locked}
                onChange={() => toggleChannel(c.key)} />
              {c.label}{c.locked && <span className="text-[9px]">(always)</span>}
            </label>
          ))}
          <p className="text-[10px] mt-2" style={{ color: 'var(--text-muted)' }}>
            Push reaches phones that have signed in to the app at least once.
          </p>
        </div>

        <button onClick={send} disabled={sending || willReach === 0}
          className="flex items-center justify-center gap-2 px-4 py-3 rounded-xl text-sm font-bold text-white"
          style={{ background: GRAD, opacity: sending || willReach === 0 ? 0.5 : 1 }}>
          <Send size={15} />
          {sending ? 'Sending…' : `Send to ${willReach} ${willReach === 1 ? 'person' : 'people'}`}
        </button>
        {willReach === 0 && (
          <p className="text-[10px] text-center" style={{ color: '#f87171' }}>
            Nobody matches this audience yet.
          </p>
        )}
      </div>
    </div>
  )
}
