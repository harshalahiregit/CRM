/**
 * The people who clock in — SangoeTrack's staff, not the CRM's employee records.
 *
 * Deliberately named Staff Directory rather than Employees: /app/hr/employees is
 * the recruitment-side record and already owns that word. These are different
 * lists of possibly the same humans, and two identical labels in one sidebar is
 * how somebody ends up editing the wrong one.
 *
 * Creating an account here creates it on SangoeTrack — the person can log into
 * the phone app immediately. The temporary password comes back exactly once and
 * is never retrievable again, so it is shown until dismissed rather than in a
 * toast that vanishes after three seconds.
 */

import { useState, useEffect, useCallback, useMemo } from 'react'
import { UserPlus, KeyRound, Search, Copy, Check, Users } from 'lucide-react'
import { sangoeTrackApi, trackErrorMessage } from '@/services/sangoeTrackApi'
import { useToast } from '@/hooks/useToast'
import LoadError from '@/components/ui/LoadError'
import EmptyState from '@/components/ui/EmptyState'
import { TrackHeader } from './TrackShell'

/* ── new account ─────────────────────────────────────────────────────── */

function CreateStaff({ roles, onCreated, onClose }) {
  const [form, setForm] = useState({ name: '', email: '', mobile_no: '', role: 'staff', password: '' })
  const [busy, setBusy] = useState(false)
  const [created, setCreated] = useState(null)
  const [copied, setCopied] = useState(false)
  const toast = useToast()

  const set = (k, v) => setForm(prev => ({ ...prev, [k]: v }))

  async function submit() {
    if (!form.name.trim() || !form.email.trim()) {
      toast.error('Name and email are required.')
      return
    }
    setBusy(true)
    try {
      const res = await sangoeTrackApi.staff.create({
        name: form.name.trim(),
        email: form.email.trim(),
        mobile_no: form.mobile_no.trim() || undefined,
        role: form.role,
        // Blank means let SangoeTrack generate one.
        password: form.password.trim() || undefined,
      })
      setCreated(res?.data ?? null)
      onCreated?.()
    } catch (err) {
      toast.error(trackErrorMessage(err, 'Could not create the account.'))
    } finally {
      setBusy(false)
    }
  }

  // The password screen. Shown until dismissed because this is the only time it
  // exists — closing without copying means resetting it to find out what it is.
  if (created) {
    return (
      <div className="rounded-xl p-4 flex flex-col gap-3" style={{ border: '1px solid #34d399', background: 'rgba(52,211,153,0.06)' }}>
        <div>
          <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>
            {created.name} can now sign in
          </p>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{created.email}</p>
        </div>

        {created.temp_password && (
          <div className="rounded-lg p-3 flex flex-col gap-2" style={{ background: 'var(--bg-input)' }}>
            <p className="text-[11px] font-semibold" style={{ color: '#fbbf24' }}>
              {created.password_generated
                ? 'Generated password — this is the only time it is shown. Copy it now.'
                : 'The password you set.'}
            </p>
            <div className="flex items-center gap-2">
              <code className="text-sm font-mono flex-1 px-2.5 py-1.5 rounded"
                style={{ background: 'var(--bg-card)', color: 'var(--text-h)', wordBreak: 'break-all' }}>
                {created.temp_password}
              </code>
              <button
                onClick={() => {
                  navigator.clipboard?.writeText(created.temp_password)
                  setCopied(true)
                  setTimeout(() => setCopied(false), 1800)
                }}
                className="rounded-lg text-xs font-semibold flex items-center gap-1.5"
                style={{ padding: '7px 12px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: copied ? '#34d399' : '#a78bfa' }}>
                {copied ? <Check size={13} /> : <Copy size={13} />}
                {copied ? 'Copied' : 'Copy'}
              </button>
            </div>
          </div>
        )}

        <div className="flex gap-2">
          <button onClick={() => { setCreated(null); setForm({ name: '', email: '', mobile_no: '', role: 'staff', password: '' }) }}
            className="rounded-lg text-xs font-semibold"
            style={{ padding: '7px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
            Add another
          </button>
          <button onClick={onClose}
            className="rounded-lg text-xs font-bold"
            style={{ padding: '7px 14px', background: '#059669', color: '#fff' }}>
            Done
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl p-4 flex flex-col gap-3" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>New staff account</p>

      <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))' }}>
        {[
          ['name', 'Full name', 'text'],
          ['email', 'Email', 'email'],
          ['mobile_no', 'Mobile (optional)', 'tel'],
        ].map(([key, label, type]) => (
          <label key={key} className="flex flex-col gap-1">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{label}</span>
            <input type={type} value={form[key]} onChange={e => set(key, e.target.value)}
              className="rounded-lg text-sm px-2.5 py-2"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          </label>
        ))}

        <label className="flex flex-col gap-1">
          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Role</span>
          <select value={form.role} onChange={e => set('role', e.target.value)}
            className="rounded-lg text-sm px-2.5 py-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
            {roles.map(r => (
              <option key={r.value} value={r.value}>{r.label}{r.is_admin ? ' — admin access' : ''}</option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1">
          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
            Password
          </span>
          <input type="text" value={form.password} onChange={e => set('password', e.target.value)}
            placeholder="Leave blank to generate one"
            className="rounded-lg text-sm px-2.5 py-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        </label>
      </div>

      <div className="flex gap-2">
        <button onClick={submit} disabled={busy}
          className="rounded-lg text-xs font-bold disabled:opacity-50"
          style={{ padding: '7px 14px', background: '#7C3AED', color: '#fff' }}>
          {busy ? 'Creating…' : 'Create account'}
        </button>
        <button onClick={onClose} disabled={busy}
          className="rounded-lg text-xs font-semibold disabled:opacity-50"
          style={{ padding: '7px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          Cancel
        </button>
      </div>
    </div>
  )
}

/* ── password reset ──────────────────────────────────────────────────── */

function ResetPassword({ person, onClose }) {
  const [pw, setPw]     = useState('')
  const [busy, setBusy] = useState(false)
  const toast = useToast()

  async function submit() {
    setBusy(true)
    try {
      await sangoeTrackApi.staff.resetPassword(person.user_id, pw)
      toast.success(`Password reset for ${person.name}`, 'Give it to them directly — it is not emailed.')
      onClose()
    } catch (err) {
      toast.error(trackErrorMessage(err, 'Could not reset the password.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div role="dialog" aria-modal="true" aria-label={`Reset password for ${person.name}`}
      onClick={onClose}
      className="fixed inset-0 z-50 flex items-center justify-center p-6"
      style={{ background: 'rgba(0,0,0,0.65)' }}>
      <div onClick={e => e.stopPropagation()}
        className="rounded-xl p-5 flex flex-col gap-3"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', width: 'min(420px, 100%)' }}>
        <div>
          <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Reset password</p>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{person.name} · {person.email}</p>
        </div>

        <input type="text" value={pw} onChange={e => setPw(e.target.value)} autoFocus
          placeholder="New password (min 8 characters)"
          className="rounded-lg text-sm px-2.5 py-2"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />

        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          They are not told automatically — pass it on yourself.
        </p>

        <div className="flex gap-2">
          <button onClick={submit} disabled={busy || pw.trim().length < 8}
            className="rounded-lg text-xs font-bold disabled:opacity-50"
            style={{ padding: '7px 14px', background: '#7C3AED', color: '#fff' }}>
            {busy ? 'Resetting…' : 'Reset password'}
          </button>
          <button onClick={onClose} disabled={busy}
            className="rounded-lg text-xs font-semibold disabled:opacity-50"
            style={{ padding: '7px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            Cancel
          </button>
        </div>
      </div>
    </div>
  )
}

/* ── page ────────────────────────────────────────────────────────────── */

export default function TrackStaff() {
  const [rows, setRows]       = useState([])
  const [roles, setRoles]     = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)
  const [query, setQuery]     = useState('')
  const [adding, setAdding]   = useState(false)
  const [resetting, setResetting] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      // Roles are only needed for the create form, but they are small and this
      // way opening it is instant rather than showing an empty dropdown.
      const [people, roleList] = await Promise.all([
        sangoeTrackApi.staff.list(),
        sangoeTrackApi.staff.roles().catch(() => null),
      ])
      setRows(Array.isArray(people?.data) ? people.data : [])
      setRoles(Array.isArray(roleList?.data) ? roleList.data : [{ value: 'staff', label: 'Staff Member' }])
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return rows
    return rows.filter(r =>
      [r.name, r.email, r.employee_id, r.department, r.designation]
        .some(v => String(v ?? '').toLowerCase().includes(q))
    )
  }, [rows, query])

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <TrackHeader
        title="Staff Directory"
        subtitle={rows.length ? `${rows.length} people on SangoeTrack.` : 'People who clock in on SangoeTrack.'}
        onRefresh={load}
        loading={loading}
      />

      <div className="flex flex-wrap gap-2 items-center">
        <div className="flex items-center gap-2 rounded-lg px-2.5 flex-1"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', minWidth: 200, maxWidth: 340 }}>
          <Search size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
          <input value={query} onChange={e => setQuery(e.target.value)}
            placeholder="Search name, email, code, department"
            className="text-sm py-2 flex-1 bg-transparent outline-none"
            style={{ color: 'var(--text-h)' }} />
        </div>
        {!adding && (
          <button onClick={() => setAdding(true)}
            className="rounded-lg text-xs font-bold flex items-center gap-1.5"
            style={{ padding: '8px 14px', background: '#7C3AED', color: '#fff' }}>
            <UserPlus size={13} /> Add staff
          </button>
        )}
      </div>

      {adding && <CreateStaff roles={roles} onCreated={load} onClose={() => setAdding(false)} />}

      {error ? (
        <LoadError error={error} onRetry={load} title="Could not load the directory" />
      ) : loading ? (
        <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : visible.length === 0 ? (
        <EmptyState icon={Users}
          title={query ? 'Nobody matches that' : 'No staff on SangoeTrack yet'}
          description={query ? 'Try a different search.' : 'Add someone to let them start clocking in.'} />
      ) : (
        <div className="rounded-xl overflow-x-auto" style={{ border: '1px solid var(--border)' }}>
          <table className="w-full text-sm" style={{ minWidth: 760 }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)' }}>
                {['Person', 'Code', 'Department', 'Designation', 'Joined', ''].map(h => (
                  <th key={h} className="text-[11px] font-bold uppercase tracking-wider px-3 py-2.5 text-left"
                    style={{ color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {visible.map(p => (
                <tr key={p.user_id} style={{ borderTop: '1px solid var(--border)' }}>
                  <td className="px-3 py-2.5">
                    <div className="flex items-center gap-2.5">
                      {p.avatar
                        ? <img src={p.avatar} alt="" width={28} height={28} className="rounded-full object-cover" style={{ flexShrink: 0 }} />
                        : <span className="rounded-full flex items-center justify-center text-[11px] font-bold"
                            style={{ width: 28, height: 28, flexShrink: 0, background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                            {String(p.name ?? '?').charAt(0).toUpperCase()}
                          </span>}
                      <div style={{ minWidth: 0 }}>
                        <div className="font-semibold truncate" style={{ color: 'var(--text-h)' }}>{p.name}</div>
                        <div className="text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>{p.email}</div>
                      </div>
                    </div>
                  </td>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                    {p.employee_id}
                  </td>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-h)' }}>{p.department}</td>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-h)' }}>{p.designation}</td>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{p.company_doj}</td>
                  <td className="px-3 py-2.5 text-right">
                    <button onClick={() => setResetting(p)}
                      title={`Reset password for ${p.name}`}
                      className="rounded-lg text-[11px] font-semibold flex items-center gap-1.5 ml-auto"
                      style={{ padding: '5px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa', whiteSpace: 'nowrap' }}>
                      <KeyRound size={12} /> Reset
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {resetting && <ResetPassword person={resetting} onClose={() => setResetting(null)} />}
    </div>
  )
}
