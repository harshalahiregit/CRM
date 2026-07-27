import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ClipboardList, UserCheck, FileText, ShieldCheck, Check, Rocket,
  ArrowLeft, ArrowRight, Loader2, AlertTriangle, CheckCircle2, Eye, Download,
} from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import PurchaseVendorDocuments from '@/modules/purchase/components/PurchaseVendorDocuments'
import { KIT3D_STYLE, Field, TextInput } from '@/components/ui/kit3d'

/**
 * Purchase Vendor Portal — onboarding wizard. Purchase-owned; consumes ONLY
 * purchasePortalApi (/portal/purchase/*). The authenticated PurchaseVendor is
 * resolved server-side from the token — there is no vendor id in any URL. No TPV
 * or shared-vendor imports; the documents step embeds the Purchase-owned
 * PurchaseVendorDocuments component.
 */
const STEP_ICONS = { kickoff: ClipboardList, profile: UserCheck, documents: FileText, review: ShieldCheck, confirmation: Check, submission: Rocket }

const EMPTY_PROFILE = {
  company_name: '', legal_name: '', gst_number: '', pan_number: '', website: '',
  contact_person: '', contact_email: '', contact_mobile: '',
  address: '', city: '', state: '', pincode: '',
  bank_account_holder: '', bank_name: '', bank_account_number: '', bank_ifsc: '',
  scope_of_work: '',
}

