import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate, NavLink, Routes, Route, Navigate } from 'react-router-dom'
import { ArrowLeft, Building2, CheckCircle2, CheckCircle, XCircle, PauseCircle, CornerUpLeft, ShieldCheck, ChevronDown, ChevronRight, Mail } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { Overlay, ModalFooter } from '@/components/ui/kit3d'
import { VENDOR_NAV_GROUPS, VENDOR_NAV_ITEMS } from './vendorDetailNav'
import { TAB_ELEMENTS, ComingSoonTab } from './vendorDetailTabs'
import { VendorWorkspaceContext } from './vendorWorkspaceContext'
import PurchaseRegistrationBadge from '@/modules/purchase/components/PurchaseRegistrationBadge'
import TemporaryVendorValidityBadge from '@/modules/purchase/components/TemporaryVendorValidityBadge'

// Onboarding status → tint + label for the decision panel pills. Mirrors the TPV
// obStatusCfg without importing across modules.
const OB_STATUS = {
  Draft: { label: 'Draft', color: '#6b7280' },
  In_Progress: { label: 'In Progress', color: '#0ea5e9' },
  Submitted: { label: 'Submitted', color: '#7C3AED' },
  Under_Review: { label: 'Under Review', color: '#7C3AED' },
  Resubmit_Required: { label: 'Resubmit Required', color: '#f59e0b' },
  On_Hold: { label: 'On Hold', color: '#b45309' },
  Approved: { label: 'Approved', color: '#0ca30c' },
  Rejected: { label: 'Rejected', color: '#d03b3b' },
}
const obCfg = (s) => OB_STATUS[s] || { label: String(s || '—').replace(/_/g, ' '), color: '#6b7280' }

/**
 * Purchase Vendor Detail workspace — persistent left-sidebar layout for a single
 * PurchaseVendor (/app/purchase/vendors/:id/*). The sidebar stays fixed while the
 * nested route renders into <Outlet/>; each tab is deep-linkable and refresh-safe
 * because the active tab lives in the URL path. 100% Purchase-owned: loads
 * PurchaseVendor via purchaseApi only — never Vendor, never TPV.
 */
const STATUS_COLORS = { Active: '#10b981', Pending_Approval: '#f59e0b', Draft: '#6b7280', On_Hold: '#f59e0b', Rejected: '#ef4444', Blacklisted: '#991b1b', Inactive: '#6b7280' }
const NOTIF_COLORS = { sent: '#10b981', failed: '#ef4444', skipped: '#94a3b8', queued: '#0ea5e9' }

