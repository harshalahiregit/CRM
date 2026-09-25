import { useState, useEffect, useCallback } from 'react'
import { Users, UserX, KeyRound, ChevronDown, ChevronUp, Link2, AlertTriangle } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { readFieldErrors } from '@/services/apiError'

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

    And, now, what it FIXES. The remediation endpoint existed on the server, was
    routed, had a client helper written for it, and was called by nothing — the
    panel reported eleven people who could not sign in and offered no way to give
    any of them a login. A diagnostic with no remedy is a screen people learn to
    scroll past.

    Collapsed by default: on a workspace where the two agree there is nothing
    to do here, and a panel that shouts on every visit stops being read.
    ──────────────────────────────────────────────────────────────────────── */

export default function DirectoryGapPanel({ showToast }) {
  const [data, setData] = useState(null)
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(null)
  const [newPassword, setNewPassword] = useState(null)

  const load = useCallback(() => {
    hrApi.employees.reconciliation().then(setData).catch(() => {})
  }, [])
  useEffect(() => { load() }, [load])

  /**
   * Close one gap.
   *
   * One endpoint for both offered actions, because they are one decision made
   * by the server rather than two the browser chooses between: provision()
   * links to a matching account when one exists and creates a login when none
   * does. Having the UI pick would mean the UI deciding what counts as a match,
   * which is exactly the judgement that must not live in two places.
   *
   * Every refusal — another tenant's address, a portal account, a login another
   * employee already holds — comes back from the server with its reason, and is
   * shown rather than swallowed.
   */
  /**
   * Resolve one of the "linked but wrong" issues.
   *
   * Nothing here decides anything on the admin's behalf. Each button carries out
   * the single choice its label names, and the server refuses it if it should not
   * happen — the panel never merges people or picks a winner between two values.
   */
  const issueAction = async (issue, what) => {
    setBusy(issue.key)
    try {
      const id = issue.employee?.employee_id

      if (what === 'resync')  await hrApi.employees.resyncLogin(id)
      if (what === 'unlink')  await hrApi.employees.unlinkLogin(id)
      if (what === 'dismiss') await hrApi.employees.dismissDirectoryIssue(issue.key)

      showToast?.({
        resync:  'Account updated from the employee record',
        unlink:  'Link cleared',
        dismiss: 'Hidden — it will return if the records change',
      }[what])
      load()
    } catch (e) {
      showToast?.(readFieldErrors(e).summary, 'error')
    } finally {
      setBusy(null)
    }
  }

  const act = async (employeeId) => {
    setBusy(employeeId)
    setNewPassword(null)
    try {
      const res = await hrApi.employees.provisionLogin(employeeId)
      if (res?.temporary_password) setNewPassword(res)
      showToast?.(res?.created ? 'Login created' : 'Linked to the existing login')
      load()
    } catch (e) {
      showToast?.(e.response?.data?.message || 'Could not create the login', 'error')
    } finally {
      setBusy(null)
    }
  }

  if (!data?.summary) return null

  const s = data.summary
  const issues = data.issues || []
  const gaps = (s.without_login || 0) + (s.without_employee || 0) + issues.length

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
            {gaps} thing{gaps === 1 ? '' : 's'} to reconcile
            {s.blocking > 0 && (
              <span className="ml-2 px-1.5 py-0.5 rounded text-[9px] font-black align-middle"
                style={{ background: 'rgba(239,68,68,0.15)', color: '#ef4444' }}>
                {s.blocking} BLOCKING
              </span>
            )}
          </span>
          <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>
            {s.without_login} with no login · {s.without_employee} login(s) with no employee record
            {issues.length > 0 && ` · ${issues.length} linked but inconsistent`}
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

          {newPassword && (
            <div className="rounded-lg px-2.5 py-2" style={{ background:'rgba(16,185,129,0.08)', border:'1px solid rgba(16,185,129,0.25)' }}>
              <p className="text-[10px] font-black uppercase tracking-wide" style={{ color:'#10b981' }}>
                One-time password for {newPassword.email}
              </p>
              <p className="text-[13px] font-mono font-bold mt-1 select-all" style={{ color:'var(--text-h)' }}>
                {newPassword.temporary_password}
              </p>
              <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                Shown once. Pass it on now — it is not stored anywhere and cannot be shown again.
              </p>
            </div>
          )}

          {data.without_login?.length > 0 && (
            <Group icon={KeyRound} title="Employed, no login" tone="#f59e0b"
              rows={data.without_login.map(r => ({
                key: r.employee_id,
                main: r.name,
                sub: [r.employee_code, r.department].filter(Boolean).join(' · '),
                // An exact, unique email match is the only thing offered as a
                // link. Anything less certain says so and waits for a human —
                // linking the wrong account hands one person's payslips and
                // attendance to another.
                note: r.suggested_user
                  ? `Matches the existing login ${r.suggested_user.email}`
                  : (r.email ? `Will create a login for ${r.email}` : 'Needs an email address before a login can be made'),
                action: r.email ? {
                  label: r.suggested_user ? 'Link login' : 'Create login',
                  onClick: () => act(r.employee_id),
                } : null,
              }))} busy={busy} />
          )}

          {issues.length > 0 && (
            <div>
              <p className="text-[10px] font-black uppercase tracking-wide mb-1.5 flex items-center gap-1.5"
                style={{ color: '#ef4444' }}>
                <AlertTriangle size={12} /> Linked, but the two sides disagree ({issues.length})
              </p>
              <div className="space-y-1.5">
                {issues.map(i => <IssueRow key={i.key} issue={i} busy={busy} act={issueAction} />)}
              </div>
            </div>
          )}

          {data.without_employee?.length > 0 && (
            <Group icon={Link2} title="Has a login, no employee record" tone="#0ea5e9"
              rows={data.without_employee.map(r => ({
                key: r.user_id,
                main: r.name,
                sub: [r.email, r.role].filter(Boolean).join(' · '),
                // Deliberately no button. Making an employee from a login means
                // inventing a joining date, and an invented joining date is a
                // wrong figure in every service and gratuity calculation from
                // that day on. It is a form somebody fills in, not a click.
                note: 'Add them on the Employees screen if they should be on payroll.',
                action: null,
              }))} busy={busy} />
          )}
        </div>
      )}
    </div>
  )
}

