import { Fragment, useState, useEffect } from 'react'
import { NavLink, Outlet, useNavigate, useLocation } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import { hrApi } from '@/services/hrApi'
import {
  LayoutDashboard, ClipboardList, Briefcase, Users,
  Calendar, FileText, Rocket, Building2, ChevronRight, ArrowLeft, ArrowRight, CalendarCheck, Network, Headset, BadgeCheck, UserCheck
} from 'lucide-react'

// ── The numbered Recruitment Pipeline (unchanged: same order, routes, full
//    visibility). Stages are grouped by permanent BUSINESS PHASE — a purely
//    visual grouping (colour + label), not ownership, permissions or visibility. ──
const PHASES = {
  planning:  { label: 'Planning',           active: 'Planning Phase',    color: '#a78bfa', bg: 'rgba(124,58,237,0.16)' },
  recruit:   { label: 'Recruitment',        active: 'Recruitment Phase', color: '#60a5fa', bg: 'rgba(59,130,246,0.16)' },
  lifecycle: { label: 'Employee Lifecycle', active: 'Employee Lifecycle', color: '#2dd4bf', bg: 'rgba(20,184,166,0.16)' },
}

// `ck` = key into the live-counts map; `metric` = what that count means (tooltip).
// Counts reuse existing stats/dashboard APIs only.
const PIPELINE = [
  { n: 1,  label: 'Dashboard',            short: 'Dashboard',       path: '/app/hr/dashboard',           icon: LayoutDashboard, phase: 'planning'                                                          },
  { n: 2,  label: 'Manpower Requests',    short: 'Manpower',        path: '/app/hr/manpower-requests',   icon: ClipboardList,   phase: 'planning',  ck: 'manpower',      metric: 'Manpower Requests'          },
  { n: 3,  label: 'Job Postings',         short: 'Jobs',            path: '/app/hr/jobs',                icon: Briefcase,       phase: 'planning',  ck: 'jobs',          metric: 'Active Job Postings'        },
  { n: 4,  label: 'Candidates',           short: 'Candidates',      path: '/app/hr/candidates',          icon: Users,           phase: 'recruit',   ck: 'candidates',    metric: 'Active Candidates'          },
  { n: 5,  label: 'Interviews',           short: 'Interviews',      path: '/app/hr/interviews',          icon: Calendar,        phase: 'recruit',   ck: 'interviews',    metric: 'Scheduled Interviews'       },
  { n: 6,  label: 'Pre-Offer Onboarding', short: 'Pre-Offer',       path: '/app/hr/onboarding',          icon: Rocket,          phase: 'recruit',   ck: 'preoffer',      metric: 'Pending Verifications'      },
  { n: 7,  label: 'Offer Letters',        short: 'Offers',          path: '/app/hr/offers',              icon: FileText,        phase: 'recruit',   ck: 'offers',        metric: 'Pending Offers'             },
  { n: 8,  label: 'Employees',            short: 'Employees',       path: '/app/hr/employees',           icon: Building2,       phase: 'lifecycle', ck: 'employees',     metric: 'Newly Joined Employees'     },
  { n: 9,  label: 'Employee Onboarding',  short: 'Emp. Onboarding', path: '/app/hr/employee-onboarding', icon: UserCheck,       phase: 'lifecycle', ck: 'empOnboarding', metric: 'Pending Employee Onboardings' },
  { n: 10, label: 'Attendance',           short: 'Attendance',      path: '/app/hr/attendance',          icon: CalendarCheck,   phase: 'lifecycle'                                                         },
]

const SUPPORTING = [
  { label: 'Recruitment Services', path: '/app/hr/recruitment-services', icon: Network },
  { label: 'Recruiter Desk',       path: '/app/hr/recruiter-workspace',  icon: Headset },
  { label: 'Company Approvals',    path: '/app/hr/company-approvals',    icon: BadgeCheck },
]

const PHASE_BANDS = [
  { key: 'planning',  range: '1–3'  },
  { key: 'recruit',   range: '4–7'  },
  { key: 'lifecycle', range: '8–10' },
]

