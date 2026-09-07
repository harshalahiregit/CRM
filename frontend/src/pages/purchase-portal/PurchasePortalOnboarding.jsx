import { useState, useEffect, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ClipboardList, UserCheck, FileText, ShieldCheck, Check, Rocket,
  ArrowLeft, ArrowRight, Loader2, AlertTriangle, CheckCircle2, Eye, Download,
} from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import VendorDocumentsPanel from '@/components/vendor/VendorDocumentsPanel'
import { PURCHASE_DOC_CATALOG } from '@/components/vendor/documentCatalog'
import WorkStartLetterCard from '@/components/portal/WorkStartLetterCard'
import KickoffMomReview from '@/components/portal/KickoffMomReview'
import { KIT3D_STYLE, Field, TextInput } from '@/components/ui/kit3d'
import { readFieldErrors } from '@/services/apiError'

/**
 * Purchase Vendor Portal — onboarding wizard. Purchase-owned; consumes ONLY
 * purchasePortalApi (/portal/purchase/*). The authenticated PurchaseVendor is
 * resolved server-side from the token — there is no vendor id in any URL. No TPV
 * or shared-vendor imports; the documents step embeds the Purchase-owned
 * shared VendorDocumentsPanel.
 */
const STEP_ICONS = { kickoff: ClipboardList, profile: UserCheck, documents: FileText, review: ShieldCheck, confirmation: Check, submission: Rocket }

/**
 * The keys here are the SERVER's field names, not display names.
 *
 * This form used to send `address`, which no rule on the server matched, so
 * Laravel's `validated()` dropped it: the vendor typed their registered address,
 * the save returned 200, and the address was gone — every time, silently. It is
 * `registered_address`, the name both engines and the TPV form already use.
 */
