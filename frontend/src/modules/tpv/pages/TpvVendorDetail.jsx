import { useState, useEffect, useCallback, useMemo } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft, Building2, User, Phone, Loader2, ShieldCheck, CheckCircle, XCircle, PauseCircle, CornerUpLeft, Clock, AlertTriangle,
  Briefcase, IndianRupee, ClipboardCheck, BarChart3, ChevronDown, ChevronRight, Mail, HardHat, Ban,
  Users, FileText, Paperclip, StickyNote,
} from 'lucide-react'

const NOTIF_COLORS = { sent: '#10b981', failed: '#ef4444', skipped: '#94a3b8', queued: '#0ea5e9' }

import { useAuth } from '@/context/AuthContext'
import { obStatusCfg } from '@/modules/tpv/constants'
import { useVendorModule } from '@/modules/tpv/useVendorModule'
import TpvRegistrationBadge from '@/modules/tpv/components/TpvRegistrationBadge'
import TemporaryTpvValidityBadge from '@/modules/tpv/components/TemporaryTpvValidityBadge'
import { KIT3D_STYLE, Overlay, ModalFooter } from '@/components/ui/kit3d'
import ComingSoonSection from '@/modules/tpv/components/ComingSoonSection'
import VendorTasksPanel from '@/components/vendor/VendorTasksPanel'
import TaskFormDrawer from '@/modules/tasks/components/TaskFormDrawer'
import { tpvApi } from '@/services/tpvApi'
import TpvVendorContacts from '@/modules/tpv/components/TpvVendorContacts'
import TpvVendorDocuments from '@/modules/tpv/components/TpvVendorDocuments'
import {
  VendorWorkforce, VendorMedical, VendorTraining, VendorGateLog, VendorStrikes,
} from '@/modules/tpv/components/VendorWorkforcePanels'
import { VendorProjects } from '@/modules/tpv/components/VendorProjectsPanel'
import { VendorTickets } from '@/modules/tpv/components/VendorTicketsPanel'
import { VendorExpenses } from '@/modules/tpv/components/VendorExpensesPanel'
import { VendorCustomers } from '@/modules/tpv/components/VendorCustomersPanel'
import { VendorReminders } from '@/modules/tpv/components/VendorRemindersPanel'
import { VendorNotes } from '@/modules/tpv/components/VendorNotesPanel'
import { VendorCommercial } from '@/modules/tpv/components/VendorCommercialPanel'
import { VendorAttachments } from '@/modules/tpv/components/VendorAttachmentsPanel'

/**
 * The Vendor Detail navigation — 6 groups, 38 sections. This drives BOTH the left
 * nav and the content router below, so a section exists in exactly one of three
 * states and the three partition the list with no overlap:
 *
 *   ACTIVE       a `case` in SectionContent — 24 sections, each reading an
 *                EXISTING module scoped to this vendor. None owns a TPV table:
 *                Reminders and Notes ride the SHARED polymorphic `reminders` and
 *                `notes` tables, the same way Projects rides projects.vendor_id.
 *   NOT_APPLICABLE  listed in the map below — the concept belongs to another
 *                module and never applies here. A settled answer, not a gap.
 *   COMING SOON  neither — no backing table yet, awaiting a business definition.
 *
 * Anything added here without a `case` falls to the placeholder, and anything
 * given a `case` without being listed here is UNREACHABLE: `active` resolves
 * through bySlug, which is built from this array, so a stray case silently
 * renders Overview instead. Keep the two in step.
 */
const NAV_GROUPS = [
  { group: 'General',     icon: User,           items: ['Overview', 'Profile', 'Contact', 'Customer'] },
  { group: 'Workforce',   icon: HardHat,        items: ['Workforce', 'Medical', 'Training', 'Gate Log', 'Strikes'] },
  { group: 'Commercial',  icon: IndianRupee,    items: ['Quotation', 'Contracts', 'Purchase Order', 'Purchase Invoice', 'Debit Note', 'Purchase Statement', 'Payments'] },
  { group: 'Operations',  icon: Briefcase,      items: ['Projects', 'Tasks', 'Expenses', 'Attachments', 'ToDo', 'Notes', 'Technical File Maintenance', 'Ticket', 'Job', 'Reminders'] },
  { group: 'Compliance',  icon: ClipboardCheck, items: ['Documents', 'Survey', 'PTW', 'Incidents', 'Pre Alert', 'Package', 'Visitors'] },
  { group: 'Performance', icon: BarChart3,      items: ['Risk Score', 'Award / Reward', 'Penalty', 'Feedback', 'Referrals'] },
]

/**
 * Sections the current application deliberately does not support for a TPV
 * vendor — distinct from "not built yet". Each names the module that DOES own
 * the concept, so the screen never implies a missing feature.
 *
 * The Commercial group used to live here for the same reason: every commercial
 * document keys to purchase_vendors.purchase_vendor_id, and Purchase was
 * decoupled from the shared Vendor Master on purpose (migrations
 * 2026_08_30_000009 → _000014). Those tabs are now ACTIVE, but nothing about that
 * decision changed — vendors.purchase_vendor_id is an OPTIONAL link an admin
 * sets, and the tabs read the real Purchase lists through it. Unlinked, they say
 * so. No commercial data is copied onto the TPV side.
 */
const NOT_APPLICABLE = {
  'ToDo':               'Vendor to-dos are tracked in Tasks.',
  'Pre Alert':          'Pre-alerts are a Customer module concept (client_pre_alerts).',
  'Package':            'Packages are a Customer module concept (client_packages).',
  'Job':                'No vendor job concept exists — the `jobs` table is the queue, and hr_job_* is recruitment.',
}

const slugify = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
const DEFAULT_TAB = 'Overview'