const num = (v) => (typeof v === 'number' ? v : null)
const unwrap = (r) => (r?.data ?? r)
// Count list items whose `field` is in `values`. If the field is missing from the
// payload, gracefully fall back to the total length (never a new query).
const statusCount = (x, field, values) => {
  const a = Array.isArray(x) ? x : (Array.isArray(x?.data) ? x.data : null)
  if (!a) return null
  if (a.length === 0) return 0
  if (a[0][field] === undefined) return a.length
  return a.filter(o => values.includes(o[field])).length
}

export default function HRLayout() {
  const { isDark } = useTheme()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const [counts, setCounts] = useState({})

  // Live stage counts — reuse EXISTING stats / dashboard endpoints only. Fetched
  // once when the HR workspace mounts (the layout persists across HR pages);
  // best-effort — a failed call simply omits that count.
  useEffect(() => {
    let alive = true
    Promise.allSettled([
      hrApi.dashboard.get(),            // manpower.total_requests + kpis.active_candidates
      hrApi.jobs.stats(),               // active_jobs
      hrApi.interviews.stats(),         // upcoming + today
      hrApi.onboarding.list(),          // pre-offer onboarding records
      hrApi.offers.list(),              // offers
      hrApi.employees.stats(),          // total
      hrApi.employeeOnboarding.dashboard(), // cards.total
    ]).then(([dash, jobs, iv, onb, off, emp, eob]) => {
      if (!alive) return
      const D = dash.status === 'fulfilled' ? unwrap(dash.value) : {}
      const JOBS = jobs.status === 'fulfilled' ? unwrap(jobs.value) : {}
      const IV = iv.status === 'fulfilled' ? unwrap(iv.value) : {}
      const EMP = emp.status === 'fulfilled' ? unwrap(emp.value) : {}
      const EOB = eob.status === 'fulfilled' ? unwrap(eob.value) : {}
      const ONBv = onb.status === 'fulfilled' ? onb.value : null
      const OFFv = off.status === 'fulfilled' ? off.value : null

      const ivCount = IV && (IV.upcoming != null || IV.today != null) ? ((IV.upcoming || 0) + (IV.today || 0)) : null
      // Pre-Offer: candidate onboardings awaiting HR verification (Submitted).
      const preoffer = statusCount(ONBv, 'verification_status', ['Submitted'])
      // Offers: prefer the dashboard's pending-offer metric; else count non-final offers.
      const offers = num(D?.recruitment_kpis?.offer_pending) ?? statusCount(OFFv, 'status', ['Generated', 'Sent', 'Viewed'])
      // Employees: newly joined this month; fall back to total headcount.
      const employees = num(D?.kpis?.hired_this_month) ?? num(EMP?.total)
      // Employee Onboarding: pending = not-yet-completed; fall back to total.
      const eobTotal = num(EOB?.cards?.total), eobDone = num(EOB?.cards?.completed)
      const empOnboarding = (eobTotal != null && eobDone != null) ? Math.max(0, eobTotal - eobDone) : eobTotal

      setCounts({
        manpower:      num(D?.manpower?.total_requests),
        candidates:    num(D?.kpis?.active_candidates),
        jobs:          num(JOBS?.active_jobs),
        interviews:    ivCount,
        preoffer,
        offers,
        employees,
        empOnboarding,
      })
    })
    return () => { alive = false }
  }, [])

  const activeIdx = PIPELINE.findIndex(s => pathname === s.path || pathname.startsWith(s.path + '/'))
  const activeStage = activeIdx >= 0 ? PIPELINE[activeIdx] : null
  const prevStage = activeIdx > 0 ? PIPELINE[activeIdx - 1] : null
  const nextStage = (activeIdx >= 0 && activeIdx < PIPELINE.length - 1) ? PIPELINE[activeIdx + 1] : null

  return (
    <div className="space-y-0 -m-4 md:-m-6">

      {/* ── HR Module Header ────────────────────────────── */}
      <div
        className="relative overflow-hidden px-4 md:px-6 pt-5 pb-0"
        style={{
          background: isDark
            ? 'linear-gradient(135deg,rgba(124,58,237,0.2),rgba(91,33,182,0.1),transparent)'
            : 'linear-gradient(135deg,rgba(124,58,237,0.1),rgba(167,139,250,0.06),transparent)',
          borderBottom: '1px solid var(--border)',
        }}
      >
        {/* Module badge */}
        <div className="flex items-center gap-3 mb-4">
          <button
            onClick={() => navigate('/app/modules')}
            className="flex items-center gap-1.5 text-xs font-semibold transition-all duration-150"
            style={{ color: 'var(--text-muted)' }}
            onMouseEnter={e => e.currentTarget.style.color = 'var(--text-h)'}
            onMouseLeave={e => e.currentTarget.style.color = 'var(--text-muted)'}
          >
            <ArrowLeft size={12} /> Modules
          </button>
          <ChevronRight size={12} style={{ color: 'var(--text-muted)' }} />
          <div className="flex items-center gap-2">
            <div
              className="w-6 h-6 rounded-lg flex items-center justify-center text-xs"
              style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 3px 8px rgba(124,58,237,0.4)' }}
            >
              👥
            </div>
            <span className="text-xs font-bold" style={{ color: '#a78bfa' }}>HR &amp; Recruitment</span>
          </div>
        </div>

        {/* Numbered pipeline rail — stages numbered + chained; the number badge is
            tinted by business phase; live counts sit beside each stage. Supporting
            modules follow a divider. Styling/labels only — routes unchanged. */}
        <style>{`
          .hrnav-plate {
            margin-bottom: 10px; padding: 9px; border-radius: 9999px;
            background: ${isDark ? '#242229' : '#e9e4da'};
            box-shadow: ${isDark
              ? '6px 6px 16px rgba(0,0,0,0.55), -6px -6px 16px rgba(255,255,255,0.035)'
              : '6px 6px 15px rgba(183,175,160,0.55), -6px -6px 15px rgba(255,255,255,0.9)'};
          }
          .hrnav-rail {
            display: flex; align-items: stretch; gap: 0;
            overflow-x: auto; padding: 5px; border-radius: 9999px;
            background: ${isDark ? '#1f1d24' : '#ece7de'};
            box-shadow: ${isDark
              ? 'inset 2px 2px 6px rgba(0,0,0,0.6), inset -2px -2px 6px rgba(255,255,255,0.04), inset 0 0 16px rgba(150,120,255,0.08)'
              : 'inset 2px 2px 5px rgba(183,175,160,0.6), inset -2px -2px 5px rgba(255,255,255,0.85), inset 0 0 14px rgba(255,255,255,0.55)'};
          }
          .hrnav-rail::-webkit-scrollbar { display: none; }
          .hrnav-tab {
            position: relative; display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; border-radius: 9999px;
            font-size: 12px; font-weight: 600; white-space: nowrap; cursor: pointer;
            color: ${isDark ? 'var(--text-muted)' : '#9a9182'}; text-decoration: none;
            transition: color .3s ease, background .35s ease, box-shadow .4s ease, transform .18s ease;
          }
          .hrnav-tab:hover { color: ${isDark ? 'var(--text-h)' : '#5f5849'}; }
          .hrnav-tab:active { transform: scale(0.96); }
          .hrnav-tab.on {
            color: ${isDark ? '#c4b5fd' : '#7C3AED'};
            background: ${isDark ? 'linear-gradient(145deg,#3a3450,#2c2840)' : '#ffffff'};
            box-shadow: ${isDark
              ? '0 0 16px rgba(167,139,250,0.45), 3px 3px 8px rgba(0,0,0,0.5), -2px -2px 6px rgba(255,255,255,0.05)'
              : '0 0 16px rgba(255,255,255,0.9), 3px 3px 8px rgba(183,175,160,0.55), -3px -3px 8px rgba(255,255,255,0.95), 0 0 0 1px rgba(255,255,255,0.7)'};
          }
          .hrnav-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 16px; height: 16px; border-radius: 50%; font-size: 9.5px; font-weight: 800;
          }
          .hrnav-count { font-weight: 800; font-size: 11px; opacity: .7; margin-left: 1px; }
          .hrnav-tab.on .hrnav-count { opacity: .95; color: #a78bfa; }
          .hrnav-ico { opacity: .8; transition: transform .3s cubic-bezier(.34,1.56,.64,1), opacity .3s ease; }
          .hrnav-tab:hover .hrnav-ico { opacity: 1; }
          .hrnav-tab.on .hrnav-ico { transform: scale(1.12); opacity: 1; }
          .hrnav-arrow { flex: 0 0 auto; align-self: center; margin: 0 -1px; color: ${isDark ? 'rgba(255,255,255,0.16)' : 'rgba(160,150,134,0.55)'}; }
          .hrnav-div { flex: 0 0 auto; align-self: center; width: 1px; height: 20px; margin: 0 9px; background: ${isDark ? 'rgba(255,255,255,0.14)' : 'rgba(160,150,134,0.5)'}; }
          .hrnav-support { font-size: 8.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: ${isDark ? 'rgba(255,255,255,0.35)' : 'rgba(120,110,95,0.6)'}; align-self: center; padding: 0 8px 0 2px; white-space: nowrap; }

          .hrph { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin: 0 2px 12px; }
          .hrph-legend { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 10.5px; color: var(--text-muted); }
          .hrph-legend > .cap { font-weight: 700; text-transform: uppercase; letter-spacing: .05em; font-size: 9.5px; opacity: .8; }
          .hrph-chip { display: inline-flex; align-items: center; gap: 5px; font-weight: 700; }
          .hrph-chip i { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
          .hrph-nav { display: flex; gap: 8px; }
          .hrph-btn { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-decoration: none; padding: 5px 11px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-card); transition: color .2s, border-color .2s; }
          .hrph-btn:hover { color: #a78bfa; border-color: rgba(124,58,237,0.4); }
          .hrph-next { color: #a78bfa; border-color: rgba(124,58,237,0.35); }
          .hrph-stage { display: inline-flex; align-items: center; gap: 8px; font-size: 11.5px; font-weight: 700; color: var(--text-muted); }
          .hrph-pill { font-weight: 800; padding: 2px 9px; border-radius: 7px; }
        `}</style>

        <nav className="hrnav-plate">
          <div className="hrnav-rail">
            {PIPELINE.map((s, i) => {
              const p = PHASES[s.phase]
              const c = s.ck ? counts[s.ck] : null
              return (
                <Fragment key={s.path}>
                  {i > 0 && <ChevronRight className="hrnav-arrow" size={13} aria-hidden="true" />}
                  <NavLink to={s.path} title={`Stage ${s.n} · ${s.label} — ${p.active}${c != null ? `\n${c} ${s.metric}` : ''}`} className={({ isActive }) => `hrnav-tab${isActive ? ' on' : ''}`}>
                    <span className="hrnav-num" style={{ background: p.bg, color: p.color }}>{s.n}</span>
                    <s.icon size={13} className="hrnav-ico" />
                    {s.short}
                    {c != null && <span className="hrnav-count">({c})</span>}
                  </NavLink>
                </Fragment>
              )
            })}

            <span className="hrnav-div" aria-hidden="true" />
            <span className="hrnav-support">Support</span>
            {SUPPORTING.map(s => (
              <NavLink key={s.path} to={s.path} title={s.label} className={({ isActive }) => `hrnav-tab${isActive ? ' on' : ''}`}>
                <s.icon size={13} className="hrnav-ico" />
                {s.label}
              </NavLink>
            ))}
          </div>
        </nav>

        {/* Business-phase legend + (on pipeline pages) the active stage's phase + prev/next. */}
        <div className="hrph">
          <div className="hrph-legend">
            <span className="cap">Business Phase</span>
            {PHASE_BANDS.map(b => (
              <span key={b.key} className="hrph-chip"><i style={{ background: PHASES[b.key].color }} />{b.range} · {PHASES[b.key].label}</span>
            ))}
          </div>
          {activeStage && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
              <span className="hrph-stage">
                Stage {activeStage.n} of {PIPELINE.length}
                <span className="hrph-pill" style={{ background: PHASES[activeStage.phase].bg, color: PHASES[activeStage.phase].color }}>{PHASES[activeStage.phase].active}</span>
                · {activeStage.label}
              </span>
              <div className="hrph-nav">
                {prevStage && <NavLink to={prevStage.path} className="hrph-btn"><ArrowLeft size={12} /> {prevStage.short}</NavLink>}
                {nextStage && <NavLink to={nextStage.path} className="hrph-btn hrph-next">{nextStage.short} <ArrowRight size={12} /></NavLink>}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* ── HR Page Content ─────────────────────────────── */}
      <div className="p-4 md:p-6">
        <Outlet />
      </div>
    </div>
  )
}
