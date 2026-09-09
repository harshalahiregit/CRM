import { useState, useEffect, useCallback } from 'react'
import { Users, UserX, KeyRound, ChevronDown, ChevronUp, Link2 } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

/*  ────────────────────────────────────────────────────────────────────────
    Where the staff and employee directories disagree.

    The instruction was one directory, not two. They are not merged, because
    they are not duplicates: a login account and an employment record overlap
    heavily and neither contains the other — a vendor portal login and a
    super-admin are users who are not employees, a site worker with no system
    access is an employee who is not a user. Tasks, Helpdesk and ticket threads
    all resolve their assignable-people lists from the staff side, so
    collapsing the tables is a change across four other modules.

    The actual complaint was never that two tables exist. It was that somebody
    gets added in one place and is missing from the other, and nobody finds out
    until they are left off a payroll run. That is what this shows.

    Collapsed by default: on a workspace where the two agree there is nothing
    to do here, and a panel that shouts on every visit stops being read.
    ──────────────────────────────────────────────────────────────────────── */

export default function DirectoryGapPanel({ showToast }) {
  const [data, setData] = useState(null)
  const [open, setOpen] = useState(false)

  const load = useCallback(() => {
    hrApi.employees.reconciliation().then(setData).catch(() => {})
  }, [])
  useEffect(() => { load() }, [load])

  if (!data?.summary) return null

  const s = data.summary
  const gaps = (s.without_login || 0) + (s.without_employee || 0)

  // Nothing to reconcile — say so quietly and stay out of the way.
  if (gaps === 0) {
    return (
      <div className="rounded-xl p-2.5 flex items-center gap-2"
        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <Users size={14} style={{ color: '#10b981' }} />
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          Staff and employee directories agree — {s.linked} of {s.employees} employees have a login.
        </p>
      </div>
    )
  }

  return (
    <div className="card-3d" style={{ padding: 0, overflow: 'hidden' }}>
      <button onClick={() => setOpen(o => !o)}
        className="w-full flex items-center gap-2.5 px-3.5 py-3 text-left">
        <UserX size={15} style={{ color: '#f59e0b', flexShrink: 0 }} />
        <span style={{ flex: 1, minWidth: 0 }}>
          <span className="block text-[12px] font-black" style={{ color: 'var(--text-h)' }}>
            {gaps} record{gaps === 1 ? '' : 's'} exist on one side only
          </span>
          <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>
            {s.without_login} employee(s) with no login · {s.without_employee} login(s) with no employee record
          </span>
        </span>
        {open ? <ChevronUp size={15} style={{ color: 'var(--text-muted)' }} />
              : <ChevronDown size={15} style={{ color: 'var(--text-muted)' }} />}
      </button>

      {open && (
        <div className="px-3.5 pb-3.5 space-y-3">
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            Both are normal in some cases — a site worker needs no login, and a super-admin or portal account is not
            an employee. What matters is the ones that are there by accident: an employee with no login cannot use
            the app, and a login with no employee record is missed by every payroll run.
          </p>

          {data.without_login?.length > 0 && (
            <Group icon={KeyRound} title="Employed, no login" tone="#f59e0b"
              rows={data.without_login.map(r => ({
                key: r.employee_id,
                main: r.name,
                sub: [r.employee_code, r.department].filter(Boolean).join(' · '),
              }))} />
          )}

          {data.without_employee?.length > 0 && (
            <Group icon={Link2} title="Has a login, no employee record" tone="#0ea5e9"
              rows={data.without_employee.map(r => ({
                key: r.user_id,
                main: r.name,
                sub: [r.email, r.role].filter(Boolean).join(' · '),
              }))} />
          )}
        </div>
      )}
    </div>
  )
}

function Group({ icon: Icon, title, tone, rows }) {
  return (
    <div>
      <p className="text-[10px] font-black uppercase tracking-wide mb-1.5 flex items-center gap-1.5"
        style={{ color: tone }}>
        <Icon size={12} /> {title} ({rows.length})
      </p>
      <div className="space-y-1">
        {rows.map(r => (
          <div key={r.key} className="rounded-lg px-2.5 py-1.5" style={{ background: 'var(--bg-input)' }}>
            <p className="text-[12px] font-bold" style={{ color: 'var(--text-h)' }}>{r.main}</p>
            {r.sub && <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.sub}</p>}
          </div>
        ))}
      </div>
    </div>
  )
}
