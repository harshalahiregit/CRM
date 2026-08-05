import { useState, useEffect, useCallback, useMemo } from 'react'
import { useParams, useNavigate, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft, Building2, User, Phone, Loader2, ShieldCheck, CheckCircle, XCircle, PauseCircle, CornerUpLeft, Clock, AlertTriangle,
  Briefcase, IndianRupee, ClipboardCheck, BarChart3, ChevronDown, ChevronRight, Mail,
} from 'lucide-react'

const NOTIF_COLORS = { sent: '#10b981', failed: '#ef4444', skipped: '#94a3b8', queued: '#0ea5e9' }

/**
 * What an empty tab should say, based on an audit of what actually exists.
 *
 *  · Listed here  → the owning module EXISTS; the vendor simply has no rows.
 *  · Not listed   → no such module in this system, so the default
 *                   "This module is not available." is the truthful message.
 *
 * Verified against the schema: tpv_workers / tpv_worker_medicals /
 * tpv_worker_inductions / tpv_worker_ppe_issues / tpv_gate_scans /
 * tpv_safety_strikes exist. PTW, Incidents, Visitors, Pre Alert, Package,
 * Survey, Training, Risk/Award/Penalty/Feedback/Referral have NO backing table.
 * Projects / Tasks / Expenses / Tickets exist generically but carry no vendor
 * link, so they cannot be filtered to this vendor.
 */
const TAB_EMPTY_REASON = {
  Medical:    'No Medical Records',
  Workforce:  'No Workforce Records',
  'Gate Log': 'No Gate Records',
  Strikes:    'No Safety Strikes',
  Projects:   'Projects are not linked to vendors in this system.',
  Expenses:   'Expenses are not linked to vendors in this system.',
  Ticket:     'Tickets are not linked to vendors in this system.',
}
import { useAuth } from '@/context/AuthContext'
import { obStatusCfg } from '@/modules/tpv/constants'
import { useVendorModule } from '@/modules/tpv/useVendorModule'
import TpvRegistrationBadge from '@/modules/tpv/components/TpvRegistrationBadge'
import TemporaryTpvValidityBadge from '@/modules/tpv/components/TemporaryTpvValidityBadge'
import { KIT3D_STYLE, Overlay, ModalFooter } from '@/components/ui/kit3d'
import ComingSoonSection from '@/modules/tpv/components/ComingSoonSection'
import VendorTasksPanel from '@/components/vendor/VendorTasksPanel'
import { tpvApi } from '@/services/tpvApi'
import TpvVendorContacts from '@/modules/tpv/components/TpvVendorContacts'
import TpvVendorDocuments from '@/modules/tpv/components/TpvVendorDocuments'

