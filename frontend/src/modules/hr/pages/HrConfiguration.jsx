import { useNavigate } from 'react-router-dom'
import {
  Building2, Tag, Layers, UserCog, MapPin, Clock, CalendarOff, PartyPopper,
  LogOut, GraduationCap, ShieldCheck, Settings2, Network, KeyRound, ArrowRight,
  GitBranch,
} from 'lucide-react'
import { useAuth } from '@/context/AuthContext'

/**
 * HR Configuration — an index, not a new settings store.
 *
 * Every master this page links to already existed and already worked; what did
 * not exist was one place that said so. They are spread across seven screens
 * because each grew inside the module that uses it — leave types inside Leave
 * Management, exit types inside Exit Management, shifts inside HR Operations —
 * which is reasonable for somebody already in that module and useless for
 * somebody asking "where do I configure HR?". An administrator looking for a
 * designation found the Employees filter, which lists only the values in USE,
 * and concluded the catalogue was fixed. It was not: the master had fifteen
 * entries and a CRUD screen two clicks away.
 *
 * So this page LINKS rather than re-implements. No master is moved, no API is
 * duplicated, and each destination stays the one screen that owns its data —
 * which is what stops this index and the real editors from drifting apart.
 *
 * WHAT IS DELIBERATELY ABSENT. Employment Types, Business Units and Locations
 * are not listed, because they have no backend to link to: there is no table
 * and no endpoint for employment types, while business units and locations are
 * DERIVED at read time (distinct values off manpower requests and employee
 * rows) and so cannot be edited. Listing them would have been the one thing
 * worse than not listing them — a control that looks configurable and is not.
 */

/** Destinations that exist. Each `to` is a real route; each master is real. */
const GROUPS = [
  {
    title: 'Organization structure',
    hint: 'Who the company is made of. These feed the Department, Designation and Grade pickers on every employee form.',
    items: [
      { icon: Building2, label: 'Departments',  desc: 'Create, rename and retire departments',        to: '/app/hr/organization-setup' },
      { icon: Tag,       label: 'Designations', desc: 'Job titles offered on the employee form',      to: '/app/hr/organization-setup' },
      { icon: Layers,    label: 'Grades',       desc: 'Seniority bands a designation can sit in',     to: '/app/hr/organization-setup' },
      { icon: UserCog,   label: 'Org Roles',    desc: 'Org-chart roles — not access permissions',     to: '/app/hr/organization-setup' },
      { icon: Network,   label: 'Organization Chart', desc: 'The reporting line as it stands today',  to: '/app/hr/org-chart' },
    ],
  },
  {
    title: 'Workplace & time',
    hint: 'Where and when people work.',
    items: [
      { icon: MapPin, label: 'Branches', desc: 'Workplace locations',                      to: '/app/hr/operations' },
      { icon: Clock,  label: 'Shifts',   desc: 'Shift patterns and their working windows', to: '/app/hr/operations' },
      { icon: Settings2, label: 'Attendance & working-hour rules',
        desc: 'Day start/end, grace period, late marks, deductions', to: '/app/hr/settings' },
    ],
  },
  {
    title: 'Leave & holidays',
    items: [
      { icon: CalendarOff,  label: 'Leave Types',   desc: 'Casual, sick, earned — and their rules', to: '/app/hr/leave-management' },
      { icon: PartyPopper,  label: 'Holidays',      desc: 'The company holiday calendar',           to: '/app/hr/holidays' },
    ],
  },
  {
    title: 'Approvals & workflow',
    items: [
      { icon: GitBranch, label: 'Approval Workflows', desc: 'Who approves each request, and in what order', to: '/app/hr/approval-workflows' },
    ],
  },
  {
    title: 'Employee lifecycle',
    items: [
      { icon: ShieldCheck,    label: 'Probation Types & Policies', desc: 'Probation periods and review rules', to: '/app/hr/probation-management' },
      { icon: LogOut,         label: 'Exit Types',                 desc: 'Resignation, termination and the rest', to: '/app/hr/exit-management' },
      { icon: GraduationCap,  label: 'Training Catalogue',         desc: 'Categories, types and providers',    to: '/app/hr/learning-development' },
    ],
  },
]

/**
 * Access control is its own group, and its own sentence.
 *
 * This is the distinction the whole page exists to draw: an employee record
 * says who somebody IS — their department, their manager, their probation. It
 * says nothing about what they may OPEN. That is a staff account with a role,
 * a module permission grid and a data scope, and it lives in Staff Management.
 *
 * Shown only to admins because /api/admin/* is role:admin on the server. A tile
 * that leads to a 403 is worse than no tile.
 */
const ACCESS_ITEM = {
  icon: KeyRound,
  label: 'Staff Access & Permissions',
  desc: 'Roles, module permissions and data scope — who can open which HR module',
  to: '/app/admin/staff',
}

export default function HrConfiguration() {
  const navigate = useNavigate()
  /*
   * The SERVER's answer, not a locally re-derived one. isAdmin is
   * permissions.is_admin from the /me payload, which the backend computes as
   * StaffPermissionService::bypasses(); BYPASS_ROLES is exactly ['admin'], the
   * same test role:admin applies to /api/admin/*. So the tile appears only
   * where the API would actually answer.
   */
  const { isAdmin } = useAuth()

  const Tile = ({ icon: Icon, label, desc, to }) => (
    <button
      type="button"
      onClick={() => navigate(to)}
      className="group text-left p-3 rounded-xl transition-colors w-full"
      style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}
    >
      <div className="flex items-start gap-2.5">
        <span className="mt-0.5 shrink-0"><Icon size={16} style={{ color: '#a78bfa' }} /></span>
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-1">
            <span className="text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>{label}</span>
            <ArrowRight size={12} className="opacity-0 group-hover:opacity-100 transition-opacity shrink-0" style={{ color: 'var(--text-muted)' }} />
          </span>
          <span className="block text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{desc}</span>
        </span>
      </div>
    </button>
  )

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>HR Configuration</h1>
        <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
          Every HR master, and where it is maintained. Each item opens the screen that owns it.
        </p>
      </div>

      {/* Access control first: it is the distinction people come here having missed. */}
      {isAdmin && (
        <section>
          <h2 className="text-xs font-semibold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>
            Access control
          </h2>
          <p className="text-[11px] mb-2" style={{ color: 'var(--text-muted)' }}>
            An employee record describes a person. A staff account decides what they can open — the two are edited in different places.
          </p>
          <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
            <Tile {...ACCESS_ITEM} />
          </div>
        </section>
      )}

      {GROUPS.map((g) => (
        <section key={g.title}>
          <h2 className="text-xs font-semibold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>
            {g.title}
          </h2>
          {g.hint && (
            <p className="text-[11px] mb-2" style={{ color: 'var(--text-muted)' }}>{g.hint}</p>
          )}
          <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
            {g.items.map((it) => <Tile key={it.label} {...it} />)}
          </div>
        </section>
      ))}

      {/*
        Stated rather than hidden. Somebody who came looking for Employment Type
        deserves to know it is absent by fact, not to keep hunting for a screen
        that was never built.
      */}
      <section>
        <h2 className="text-xs font-semibold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>
          Not configurable yet
        </h2>
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          <strong>Employment Types</strong> have no master yet. <strong>Business Units</strong> and{' '}
          <strong>Locations</strong> are read back from what employees and manpower requests already
          record, so they appear in dropdowns as data is entered rather than being maintained here.
        </p>
      </section>
    </div>
  )
}