export default function PurchasePortalOnboarding() {
  const navigate = useNavigate()
  const [onboarding, setOnboarding] = useState(null)
  const [progress, setProgress] = useState(null)
  const [loading, setLoading] = useState(true)
  const [active, setActive] = useState(1)
  const [err, setErr] = useState(null)

  const load = useCallback(async (keepStep = false) => {
    try {
      const d = await purchasePortalApi.onboarding.self()
      const ob = d?.onboarding ?? null
      setOnboarding(ob)
      setProgress(d?.progress ?? null)
      if (!keepStep && ob?.current_step) setActive(ob.current_step)
    } catch { setErr('Could not load your onboarding.') }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  const steps = progress?.steps || []
  const done = steps.filter(s => s.complete).length
  const pct = steps.length ? Math.round((done / steps.length) * 100) : 0
  const editable = onboarding && ['In_Progress', 'Rejected', 'Resubmit_Required'].includes(onboarding.status)

  const goStep = (step) => {
    setActive(step)
    if (editable && onboarding) purchasePortalApi.onboarding.setStep(onboarding.id, step).catch(() => {})
  }

  if (loading) return <div style={{ padding: 24 }}><style>{KIT3D_STYLE}</style><div className="skeleton" style={{ height: 44, width: 260, borderRadius: 12, background: 'var(--border)' }} /></div>
  if (!onboarding) return <div style={{ padding: 24 }}><style>{KIT3D_STYLE}</style><p style={{ color: 'var(--text-muted)' }}>{err || 'No onboarding record found.'}</p></div>

  const activeStep = steps.find(s => s.step === active) || steps[0]

  return (
    <div style={{ padding: 24 }}>
      <style>{KIT3D_STYLE}</style>
      <style>{`@keyframes ppSpin{to{transform:rotate(360deg)}}.pp-spin{animation:ppSpin .9s linear infinite}`}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 style={{ color: 'var(--text-h)', fontSize: 21, fontWeight: 800, margin: 0 }}>Vendor Onboarding</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Complete each step to activate your purchase-vendor account. {pct}% complete.</p>
        </div>
        <div className="pr-bar" style={{ minWidth: 200, maxWidth: 260 }}><span style={{ width: `${pct}%` }} /></div>
      </div>

      {onboarding.status === 'Resubmit_Required' && onboarding.remarks && (
        <Banner tone="#f59e0b" icon={AlertTriangle}><strong>Sent back for revision:</strong> {onboarding.remarks}</Banner>
      )}
      {onboarding.status === 'Rejected' && onboarding.remarks && (
        <Banner tone="#ef4444" icon={AlertTriangle}><strong>Rejected:</strong> {onboarding.remarks}</Banner>
      )}
      {err && <Banner tone="#ef4444" icon={AlertTriangle}>{err}</Banner>}

      {/* Step tracker */}
      <div className="pr-glass" style={{ padding: 14, marginBottom: 16, overflowX: 'auto' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 0, minWidth: 'max-content' }}>
          {steps.map((s, i) => {
            const Icon = STEP_ICONS[s.key] || FileText
            const on = s.step === active
            const lit = s.complete || on
            return (
              <div key={s.key} style={{ display: 'flex', alignItems: 'center' }}>
                <button onClick={() => goStep(s.step)} title={s.detail}
                  style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '9px 13px', borderRadius: 13, cursor: 'pointer', minWidth: 150,
                    background: lit ? 'linear-gradient(135deg, rgba(124,58,237,.2), rgba(124,58,237,.06))' : 'var(--bg-input)',
                    border: `1.5px solid ${on ? '#7C3AED' : s.complete ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`, opacity: lit ? 1 : 0.65 }}>
                  <span style={{ position: 'relative', width: 32, height: 32, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', color: '#fff', flexShrink: 0 }}>
                    <Icon size={15} />
                    {s.complete && <span style={{ position: 'absolute', right: -4, bottom: -4, width: 15, height: 15, borderRadius: '50%', background: '#10b981', border: '2px solid var(--bg-card)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Check size={8} color="#fff" strokeWidth={4} /></span>}
                  </span>
                  <span style={{ textAlign: 'left', lineHeight: 1.2 }}>
                    <span style={{ display: 'block', fontSize: 8.5, fontWeight: 800, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>Step {s.step}</span>
                    <span style={{ display: 'block', fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>{s.label}</span>
                  </span>
                </button>
                {i < steps.length - 1 && <div style={{ width: 18, height: 3, borderRadius: 4, margin: '0 4px', flexShrink: 0, background: s.complete ? '#7C3AED' : 'var(--border)' }} />}
              </div>
            )
          })}
        </div>
      </div>

      {/* Step body */}
      <div className="pr-glass" style={{ padding: 22 }}>
        {activeStep?.key === 'kickoff' && <StepKickoff onboarding={onboarding} editable={editable} onDone={() => load(true)} onContinue={() => goStep(2)} />}
        {activeStep?.key === 'profile' && <StepProfile onboarding={onboarding} editable={editable} onSaved={() => load(true)} onContinue={() => goStep(3)} />}
        {activeStep?.key === 'documents' && (
          <div>
            <StepHead title="Statutory Documents" sub="Upload the required documents for review." />
            <PurchaseVendorDocuments api={purchasePortalApi.documents} manage admin={false} onChanged={() => load(true)} />
            <StepNav onBack={() => goStep(2)} onContinue={() => goStep(4)} />
          </div>
        )}
        {activeStep?.key === 'review' && <StepInfo title="Under Review" icon={ShieldCheck}
          text="Your documents are being reviewed by our procurement team. You'll be notified once each document is approved." onBack={() => goStep(3)} onContinue={() => goStep(5)} />}
        {activeStep?.key === 'confirmation' && <StepInfo title="Confirmation" icon={Check}
          text="Review your details. Once everything is complete, submit your onboarding for final admin approval." onBack={() => goStep(4)} onContinue={() => goStep(6)} />}
        {activeStep?.key === 'submission' && <StepSubmission onboarding={onboarding} editable={editable} onSubmitted={() => load(true)} onBack={() => goStep(5)} navigate={navigate} />}
      </div>
    </div>
  )
}

/* ── Step 1 — Kickoff acknowledgement ───────────────────────────────────────── */
function StepKickoff({ onboarding, editable, onDone, onContinue }) {
  const [pdfUrl, setPdfUrl] = useState(null)
  const [pdfErr, setPdfErr] = useState(null)
  const [checked, setChecked] = useState(!!onboarding.acknowledged)
  const [busy, setBusy] = useState(false)
  const acknowledged = !!onboarding.acknowledged

  useEffect(() => {
    let url
    purchasePortalApi.onboarding.kickoffPdf(onboarding.id)
      .then(blob => { url = URL.createObjectURL(blob); setPdfUrl(url); setPdfErr(null); purchasePortalApi.onboarding.logKickoffEvent(onboarding.id, 'viewed').catch(() => {}) })
      .catch((e) => setPdfErr(e?.response?.data?.message || 'Kickoff meeting is not completed yet.'))
    return () => { if (url) URL.revokeObjectURL(url) }
  }, [onboarding.id])

  const accept = async () => {
    if (!acknowledged) {
      if (!checked) return
      setBusy(true)
      try { await purchasePortalApi.onboarding.acceptKickoff(onboarding.id); onDone?.() }
      catch (e) { setPdfErr(e?.response?.data?.message || 'Could not record acknowledgement.'); setBusy(false); return }
      setBusy(false)
    }
    onContinue?.()
  }

  const download = async () => {
    try {
      const blob = await purchasePortalApi.onboarding.kickoffPdf(onboarding.id)
      const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `Kickoff-MOM-${onboarding.id}.pdf`
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 30000)
      purchasePortalApi.onboarding.logKickoffEvent(onboarding.id, 'downloaded').catch(() => {})
    } catch { /* non-fatal */ }
  }

  return (
    <div>
      <StepHead title="Kickoff MOM Review & Acknowledgement" sub="Step 1 · Review the Minutes of Meeting" />
      {pdfErr && !pdfUrl ? (
        <div style={{ padding: '24px 20px', borderRadius: 14, textAlign: 'center', background: 'rgba(239,68,68,0.06)', border: '1.5px dashed rgba(239,68,68,0.3)' }}>
          <AlertTriangle size={30} style={{ color: '#ef4444' }} />
          <h3 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: '8px 0 4px' }}>{pdfErr}</h3>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>The kickoff meeting must be completed and its MOM published before you can acknowledge.</p>
        </div>
      ) : (
        <>
          <div style={{ display: 'flex', gap: 8, marginBottom: 10, justifyContent: 'flex-end' }}>
            <button onClick={() => pdfUrl && window.open(pdfUrl, '_blank', 'noopener')} style={tbBtn}><Eye size={13} /> View</button>
            <button onClick={download} style={tbBtn}><Download size={13} /> Download</button>
          </div>
          <div style={{ height: 460, overflow: 'auto', border: '1px solid var(--border)', borderRadius: 12, background: '#525659' }}>
            {pdfUrl ? <iframe title="Kickoff MOM" src={pdfUrl} style={{ width: '100%', height: 460, border: 'none' }} />
              : <div style={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#cbd5e1' }}><Loader2 size={20} className="pp-spin" /></div>}
          </div>
          <div style={{ marginTop: 16, padding: '14px 16px', borderRadius: 13, background: acknowledged ? 'rgba(16,185,129,0.06)' : 'var(--bg-input)', border: `1px solid ${acknowledged ? 'rgba(16,185,129,0.3)' : 'var(--border)'}` }}>
            {acknowledged ? (
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7, fontSize: 13, fontWeight: 800, color: '#10b981' }}><CheckCircle2 size={15} /> MOM Acknowledged</span>
                <button onClick={onContinue} style={solidBtn}>Continue <ArrowRight size={15} /></button>
              </div>
            ) : (
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: 9, cursor: editable ? 'pointer' : 'not-allowed', flex: 1, minWidth: 240 }}>
                  <input type="checkbox" checked={checked} disabled={!editable || !pdfUrl} onChange={e => setChecked(e.target.checked)} style={{ width: 17, height: 17, accentColor: '#7C3AED' }} />
                  <span style={{ fontSize: 13, color: 'var(--text-h)', fontWeight: 600 }}>I have read and understood the Minutes of Meeting.</span>
                </label>
                <button onClick={accept} disabled={!checked || busy || !editable} style={{ ...solidBtn, opacity: (!checked || !editable) ? 0.6 : 1 }}>
                  {busy ? <Loader2 size={14} className="pp-spin" /> : <Check size={15} />} Acknowledge &amp; Continue
                </button>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  )
}

/* ── Step 2 — Company profile ────────────────────────────────────────────────── */
function StepProfile({ onboarding, editable, onSaved, onContinue }) {
  const [f, setF] = useState(() => ({ ...EMPTY_PROFILE, ...(onboarding.profile || {}) }))
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }

  const save = async (thenContinue) => {
    setSaving(true); setErr(null)
    try {
      const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v != null))
      await purchasePortalApi.onboarding.saveProfile(onboarding.id, payload)
      setSaved(true); onSaved?.()
      if (thenContinue) onContinue?.()
    } catch (e) {
      setErr(e?.response?.data?.message || Object.values(e?.response?.data?.errors || {})[0]?.[0] || 'Could not save profile.')
    } finally { setSaving(false) }
  }

  const F = (label, key, props = {}) => (
    <Field label={label}><TextInput value={f[key] ?? ''} onChange={set(key)} disabled={!editable} {...props} /></Field>
  )

  return (
    <div>
      <StepHead title="Company Profile" sub="Company, contact, address, GST/PAN and bank details." />
      {!editable && <Banner tone="#0ea5e9" icon={ShieldCheck}>This onboarding is no longer editable — your profile is shown read-only.</Banner>}
      <ProfileSection title="Company">
        {F('Company Name', 'company_name')}
        {F('Legal Name', 'legal_name')}
        {F('GST Number', 'gst_number', { maxLength: 15 })}
        {F('PAN Number', 'pan_number', { maxLength: 10 })}
        {F('Website', 'website', { placeholder: 'https://' })}
      </ProfileSection>
      <ProfileSection title="Primary Contact">
        {F('Contact Person', 'contact_person')}
        {F('Email', 'contact_email', { type: 'email' })}
        {F('Mobile', 'contact_mobile')}
      </ProfileSection>
      <ProfileSection title="Registered Address">
        {F('Address', 'address')}
        {F('City', 'city')}
        {F('State', 'state')}
        {F('Pincode', 'pincode', { maxLength: 6 })}
      </ProfileSection>
      <ProfileSection title="Bank Details">
        {F('Account Holder', 'bank_account_holder')}
        {F('Bank Name', 'bank_name')}
        {F('Account Number', 'bank_account_number')}
        {F('IFSC', 'bank_ifsc')}
      </ProfileSection>
      {err && <Banner tone="#ef4444" icon={AlertTriangle}>{err}</Banner>}
      {editable && (
        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 18, gap: 10, flexWrap: 'wrap' }}>
          <button onClick={() => save(false)} disabled={saving} style={ghostBtn}>{saving ? <Loader2 size={14} className="pp-spin" /> : saved ? <Check size={14} /> : null} {saved ? 'Saved' : 'Save Draft'}</button>
          <button onClick={() => save(true)} disabled={saving} style={solidBtn}>Save &amp; Continue <ArrowRight size={15} /></button>
        </div>
      )}
      {!editable && <StepNav onContinue={onContinue} />}
    </div>
  )
}

/* ── Step 6 — Submission ─────────────────────────────────────────────────────── */
function StepSubmission({ onboarding, editable, onSubmitted, onBack }) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const submitted = ['Submitted', 'Under_Review', 'Approved'].includes(onboarding.status)

  const submit = async () => {
    setBusy(true); setErr(null)
    try { await purchasePortalApi.onboarding.submit(onboarding.id, { declaration: true }); onSubmitted?.() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not submit.'); setBusy(false) }
  }

  if (onboarding.status === 'Approved') {
    return (
      <div style={{ textAlign: 'center', padding: '28px 16px' }}>
        <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 14px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(16,185,129,0.14)' }}><CheckCircle2 size={30} style={{ color: '#10b981' }} /></div>
        <h3 style={{ fontSize: 17, fontWeight: 900, color: 'var(--text-h)', margin: 0 }}>Onboarding Approved</h3>
        <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: '6px 0 0' }}>Your purchase-vendor account is active. Registration No. <strong style={{ color: 'var(--text-h)' }}>{onboarding.registration_number || '—'}</strong></p>
      </div>
    )
  }

  return (
    <div>
      <StepHead title="Submit for Approval" sub="Final step — submit your completed onboarding for admin approval." />
      {submitted ? (
        <div style={{ padding: '20px', borderRadius: 14, background: 'rgba(139,92,246,0.08)', border: '1px solid rgba(139,92,246,0.32)', textAlign: 'center' }}>
          <ShieldCheck size={26} style={{ color: '#8b5cf6' }} />
          <div style={{ fontSize: 14, fontWeight: 800, color: '#8b5cf6', marginTop: 6 }}>Submitted — under review</div>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '6px 0 0' }}>Your onboarding is with the procurement team for final approval.</p>
        </div>
      ) : (
        <>
          <p style={{ fontSize: 13, color: 'var(--text-h)', lineHeight: 1.55 }}>By submitting, you confirm that all information and documents provided are accurate and complete.</p>
          {err && <Banner tone="#ef4444" icon={AlertTriangle}>{err}</Banner>}
          <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 16, gap: 10 }}>
            <button onClick={onBack} style={ghostBtn}><ArrowLeft size={15} /> Back</button>
            <button onClick={submit} disabled={busy || !editable} style={{ ...solidBtn, opacity: editable ? 1 : 0.6 }}>{busy ? <Loader2 size={14} className="pp-spin" /> : <Rocket size={15} />} Submit onboarding</button>
          </div>
        </>
      )}
    </div>
  )
}