// The Vendor Detail navigation — 5 groups, 35 sections. Only Overview / Profile /
// Contact are backed by live data today; every other section is a ComingSoonSection
// placeholder. This data drives both the left nav and the content router below, so
// adding a real section later means dropping it into the LIVE switch — nothing else.
const NAV_GROUPS = [
  { group: 'General',     icon: User,           items: ['Overview', 'Profile', 'Contact', 'Medical', 'Training', 'Customer'] },
  { group: 'Commercial',  icon: IndianRupee,    items: ['Quotation', 'Contracts', 'Purchase Order', 'Purchase Invoice', 'Debit Note', 'Purchase Statement', 'Payments'] },
  { group: 'Operations',  icon: Briefcase,      items: ['Projects', 'Tasks', 'Expenses', 'Attachments', 'ToDo', 'Notes', 'Technical File Maintenance', 'Ticket', 'Job', 'Reminders'] },
  { group: 'Compliance',  icon: ClipboardCheck, items: ['Survey', 'PTW', 'Incidents', 'Documents', 'Pre Alert', 'Package', 'Visitors'] },
  { group: 'Performance', icon: BarChart3,      items: ['Risk Score', 'Award / Reward', 'Penalty', 'Feedback', 'Referrals'] },
]

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

        {/* The Onboarding Decision toolbar used to sit here. It now lives with
            the documents it is a judgement about — Compliance → Documents. */}
      </div>

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
                      return (
                        <button
                          key={it}
                          onClick={() => selectTab(it)}
                          style={{
                            display: 'block', width: '100%', textAlign: 'left', cursor: 'pointer',
                            padding: '7px 12px 7px 30px', fontSize: 12.5, borderRadius: 8, border: 'none',
                            marginBottom: 1, transition: 'background 0.12s',
                            fontWeight: on ? 700 : 500,
                            color: on ? '#a78bfa' : 'var(--text-muted)',
                            background: on ? 'rgba(124,58,237,0.12)' : 'transparent',
                            borderLeft: `2px solid ${on ? '#7C3AED' : 'transparent'}`,
                          }}
                        >{it}</button>
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
          onDecision={kind => { setDecisionModal(kind); setRemarks('') }} />
        </div>
      </div>

      {/* Decision Modal Overlay */}
      {decisionModal && (
        <Overlay onClose={() => !decisionBusy && setDecisionModal(null)} width={480}>
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
function SectionContent({ tab, v, isActive, manage, api, moduleName, onDecision }) {
  switch (tab) {
    case 'Overview':
      return (
        <Card icon={ShieldCheck} title="Overview">
          <Grid rows={[
            ['Company', v.company_name],
            ['Vendor Code', v.vendor_code],
            ['Status', isActive ? 'Active' : 'Inactive'],
            ['Type', v.vendor_type],
            ['Portal Login', v.user?.name || '—'],
            ['Login Status', v.user?.status || '—'],
            ['Engagements', (v.engagements || []).join(', ') || '—'],
            ['Created', v.created_at ? new Date(v.created_at).toLocaleDateString() : '—'],
          ]} />
        </Card>
      )
    case 'Profile':
      return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <Card icon={User} title="Profile">
            <Grid rows={[
              ['Legal Name', v.legal_name],
              ['GST Number', v.gst_number],
              ['PAN Number', v.pan_number],
              ['Registration No.', v.registration_number],
              ['Category', v.category],
              ['Website', v.website],
              ['Account Manager', v.account_manager?.name],
            ]} />
          </Card>
          {/* Contact & Address lives with the vendor profile (moved off the Contact tab) */}
          <Card icon={Phone} title="Contact & Address">
            <Grid rows={[
              ['Email', v.email],
              ['Phone', v.phone],
              ['Address', v.address],
              ['City', v.city],
              ['State', v.state],
              ['Country', v.country],
              ['Pincode', v.pincode],
            ]} />
          </Card>
        </div>
      )
    case 'Contact':
      // The Contact tab is now purely the master contact list (vendor-scoped CRUD).
      return <TpvVendorContacts vendorId={v.id} vendor={v} manage={manage} api={api} />
    case 'Tasks':
      // Two routes reach this tab: tasks raised against the vendor
      // (rel_type='tpv_vendor') and tasks assigned to the vendor's portal login.
      return (
        <VendorTasksPanel
          queryKey={['tpv-vendor-tasks', v.id]}
          fetcher={() => tpvApi.vendors.tasks(v.id)}
          accent="#0ea5e9"
          emptyHint="Assign a task to this vendor’s login, or set a task’s “Related To” → “TPV Vendor” → this vendor."
        />
      )
    case 'Documents':
      // Read-only: the vendor uploads through the portal during onboarding.
      // Admin reviews (approve/reject) and views/downloads — never uploads.
      // The onboarding decision sits directly above what it is a judgement about.
      return (
        <>
          {manage && onDecision && <OnboardingDecisionBar onDecision={onDecision} />}
          <TpvVendorDocuments vendorId={v.id} vendor={v} manage={false} api={api} moduleName={moduleName} />
        </>
      )
    default:
      // Honest copy: distinguish "module exists, no rows for this vendor" from
      // "no such module in this system". See TAB_EMPTY_REASON.
      return <ComingSoonSection name={tab} reason={TAB_EMPTY_REASON[tab]} />
  }
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
function OnboardingDecisionBar({ onDecision }) {
  return (
    <div style={{ marginBottom: 16, padding: '13px 16px', borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <p style={{ fontSize: 10, fontWeight: 700, letterSpacing: '0.05em', textTransform: 'uppercase', color: 'var(--text-muted)', margin: '0 0 9px' }}>
        Onboarding Decision
      </p>
      <div style={{ display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap' }}>
        <button onClick={() => onDecision('approve')}
          style={{ padding: '8px 14px', borderRadius: 9, background: '#0ca30c', color: '#fff', border: 'none', fontSize: 12, fontWeight: 700, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          <ShieldCheck size={14} /> Approve Onboarding
        </button>
        <button onClick={() => onDecision('hold')} style={ghostBtn}>
          <PauseCircle size={14} /> Put On Hold
        </button>
        <button onClick={() => onDecision('reject')}
          style={{ ...ghostBtn, color: '#d03b3b', borderColor: 'color-mix(in srgb, #d03b3b 32%, transparent)' }}>
          <XCircle size={14} /> Reject
        </button>
        <button onClick={() => onDecision('resubmit')} style={ghostBtn}>
          <CornerUpLeft size={14} /> Send Back
        </button>
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