const EMPTY_PROFILE = {
  company_name: '', legal_name: '', gst_number: '', pan_number: '', website: '',
  contact_person: '', contact_email: '', contact_mobile: '',
  registered_address: '', city: '', state: '', pincode: '',
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

  /**
   * A step with a form registers how to persist it, so leaving the step keeps
   * what was typed.
   *
   * Moving through the wizard used to switch the panel and nothing else: a
   * vendor who filled in half the profile and pressed the next step lost every
   * word of it, with no warning and nothing to go back to. The server accepts a
   * partial profile — every field on it is nullable — so what has been entered
   * is stored as a draft on the way past, and the completeness check stays
   * where it belongs, on Save & Continue and on submission.
   */
  const flushRef = useRef(null)
  const registerFlush = useCallback((fn) => { flushRef.current = fn }, [])

  const goStep = async (step) => {
    // Never trap somebody on a step: a draft that will not save is a reason to
    // say so, not a reason to refuse to move.
    let flushed = false
    try { flushed = await flushRef.current?.() } catch { /* the step reports its own error */ }
    flushRef.current = null

    setActive(step)
    if (editable && onboarding) purchasePortalApi.onboarding.setStep(onboarding.id, step).catch(() => {})

    // Each step unmounts when you leave it and seeds itself from `onboarding` on
    // the way back. Without this refetch it seeds from the copy loaded when the
    // page opened — so a draft that WAS stored still came back as an empty form,
    // and saving that form then wrote the stale values over the good ones.
    if (flushed) load(true)
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
        {activeStep?.key === 'profile' && <StepProfile onboarding={onboarding} editable={editable} onSaved={() => load(true)} onContinue={() => goStep(3)} registerFlush={registerFlush} />}
        {activeStep?.key === 'documents' && (
          <div>
            <StepHead title="Statutory Documents" sub="Upload the required documents for review." />
            <VendorDocumentsPanel
              api={purchasePortalApi.documents}
              catalog={PURCHASE_DOC_CATALOG}
              onboarding={onboarding}
              editable={editable}
              manage
              admin={false}
              onChanged={() => load(true)}
            />
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

/**
 * The minutes, as readable information.
 *
 * This step used to render the MOM as a 460px PDF viewer embedded in the page —
 * a document in a box, unsearchable, unreadable on a phone, and nothing like the
 * rest of the portal. The minutes are structured data on the server, so they are
 * shown as data here (the Governance → Meetings tab already does exactly this),
 * and the PDF stays available as the two things a PDF is actually for: View and
 * Download.
 */
function StepKickoff({ onboarding, editable, onDone, onContinue }) {
  const [meeting, setMeeting] = useState(null)
  const [mom, setMom] = useState(null)
  const [err, setErr] = useState(null)
  const [checked, setChecked] = useState(!!onboarding.acknowledged)
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const acknowledged = !!onboarding.acknowledged

  useEffect(() => {
    let alive = true
    setLoading(true)

    purchasePortalApi.kickoff.get()
      .then(async (d) => {
        if (!alive) return
        const m = d?.meeting ?? null
        setMeeting(m)

        /*
         * The minutes come from the ONBOARDING, not from this card's meeting.
         *
         * The card is resolved by ownKickoff(); the PDF is resolved by
         * resolveKickoffMeeting(). Those are two resolvers and can land on two
         * meetings, which is how a populated document ends up beside empty
         * sections. `kickoffData` uses the PDF's resolver, so the text and the
         * document always describe the same meeting.
         *
         * Always settles — `{}` rather than null — so the panel can say "not
         * published yet" instead of spinning forever.
         */
        try {
          const detail = await purchasePortalApi.onboarding.kickoffData(onboarding.id)
          if (alive) setMom(detail?.meeting ? detail : {})
        } catch {
          if (alive) setMom({})   // not distributed yet is a normal answer
        }
        if (!m) setErr('No kickoff meeting has been scheduled for you yet.')
      })
      .catch(() => alive && setErr('Could not load the kickoff meeting.'))
      .finally(() => alive && setLoading(false))

    // Viewing the step is the event the audit trail cares about, whether or not
    // the PDF is ever opened.
    purchasePortalApi.onboarding.logKickoffEvent(onboarding.id, 'viewed').catch(() => {})

    return () => { alive = false }
  }, [onboarding.id])

  const openPdf = async () => {
    try {
      const blob = await purchasePortalApi.onboarding.kickoffPdf(onboarding.id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60000)
      purchasePortalApi.onboarding.logKickoffEvent(onboarding.id, 'viewed').catch(() => {})
    } catch (e) {
      setErr(e?.response?.data?.message || 'The MOM document is not available yet.')
    }
  }

  const download = async () => {
    try {
      const blob = await purchasePortalApi.onboarding.kickoffPdf(onboarding.id)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url; a.download = `Kickoff-MOM-${onboarding.id}.pdf`
      document.body.appendChild(a); a.click(); a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 30000)
      purchasePortalApi.onboarding.logKickoffEvent(onboarding.id, 'downloaded').catch(() => {})
    } catch (e) {
      setErr(e?.response?.data?.message || 'The MOM document is not available yet.')
    }
  }

  const accept = async () => {
    if (!acknowledged) {
      if (!checked) return
      setBusy(true)
      try { await purchasePortalApi.onboarding.acceptKickoff(onboarding.id); onDone?.() }
      catch (e) { setErr(e?.response?.data?.message || 'Could not record acknowledgement.'); setBusy(false); return }
      setBusy(false)
    }
    onContinue?.()
  }

  // One agreed shape from both portals (VendorMomView on the server). This
  // screen used to read this engine's own relation names; when the payload was
  // normalised those keys stopped existing and every section here would have
  // read as empty.

  /*
   * You cannot accept minutes you were never shown.
   *
   * This was `!!meeting` — a meeting merely EXISTING was enough to tick "I have
   * read and understood the Minutes of Meeting" and pass Step 1. So an
   * onboarding could be acknowledged against a meeting whose minutes were still
   * a draft and whose document would not open, and the record then claimed the
   * vendor had read something never issued to them.
   *
   * The server refuses this too — a disabled checkbox is a courtesy, not a rule.
   */
  const canAcknowledge = !!meeting && !!meeting.mom_available

  return (
    <div>
      <StepHead title="Kickoff MOM Review & Acknowledgement" sub="Step 1 · Review the Minutes of Meeting" />

      {loading ? (
        <div style={{ padding: 24, color: 'var(--text-muted)', fontSize: 13 }}>
          <Loader2 size={16} className="pp-spin" /> Loading the meeting…
        </div>
      ) : !meeting ? (
        <div style={{ padding: '24px 20px', borderRadius: 14, textAlign: 'center', background: 'rgba(239,68,68,0.06)', border: '1.5px dashed rgba(239,68,68,0.3)' }}>
          <AlertTriangle size={30} style={{ color: '#ef4444' }} />
          <h3 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: '8px 0 4px' }}>{err || 'No kickoff meeting yet'}</h3>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>The procurement team will schedule one and you will be notified.</p>
        </div>
      ) : (
        <>
          {/* The meeting itself */}
          <div style={koCard}>
            <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{meeting.title || 'Kickoff Meeting'}</h3>
                <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{meeting.status_label || meeting.status}</span>
              </div>
              {/* Offered only when there is something to open. Showing them
                  regardless meant every press answered "not available yet",
                  which reads as a broken button rather than an unissued
                  document. */}
              {meeting.mom_available && (
                <div style={{ display: 'flex', gap: 8 }}>
                  <button onClick={openPdf} style={koGhostBtn}><Eye size={13} /> View MOM</button>
                  <button onClick={download} style={koGhostBtn}><Download size={13} /> Download PDF</button>
                </div>
              )}
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '10px 20px' }}>
              <KoFact label="Scheduled" value={meeting.scheduled_at ? new Date(meeting.scheduled_at).toLocaleString() : '—'} />
              <KoFact label="Mode" value={meeting.mode ? String(meeting.mode).replace(/_/g, ' ') : '—'} />
              <KoFact label="Location" value={meeting.location || '—'} />
            </div>

            {meeting.meeting_link && (
              <a href={meeting.meeting_link} target="_blank" rel="noopener noreferrer" style={koJoinBtn}>
                Join the online meeting
              </a>
            )}
          </div>

          {/* The minutes, as information */}
          {!meeting.mom_available ? (
            <div style={{ ...koCard, color: 'var(--text-muted)', fontSize: 12.5 }}>
              The Minutes of Meeting have not been published yet. They will appear here once the
              procurement team has approved and circulated them.
            </div>
          ) : (
            <div style={koCard}>
              <h3 style={{ margin: '0 0 12px', fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)' }}>Minutes of Meeting</h3>

              {/* The shared minutes view — the same sections the PDF prints, and
                  the same ones TPV now shows. This screen used to render its own
                  four sections and read only the structured agenda rows. */}
              <KickoffMomReview mom={mom} />
            </div>
          )}

          {err && <div style={{ fontSize: 12.5, color: '#ef4444', marginBottom: 10 }}>{err}</div>}

          {/* Acknowledgement — unchanged in meaning */}
          <div style={{ marginTop: 4, padding: '14px 16px', borderRadius: 13, background: acknowledged ? 'rgba(16,185,129,0.06)' : 'var(--bg-input)', border: `1px solid ${acknowledged ? 'rgba(16,185,129,0.3)' : 'var(--border)'}` }}>
            {acknowledged ? (
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7, fontSize: 13, fontWeight: 800, color: '#10b981' }}><CheckCircle2 size={15} /> MOM Acknowledged</span>
                <button onClick={onContinue} style={solidBtn}>Continue <ArrowRight size={15} /></button>
              </div>
            ) : (
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: 9, cursor: (editable && canAcknowledge) ? 'pointer' : 'not-allowed', flex: 1, minWidth: 240 }}>
                  <input type="checkbox" checked={checked} disabled={!editable || !canAcknowledge} onChange={e => setChecked(e.target.checked)} style={{ width: 17, height: 17, accentColor: '#7C3AED' }} />
                  <span style={{ fontSize: 13, color: canAcknowledge ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: 600 }}>
                    {canAcknowledge
                      ? 'I have read and understood the Minutes of Meeting.'
                      : 'Nothing to acknowledge yet — the minutes of this meeting have not been issued.'}
                  </span>
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

function KoFact({ label, value }) {
  return (
    <div>
      <div style={{ fontSize: 10.5, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 3 }}>{label}</div>
      <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-h)', textTransform: label === 'Mode' ? 'capitalize' : 'none' }}>{value}</div>
    </div>
  )
}


const koCard = { padding: 16, borderRadius: 13, background: 'var(--bg-card)', border: '1px solid var(--border)', marginBottom: 14 }
const koGhostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 13px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 12, fontWeight: 700 }
const koJoinBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, marginTop: 14, padding: '9px 16px', borderRadius: 9, textDecoration: 'none', fontSize: 12.5, fontWeight: 800, color: '#fff', background: 'linear-gradient(145deg,#22c55e,#16a34a)' }