/* ── shared bits ─────────────────────────────────────────────────────────────── */
const StepHead = ({ title, sub }) => (
  <div style={{ marginBottom: 16 }}>
    <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
    {sub && <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{sub}</p>}
  </div>
)

const ProfileSection = ({ title, children }) => (
  <div style={{ marginBottom: 16 }}>
    <div style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#a78bfa', margin: '4px 0 10px' }}>{title}</div>
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 12 }}>{children}</div>
  </div>
)

function StepInfo({ title, icon: Icon, text, onBack, onContinue }) {
  return (
    <div>
      <StepHead title={title} />
      <div style={{ display: 'flex', gap: 12, padding: '16px', borderRadius: 13, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <Icon size={20} style={{ color: '#a78bfa', flexShrink: 0 }} />
        <p style={{ fontSize: 13, color: 'var(--text-h)', margin: 0, lineHeight: 1.55 }}>{text}</p>
      </div>
      <StepNav onBack={onBack} onContinue={onContinue} />
    </div>
  )
}

function StepNav({ onBack, onContinue }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 18, gap: 10 }}>
      {onBack ? <button onClick={onBack} style={ghostBtn}><ArrowLeft size={15} /> Back</button> : <span />}
      {onContinue && <button onClick={onContinue} style={solidBtn}>Continue <ArrowRight size={15} /></button>}
    </div>
  )
}

const Banner = ({ tone, icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 14, background: `${tone}12`, border: `1px solid ${tone}55` }}>
    <Icon size={15} style={{ color: tone, flexShrink: 0 }} />
    <span style={{ fontSize: 13, color: 'var(--text-h)' }}>{children}</span>
  </div>
)

const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 18px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }
const tbBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, cursor: 'pointer', fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-input)', border: '1px solid var(--border)' }