export default function TpvVendorDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const cfg = useVendorModule()
  const manage = cfg.canManage(user)
  const [params, setParams] = useSearchParams()
  const [v, setV] = useState(null)
  const [loading, setLoad] = useState(true)
  const [collapsed, setCollapsed] = useState({}) // group -> true when collapsed

  // Admin decision state
  const [decisionModal, setDecisionModal] = useState(null) // 'approve' | 'reject' | 'hold' | 'resubmit'
  const [remarks, setRemarks] = useState('')
  const [decisionBusy, setDecisionBusy] = useState(false)
  const [resending, setResending] = useState(false)
  const [notice, setNotice] = useState(null)
  const [showTimeline, setShowTimeline] = useState(false)

  // Slug ↔ label lookup so the ?tab= query param survives reloads and drives history.
  const bySlug = useMemo(() => {
    const map = {}
    NAV_GROUPS.forEach(g => g.items.forEach(it => { map[slugify(it)] = it }))
    return map
  }, [])
  const active = bySlug[params.get('tab')] || DEFAULT_TAB

  const selectTab = useCallback((label) => {
    const next = new URLSearchParams(params)
    next.set('tab', slugify(label))
    setParams(next) // pushes history — Back/Forward move between sections, no reload
  }, [params, setParams])

  // Manual resend. Every attempt is logged server-side; the line below reflects it.
  const resendActivation = async () => {
    setResending(true); setNotice(null)
    try {
      const r = await cfg.api.vendors.resendActivation(id)
      setNotice(r?.status === 'sent'
        ? { ok: true, text: `Activation email sent to ${r.recipient}.` }
        : { ok: false, text: 'Could not send the activation email. It has been logged — try again.' })
      load()
    } catch (e) {
      setNotice({ ok: false, text: e?.response?.data?.message || 'Could not send the activation email.' })
    } finally { setResending(false) }
  }

  // Compliance suspension (admin). The nightly sweep does this automatically on
  // expired statutory docs; these are the manual overrides.
  const suspendVendor = async () => {
    const reason = window.prompt('Reason for suspending this vendor (required):')
    if (reason == null) return
    if (!reason.trim()) { alert('A reason is required to suspend.'); return }
    try { await cfg.api.vendors.suspend(id, reason.trim()); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not suspend the vendor.') }
  }
  const reinstateVendor = async () => {
    if (!confirm('Reinstate this vendor to Active? Their login and site access are restored.')) return
    try { await cfg.api.vendors.reinstate(id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not reinstate the vendor.') }
  }
  const offboardVendor = async () => {
    if (!confirm('Offboard this vendor? This ENDS the engagement: the login is locked and every on-site worker is terminated. This is not auto-reversible.')) return
    const reason = window.prompt('Reason for offboarding (required):')
    if (reason == null) return
    if (!reason.trim()) { alert('A reason is required to offboard.'); return }
    try { await cfg.api.vendors.offboard(id, reason.trim()); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not offboard the vendor.') }
  }

  const load = useCallback(() => {
    setLoad(true)
    cfg.api.vendors.get(id).then(r => { setV(r?.data ?? r); setLoad(false) }).catch(() => setLoad(false))
  }, [id, cfg.api])
  useEffect(() => { load() }, [load])

  if (loading) return <div style={wrap}><style>{KIT3D_STYLE}</style><Loader2 size={22} className="rfq-spin" style={{ color: '#a78bfa' }} /></div>
  if (!v) return <div style={wrap}><style>{KIT3D_STYLE}</style><p style={{ color: 'var(--text-muted)' }}>Vendor not found.</p></div>

  const isActive = v.status === 'Active'
  const activeOnboarding = v.tpv_onboarding || v.tpvOnboarding
    || v.purchase_onboarding || v.purchaseOnboarding
    || (v.onboardings && v.onboardings[0]) || null
  const obStatus = activeOnboarding?.status || 'Draft'
  const obCfg = obStatusCfg(obStatus)

  const handleAdminDecision = async () => {
    if ((decisionModal === 'reject' || decisionModal === 'hold' || decisionModal === 'resubmit') && !remarks.trim()) {
      alert('Mandatory remarks are required for this action.')
      return
    }

    setDecisionBusy(true)
    try {
      let ob = activeOnboarding
      if (!ob) {
        const r = await cfg.api.onboarding.create({ vendor_id: v.id })
        ob = r?.data ?? r
      }

      if (decisionModal === 'approve') {
        await cfg.api.onboarding.approve(ob.id, remarks)
      } else if (decisionModal === 'reject') {
        await cfg.api.onboarding.reject(ob.id, remarks)
      } else if (decisionModal === 'hold') {
        await cfg.api.onboarding.hold(ob.id, remarks)
      } else if (decisionModal === 'resubmit') {
        await cfg.api.onboarding.requestResubmit(ob.id, remarks)
      }

      setDecisionModal(null)
      setRemarks('')
      load()
    } catch (e) {
      alert(e?.response?.data?.message || 'Decision failed')
    } finally {
      setDecisionBusy(false)
    }
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Header. One surface, three tiers: identity -> status -> meta. Previously
          these competed as loose rows, with the decision toolbar floating beside the
          company name and pulling the eye away from it. */}
      <div style={{
        background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16,
        padding: '18px 20px', marginBottom: 18,
        display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 20, flexWrap: 'wrap',
      }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14, flex: 1, minWidth: 300 }}>
          <button onClick={() => navigate(cfg.listPath)} style={backBtn}><ArrowLeft size={16} /></button>
          <div style={{ width: 54, height: 54, borderRadius: 16, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', border: '1px solid rgba(124,58,237,0.3)' }}>
            <Building2 size={24} style={{ color: '#a78bfa' }} />
          </div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800 }}>{v.vendor_code || `${cfg.codePrefix}-${v.id}`}</p>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginTop: 3 }}>
              <h1 style={{ color: 'var(--text-h)', fontSize: 23, fontWeight: 800, margin: 0, letterSpacing: '-0.025em' }}>{v.company_name}</h1>

              {/* Badge 0: Registration type — the stored choice, never inferred */}
              <TpvRegistrationBadge type={v.registration_type} label={v.registration_type_label} size="md" />

              {/* Remaining access — countdown for temporary, "Permanent" otherwise */}
              <TemporaryTpvValidityBadge countdown={v.validity_countdown} showLabel />

              {/* Badge 1: Vendor Account Status (Controls Portal Login) */}
              <StatusPill label="Account" value={v.status || 'Inactive'} tone={isActive ? '#0ca30c' : '#d03b3b'} />

              {/* Badge 2: Onboarding Status (Independent TPV Workflow) */}
              <StatusPill label="Onboarding" value={obCfg.label || obStatus} tone={obCfg.color} />
            </div>
            <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>{v.user?.name ? `Login: ${v.user.name} · ` : ''}{v.email || 'No email'}</p>

            {/* Activation notification: resend (Active only) + last-send status */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginTop: 8 }}>
              {isActive && (
                <button onClick={resendActivation} disabled={resending}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
                  <Mail size={13} /> {resending ? 'Sending…' : 'Resend Activation Email'}
                </button>
              )}
              {manage && isActive && (
                <button onClick={suspendVendor}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid rgba(249,115,22,0.4)', color: '#f97316', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
                  <Ban size={13} /> Suspend
                </button>
              )}
              {manage && v.status === 'Suspended' && (
                <button onClick={reinstateVendor}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid rgba(16,185,129,0.4)', color: '#10b981', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
                  <ShieldCheck size={13} /> Reinstate
                </button>
              )}
              {manage && !['Offboarded', 'Draft', 'Pending_Approval'].includes(v.status) && (
                <button onClick={offboardVendor}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid rgba(100,116,139,0.4)', color: '#64748b', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
                  <XCircle size={13} /> Offboard
                </button>
              )}
              {v.status === 'Suspended' && v.suspension_reason && (
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, color: '#f97316', fontWeight: 600 }}>
                  <AlertTriangle size={12} /> {v.suspension_reason}
                </span>
              )}
              {notice && <span style={{ fontSize: 12, fontWeight: 700, color: notice.ok ? '#10b981' : '#ef4444' }}>{notice.text}</span>}
              {v.last_notification && (
                <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                  Last activation email: <strong style={{ color: NOTIF_COLORS[v.last_notification.status] || 'var(--text-h)' }}>{v.last_notification.status}</strong>
                  {v.last_notification.sent_at && <> · {new Date(v.last_notification.sent_at).toLocaleString()}</>}
                </span>
              )}
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                · Last login: <strong style={{ color: 'var(--text-h)' }}>{v.login_stats?.last_login_at ? new Date(v.login_stats.last_login_at).toLocaleString() : 'never'}</strong>
                {' '}· Logins: <strong style={{ color: 'var(--text-h)' }}>{v.login_stats?.login_count ?? 0}</strong>
              </span>
              {v.notification_timeline?.length > 0 && (
                <button onClick={() => setShowTimeline(t => !t)} style={{ padding: '4px 10px', borderRadius: 7, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', fontSize: 11.5, fontWeight: 700, cursor: 'pointer' }}>
                  {showTimeline ? 'Hide' : 'Timeline'} ({v.notification_timeline.length})
                </button>
              )}
            </div>

            {/* Notification timeline — chronological, straight from tpv_notification_logs */}
            {showTimeline && (
              <div style={{ marginTop: 10, borderTop: '1px solid var(--border)', paddingTop: 8, maxWidth: 760 }}>
                {v.notification_timeline.map(n => (
                  <div key={n.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '5px 0', fontSize: 12, borderBottom: '1px solid var(--border)' }}>
                    <span style={{ width: 8, height: 8, borderRadius: 999, background: NOTIF_COLORS[n.status] || '#94a3b8', flexShrink: 0 }} />
                    <span style={{ color: NOTIF_COLORS[n.status] || 'var(--text-h)', fontWeight: 800, minWidth: 58 }}>{n.status}</span>
                    <span style={{ color: 'var(--text-h)' }}>{n.type.replace(/_/g, ' ')}</span>
                    <span style={{ color: 'var(--text-muted)' }}>· {n.channel} · {n.recipient}</span>
                    <span style={{ marginLeft: 'auto', color: 'var(--text-muted)' }}>{new Date(n.sent_at || n.created_at).toLocaleString()}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

      </div>

      {/* Onboarding Decision — prominent, directly under the header. Only for
          admins/staff, and only once the vendor has an onboarding to decide on. */}
      {manage && activeOnboarding && (
        <OnboardingDecisionPanel
          vendor={v} onboarding={activeOnboarding} api={cfg.api}
          onDecision={kind => { setDecisionModal(kind); setRemarks('') }}
        />
      )}

      {/* Two-pane: left section nav + right content */}
      <div style={{ display: 'flex', gap: 20, alignItems: 'flex-start' }}>
        {/* 35 sections across 5 groups. Sticky with no height cap meant that once
            enough groups were expanded the nav grew past the viewport and its lower
            entries could not be reached — the page scrolls, but a sticky element
            does not. Cap it to the viewport and let it scroll independently.
            scrollbarGutter keeps the items from shifting when the bar appears. */}
        <nav style={{
          width: 232, flexShrink: 0, position: 'sticky', top: 16,
          maxHeight: 'calc(100vh - 32px)', overflowY: 'auto', overscrollBehavior: 'contain',
          scrollbarGutter: 'stable', paddingRight: 2,
        }}>
          {NAV_GROUPS.map(({ group, icon: GIcon, items }) => {
            const open = !collapsed[group]
            return (
              <div key={group} style={{ marginBottom: 6 }}>
                <button
                  onClick={() => setCollapsed(c => ({ ...c, [group]: !c[group] }))}
                  style={groupBtn}
                >
                  <GIcon size={14} style={{ color: '#a78bfa', flexShrink: 0 }} />
                  <span style={{ flex: 1, textAlign: 'left' }}>{group}</span>
                  {open ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                </button>
                {open && (
                  <div style={{ padding: '2px 0 6px' }}>
                    {items.map(it => {
                      const on = active === it
                      // Settled-as-out-of-scope sections stay reachable (the reason
                      // is worth reading) but are dimmed, so the sidebar shows at a
                      // glance what this vendor actually has.
                      const na = !!NOT_APPLICABLE[it]
                      return (
                        <button
                          key={it}
                          onClick={() => selectTab(it)}
                          title={na ? `Not applicable — ${NOT_APPLICABLE[it]}` : undefined}
                          style={{
                            display: 'flex', alignItems: 'center', gap: 6, width: '100%', textAlign: 'left', cursor: 'pointer',
                            padding: '7px 12px 7px 30px', fontSize: 12.5, borderRadius: 8, border: 'none',
                            marginBottom: 1, transition: 'background 0.12s',
                            fontWeight: on ? 700 : 500,
                            color: on ? '#a78bfa' : 'var(--text-muted)',
                            background: on ? 'rgba(124,58,237,0.12)' : 'transparent',
                            borderLeft: `2px solid ${on ? '#7C3AED' : 'transparent'}`,
                            opacity: na && !on ? 0.5 : 1,
                          }}
                        >
                          <span>{it}</span>
                          {na && <Ban size={11} style={{ flexShrink: 0, marginLeft: 'auto', opacity: 0.75 }} />}
                        </button>
                      )
                    })}
                  </div>
                )}
              </div>
            )
          })}
        </nav>

        <div style={{ flex: 1, minWidth: 0 }}>
          <SectionContent tab={active} v={v} isActive={isActive} manage={manage} api={cfg.api} moduleName={cfg.moduleName}
          onDecision={kind => { setDecisionModal(kind); setRemarks('') }}
          onReload={load} />
        </div>
      </div>

      {/* Decision Modal Overlay */}
      {decisionModal && (
        <Overlay onClose={() => !decisionBusy && setDecisionModal(null)} width={480} showClose={false}>
          <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
              {decisionModal === 'approve' ? <CheckCircle size={18} style={{ color: '#10b981' }} /> : decisionModal === 'hold' ? <PauseCircle size={18} style={{ color: '#b45309' }} /> : <XCircle size={18} style={{ color: '#ef4444' }} />}
              {decisionModal === 'approve' ? 'Approve & Activate Vendor' : decisionModal === 'hold' ? 'Put Vendor On Hold' : decisionModal === 'reject' ? 'Reject Vendor Onboarding' : 'Send Back for Revision'}
            </h3>
            <button onClick={() => setDecisionModal(null)} style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 18 }}>✕</button>
          </div>

          <div style={{ padding: 22 }}>
            <p style={{ marginTop: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>
              {decisionModal === 'approve'
                ? 'Approving will generate the Registration Number, set the Vendor status to Active, log audit records, and dispatch Email & WhatsApp notifications.'
                : 'Please specify the mandatory rationale for this action.'}
            </p>

            <div style={{ marginBottom: 14 }}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-h)', marginBottom: 6 }}>
                {decisionModal === 'approve' ? 'Remarks (Optional)' : 'Remarks / Reason *'}
              </label>
              <textarea
                value={remarks}
                onChange={e => setRemarks(e.target.value)}
                rows={3}
                placeholder={decisionModal === 'approve' ? 'e.g. Compliance and documents verified...' : 'Enter mandatory remarks...'}
                style={{ width: '100%', padding: 10, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, outline: 'none', resize: 'vertical' }}
              />
            </div>

            <ModalFooter onClose={() => setDecisionModal(null)} onConfirm={handleAdminDecision} loading={decisionBusy}
              disabled={(decisionModal === 'reject' || decisionModal === 'hold' || decisionModal === 'resubmit') && !remarks.trim()}
              confirmLabel={decisionModal === 'approve' ? 'Approve & Activate' : decisionModal === 'hold' ? 'Confirm Hold' : decisionModal === 'reject' ? 'Confirm Rejection' : 'Send Back'}
              color={decisionModal === 'approve' ? '#10b981' : decisionModal === 'hold' ? '#f59e0b' : '#ef4444'} />
          </div>
        </Overlay>
      )}
    </div>
  )
}

/** Routes the active section to live data or the shared placeholder. */
function SectionContent({ tab, v, isActive, manage, api, moduleName, onDecision, onReload }) {
  switch (tab) {
    case 'Overview':
      return <VendorOverview vendor={v} api={api} isActive={isActive} />
    case 'Customer':
      return <VendorCustomers vendorId={v.id} vendorName={v.company_name} manage={manage} api={api} />
    case 'Profile':
      // Editable for admin/staff (canManage). A vendor never reaches this screen,
      // and PUT /vendors/{id} is role:admin,staff + assertTenant() regardless —
      // the button below is convenience, not the security boundary.
      return <ProfilePanel v={v} manage={manage} api={api} onSaved={onReload} />
    case 'Contact':
      // The Contact tab is now purely the master contact list (vendor-scoped CRUD).
      return <TpvVendorContacts vendorId={v.id} vendor={v} manage={manage} api={api} />
    case 'Tasks':
      // Two routes reach this tab: tasks raised against the vendor
      // (rel_type='tpv_vendor') and tasks assigned to the vendor's portal login.
      // Raising one here mirrors the Projects tab — see VendorTasks below.
      return <VendorTasks v={v} manage={manage} />
    case 'Documents':
      // Read-only: the vendor uploads through the portal during onboarding.
      // Admin reviews (approve/reject) and views/downloads — never uploads. The
      // onboarding decision now lives in the prominent panel under the header.
      return <TpvVendorDocuments vendorId={v.id} vendor={v} manage={false} api={api} moduleName={moduleName} />
    // ── Backed by data the TPV module already holds ────────────────────
    // Each reads an EXISTING admin endpoint scoped to this vendor; none of them
    // introduces a TPV-vendor-specific API or a second copy of the data.
    case 'Workforce':
      return <VendorWorkforce vendorId={v.id} manage={manage} />
    case 'Medical':
      return <VendorMedical vendorId={v.id} manage={manage} />
    case 'Training':
      return <VendorTraining vendorId={v.id} manage={manage} />
    case 'Gate Log':
      return <VendorGateLog vendorId={v.id} />
    case 'Strikes':
      return <VendorStrikes vendorId={v.id} manage={manage} />
    // Projects were already vendor-linked on the projects table (vendor_id +
    // link_type) — this reads that existing list, filtered to this vendor.
    case 'Projects':
      return <VendorProjects vendorId={v.id} vendorName={v.company_name} manage={manage} />
    // Tickets and Expenses have no vendor column of their own — both reach this
    // vendor through its PROJECTS, resolved server-side.
    case 'Ticket':
      return <VendorTickets vendorId={v.id} vendorName={v.company_name} manage={manage} />
    case 'Expenses':
      return <VendorExpenses vendorId={v.id} manage={manage} />
    // Both ride SHARED polymorphic tables — `reminders` (remindable_*) and
    // `notes` (notable_*) — so neither introduces a vendor-specific store.
    // Commercial — all seven read the Purchase module through the optional
    // vendors.purchase_vendor_id link. One component; the tab picks the document.
    case 'Quotation':
    case 'Contracts':
    case 'Purchase Order':
    case 'Purchase Invoice':
    case 'Debit Note':
    case 'Purchase Statement':
    case 'Payments':
      return <VendorCommercial tab={tab} vendorId={v.id} vendorName={v.company_name} manage={manage} />
    case 'Reminders':
      return <VendorReminders vendorId={v.id} vendorName={v.company_name} manage={manage} />
    case 'Notes':
      return <VendorNotes vendorId={v.id} vendorName={v.company_name} manage={manage} />
    // Free-form files in folders — separate from Documents, which is the
    // statutory checklist. Google Drive and OneDrive import into it.
    case 'Attachments':
      return <VendorAttachments vendorId={v.id} manage={manage} />
    default:
      // Everything else is either settled as out of scope for a TPV vendor, or
      // genuinely unbacked — no table, awaiting a business definition.
      return <ComingSoonSection name={tab} reason={NOT_APPLICABLE[tab]} notApplicable={!!NOT_APPLICABLE[tab]} />
  }
}

/**
 * The Tasks tab, with the same shape as Projects: list, search, and an add button
 * that opens the TASK module's own drawer with this vendor pre-linked and locked.
 *
 * A task raised here therefore carries every field one raised from the Task screen
 * does — assignees, followers, checklists, recurrence, billing — because it IS
 * that form, not a reduced copy. Only `rel_type`/`rel_id` are decided for the user,
 * which is exactly what makes the task come back to this tab.
 */
function VendorTasks({ v, manage }) {
  const qc = useQueryClient()
  const [adding, setAdding] = useState(false)
  const key = ['tpv-vendor-tasks', v.id]

  return (
    <>
      <TaskFormDrawer
        open={adding}
        onClose={() => setAdding(false)}
        defaults={{ rel_type: 'tpv_vendor', rel_id: v.id }}
        lockRel
        lockRelLabel={v.company_name}
        onSaved={() => qc.invalidateQueries({ queryKey: key })}
      />
      <VendorTasksPanel
        queryKey={key}
        fetcher={() => tpvApi.vendors.tasks(v.id)}
        accent="#0ea5e9"
        searchable
        onAdd={manage ? () => setAdding(true) : undefined}
        emptyHint="Assign a task to this vendor’s login, or set a task’s “Related To” → “TPV Vendor” → this vendor."
      />
    </>
  )
}

/**
 * A status pill. The label sits above the value so the two read as one unit
 * instead of a run-on sentence, and the colour is a quiet tint — status colour is
 * never the only carrier of meaning, the written label always is.
 */
/**
 * The approve / hold / reject / send-back toolbar.
 *
 * Lives with the onboarding documents rather than in the page header: the
 * decision is a judgement ABOUT those documents, and a header button sat next
 * to the vendor name invited approving an onboarding without having opened
 * what was submitted. Same actions, same handler — only the placement moved.
 */
/**
 * Overview tab — a live per-vendor dashboard: profile summary + count cards
 * (customers, contacts, workers, documents, attachments, notes) fetched from
 * GET /tpv/vendors/{id}/overview, so the numbers always match the other tabs.
 */
// The five mandated onboarding gates (Doc 2/4) — a cleared/total strip.
function GateStrip({ g }) {
  return (
    <div className="pr-glass" style={{ borderRadius: 14, padding: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <ShieldCheck size={15} style={{ color: '#a78bfa' }} />
        <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Onboarding Gates</span>
        <span style={{ marginLeft: 'auto', fontSize: 12, fontWeight: 800, color: g.all_cleared ? '#10b981' : '#f59e0b' }}>{g.cleared}/{g.total} cleared</span>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 8 }}>
        {(g.gates || []).map((gate, i) => (
          <div key={i} style={{ padding: '9px 11px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              {gate.passed ? <CheckCircle size={14} style={{ color: '#10b981' }} /> : <Clock size={14} style={{ color: '#f59e0b' }} />}
              <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h)' }}>{gate.gate}</span>
            </div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 3, lineHeight: 1.4 }}>{gate.detail}</div>
          </div>
        ))}
      </div>
    </div>
  )
}

// VRS scorecard (Doc 5) — overall band + the three contributing dimensions.
function ScorecardCard({ sc }) {
  const bandColor = { A: '#10b981', B: '#0ea5e9', C: '#f59e0b', D: '#ef4444' }[sc.band] || '#94a3b8'
  const dims = [
    { key: 'safety', label: 'Safety', v: sc.dimensions?.safety, note: d => `${d.open_incidents ?? 0} open · ${d.active_strikes ?? 0} strikes` },
    { key: 'compliance', label: 'Compliance', v: sc.dimensions?.compliance, note: d => d.note || `${d.valid ?? 0}/${d.required ?? 0} docs current` },
    { key: 'workforce', label: 'Workforce', v: sc.dimensions?.workforce, note: d => d.note || `${d.active ?? 0}/${d.total ?? 0} active` },
  ]
  const barColor = (s) => s >= 85 ? '#10b981' : s >= 70 ? '#0ea5e9' : s >= 55 ? '#f59e0b' : '#ef4444'
  return (
    <div className="pr-glass" style={{ borderRadius: 14, padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 58, height: 58, borderRadius: 14, background: `${bandColor}22`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
            <span style={{ fontSize: 30, fontWeight: 900, color: bandColor }}>{sc.band}</span>
          </div>
          <div>
            <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)' }}>Vendor Rating</div>
            <div style={{ fontSize: 24, fontWeight: 800, color: 'var(--text-h)' }}>{sc.overall_score}<span style={{ fontSize: 14, color: 'var(--text-muted)' }}>/100</span></div>
          </div>
        </div>
        <div style={{ flex: 1, minWidth: 240, display: 'grid', gap: 9 }}>
          {dims.map(d => (
            <div key={d.key}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11.5, marginBottom: 3 }}>
                <span style={{ color: 'var(--text-h)', fontWeight: 700 }}>{d.label} <span style={{ color: 'var(--text-muted)', fontWeight: 500 }}>· {d.v ? d.note(d.v) : '—'}</span></span>
                <span style={{ color: barColor(d.v?.score ?? 0), fontWeight: 800 }}>{d.v?.score ?? 0}</span>
              </div>
              <div style={{ height: 6, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden' }}>
                <div style={{ width: `${d.v?.score ?? 0}%`, height: '100%', background: barColor(d.v?.score ?? 0), borderRadius: 999 }} />
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

function VendorOverview({ vendor, api, isActive }) {
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [scorecard, setScorecard] = useState(null)
  const [gates, setGates] = useState(null)

  useEffect(() => {
    let alive = true
    api.vendors.overview(vendor.id)
      .then(d => { if (alive) setData(d) })
      .catch(e => { if (alive) setErr(e?.response?.data?.message || 'Could not load the overview.') })
    if (api.vendors.scorecard) {
      api.vendors.scorecard(vendor.id).then(d => { if (alive) setScorecard(d?.live ?? null) }).catch(() => {})
    }
    if (api.vendors.gates) {
      api.vendors.gates(vendor.id).then(d => { if (alive) setGates(d) }).catch(() => {})
    }
    return () => { alive = false }
  }, [vendor.id, api])

  const c = data?.counts || {}
  const cards = [
    { label: 'Customers', value: c.customers, icon: Users },
    { label: 'Contacts', value: c.contacts, icon: User },
    { label: 'Workers', value: c.workers, icon: HardHat },
    { label: 'Documents', value: c.documents, icon: FileText },
    { label: 'Attachments', value: c.attachments, icon: Paperclip },
    { label: 'Notes', value: c.notes, icon: StickyNote },
  ]

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: 12 }}>
        {cards.map(card => (
          <div key={card.label} className="pr-glass" style={{ borderRadius: 14, padding: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)' }}>{card.label}</span>
              <card.icon size={16} style={{ color: '#a78bfa' }} />
            </div>
            <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--text-h)', marginTop: 6 }}>
              {data ? (card.value ?? 0) : '—'}
            </div>
          </div>
        ))}
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: 0 }}>{err}</p>}

      {gates && <GateStrip g={gates} />}

      {scorecard && <ScorecardCard sc={scorecard} />}

      <Card icon={ShieldCheck} title="Vendor Summary">
        <Grid rows={[
          ['Company', vendor.company_name],
          ['Vendor Code', vendor.vendor_code],
          ['Account Status', isActive ? 'Active' : (vendor.status_label || vendor.status || 'Inactive')],
          ['Type', vendor.vendor_type],
          ['Portal Login', vendor.user?.name || '—'],
          ['Login Status', vendor.user?.status || '—'],
          ['Engagements', (vendor.engagements || []).join(', ') || '—'],
          ['Created', vendor.created_at ? new Date(vendor.created_at).toLocaleDateString() : '—'],
        ]} />
      </Card>
    </div>
  )
}

/**
 * Prominent Onboarding Decision panel — sits directly under the header once the
 * vendor has an onboarding. Shows the vendor identity + current onboarding step /
 * status / account status, and the Approve / Hold / Reject / Send-Back actions.
 *
 * Approve is gated: it enables only when the vendor has actually completed Steps
 * 1–5 (onboarding Submitted / Under Review = "Waiting for Admin Approval") AND all
 * required documents are uploaded, approved, and none are still rejected — the
 * same completeness the Documents checklist reports. When blocked it stays visible
 * but disabled, listing exactly what is missing, so an incomplete onboarding can
 * never be approved by accident.
 */
function OnboardingDecisionPanel({ vendor, onboarding, api, onDecision }) {
  const [docs, setDocs] = useState(null)
  const status = onboarding?.status || 'Draft'
  const step = onboarding?.current_step || 1
  const obc = obStatusCfg(status)
  const approved = status === 'Approved'
  const accountActive = vendor.status === 'Active'

  useEffect(() => {
    let alive = true
    api.documents.checklist(vendor.id).then(d => { if (alive) setDocs(d) }).catch(() => {})
    return () => { alive = false }
  }, [vendor.id, api])

  const decidable = ['Submitted', 'Under_Review'].includes(status)
  const rejectedDocs = docs?.summary?.rejected ?? 0
  const docsComplete = docs?.complete ?? false
  const canApprove = decidable && docsComplete && rejectedDocs === 0

  const reasons = []
  if (!decidable) reasons.push('Vendor must complete Steps 1–5 and submit (Waiting for Admin Approval)')
  if (docs && !docsComplete) reasons.push('All required documents must be uploaded and approved')
  if (rejectedDocs > 0) reasons.push(`${rejectedDocs} document(s) still rejected — must be corrected & re-approved`)

  const tint = approved ? '#0ca30c' : status === 'Rejected' ? '#d03b3b' : status === 'On_Hold' ? '#b45309' : '#7C3AED'

  return (
    <div style={{
      marginBottom: 18, borderRadius: 16, overflow: 'hidden',
      background: 'var(--bg-card)', border: `1px solid color-mix(in srgb, ${tint} 40%, var(--border))`,
      boxShadow: `0 1px 0 color-mix(in srgb, ${tint} 20%, transparent)`,
    }}>
      <div style={{ padding: '14px 18px', display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', borderBottom: '1px solid var(--border)', background: `color-mix(in srgb, ${tint} 6%, transparent)` }}>
        <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', display: 'inline-flex', alignItems: 'center', gap: 7 }}>
          <ShieldCheck size={16} style={{ color: tint }} /> Onboarding Decision
        </span>
        <span style={{ flex: 1 }} />
        <StatusPill label="Step" value={`${step} of 6`} tone="#7C3AED" />
        <StatusPill label="Onboarding" value={obc.label} tone={obc.color} />
        <StatusPill label="Account" value={accountActive ? 'Active' : (vendor.status_label || vendor.status)} tone={accountActive ? '#0ca30c' : '#8a94a6'} />
      </div>

      <div style={{ padding: '16px 18px' }}>
        <p style={{ margin: '0 0 12px', fontSize: 13, color: 'var(--text-muted)' }}>
          <strong style={{ color: 'var(--text-h)' }}>{vendor.company_name || vendor.vendor_code}</strong>
          {' — '}{approved
            ? 'onboarding approved and the account is activated (Step 6).'
            : decidable
              ? 'has completed all steps and is waiting for your decision.'
              : 'is still progressing through onboarding.'}
        </p>

        {approved ? (
          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 9, padding: '10px 14px', borderRadius: 10, background: 'color-mix(in srgb, #0ca30c 12%, transparent)', border: '1px solid color-mix(in srgb, #0ca30c 30%, transparent)', color: '#0ca30c', fontSize: 12.5, fontWeight: 700 }}>
            <CheckCircle size={16} /> Step 6 — Account Activated. The vendor can now access the active portal.
          </div>
        ) : (
          <>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <button onClick={() => canApprove && onDecision('approve')} disabled={!canApprove}
                title={canApprove ? 'Approve onboarding and activate the account' : 'Complete the requirements below to enable approval'}
                style={{ padding: '9px 16px', borderRadius: 9, background: canApprove ? '#0ca30c' : 'color-mix(in srgb, #0ca30c 30%, var(--bg-input))', color: '#fff', border: 'none', fontSize: 12.5, fontWeight: 700, cursor: canApprove ? 'pointer' : 'not-allowed', opacity: canApprove ? 1 : 0.55, display: 'inline-flex', alignItems: 'center', gap: 7 }}>
                <ShieldCheck size={15} /> Approve Onboarding
              </button>
              <button onClick={() => onDecision('hold')} style={{ ...ghostBtn, color: '#b45309', borderColor: 'color-mix(in srgb, #b45309 32%, transparent)' }}>
                <PauseCircle size={14} /> Put Account On Hold
              </button>
              <button onClick={() => onDecision('reject')} style={{ ...ghostBtn, color: '#d03b3b', borderColor: 'color-mix(in srgb, #d03b3b 32%, transparent)' }}>
                <XCircle size={14} /> Reject Account
              </button>
              <button onClick={() => onDecision('resubmit')} style={ghostBtn}>
                <CornerUpLeft size={14} /> Send Back
              </button>
            </div>
            {!canApprove && reasons.length > 0 && (
              <ul style={{ margin: '12px 0 0', padding: '10px 14px 10px 30px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.7 }}>
                {reasons.map((r, i) => <li key={i}>{r}</li>)}
              </ul>
            )}
          </>
        )}
      </div>
    </div>
  )
}

function StatusPill({ label, value, tone }) {
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 6,
      padding: '4px 11px', borderRadius: 999, whiteSpace: 'nowrap',
      background: `color-mix(in srgb, ${tone} 11%, transparent)`,
      border: `1px solid color-mix(in srgb, ${tone} 26%, transparent)`,
    }}>
      <span style={{ width: 6, height: 6, borderRadius: 999, background: tone, flexShrink: 0 }} />
      <span style={{ fontSize: 9.5, fontWeight: 700, letterSpacing: '0.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{label}</span>
      <span style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--text-h)' }}>{value}</span>
    </span>
  )
}

/* Recessive action. Only ONE button in a toolbar should be filled. */
const ghostBtn = {
  padding: '8px 12px', borderRadius: 9, background: 'transparent',
  border: '1px solid var(--border)', color: 'var(--text-muted)',
  fontSize: 12, fontWeight: 700, cursor: 'pointer',
  display: 'inline-flex', alignItems: 'center', gap: 5,
}

/**
 * Vendor profile — read-only until an authorised user chooses to edit.
 *
 * Registration Number and Account Manager stay read-only on purpose: the first is
 * assigned by onboarding approval, the second is an internal assignment. Both are
 * shown because they are useful context, but neither is typed here.
 *
 * Saves through the SAME PUT /vendors/{id} the rest of the module uses, so
 * validation and tenancy checks are the server's, not this component's.
 */
function ProfilePanel({ v, manage, api, onSaved }) {
  const FIELDS = [
    ['legal_name', 'Legal Name'],
    ['gst_number', 'GST Number'],
    ['pan_number', 'PAN Number'],
    ['category',   'Category'],
    ['website',    'Website'],
  ]
  const CONTACT = [
    ['email',   'Email'],
    ['phone',   'Phone'],
    ['address', 'Address'],
    ['city',    'City'],
    ['state',   'State'],
    ['country', 'Country'],
    ['pincode', 'Pincode'],
  ]

  const blank = () => [...FIELDS, ...CONTACT].reduce((a, [k]) => ({ ...a, [k]: v[k] ?? '' }), {})

  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState(blank)
  const [saving, setSaving] = useState(false)
  const [errs, setErrs] = useState({})
  const [notice, setNotice] = useState(null)

  // Re-seed whenever the vendor reloads, so a save (or another tab's change)
  // is reflected instead of leaving stale values in the inputs.
  useEffect(() => { if (!editing) setForm(blank()) }, [v]) // eslint-disable-line react-hooks/exhaustive-deps

  const set = (k) => (e) => setForm((p) => ({ ...p, [k]: e.target.value }))

  const cancel = () => { setForm(blank()); setErrs({}); setEditing(false) }

  const validate = () => {
    const e = {}
    if (form.pan_number && !/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/.test(form.pan_number)) e.pan_number = 'Invalid PAN (AAAAA9999A).'
    if (form.email && !/^\S+@\S+\.\S+$/.test(form.email)) e.email = 'Enter a valid email address.'
    if (form.pincode && !/^[0-9]{6}$/.test(form.pincode)) e.pincode = '6 digits.'
    return e
  }

  const save = async () => {
    const e = validate()
    setErrs(e)
    if (Object.keys(e).length) return

    setSaving(true); setNotice(null)
    try {
      const payload = { ...form, pan_number: form.pan_number ? form.pan_number.toUpperCase() : '' }
      await api.vendors.update(v.id, payload)
      setEditing(false)
      setNotice({ ok: true, text: 'Profile updated.' })
      onSaved?.()
    } catch (err) {
      // Field errors from the server win over the local guesses.
      const back = err?.response?.data?.errors
      if (back) setErrs(Object.fromEntries(Object.entries(back).map(([k, m]) => [k, Array.isArray(m) ? m[0] : m])))
      setNotice({ ok: false, text: err?.response?.data?.message || 'Could not save the profile.' })
    } finally { setSaving(false) }
  }

  const inputStyle = (k) => ({
    width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5,
    background: 'var(--bg-input)', color: 'var(--text-h)',
    border: `1px solid ${errs[k] ? '#ef4444' : 'var(--border)'}`,
  })

  const Row = ([k, label]) => (
    <div key={k} style={{ display: 'flex', flexDirection: 'column', gap: 4, paddingBottom: 7, borderBottom: editing ? 'none' : '1px solid var(--border)' }}>
      <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{label}</span>
      {editing ? (
        <>
          <input value={form[k]} onChange={set(k)} style={inputStyle(k)} disabled={saving} />
          {errs[k] && <span style={{ color: '#ef4444', fontSize: 11 }}>{errs[k]}</span>}
        </>
      ) : (
        <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600 }}>{v[k] || '—'}</span>
      )}
    </div>
  )

  const btn = (bg) => ({
    display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', borderRadius: 9,
    border: bg ? 'none' : '1px solid var(--border)', cursor: saving ? 'default' : 'pointer',
    background: bg || 'var(--bg-input)', color: bg ? '#fff' : 'var(--text-h)',
    fontSize: 12.5, fontWeight: 700, opacity: saving ? 0.7 : 1,
  })

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      {notice && (
        <div style={{
          maxWidth: 760, padding: '9px 12px', borderRadius: 10, fontSize: 12.5,
          background: notice.ok ? 'rgba(16,185,129,.10)' : 'rgba(239,68,68,.10)',
          color: notice.ok ? '#047857' : '#ef4444',
        }}>{notice.text}</div>
      )}

      <Card icon={User} title="Profile">
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 24px' }}>
          {FIELDS.map(Row)}
          {/* Assigned by the system / internally — never typed on this form. */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, paddingBottom: 7, borderBottom: '1px solid var(--border)' }}>
            <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>Registration No.</span>
            <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600 }}>{v.registration_number || '—'}</span>
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, paddingBottom: 7, borderBottom: '1px solid var(--border)' }}>
            <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>Account Manager</span>
            <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600 }}>{v.account_manager?.name || '—'}</span>
          </div>
        </div>

        {manage && (
          <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
            {editing ? (
              <>
                <button onClick={save} disabled={saving} style={btn('#7C3AED')}>
                  {saving && <Loader2 size={13} className="rfq-spin" />} Save
                </button>
                <button onClick={cancel} disabled={saving} style={btn(null)}>Cancel</button>
              </>
            ) : (
              <button onClick={() => setEditing(true)} style={btn('#7C3AED')}>Edit</button>
            )}
          </div>
        )}
      </Card>

      {/* Contact & Address lives with the vendor profile (moved off the Contact tab) */}
      <Card icon={Phone} title="Contact & Address">
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 24px' }}>
          {CONTACT.map(Row)}
        </div>
      </Card>
    </div>
  )
}

function Card({ icon: Icon, title, children }) {
  return (
    <div className="pr-glass" style={{ padding: 20, borderRadius: 16, maxWidth: 760 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
        <Icon size={16} style={{ color: '#a78bfa' }} />
        <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{title}</span>
      </div>
      {children}
    </div>
  )
}

function Grid({ rows }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 24px' }}>
      {rows.map(([k, val]) => (
        <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 7 }}>
          <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
          <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, textAlign: 'right' }}>{val || '—'}</span>
        </div>
      ))}
    </div>
  )
}

const wrap = { padding: 24, minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg-global)' }
const backBtn = { width: 38, height: 38, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', flexShrink: 0 }
const groupBtn = { display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '8px 10px', cursor: 'pointer', background: 'none', border: 'none', color: 'var(--text-h)', fontSize: 11.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.03em' }