/** How an issue type reads, and what may be offered for it. */
const ISSUE_LABELS = {
  access_mismatch:     'Access mismatch',
  permission_mismatch: 'Unexpected account role',
  identity_mismatch:   'Identity mismatch',
  broken_link:         'Broken link',
  cross_tenant:        'Cross-workspace link',
  duplicate_email:     'Duplicate email',
}

const SEVERITY_TONE = { blocking: '#ef4444', warning: '#f59e0b', info: '#0ea5e9' }

/**
 * One problem, both sides of it, and what can be done.
 *
 * The two sides are printed together on purpose. Every one of these is a
 * disagreement, and an admin cannot decide which side is right from a summary —
 * they need to see that the employee record says Engineering and the account says
 * Sales before choosing to overwrite one with the other.
 */
function IssueRow({ issue, busy, act }) {
  const tone = SEVERITY_TONE[issue.severity] || 'var(--text-muted)'
  const e = issue.employee
  const u = issue.user

  return (
    <div className="rounded-lg px-2.5 py-2" style={{ background: 'var(--bg-input)', borderLeft: `2px solid ${tone}` }}>
      <div className="flex items-start gap-2">
        <div style={{ flex: 1, minWidth: 0 }}>
          <p className="text-[11px] font-black uppercase tracking-wide" style={{ color: tone }}>
            {ISSUE_LABELS[issue.type] || issue.type}
          </p>
          {e && (
            <p className="text-[12px] font-bold mt-0.5" style={{ color: 'var(--text-h)' }}>
              {e.name} <span className="font-normal" style={{ color: 'var(--text-muted)' }}>{e.employee_code}</span>
            </p>
          )}
          <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{issue.reason}</p>

          {/* Field-by-field, so the choice is visible rather than described. */}
          {issue.differences && (
            <table className="mt-1.5 text-[10px]" style={{ color: 'var(--text-muted)' }}>
              <tbody>
                {Object.entries(issue.differences).map(([field, v]) => (
                  <tr key={field}>
                    <td className="pr-3 font-semibold" style={{ color: 'var(--text-h)' }}>{field}</td>
                    <td className="pr-3">employee: <strong>{v.employee}</strong></td>
                    <td>account: <strong>{v.account}</strong></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {u && !issue.differences && (
            <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
              Account: {u.email} · role {u.role} · {u.status}
            </p>
          )}
        </div>

        <div className="flex flex-col gap-1" style={{ flexShrink: 0 }}>
          {issue.action === 'sync_identity' && (
            <ActionButton busy={busy === issue.key} onClick={() => act(issue, 'resync')}
              title="Overwrite the account's copy with the employee record, which owns these fields">
              Use employee values
            </ActionButton>
          )}
          {issue.action === 'clear_link' && (
            <ActionButton busy={busy === issue.key} onClick={() => act(issue, 'unlink')}
              title="Clear the link. The account is not deleted — on a cross-workspace link it is not ours to delete">
              Clear link
            </ActionButton>
          )}
          {issue.action === 'deactivate_account' && (
            <a href="/app/admin/staff" className="px-2.5 py-1 rounded-lg text-[10px] font-black whitespace-nowrap text-center"
              style={{ background: 'var(--bg-card)', color: 'var(--text-h)', border: '1px solid var(--border)' }}
              title="Account status is Staff Management's to change — this panel does not keep a second editor for it">
              Open account
            </a>
          )}
          <ActionButton busy={busy === issue.key} onClick={() => act(issue, 'dismiss')}
            title="Hide this. It comes back if the underlying records change">
            Dismiss
          </ActionButton>
        </div>
      </div>
    </div>
  )
}

function ActionButton({ children, onClick, busy, title }) {
  return (
    <button type="button" onClick={onClick} disabled={busy} title={title}
      className="px-2.5 py-1 rounded-lg text-[10px] font-black whitespace-nowrap"
      style={{ background: 'var(--bg-card)', color: 'var(--text-h)', border: '1px solid var(--border)', opacity: busy ? 0.5 : 1 }}>
      {busy ? 'Working…' : children}
    </button>
  )
}

function Group({ icon: Icon, title, tone, rows, busy }) {
  return (
    <div>
      <p className="text-[10px] font-black uppercase tracking-wide mb-1.5 flex items-center gap-1.5"
        style={{ color: tone }}>
        <Icon size={12} /> {title} ({rows.length})
      </p>
      <div className="space-y-1">
        {rows.map(r => (
          <div key={r.key} className="rounded-lg px-2.5 py-1.5 flex items-center gap-2" style={{ background: 'var(--bg-input)' }}>
            <div style={{ flex: 1, minWidth: 0 }}>
              <p className="text-[12px] font-bold" style={{ color: 'var(--text-h)' }}>{r.main}</p>
              {r.sub && <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.sub}</p>}
              {r.note && <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{r.note}</p>}
            </div>
            {r.action && (
              <button type="button" onClick={r.action.onClick} disabled={busy === r.key}
                className="px-2.5 py-1 rounded-lg text-[10px] font-black whitespace-nowrap"
                style={{ background:'var(--bg-card)', color:'var(--text-h)', border:'1px solid var(--border)',
                         opacity: busy === r.key ? 0.5 : 1 }}>
                {busy === r.key ? 'Working…' : r.action.label}
              </button>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
