import RegistrationStatusCard from '@/components/vendor/RegistrationStatusCard'
import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ShoppingBag, FileText, Wallet, Rocket, CheckCircle2, Clock, ArrowRight, HardHat,
  RefreshCw, TrendingUp, Users, UserCheck, ClipboardList, HelpCircle, ChevronRight,
  AlertTriangle, BarChart3, PhoneCall, Building2, MessageSquare, ExternalLink,
} from 'lucide-react'
import { portalApi } from '@/services/portalApi'
import { useAuth } from '@/context/AuthContext'
import { obStatusCfg, poStatusCfg, invStatusCfg, fmtMoney, fmtDate } from './portalConstants'
import { KIT3D_STYLE } from '@/components/ui/kit3d'
import './portal.css'

/**
 * Vendor portal landing — professional SaaS dashboard.
 * All data fetching, state, and API calls are 100% unchanged.
 * Only the UI layout is redesigned.
 */
export default function PortalDashboard() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const isTPV = user?.role === 'third_party_vendor'
  const [data, setData] = useState({ me: null, onboarding: null, workerStats: {}, documents: null, orders: [], invoices: [] })
  const [work, setWork] = useState({ summary: {}, projects: [], tasks: [], tickets: [] })
  const [loading, setLoad] = useState(true)

  // ── DATA FETCHING ──────────────────────────────────────────────────────
  const load = () => {
    setLoad(true)
    const calls = [
      portalApi.me().catch(() => null),
      portalApi.onboarding.list().catch(() => []),
      portalApi.workers.stats().catch(() => ({})),
      portalApi.documents.checklist().catch(() => null),
      isTPV ? Promise.resolve([]) : portalApi.orders().catch(() => []),
      isTPV ? Promise.resolve([]) : portalApi.invoices().catch(() => []),
      // "My Work" — assigned projects/tasks/tickets (role-gated, always available).
      portalApi.myWork.summary().catch(() => ({})),
      portalApi.myWork.projects().catch(() => []),
      portalApi.myWork.tasks().catch(() => []),
      portalApi.myWork.tickets().catch(() => []),
    ]
    Promise.all(calls).then(([me, obList, wkStats, docs, orders, invoices, wSummary, wProjects, wTasks, wTickets]) => {
      setData({
        me,
        onboarding: obList[0] ?? null,
        workerStats: wkStats || {},
        documents: docs,
        orders: orders?.data ?? orders ?? [],
        invoices: invoices?.data ?? invoices ?? [],
      })
      setWork({
        summary:  wSummary || {},
        projects: Array.isArray(wProjects) ? wProjects : [],
        tasks:    Array.isArray(wTasks) ? wTasks : [],
        tickets:  Array.isArray(wTickets) ? wTickets : [],
      })
      setLoad(false)
    })
  }
  useEffect(() => { load() }, [isTPV])

  // ── DERIVED DATA ───────────────────────────────────────────────────────
  const summary = data.me?.summary || {}
  const orders = data.orders
  const invoices = data.invoices
  const onboarding = data.onboarding
  const vendorName = data.me?.vendor?.company_name || user?.name || 'Your account'
  const vendorStatus = data.me?.vendor?.status

  // Onboarding stats derived from the single onboarding object
  const obProgress = onboarding?.progress?.steps || []
  const obDone = obProgress.filter(s => s.complete).length
  const obTotal = obProgress.length || 6
  const obPct = obProgress.length
    ? Math.round((obDone / obTotal) * 100)
    : (onboarding?.status === 'Approved' ? 100 : Math.round(((onboarding?.current_step || 1) / 6) * 100))

  // ── STAT CARDS ─────────────────────────────────────────────────────────
  // Operational, not procedural. The old cards reported where an ADMIN workflow had
  // got to (onboarding step, progress %, status) — none of which is something the
  // vendor acts on. These are their day-to-day numbers instead. Document counts come
  // from the same checklist the Documents page renders.
  const docRows = Array.isArray(data.documents?.documents) ? data.documents.documents
    : (Array.isArray(data.documents) ? data.documents : [])
  const wk = data.workerStats || {}
  const expiringDocs = docRows.filter(d => {
    if (!d.expiry_date) return false
    const days = (new Date(d.expiry_date) - new Date()) / 86400000
    return days >= 0 && days <= 30
  }).length
  const docIssues = docRows.filter(d => ['Rejected', 'Resubmit', 'Resubmit Required'].includes(d.status)).length

  const statCards = isTPV
    ? [
        { label: 'Total Workers',       value: wk.total ?? 0,   color: '#7C3AED', bg: 'rgba(124,58,237,0.1)', icon: Users },
        { label: 'Active Workers',      value: wk.active ?? 0,  color: '#10b981', bg: 'rgba(16,185,129,0.1)', icon: UserCheck },
        { label: 'Pending Verification', value: wk.pending ?? 0, color: '#f59e0b', bg: 'rgba(245,158,11,0.1)', icon: Clock },
        { label: 'Expiring Documents',  value: expiringDocs,    color: '#0ea5e9', bg: 'rgba(14,165,233,0.1)', icon: FileText },
        { label: 'Open Compliance Issues', value: docIssues,    color: '#ef4444', bg: 'rgba(239,68,68,0.1)', icon: AlertTriangle },
      ]
    : [
        { label: 'Active Orders',    value: summary.open_orders ?? 0,           color: '#0ea5e9', bg: 'rgba(14,165,233,0.1)', icon: ShoppingBag },
        { label: 'Unpaid Invoices',  value: summary.unpaid_invoices ?? 0,       color: '#f59e0b', bg: 'rgba(245,158,11,0.1)', icon: FileText },
        { label: 'Outstanding',      value: fmtMoney(summary.outstanding_balance), color: '#ef4444', bg: 'rgba(239,68,68,0.1)', icon: Wallet },
        { label: 'Total Vendors',    value: 1,                                  color: '#7C3AED', bg: 'rgba(124,58,237,0.1)', icon: Building2 },
      ]

  // Read-only registration status. Derived from the onboarding record the portal
  // already loads — no new endpoint, and nothing here is actionable.
  const regSteps = useMemo(() => {
    const ob = onboarding
    const step = ob?.current_step ?? 0
    const approved = ob?.status === 'Approved'
    const at = (n) => ({ done: approved || step > n, current: !approved && step === n })
    return [
      { key: 'company',    label: 'Company Details Completed', ...at(2) },
      { key: 'documents',  label: 'Documents Verified',        ...at(3) },
      { key: 'workforce',  label: 'Workforce Approved',        ...at(4) },
      { key: 'compliance', label: 'Compliance Approved',       ...at(5) },
      { key: 'activated',  label: 'Account Activated',         done: vendorStatus === 'Active', current: approved && vendorStatus !== 'Active' },
    ]
  }, [onboarding, vendorStatus])

  // ── QUICK ACTIONS ──────────────────────────────────────────────────────
  const quickActions = isTPV
    ? [
        // 'Continue Onboarding' removed: progressing an onboarding is an admin
        // action. The read-only status card below reports where it stands.
        ...(vendorStatus === 'Active' ? [
          { label: 'Open Workforce',        icon: HardHat,      color: '#10b981', action: () => navigate('/vendor-portal/workforce/dashboard') },
          { label: 'View Workers',          icon: Users,        color: '#0ea5e9', action: () => navigate('/vendor-portal/workforce/workers') },
        ] : []),
        { label: 'View Documents',         icon: FileText,     color: '#f59e0b', action: () => navigate('/vendor-portal/documents') },
        { label: 'Contact Support',        icon: PhoneCall,    color: '#6366f1', action: () => {} },
      ]
    : [
        { label: 'View Purchase Orders',   icon: ShoppingBag,  color: '#0ea5e9', action: () => {} },
        { label: 'View Invoices',          icon: FileText,     color: '#f59e0b', action: () => {} },
        { label: 'Manage Documents',       icon: ClipboardList,color: '#7C3AED', action: () => navigate('/vendor-portal/documents') },
        { label: 'Contact Support',        icon: PhoneCall,    color: '#6366f1', action: () => {} },
      ]

  // ── LOADING SKELETON ───────────────────────────────────────────────────
  if (loading) {
    return (
      <div>
        <style>{KIT3D_STYLE}</style>
        {/* Hero skeleton */}
        <div className="skeleton" style={{ height: 140, borderRadius: 16, marginBottom: 24, background: 'var(--border)' }} />
        {/* Stat skeletons */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 24 }}>
          {[1,2,3,4].map(i => <div key={i} className="skeleton" style={{ height: 96, borderRadius: 16, background: 'var(--border)' }} />)}
        </div>
        {/* 3-col grid skeletons */}
        <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr 0.9fr', gap: 16 }}>
          {[160, 200, 180].map((h, i) => <div key={i} className="skeleton" style={{ height: h, borderRadius: 16, background: 'var(--border)' }} />)}
        </div>
      </div>
    )
  }

  return (
    <div>
      <style>{KIT3D_STYLE}</style>

      {/* ── Hero Welcome Card ─────────────────────────────────────────── */}
      <div className="portal-hero">
        <div style={{ position: 'relative', zIndex: 1 }}>
          <div className="portal-hero-label">Welcome Back</div>
          <h1 className="portal-hero-title">{vendorName}</h1>
          <p className="portal-hero-sub">
            {isTPV
              ? 'Your onboarding progress and workforce management hub.'
              : 'Your onboarding, orders and invoices at a glance.'}
          </p>
          {vendorStatus && (
            <span style={{
              display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 14,
              padding: '5px 12px', borderRadius: 999,
              background: vendorStatus === 'Active' ? 'rgba(16,185,129,0.25)' : 'rgba(245,158,11,0.25)',
              color: vendorStatus === 'Active' ? '#6ee7b7' : '#fcd34d',
              fontSize: 12, fontWeight: 700, border: `1px solid ${vendorStatus === 'Active' ? 'rgba(16,185,129,0.4)' : 'rgba(245,158,11,0.4)'}`,
            }}>
              <span style={{ width: 7, height: 7, borderRadius: '50%', background: 'currentColor' }} />
              {vendorStatus}
            </span>
          )}
        </div>

        {/* Illustration — abstract SaaS graphic */}
        <div className="portal-hero-illus" aria-hidden>
          <svg viewBox="0 0 140 100" fill="none" xmlns="http://www.w3.org/2000/svg" style={{ width: '100%', height: '100%', opacity: 0.7 }}>
            <rect x="10" y="20" width="120" height="68" rx="10" fill="rgba(255,255,255,0.08)" stroke="rgba(255,255,255,0.18)" strokeWidth="1.5"/>
            <rect x="22" y="32" width="40" height="4" rx="2" fill="rgba(255,255,255,0.5)"/>
            <rect x="22" y="40" width="60" height="3" rx="1.5" fill="rgba(255,255,255,0.25)"/>
            <rect x="22" y="47" width="50" height="3" rx="1.5" fill="rgba(255,255,255,0.2)"/>
            <rect x="22" y="58" width="28" height="18" rx="5" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.2)" strokeWidth="1"/>
            <rect x="56" y="58" width="28" height="18" rx="5" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.2)" strokeWidth="1"/>
            <rect x="90" y="58" width="28" height="18" rx="5" fill="rgba(124,58,237,0.5)" stroke="rgba(255,255,255,0.3)" strokeWidth="1"/>
            <circle cx="116" cy="22" r="16" fill="rgba(255,255,255,0.06)" stroke="rgba(255,255,255,0.15)" strokeWidth="1.5"/>
            <circle cx="116" cy="22" r="8" fill="rgba(255,255,255,0.15)"/>
          </svg>
        </div>
      </div>

      {/* ── Stat Cards ────────────────────────────────────────────────── */}
      <div style={{ display: 'grid', gridTemplateColumns: `repeat(${statCards.length},1fr)`, gap: 14, marginBottom: 24 }}>
        {statCards.map((s, i) => {
          const Icon = s.icon
          return (
            <div key={i} className="portal-stat-card">
              <div className="portal-stat-card-icon" style={{ background: s.bg }}>
                <Icon size={18} style={{ color: s.color }} />
              </div>
              <div className="portal-stat-value" style={{ color: s.color }}>{s.value}</div>
              <div className="portal-stat-label">{s.label}</div>
            </div>
          )
        })}
      </div>

      {/* ── My Work — projects / tasks / tickets assigned to this vendor ── */}
      <div className="portal-card portal-card-padded" style={{ marginBottom: 24 }}>
        <div className="portal-card-header">
          <div className="portal-card-icon" style={{ background: 'rgba(124,58,237,0.1)' }}>
            <ClipboardList size={16} style={{ color: '#7C3AED' }} />
          </div>
          <div>
            <div className="portal-card-title">My Work</div>
            <div className="portal-card-sub">
              {work.summary.projects || 0} project{(work.summary.projects || 0) !== 1 ? 's' : ''} ·{' '}
              {work.summary.tasks || 0} task{(work.summary.tasks || 0) !== 1 ? 's' : ''} ·{' '}
              {work.summary.tickets || 0} ticket{(work.summary.tickets || 0) !== 1 ? 's' : ''} assigned to you
            </div>
          </div>
        </div>

        {/* Read-only registration status — replaces the actionable onboarding
            widgets. Information only; the admin owns the decisions. */}
        <div style={{ marginTop: 4, marginBottom: 16 }}>
          <RegistrationStatusCard steps={regSteps} />
        </div>

        <div className="portal-dash-grid" style={{ marginTop: 4 }}>
          {/* Projects */}
          <WorkColumn icon={Building2} color="#7C3AED" title="Projects" items={work.projects} empty="No projects assigned."
            render={p => (
              <>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 700, fontSize: 13, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{p.name}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{p.role}{p.deadline ? ` · due ${p.deadline}` : ''}</div>
                </div>
                <span style={{ fontSize: 11.5, fontWeight: 800, color: '#7C3AED', flexShrink: 0 }}>{p.progress}%</span>
              </>
            )} />
          {/* Tasks */}
          <WorkColumn icon={ClipboardList} color="#0ea5e9" title="Tasks" items={work.tasks} empty="No tasks assigned."
            render={t => (
              <>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 700, fontSize: 13, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{t.name}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{t.project ? `${t.project} · ` : ''}{t.priority}{t.due_date ? ` · due ${t.due_date}` : ''}</div>
                </div>
                <WorkPill text={t.status} />
              </>
            )} />
          {/* Tickets */}
          <WorkColumn icon={MessageSquare} color="#f59e0b" title="Tickets" items={work.tickets} empty="No tickets assigned."
            render={t => (
              <>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 700, fontSize: 13, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>#{t.id} {t.subject}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{t.priority}</div>
                </div>
                <WorkPill text={t.status} />
              </>
            )} />
        </div>
      </div>

      {/* ── Onboarding progress card (full-width, when present) ────────── */}
      {onboarding && (
        <div className="portal-card portal-card-padded" style={{ marginBottom: 24 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 10 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <div className="portal-card-icon" style={{ background: 'rgba(124,58,237,0.1)' }}>
                <Rocket size={17} style={{ color: '#7C3AED' }} />
              </div>
              <div>
                <div className="portal-card-title">Onboarding Progress</div>
                <div className="portal-card-sub">{obDone} of {obTotal} steps complete</div>
              </div>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <StatusPillInline cfg={obStatusCfg(onboarding.status)} />
              <button
                onClick={() => navigate('/vendor-portal/documents')}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 6px 18px -4px rgba(124,58,237,.55)' }}
              >
                <FileText size={14} />
                Manage Docs
                <ArrowRight size={14} />
              </button>
            </div>
          </div>
          {/* Progress bar */}
          <div className="portal-progress-bar">
            <div className="portal-progress-fill" style={{ width: `${obPct}%` }} />
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6 }}>
            <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Step {onboarding.current_step} of 6</span>
            <span style={{ fontSize: 11, fontWeight: 700, color: '#7C3AED' }}>{obPct}% complete</span>
          </div>
          {/* Step tiles */}
          {obProgress.length > 0 && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: 10, marginTop: 16 }}>
              {obProgress.map(s => (
                <div key={s.key || s.step} style={{
                  display: 'flex', alignItems: 'center', gap: 9, padding: '9px 11px', borderRadius: 10,
                  background: s.complete ? 'rgba(16,185,129,0.07)' : 'var(--bg-input)',
                  border: `1px solid ${s.complete ? 'rgba(16,185,129,0.25)' : 'var(--border)'}`,
                }}>
                  {s.complete
                    ? <CheckCircle2 size={14} style={{ color: '#10b981', flexShrink: 0 }} />
                    : <Clock size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />}
                  <span style={{ fontSize: 11.5, fontWeight: 600, color: s.complete ? 'var(--text-h)' : 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                    {s.label}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ── Three-column dashboard grid ─────────────────────────────── */}
      <div className="portal-dash-grid" style={{ marginBottom: 24 }}>
        {/* Left — Recent Onboarding / Orders */}
        <div className="portal-card portal-card-padded">
          <div className="portal-card-header">
            <div className="portal-card-icon" style={{ background: 'rgba(124,58,237,0.1)' }}>
              {isTPV ? <ClipboardList size={16} style={{ color: '#7C3AED' }} /> : <ShoppingBag size={16} style={{ color: '#7C3AED' }} />}
            </div>
            <div>
              <div className="portal-card-title">{isTPV ? 'Recent Onboarding' : 'Purchase Orders'}</div>
              <div className="portal-card-sub">{isTPV ? 'Your onboarding applications' : 'Recent purchase orders'}</div>
            </div>
          </div>

          {isTPV ? (
            onboarding ? (
              // Inert: status display only. Progressing the onboarding is an admin
              // action, so this row no longer navigates anywhere.
              <div className="portal-ob-row" style={{ cursor: 'default' }}>
                <div style={{ width: 34, height: 34, borderRadius: 10, background: 'rgba(124,58,237,0.1)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                  <Rocket size={16} style={{ color: '#7C3AED' }} />
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 700, fontSize: 13.5, color: 'var(--text-h)' }}>Onboarding Application</div>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}>
                    Step {onboarding.current_step} of 6 · Started {fmtDate(onboarding.created_at)}
                  </div>
                  <div className="portal-progress-bar" style={{ marginTop: 6, height: 4 }}>
                    <div className="portal-progress-fill" style={{ width: `${obPct}%` }} />
                  </div>
                </div>
                <StatusPillInline cfg={obStatusCfg(onboarding.status)} />
              </div>
            ) : (
              <EmptyState icon={ClipboardList} text="No onboarding started yet." />
            )
          ) : (
            orders.length === 0 ? (
              <EmptyState icon={ShoppingBag} text="No purchase orders yet." />
            ) : (
              orders.slice(0, 5).map(o => (
                <div key={o.id} className="portal-ob-row" onClick={() => navigate(`/vendor-portal/orders/${o.id}`)}>
                  <div style={{ width: 34, height: 34, borderRadius: 10, background: 'rgba(14,165,233,0.1)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                    <ShoppingBag size={15} style={{ color: '#0ea5e9' }} />
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 700, fontSize: 13, color: '#a78bfa' }}>{o.po_number}</div>
                    <div style={{ fontSize: 11.5, color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{o.title}</div>
                  </div>
                  <div style={{ textAlign: 'right', flexShrink: 0 }}>
                    <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(o.total, o.currency)}</div>
                    <StatusPillInline cfg={poStatusCfg(o.status)} />
                  </div>
                </div>
              ))
            )
          )}
        </div>

        {/* Center — Notifications */}
        <div className="portal-card portal-card-padded">
          <div className="portal-card-header">
            <div className="portal-card-icon" style={{ background: 'rgba(99,102,241,0.1)' }}>
              <MessageSquare size={16} style={{ color: '#6366f1' }} />
            </div>
            <div>
              <div className="portal-card-title">Notifications</div>
              <div className="portal-card-sub">Recent updates</div>
            </div>
          </div>

          {/* Notifications — shown as placeholder-style items since no notification API is wired yet */}
          {onboarding ? (
            <div>
              {[
                { dot: '#7C3AED', text: `Onboarding at Step ${onboarding.current_step}`, time: fmtDate(onboarding.updated_at || onboarding.created_at) },
                ...(onboarding.submitted_at ? [{ dot: '#f59e0b', text: 'Application submitted for review', time: fmtDate(onboarding.submitted_at) }] : []),
                ...(onboarding.status === 'Approved' ? [{ dot: '#10b981', text: 'Onboarding approved!', time: fmtDate(onboarding.updated_at) }] : []),
                ...(vendorStatus === 'Active' ? [{ dot: '#10b981', text: 'Vendor account activated', time: '' }] : []),
              ].slice(0, 5).map((n, i) => (
                <div key={i} className="portal-notif-item">
                  <span className="portal-notif-dot" style={{ background: n.dot }} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)' }}>{n.text}</div>
                    {n.time && <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{n.time}</div>}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <EmptyState icon={MessageSquare} text="No notifications yet." />
          )}
        </div>

        {/* Right — Quick Actions */}
        <div className="portal-card portal-card-padded">
          <div className="portal-card-header">
            <div className="portal-card-icon" style={{ background: 'rgba(16,185,129,0.1)' }}>
              <ChevronRight size={16} style={{ color: '#10b981' }} />
            </div>
            <div>
              <div className="portal-card-title">Quick Actions</div>
              <div className="portal-card-sub">Common tasks</div>
            </div>
          </div>

          {quickActions.map((a, i) => {
            const Icon = a.icon
            return (
              <button key={i} className="portal-qa-btn" onClick={a.action}>
                <span style={{ width: 30, height: 30, borderRadius: 8, background: `${a.color}18`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                  <Icon size={15} style={{ color: a.color }} />
                </span>
                <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-h)' }}>{a.label}</span>
                <ChevronRight size={14} style={{ color: 'var(--text-muted)', marginLeft: 'auto' }} />
              </button>
            )
          })}
        </div>
      </div>

      {/* ── Bottom — Purchase vendor invoices ─────────────────────────── */}
      {!isTPV && (
        <div className="portal-card portal-card-padded" style={{ marginBottom: 24 }}>
          <div className="portal-card-header">
            <div className="portal-card-icon" style={{ background: 'rgba(245,158,11,0.1)' }}>
              <FileText size={16} style={{ color: '#f59e0b' }} />
            </div>
            <div>
              <div className="portal-card-title">Invoices</div>
              <div className="portal-card-sub">{invoices.length} invoice{invoices.length !== 1 ? 's' : ''}</div>
            </div>
          </div>
          {invoices.length === 0 ? (
            <EmptyState icon={FileText} text="No invoices yet." />
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {invoices.slice(0, 5).map(inv => (
                <div key={inv.id} className="portal-ob-row" onClick={() => navigate(`/vendor-portal/invoices/${inv.id}`)}>
                  <div style={{ width: 34, height: 34, borderRadius: 10, background: 'rgba(245,158,11,0.1)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                    <FileText size={15} style={{ color: '#f59e0b' }} />
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 700, fontSize: 13, color: '#a78bfa' }}>{inv.invoice_number}</div>
                    <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{inv.title}{inv.due_date ? ` · Due ${fmtDate(inv.due_date)}` : ''}</div>
                  </div>
                  <div style={{ textAlign: 'right', flexShrink: 0 }}>
                    <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(inv.balance, inv.currency)}</div>
                    <StatusPillInline cfg={invStatusCfg(inv.status)} />
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ── TPV Workforce CTA ──────────────────────────────────────────── */}
      {isTPV && vendorStatus === 'Active' && (
        <div className="portal-card portal-card-padded" style={{ marginBottom: 24, background: 'linear-gradient(135deg, rgba(16,185,129,0.06), rgba(16,185,129,0.03))' }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <div style={{ width: 44, height: 44, borderRadius: 12, background: 'rgba(16,185,129,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <HardHat size={20} style={{ color: '#10b981' }} />
              </div>
              <div>
                <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Workforce Management</div>
                <div style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 2 }}>Manage workers, badges, gate log and attendance.</div>
              </div>
            </div>
            <button
              onClick={() => navigate('/vendor-portal/workforce/dashboard')}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 20px', borderRadius: 12, background: '#10b981', color: '#fff', fontWeight: 800, fontSize: 13.5, border: 'none', cursor: 'pointer', boxShadow: '0 6px 18px -4px rgba(16,185,129,0.5)' }}
            >
              <HardHat size={16} /> Open Workforce <ArrowRight size={15} />
            </button>
          </div>
        </div>
      )}

      {/* ── Account Summary + Help ─────────────────────────────────────── */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 16, alignItems: 'start' }}>
        {/* Account summary */}
        <div className="portal-card portal-card-padded">
          <div className="portal-card-header">
            <div className="portal-card-icon" style={{ background: 'rgba(124,58,237,0.1)' }}>
              <Building2 size={16} style={{ color: '#7C3AED' }} />
            </div>
            <div className="portal-card-title">Account Summary</div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 12 }}>
            {[
              { label: 'Company', value: data.me?.vendor?.company_name || '—' },
              { label: 'Vendor Code', value: data.me?.vendor?.vendor_code || '—' },
              { label: 'Type', value: data.me?.vendor?.vendor_type || '—' },
              { label: 'Status', value: vendorStatus || '—' },
              { label: 'Contact', value: data.me?.vendor?.email || user?.email || '—' },
              { label: 'Phone', value: data.me?.vendor?.phone || '—' },
            ].map((item, i) => (
              <div key={i} style={{ padding: '12px 14px', background: 'var(--bg-input)', borderRadius: 10, border: '1px solid var(--border)' }}>
                <div style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 4 }}>{item.label}</div>
                <div style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{item.value}</div>
              </div>
            ))}
          </div>
        </div>

        {/* Help card */}
        <div className="portal-card portal-card-padded" style={{ minWidth: 220 }}>
          <div style={{ textAlign: 'center', padding: '8px 0 16px' }}>
            <div style={{ width: 48, height: 48, borderRadius: 14, background: 'rgba(99,102,241,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 12px' }}>
              <HelpCircle size={22} style={{ color: '#6366f1' }} />
            </div>
            <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', marginBottom: 6 }}>Need Help?</div>
            <div style={{ fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.5, marginBottom: 16 }}>
              Our support team is ready to assist with onboarding and compliance questions.
            </div>
            <a
              href="mailto:support@company.com"
              style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 18px', borderRadius: 10, background: 'rgba(99,102,241,0.12)', color: '#818cf8', fontWeight: 700, fontSize: 13, textDecoration: 'none', border: '1px solid rgba(99,102,241,0.2)' }}
            >
              <ExternalLink size={14} /> Contact Support
            </a>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Tiny shared sub-components ────────────────────────────────────────────────
function StatusPillInline({ cfg }) {
  if (!cfg) return null
  return (
    <span style={{
      padding: '3px 9px', borderRadius: 999,
      background: cfg.bg, color: cfg.color,
      fontSize: 10.5, fontWeight: 800,
      whiteSpace: 'nowrap',
    }}>
      {cfg.label}
    </span>
  )
}

/** One column of the "My Work" grid — a titled list with a soft empty state. */
function WorkColumn({ icon: Icon, color, title, items, empty, render }) {
  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <span style={{ width: 26, height: 26, borderRadius: 8, background: `${color}18`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <Icon size={14} style={{ color }} />
        </span>
        <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>{title}</span>
        <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginLeft: 'auto' }}>{items.length}</span>
      </div>
      {items.length === 0 ? (
        <div style={{ fontSize: 12, color: 'var(--text-muted)', padding: '14px 8px', textAlign: 'center' }}>{empty}</div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {items.slice(0, 6).map((it, i) => (
            <div key={it.id ?? i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 11px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              {render(it)}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

/** A neutral status chip for a work item. */
function WorkPill({ text }) {
  if (!text) return null
  return (
    <span style={{ padding: '3px 9px', borderRadius: 999, background: 'rgba(124,58,237,0.12)', color: '#a78bfa', fontSize: 10.5, fontWeight: 800, whiteSpace: 'nowrap', textTransform: 'capitalize', flexShrink: 0 }}>
      {String(text).replace(/_/g, ' ')}
    </span>
  )
}

function EmptyState({ icon: Icon, text }) {
  return (
    <div style={{ textAlign: 'center', padding: '28px 16px', color: 'var(--text-muted)' }}>
      <Icon size={28} style={{ opacity: 0.3, marginBottom: 8 }} />
      <div style={{ fontSize: 13 }}>{text}</div>
    </div>
  )
}