export default function PurchaseVendorDetailLayout() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [vendor, setVendor] = useState(null)
  const [onboarding, setOnboarding] = useState(null)
  const [loading, setLoading] = useState(true)
  const [collapsed, setCollapsed] = useState({})
  const [resending, setResending] = useState(false)
  const [notice, setNotice] = useState(null)
  const [showTimeline, setShowTimeline] = useState(false)

  // Onboarding decision state (approve / reject / hold / send-back).
  const [decision, setDecision] = useState(null)
  const [remarks, setRemarks] = useState('')
  const [decisionBusy, setDecisionBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    purchaseApi.vendors.get(id)
      .then((v) => {
        setVendor(v)
        return purchaseApi.onboarding.list({ purchase_vendor_id: id }).catch(() => [])
      })
      .then((list) => setOnboarding((Array.isArray(list) ? list : list?.data ?? [])[0] || null))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [id])

  useEffect(() => { load() }, [load])

  const activate = async () => { try { await purchaseApi.vendors.approve(id); load() } catch { /* noop */ } }

  // Runs the chosen onboarding decision. Reject / Hold / Send-Back require remarks;
  // Approve also really activates the account (portal login + activation email)
  // because PurchaseOnboardingService::approve now routes through the vendor service.
  const runDecision = async () => {
    if (['reject', 'hold', 'resubmit'].includes(decision) && !remarks.trim()) {
      alert('Mandatory remarks are required for this action.')
      return
    }
    setDecisionBusy(true)
    try {
      let ob = onboarding
      if (!ob) {
        const r = await purchaseApi.onboarding.create({ purchase_vendor_id: id })
        ob = r?.data ?? r
      }
      if (decision === 'approve') await purchaseApi.onboarding.approve(ob.id, remarks)
      else if (decision === 'reject') await purchaseApi.onboarding.reject(ob.id, remarks)
      else if (decision === 'hold') await purchaseApi.onboarding.hold(ob.id, remarks)
      else if (decision === 'resubmit') await purchaseApi.onboarding.requestResubmit(ob.id, remarks)
      setDecision(null); setRemarks(''); load()
    } catch (e) {
      alert(e?.response?.data?.message || 'Decision failed')
    } finally { setDecisionBusy(false) }
  }

  // Manual resend. Every attempt is logged server-side; the panel below reflects it.
  const resendActivation = async () => {
    setResending(true); setNotice(null)
    try {
      const r = await purchaseApi.vendors.resendActivation(id)
      setNotice(r?.status === 'sent'
        ? { ok: true, text: `Activation email sent to ${r.recipient}.` }
        : { ok: false, text: 'Could not send the activation email. It has been logged — try again.' })
      load()
    } catch (e) {
      setNotice({ ok: false, text: e?.response?.data?.message || 'Could not send the activation email.' })
    } finally { setResending(false) }
  }
  const toggle = (title) => setCollapsed((c) => ({ ...c, [title]: !c[title] }))

  if (loading) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading vendor…</div>
  if (!vendor) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Purchase vendor not found.</div>

  const statusColor = STATUS_COLORS[vendor.status] || '#6b7280'

  return (
    <div style={{ padding: 20 }}>
      {/* Header */}
      <div className="card-3d" style={{ padding: 16, marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <button onClick={() => navigate('/app/purchase/vendors')} style={backBtn} title="Back to Vendors"><ArrowLeft size={16} /></button>
            <div style={{ width: 52, height: 52, borderRadius: 14, background: 'rgba(124,58,237,0.14)', border: '1px solid rgba(124,58,237,0.3)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <Building2 size={24} style={{ color: '#a78bfa' }} />
            </div>
            <div>
              <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: '.05em', color: '#a78bfa', textTransform: 'uppercase' }}>#{vendor.purchase_vendor_code}</div>
              <h1 style={{ fontSize: 20, fontWeight: 900, color: 'var(--text-h)', margin: '1px 0 4px' }}>{vendor.company_name}</h1>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                {/* Registration type — the stored choice, never inferred */}
                <PurchaseRegistrationBadge type={vendor.registration_type} label={vendor.registration_type_label} />
                {/* Remaining access — countdown for temporary, "Permanent" otherwise */}
                <TemporaryVendorValidityBadge countdown={vendor.validity_countdown} showLabel />
                <span style={{ ...pill, color: statusColor, borderColor: statusColor + '55' }}>{vendor.status_label || vendor.status}</span>
                {vendor.category && <span style={{ ...pill, color: 'var(--text-muted)' }}>{vendor.category}</span>}
                {onboarding && <span style={{ ...pill, color: '#7C3AED', borderColor: 'rgba(124,58,237,0.35)' }}>Onboarding: {onboarding.status_label || onboarding.status}</span>}
              </div>
            </div>
          </div>
          {/* Quick actions */}
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            {/* The plain Activate is only for a vendor WITHOUT an onboarding — when
                one exists, the gated Onboarding Decision panel below owns approval. */}
            {vendor.status !== 'Active' && !onboarding && (
              <button onClick={activate} style={{ ...actBtn, color: '#10b981', borderColor: 'rgba(16,185,129,0.4)' }}><CheckCircle2 size={14} /> Activate</button>
            )}
            {/* Resend is offered only once the account is Active. */}
            {vendor.status === 'Active' && (
              <button onClick={resendActivation} disabled={resending} style={actBtn} title="Send the activation email again">
                <Mail size={14} /> {resending ? 'Sending…' : 'Resend Activation Email'}
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Onboarding Decision — prominent, directly under the header, once the
          vendor has an onboarding to decide on. */}
      {onboarding && (
        <OnboardingDecisionPanel
          vendor={vendor} onboarding={onboarding}
          onDecision={kind => { setDecision(kind); setRemarks('') }}
        />
      )}

      {/* Activation & access panel: last email, login stats, full timeline */}
      <div className="card-3d" style={{ padding: '14px 16px', marginBottom: 14 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <Mail size={14} style={{ color: 'var(--text-muted)' }} />
          {notice && <span style={{ fontSize: 12.5, fontWeight: 700, color: notice.ok ? '#10b981' : '#ef4444' }}>{notice.text}</span>}
          {vendor.last_notification ? (
            <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>
              Last activation email:&nbsp;
              <strong style={{ color: NOTIF_COLORS[vendor.last_notification.status] || 'var(--text-h)' }}>{vendor.last_notification.status}</strong>
              {vendor.last_notification.sent_at && <> · {new Date(vendor.last_notification.sent_at).toLocaleString()}</>}
              {vendor.last_notification.recipient && <> · {vendor.last_notification.recipient}</>}
            </span>
          ) : <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>No activation email sent yet.</span>}

          <span style={{ marginLeft: 'auto', fontSize: 12.5, color: 'var(--text-muted)' }}>
            Last login: <strong style={{ color: 'var(--text-h)' }}>{vendor.login_stats?.last_login_at ? new Date(vendor.login_stats.last_login_at).toLocaleString() : 'never'}</strong>
            {' '}· Logins: <strong style={{ color: 'var(--text-h)' }}>{vendor.login_stats?.login_count ?? 0}</strong>
          </span>
          {vendor.notification_timeline?.length > 0 && (
            <button onClick={() => setShowTimeline(t => !t)} style={{ ...actBtn, padding: '5px 10px', fontSize: 12 }}>
              {showTimeline ? 'Hide' : 'Timeline'} ({vendor.notification_timeline.length})
            </button>
          )}
        </div>

        {showTimeline && (
          <div style={{ marginTop: 12, borderTop: '1px solid var(--border)', paddingTop: 10 }}>
            {vendor.notification_timeline.map(n => (
              <div key={n.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '6px 0', fontSize: 12.5, borderBottom: '1px solid var(--border)' }}>
                <span style={{ width: 8, height: 8, borderRadius: 999, background: NOTIF_COLORS[n.status] || '#94a3b8', flexShrink: 0 }} />
                <span style={{ color: NOTIF_COLORS[n.status] || 'var(--text-h)', fontWeight: 800, minWidth: 62 }}>{n.status}</span>
                <span style={{ color: 'var(--text-h)' }}>{n.type.replace(/_/g, ' ')}</span>
                <span style={{ color: 'var(--text-muted)' }}>· {n.channel} · {n.recipient}</span>
                <span style={{ marginLeft: 'auto', color: 'var(--text-muted)' }}>{new Date(n.sent_at || n.created_at).toLocaleString()}</span>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Two-pane: sidebar + content */}
      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
        <nav className="card-3d" style={{ width: 236, flexShrink: 0, position: 'sticky', top: 16, padding: 10, maxHeight: 'calc(100vh - 130px)', overflowY: 'auto' }}>
          {VENDOR_NAV_GROUPS.map((group) => {
            const isCollapsed = collapsed[group.title]
            return (
              <div key={group.title} style={{ marginBottom: 6 }}>
                <button onClick={() => toggle(group.title)} style={groupBtn}>
                  {isCollapsed ? <ChevronRight size={13} /> : <ChevronDown size={13} />}
                  {group.title}
                </button>
                {!isCollapsed && group.items.map((it) => (
                  <NavLink key={it.key} to={`/app/purchase/vendors/${id}/${it.key}`} style={({ isActive }) => ({ ...navItem, ...(isActive ? navItemActive : {}) })}>
                    <it.icon size={15} />
                    <span>{it.label}</span>
                  </NavLink>
                ))}
              </div>
            )
          })}
        </nav>

        <div style={{ flex: 1, minWidth: 0 }}>
          <VendorWorkspaceContext.Provider value={{ vendor, onboarding, reload: load }}>
            <Routes>
              <Route index element={<Navigate to={`/app/purchase/vendors/${id}/overview`} replace />} />
              {VENDOR_NAV_ITEMS.map((it) => (
                <Route key={it.key} path={it.key} element={TAB_ELEMENTS[it.key] || <ComingSoonTab label={it.label} />} />
              ))}
              <Route path="*" element={<Navigate to={`/app/purchase/vendors/${id}/overview`} replace />} />
            </Routes>
          </VendorWorkspaceContext.Provider>
        </div>
      </div>

      {/* Decision modal — shared by approve / hold / reject / send-back. */}
      {decision && (
        <Overlay onClose={() => !decisionBusy && setDecision(null)} width={480} showClose={false}>
          <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
              {decision === 'approve' ? <CheckCircle size={18} style={{ color: '#10b981' }} /> : decision === 'hold' ? <PauseCircle size={18} style={{ color: '#b45309' }} /> : <XCircle size={18} style={{ color: '#ef4444' }} />}
              {decision === 'approve' ? 'Approve & Activate Vendor' : decision === 'hold' ? 'Put Vendor On Hold' : decision === 'reject' ? 'Reject Vendor Onboarding' : 'Send Back for Revision'}
            </h3>
            <button onClick={() => setDecision(null)} style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 18, color: 'var(--text-muted)' }}>✕</button>
          </div>
          <div style={{ padding: 22 }}>
            <p style={{ marginTop: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>
              {decision === 'approve'
                ? 'Approving generates the Registration Number, activates the vendor for procurement, provisions the portal login and sends the activation email.'
                : 'Please specify the mandatory rationale for this action.'}
            </p>
            <div style={{ marginBottom: 14 }}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-h)', marginBottom: 6 }}>
                {decision === 'approve' ? 'Remarks (Optional)' : 'Remarks / Reason *'}
              </label>
              <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3}
                placeholder={decision === 'approve' ? 'e.g. Compliance and documents verified…' : 'Enter mandatory remarks…'}
                style={{ width: '100%', padding: 10, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, outline: 'none', resize: 'vertical' }} />
            </div>
            <ModalFooter onClose={() => setDecision(null)} onConfirm={runDecision} loading={decisionBusy}
              disabled={['reject', 'hold', 'resubmit'].includes(decision) && !remarks.trim()}
              confirmLabel={decision === 'approve' ? 'Approve & Activate' : decision === 'hold' ? 'Confirm Hold' : decision === 'reject' ? 'Confirm Rejection' : 'Send Back'}
              color={decision === 'approve' ? '#10b981' : decision === 'hold' ? '#f59e0b' : '#ef4444'} />
          </div>
        </Overlay>
      )}
    </div>
  )
}

/**
 * Prominent Onboarding Decision panel — the Purchase-side mirror of the TPV
 * OnboardingDecisionPanel. Shows Step X/6 + onboarding + account status, and the
 * Approve / Hold / Reject / Send-Back actions.
 *
 * Approve is gated: it enables only when the vendor has completed Steps 1–5
 * (onboarding Submitted / Under Review = "Waiting for Admin Approval") AND all
 * required documents are uploaded, approved, and none are still rejected. When
 * blocked it stays visible but disabled, listing exactly what is missing.
 */
function OnboardingDecisionPanel({ vendor, onboarding, onDecision }) {
  const [docs, setDocs] = useState(null)
  const status = onboarding?.status || 'Draft'
  const step = onboarding?.current_step || 1
  const oc = obCfg(status)
  const approved = status === 'Approved'
  const accountActive = vendor.status === 'Active'

  useEffect(() => {
    let alive = true
    purchaseApi.documents.checklist(vendor.id).then(d => { if (alive) setDocs(d) }).catch(() => {})
    return () => { alive = false }
  }, [vendor.id])

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
    <div style={{ marginBottom: 14, borderRadius: 14, overflow: 'hidden', background: 'var(--bg-card)', border: `1px solid color-mix(in srgb, ${tint} 40%, var(--border))` }}>
      <div style={{ padding: '13px 16px', display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', borderBottom: '1px solid var(--border)', background: `color-mix(in srgb, ${tint} 6%, transparent)` }}>
        <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', display: 'inline-flex', alignItems: 'center', gap: 7 }}>
          <ShieldCheck size={16} style={{ color: tint }} /> Onboarding Decision
        </span>
        <span style={{ flex: 1 }} />
        <StatusPill label="Step" value={`${step} of 6`} tone="#7C3AED" />
        <StatusPill label="Onboarding" value={oc.label} tone={oc.color} />
        <StatusPill label="Account" value={accountActive ? 'Active' : (vendor.status_label || vendor.status)} tone={accountActive ? '#0ca30c' : '#8a94a6'} />
      </div>
      <div style={{ padding: '16px' }}>
        <p style={{ margin: '0 0 12px', fontSize: 13, color: 'var(--text-muted)' }}>
          <strong style={{ color: 'var(--text-h)' }}>{vendor.company_name || vendor.purchase_vendor_code}</strong>
          {' — '}{approved
            ? 'onboarding approved and the account is activated (Step 6).'
            : decidable ? 'has completed all steps and is waiting for your decision.'
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
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 11px', borderRadius: 999, whiteSpace: 'nowrap', background: `color-mix(in srgb, ${tone} 11%, transparent)`, border: `1px solid color-mix(in srgb, ${tone} 26%, transparent)` }}>
      <span style={{ width: 6, height: 6, borderRadius: 999, background: tone, flexShrink: 0 }} />
      <span style={{ fontSize: 9.5, fontWeight: 700, letterSpacing: '0.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{label}</span>
      <span style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--text-h)' }}>{value}</span>
    </span>
  )
}

const ghostBtn = {
  padding: '8px 12px', borderRadius: 9, background: 'transparent',
  border: '1px solid var(--border)', color: 'var(--text-muted)',
  fontSize: 12, fontWeight: 700, cursor: 'pointer',
  display: 'inline-flex', alignItems: 'center', gap: 5,
}

const backBtn = { width: 38, height: 38, borderRadius: 10, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer' }
const actBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const pill = { fontSize: 11, fontWeight: 700, padding: '2px 9px', borderRadius: 999, border: '1px solid var(--border)', background: 'var(--bg-input)' }
const groupBtn = { display: 'flex', alignItems: 'center', gap: 6, width: '100%', padding: '8px 8px', background: 'none', border: 'none', color: 'var(--text-h)', fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '.04em', cursor: 'pointer' }
const navItem = { display: 'flex', alignItems: 'center', gap: 9, padding: '7px 10px 7px 14px', fontSize: 12.5, borderRadius: 8, marginBottom: 1, color: 'var(--text-muted)', fontWeight: 500, textDecoration: 'none', borderLeft: '2px solid transparent' }
const navItemActive = { color: '#a78bfa', background: 'rgba(124,58,237,0.12)', fontWeight: 700, borderLeft: '2px solid #7C3AED' }