/* ── Step 2 — Company profile ────────────────────────────────────────────────── */
function StepProfile({ onboarding, editable, onSaved, onContinue, registerFlush }) {
  const [f, setF] = useState(() => ({ ...EMPTY_PROFILE, ...(onboarding.profile || {}) }))
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [err, setErr] = useState(null)
  // Per-field messages, keyed by the field's own name, so the reason sits under
  // the box it belongs to instead of only in a banner at the bottom.
  const [errs, setErrs] = useState({})
  // What a draft save could not store yet. Not a failure — a note.
  const [skipped, setSkipped] = useState({})

  // Has anything been typed since the last successful save? Read by the flush
  // below, which is registered once, so it must be a ref rather than state that
  // callback would have closed over stale.
  const dirty = useRef(false)
  const set = (k) => (e) => {
    setF(p => ({ ...p, [k]: e.target.value }))
    setErrs(p => (p[k] ? { ...p, [k]: undefined } : p))
    setSaved(false)
    dirty.current = true
  }

  /**
   * Send the form.
   *
   * A cleared box is sent as null, not dropped. The server MERGES what arrives
   * onto the stored profile, so a dropped key means "leave it as it was" — which
   * made deleting a value impossible: it reappeared on the next load. Every rule
   * on this form is `nullable`, so null is the honest way to say "empty".
   */
  const persist = async (draft = false) => {
    const payload = Object.fromEntries(
      Object.entries(f).map(([k, v]) => [k, v === '' || v === undefined ? null : v]),
    )
    const res = await purchasePortalApi.onboarding.saveProfile(onboarding.id, payload, draft)
    dirty.current = false
    setSkipped(res?.skipped || {})
    return res
  }

  /**
   * Keep the half-filled form when the vendor moves to another step.
   *
   * Sent as a draft: the server stores every field that stands on its own and
   * names the rest. It used to be sent strictly, so an account number typed
   * without its IFSC made the whole save a 422 — swallowed here, leaving the
   * vendor to come back to a form with everything else they had typed missing.
   */
  const saveDraft = async () => {
    if (!editable || !dirty.current) return false
    try {
      await persist(true)
      return true
    } catch {
      // A draft that will not save is still not a reason to trap somebody on a
      // step; the strict save on the way out reports it properly.
      return false
    }
  }

  // Registered once, read through a ref, so the flush the wizard calls always
  // sees what is on screen now.
  const draftRef = useRef(saveDraft)
  draftRef.current = saveDraft
  useEffect(() => {
    registerFlush?.(() => draftRef.current())
    return () => registerFlush?.(null)
  }, [registerFlush])

  const save = async (thenContinue) => {
    setSaving(true); setErr(null); setErrs({})
    try {
      await persist()
      setSaved(true); onSaved?.()
      if (thenContinue) onContinue?.()
    } catch (e) {
      // The server's headline for a 422 is always the words "Validation failed",
      // which name nothing. The per-field detail is what the vendor needs, so it
      // is read first and shown against the boxes themselves.
      const { map, summary } = readFieldErrors(e, 'profile.')
      setErrs(map)
      setErr(summary)
    } finally { setSaving(false) }
  }

  const F = (label, key, props = {}) => (
    <Field label={label}>
      <TextInput value={f[key] ?? ''} onChange={set(key)} disabled={!editable}
        style={errs[key] ? { borderColor: '#ef4444' } : undefined} {...props} />
      {errs[key] && <div style={{ color: '#ef4444', fontSize: 11, marginTop: 3 }}>{errs[key]}</div>}
      {!errs[key] && skipped[key] && <div style={{ color: '#d97706', fontSize: 11, marginTop: 3 }}>Not saved yet — {skipped[key]}</div>}
    </Field>
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
        {F('Address', 'registered_address')}
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
      {err && (
        <Banner tone="#ef4444" icon={AlertTriangle}>
          {err}
          {Object.values(errs).filter(Boolean).length > 1 && (
            <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
              {Object.entries(errs).filter(([, m]) => m).map(([k, m]) => <li key={k} style={{ fontSize: 12 }}>{m}</li>)}
            </ul>
          )}
        </Banner>
      )}
      {!err && Object.keys(skipped).length > 0 && (
        <Banner tone="#d97706" icon={AlertTriangle}>
          Your draft was saved. {Object.keys(skipped).length === 1 ? 'One field is' : `${Object.keys(skipped).length} fields are`} still unfinished and {Object.keys(skipped).length === 1 ? 'was' : 'were'} not stored:
          <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
            {Object.entries(skipped).map(([k, m]) => <li key={k} style={{ fontSize: 12 }}>{m}</li>)}
          </ul>
        </Banner>
      )}
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
function StepSubmission({ onboarding, editable, onSubmitted, onBack, navigate }) {
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
      <div>
        {/* The letter leads. "Approved" is a status; the work start letter is the
            document the site asks to see before anybody is let through the gate.
            Purchase generated it, stored it, showed it to administrators — and
            never to the vendor it was about. */}
        <WorkStartLetterCard api={purchasePortalApi} onboardingId={onboarding.id}
          company={onboarding.vendor?.company_name} />

        <div style={{ textAlign: 'center', padding: '18px 16px' }}>
          <div style={{ width: 52, height: 52, borderRadius: '50%', margin: '0 auto 12px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(16,185,129,0.14)' }}>
            <CheckCircle2 size={26} style={{ color: '#10b981' }} />
          </div>
          <h3 style={{ fontSize: 16, fontWeight: 900, color: 'var(--text-h)', margin: 0 }}>Onboarding Approved</h3>
          <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: '6px 0 0' }}>
            Your purchase-vendor account is active. Registration No.{' '}
            <strong style={{ color: 'var(--text-h)' }}>{onboarding.registration_number || '—'}</strong>
          </p>
          <button onClick={() => navigate('/purchase-portal/workforce')} style={{ ...solidBtn, marginTop: 14 }}>
            <Rocket size={15} /> Start Workforce
          </button>
        </div>
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

// Top-aligned and a block child, because a banner now carries a LIST of reasons
// as well as a line of text — a <ul> inside a <span> is invalid, and centring it
// against the icon puts a five-line message half a banner above its own icon.
const Banner = ({ tone, icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'flex-start', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 14, background: `${tone}12`, border: `1px solid ${tone}55` }}>
    <Icon size={15} style={{ color: tone, flexShrink: 0, marginTop: 2 }} />
    <div style={{ fontSize: 13, color: 'var(--text-h)', minWidth: 0 }}>{children}</div>
  </div>
)

const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 18px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }
const tbBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, cursor: 'pointer', fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-input)', border: '1px solid var(--border)' }
