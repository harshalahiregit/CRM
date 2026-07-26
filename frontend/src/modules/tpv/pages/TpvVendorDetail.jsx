import { useState, useEffect, useCallback, useMemo } from 'react'
import { useParams, useNavigate, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft, Building2, User, Phone, Loader2, ShieldCheck, CheckCircle, XCircle, PauseCircle, CornerUpLeft, Clock, AlertTriangle,
  Briefcase, IndianRupee, ClipboardCheck, BarChart3, ChevronDown, ChevronRight,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import { canManageTpv, obStatusCfg } from '@/modules/tpv/constants'
import { KIT3D_STYLE, Overlay, ModalFooter } from '@/components/ui/kit3d'
import ComingSoonSection from '@/modules/tpv/components/ComingSoonSection'
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
  const manage = canManageTpv(user)
  const [params, setParams] = useSearchParams()
  const [v, setV] = useState(null)
  const [loading, setLoad] = useState(true)
  const [collapsed, setCollapsed] = useState({}) // group -> true when collapsed

  // Admin decision state
  const [decisionModal, setDecisionModal] = useState(null) // 'approve' | 'reject' | 'hold' | 'resubmit'
  const [remarks, setRemarks] = useState('')
  const [decisionBusy, setDecisionBusy] = useState(false)

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

  const load = useCallback(() => {
    setLoad(true)
    tpvApi.vendors.get(id).then(r => { setV(r?.data ?? r); setLoad(false) }).catch(() => setLoad(false))
  }, [id])
  useEffect(() => { load() }, [load])

  if (loading) return <div style={wrap}><style>{KIT3D_STYLE}</style><Loader2 size={22} className="rfq-spin" style={{ color: '#a78bfa' }} /></div>
  if (!v) return <div style={wrap}><style>{KIT3D_STYLE}</style><p style={{ color: 'var(--text-muted)' }}>Vendor not found.</p></div>

  const isActive = v.status === 'Active'
  const activeOnboarding = v.tpv_onboarding || v.tpvOnboarding || (v.onboardings && v.onboardings[0]) || null
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
        const r = await tpvApi.onboarding.create({ vendor_id: v.id })
        ob = r?.data ?? r
      }

      if (decisionModal === 'approve') {
        await tpvApi.onboarding.approve(ob.id, remarks)
      } else if (decisionModal === 'reject') {
        await tpvApi.onboarding.reject(ob.id, remarks)
      } else if (decisionModal === 'hold') {
        await tpvApi.onboarding.hold(ob.id, remarks)
      } else if (decisionModal === 'resubmit') {
        await tpvApi.onboarding.requestResubmit(ob.id, remarks)
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

      {/* Header Banner & Admin Action Bar */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 18, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, flex: 1, minWidth: 280 }}>
          <button onClick={() => navigate('/app/tpv/dashboard')} style={backBtn}><ArrowLeft size={16} /></button>
          <div style={{ width: 54, height: 54, borderRadius: 16, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', border: '1px solid rgba(124,58,237,0.3)' }}>
            <Building2 size={24} style={{ color: '#a78bfa' }} />
          </div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800 }}>{v.vendor_code || `TPV-${v.id}`}</p>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginTop: 2 }}>
              <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: 0, letterSpacing: '-0.02em' }}>{v.company_name}</h1>
              
              {/* Badge 1: Vendor Account Status (Controls Portal Login) */}
              <div style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 12px', borderRadius: 999, background: isActive ? 'rgba(16,185,129,0.14)' : 'rgba(239,68,68,0.12)', color: isActive ? '#10b981' : '#ef4444', fontSize: 11.5, fontWeight: 800, border: `1px solid ${isActive ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)'}` }}>
                <span style={{ fontSize: 10, opacity: 0.7, textTransform: 'uppercase' }}>Vendor Account:</span>
                <span>{v.status || 'Inactive'}</span>
              </div>

              {/* Badge 2: Onboarding Status (Independent TPV Workflow) */}
              <div style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 12px', borderRadius: 999, background: obCfg.bg, color: obCfg.color, fontSize: 11.5, fontWeight: 800, border: `1px solid ${obCfg.color}40` }}>
                <span style={{ fontSize: 10, opacity: 0.7, textTransform: 'uppercase' }}>Onboarding:</span>
                <span>{obCfg.label || obStatus}</span>
              </div>
            </div>
            <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>{v.user?.name ? `Login: ${v.user.name} · ` : ''}{v.email || 'No email'}</p>
          </div>
        </div>

        {/* Admin Onboarding Decision Toolbar (Modifies ONLY Onboarding Status) */}
        {manage && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', background: 'var(--bg-card)', padding: '10px 14px', borderRadius: 12, border: '1px solid var(--border)' }}>
            <span style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--text-muted)', marginRight: 4 }}>Onboarding Decision:</span>
            
            <button
              onClick={() => { setDecisionModal('approve'); setRemarks('') }}
              style={{ padding: '7px 14px', borderRadius: 8, background: '#10b981', color: '#fff', border: 'none', fontSize: 12, fontWeight: 800, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, boxShadow: '0 2px 8px rgba(16,185,129,0.3)' }}
            >
              <ShieldCheck size={14} /> Approve Onboarding
            </button>

            <button
              onClick={() => { setDecisionModal('hold'); setRemarks('') }}
              style={{ padding: '7px 12px', borderRadius: 8, background: '#fffbeb', border: '1px solid #fde68a', color: '#b45309', fontSize: 12, fontWeight: 700, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
            >
              <PauseCircle size={14} /> Put On Hold
            </button>

            <button
              onClick={() => { setDecisionModal('reject'); setRemarks('') }}
              style={{ padding: '7px 12px', borderRadius: 8, background: '#ef4444', color: '#fff', border: 'none', fontSize: 12, fontWeight: 800, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
            >
              <XCircle size={14} /> Reject
            </button>

            <button
              onClick={() => { setDecisionModal('resubmit'); setRemarks('') }}
              style={{ padding: '7px 12px', borderRadius: 8, background: 'var(--bg-input)', color: 'var(--text-h)', border: '1px solid var(--border)', fontSize: 12, fontWeight: 700, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
            >
              <CornerUpLeft size={14} /> Send Back
            </button>
          </div>
        )}
      </div>

      {/* Two-pane: left section nav + right content */}
      <div style={{ display: 'flex', gap: 20, alignItems: 'flex-start' }}>
        <nav style={{ width: 232, flexShrink: 0, position: 'sticky', top: 16 }}>
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
          <SectionContent tab={active} v={v} isActive={isActive} manage={manage} />
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
function SectionContent({ tab, v, isActive, manage }) {
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
      return <TpvVendorContacts vendorId={v.id} vendor={v} manage={manage} />
    case 'Documents':
      return <TpvVendorDocuments vendorId={v.id} vendor={v} manage={manage} />
    default:
      return <ComingSoonSection name={tab} />
  }
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
