import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertCircle, CheckCircle2, Clock, ArrowRight } from 'lucide-react'
import Drawer from '@/components/ui/Drawer'
import { hrApi } from '@/services/hrApi'
import SalarySheet from '@/modules/hr/components/SalarySheet'
import SearchableSelect from '@/modules/hr/components/SearchableSelect'
import { useMasterData, withInactive } from '@/modules/hr/useMasterData'

const EMPTY_FORM = { candidate_id: '', position: '', department: '', offered_ctc: '', salary_structure_id: '', joining_date: '', probation_period: '3 months', notice_period: '1 month', validity_date: '' }

/**
 * Generate Offer — the ONE offer-creation form in the product.
 *
 * Mounted by the Offers page (recruiter picks any eligible candidate) and by the
 * Candidate workspace (candidate is fixed, opened straight from the interview
 * completion CTA), so no duplicate modal and no duplicate API exist.
 *
 * Compensation is never invented: OfferService requires offered_ctc + joining_date,
 * so the recruiter always supplies them. Creating the offer is also what moves the
 * candidate to the Offer stage — that transition stays entirely in OfferService.
 *
 * `eligible` — onboarding records that may receive an offer (already excludes anyone
 * who has an offer). When `candidateId` is fixed and missing from this list, the
 * onboarding gate is not satisfied and the recruiter is told so instead of failing.
 */
export default function GenerateOfferDrawer({
  open, eligible = [], candidateId = null, candidateName = '', onboarding = null, onClose, onCreated, showToast,
  reviseOffer = null, onRevised,
}) {
  const navigate = useNavigate()
  const [form, setForm] = useState(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [structures, setStructures] = useState([])
  const [preview, setPreview] = useState(null)   // { name, breakdown } when a structure is linked
  const [revisionReason, setRevisionReason] = useState('')

  // Revising an existing offer reuses this same form + drawer (no duplicate modal).
  const isRevise = !!reviseOffer
  const fixed = isRevise || candidateId != null
  const match = (!isRevise && candidateId != null) ? eligible.find(o => Number(o.candidate_id) === Number(candidateId)) : null
  const blocked = !isRevise && candidateId != null && !match  // onboarding gate never applies to a revision

  useEffect(() => {
    if (!open) return
    setPreview(null); setRevisionReason('')
    if (isRevise) {
      setForm({
        candidate_id: String(reviseOffer.candidate_id),
        position: reviseOffer.position || '', department: reviseOffer.department || '',
        offered_ctc: reviseOffer.offered_ctc ? String(reviseOffer.offered_ctc) : '',
        salary_structure_id: reviseOffer.salary_structure_id ? String(reviseOffer.salary_structure_id) : '',
        joining_date: (reviseOffer.joining_date || '').slice(0, 10),
        probation_period: reviseOffer.probation_period || '3 months',
        notice_period: reviseOffer.notice_period || '1 month',
        validity_date: (reviseOffer.validity_date || '').slice(0, 10),
      })
    } else {
      const src = match || null
      setForm({
        ...EMPTY_FORM,
        candidate_id: fixed ? String(candidateId) : '',
        position: src?.position || '',
        department: src?.department || '',
      })
    }
    // Active salary structures the recruiter can base the offer on (Salary Engine).
    hrApi.payroll.salaryStructures.list({ status: 'Active' }).then(r => setStructures(r.data || [])).catch(() => setStructures([]))
  }, [open, candidateId, match, fixed, isRevise, reviseOffer])

  // Position/Department come from the HR masters, same as every other HR form.
  // withInactive() keeps a value that is no longer an active master selectable
  // and marks it "· Inactive", so an existing offer never silently loses its
  // position or department.
  // NOTE: must stay ABOVE the `!open` early return — a hook called conditionally
  // breaks the Rules of Hooks and throws when the drawer re-opens.
  const { masters, loading: mastersLoading } = useMasterData()
  const positionOptions   = withInactive((masters.designations || []).map(d => d.name), form.position)
  const departmentOptions = withInactive((masters.departments  || []).map(d => d.name), form.department)

  if (!open) return null

  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))
  // Value-setter for controls that hand back a value rather than an event.
  const setV = (k) => (v) => setForm(f => ({ ...f, [k]: v }))

  /**
   * Fill the form from the chosen candidate.
   *
   * The onboarding row snapshots position/department when it is created, so a
   * candidate who had no job posting back then is stuck on 'To Be Assigned' with
   * a blank department. When that happens, resolve from the candidate's job
   * posting instead — the record is already eager-loaded by the candidate
   * endpoint, so this needs no new API.
   */
  const selectCandidate = async (candidateIdValue) => {
    const o = eligible.find(x => String(x.candidate_id) === candidateIdValue)
    const snapPosition = o?.position && o.position !== 'To Be Assigned' ? o.position : ''
    setForm(f => ({ ...f, candidate_id: candidateIdValue, position: snapPosition, department: o?.department || '' }))

    if (!candidateIdValue) return

    try {
      const c = await hrApi.candidates.get(candidateIdValue)
      const cand = c?.data ?? c
      setForm(f => ({
        ...f,
        // Only fill what the snapshot left blank — a value already on screen wins.
        position:   f.position   || cand?.job_posting?.title      || '',
        department: f.department || cand?.job_posting?.department || '',
      }))
    } catch { /* keep the snapshot values; the fields stay editable */ }
  }

  // Linking a structure derives the CTC + freezes the breakup on the letter.
  const selectStructure = async (id) => {
    if (!id) { setPreview(null); setForm(f => ({ ...f, salary_structure_id: '' })); return }
    try {
      const s = await hrApi.payroll.salaryStructures.get(id)
      setPreview({ name: s.name, breakdown: s.breakdown })
      setForm(f => ({ ...f, salary_structure_id: id, offered_ctc: String(s.breakdown?.ctc?.yearly ?? f.offered_ctc) }))
    } catch { showToast?.('Failed to load structure', 'error') }
  }

  const create = async () => {
    // Revision path — snapshots the current version, resets to Draft (re-approval needed).
    if (isRevise) {
      if (!revisionReason.trim()) return showToast?.('A revision reason is required', 'error')
      if (!form.offered_ctc || !form.joining_date) return showToast?.('CTC and joining date required', 'error')
      setSaving(true)
      try {
        const updated = await hrApi.offers.revise(reviseOffer.id, { ...form, revision_reason: revisionReason.trim() })
        showToast?.('Offer revised — new version created (Draft)')
        onRevised?.(updated); onClose?.()
      } catch (e) {
        showToast?.(e.response?.data?.message || 'Offer could not be revised', 'error')
      } finally { setSaving(false) }
      return
    }
    if (!form.candidate_id || !form.offered_ctc || !form.joining_date) {
      return showToast?.('Candidate, CTC and joining date required', 'error')
    }
    setSaving(true)
    try {
      const offer = await hrApi.offers.create(form)
      showToast?.('Offer letter generated!')
      onCreated?.(offer)
      onClose?.()
    } catch (e) {
      // Surface the real rule (e.g. the onboarding-approval gate) — never fail silently.
      showToast?.(e.response?.data?.message || 'Offer could not be generated', 'error')
    } finally { setSaving(false) }
  }

  /* ── Pre-Offer blocker: the onboarding gate is not satisfied yet ──
     The rule is unchanged and never bypassed — the recruiter is simply shown what
     is outstanding and sent to the existing onboarding module to clear it. */
  if (blocked) {
    // Real backend values wherever they exist; anything unknown stays pending.
    const steps = [
      { label: 'Interview Completed', done: true },
      { label: 'Candidate Selected', done: true },
      { label: 'Document Verification', done: !!onboarding?.doc_verified },
      { label: 'Background Verification', done: !!onboarding?.background_verified },
      ...(onboarding && onboarding.medical_verified !== null && onboarding.medical_verified !== undefined
        ? [{ label: 'Medical Verification', done: !!onboarding.medical_verified }] : []),
      { label: 'HR Approval', done: onboarding?.verification_status === 'Approved' },
    ]
    // Progress is derived from real verification flags — hidden when there is no
    // onboarding record to derive it from.
    const pct = onboarding ? Math.round((steps.filter(s => s.done).length / steps.length) * 100) : null

    return (
      <Drawer
        open onClose={onClose} title="Candidate Selected" width="min(560px, 95vw)"
        footer={(
          <div className="flex gap-3">
            <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Close</button>
            <button onClick={() => { onClose?.(); navigate('/app/hr/onboarding') }}
              className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2"
              style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
              <ArrowRight size={14} /> Go to Pre-Offer
            </button>
          </div>
        )}
      >
        <div className="flex items-start gap-3 px-3 py-3 rounded-xl mb-4" style={{ background: 'rgba(245,158,11,0.1)', border: '1px solid rgba(245,158,11,0.3)' }}>
          <AlertCircle size={18} style={{ color: '#f59e0b', flexShrink: 0 }} />
          <p className="text-xs leading-relaxed" style={{ color: 'var(--text-h)' }}>
            <b>{candidateName || 'This candidate'}</b> has successfully cleared all interview rounds.
            <br />Before an Offer Letter can be generated, complete the <b>Pre-Offer Onboarding Verification</b>.
          </p>
        </div>

        {pct !== null && (
          <div className="mb-4">
            <div className="flex justify-between mb-1">
              <span className="text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Pre-Offer Progress</span>
              <span className="text-xs font-black" style={{ color: '#a78bfa' }}>{pct}%</span>
            </div>
            <div className="h-2 rounded-full" style={{ background: 'var(--bg-input)' }}>
              <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: 'linear-gradient(90deg,#7C3AED,#a78bfa)' }} />
            </div>
          </div>
        )}

        <div className="rounded-xl px-3 py-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <p className="text-[10px] font-bold uppercase tracking-wide mb-2.5" style={{ color: 'var(--text-muted)' }}>Required before Offer Generation</p>
          <div className="space-y-2">
            {steps.map(s => (
              <div key={s.label} className="flex items-center gap-2.5">
                {s.done
                  ? <CheckCircle2 size={14} style={{ color: '#10b981', flexShrink: 0 }} />
                  : <Clock size={14} style={{ color: '#f59e0b', flexShrink: 0 }} />}
                <span className="text-xs font-semibold" style={{ color: s.done ? 'var(--text-h)' : 'var(--text-muted)' }}>{s.label}</span>
                {!s.done && <span className="text-[9.5px] font-bold ml-auto px-1.5 py-0.5 rounded" style={{ background: 'rgba(245,158,11,0.14)', color: '#f59e0b' }}>Pending</span>}
              </div>
            ))}
          </div>
        </div>
      </Drawer>
    )
  }

  return (
    <Drawer
      open onClose={onClose} title={isRevise ? 'Revise Offer' : 'Generate Offer Letter'} width="min(640px, 95vw)"
      footer={(
        // w-full: .drawer-footer is itself a flex container, so without this the
        // wrapper shrinks to content and flex-1 has nothing to divide — the
        // buttons collapse to text width and the labels overflow.
        <div className="flex gap-3 w-full">
          <button onClick={onClose} className="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-center whitespace-nowrap" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={create} disabled={saving} className="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold text-white text-center whitespace-nowrap" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: saving ? 0.7 : 1 }}>{saving ? (isRevise ? 'Revising…' : 'Generating…') : (isRevise ? 'Revise Offer' : 'Generate')}</button>
        </div>
      )}
    >
      <div className="space-y-3">
        {isRevise && (
          <div>
            <label className="label">Revision Reason *</label>
            <textarea rows={2} className="input-3d text-sm resize-none" placeholder="Why is this offer being revised?" value={revisionReason} onChange={e => setRevisionReason(e.target.value)}
              style={!revisionReason.trim() ? { borderColor: 'rgba(248,113,113,0.5)' } : undefined} />
            <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>Version {reviseOffer?.version} is preserved in history; the revised offer resets to Draft and must be re-approved.</p>
          </div>
        )}
        {fixed ? (
          <div>
            <label className="label">Candidate</label>
            <div className="text-sm font-bold px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
              {candidateName || match?.candidate_name || reviseOffer?.candidate?.name || 'Candidate'}
            </div>
          </div>
        ) : (
          <div>
            <label className="label">Candidate * <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>(onboarding approved)</span></label>
            <select className="input-3d text-sm" value={form.candidate_id}
              onChange={e => selectCandidate(e.target.value)}>
              <option value="">Select candidate...</option>
              {eligible.map(o => <option key={o.id} value={o.candidate_id}>{o.candidate_name} — {o.position}</option>)}
            </select>
          </div>
        )}

        <div className="grid grid-cols-2 gap-3">
          {/* #18 — "listing option with filter". SearchableSelect rather than a
              bare <select>: it type-filters the list, which is what makes a
              hundred-designation master usable. `allowCreate` keeps the previous
              free-text fallback, so a fresh tenant with no masters — or a one-off
              title — is still never blocked from issuing an offer. */}
          <div>
            <label className="label">Position</label>
            <SearchableSelect
              value={form.position} onChange={setV('position')}
              options={positionOptions.map(o => o.label)}
              loading={mastersLoading}
              placeholder="Select or type a position…"
              emptyText="No designations yet" allowCreate
            />
          </div>
          <div>
            <label className="label">Department</label>
            <SearchableSelect
              value={form.department} onChange={setV('department')}
              options={departmentOptions.map(o => o.label)}
              loading={mastersLoading}
              placeholder="Select or type a department…"
              emptyText="No departments yet" allowCreate
            />
          </div>
        </div>

        {/* Salary Engine: optionally base the offer on a Salary Structure. */}
        <div>
          <label className="label">Salary Structure <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>(optional — derives CTC + breakup)</span></label>
          <select className="input-3d text-sm" value={form.salary_structure_id} onChange={e => selectStructure(e.target.value)}>
            <option value="">Manual CTC (no structure)</option>
            {structures.map(s => <option key={s.id} value={s.id}>{s.name}{s.code ? ` (${s.code})` : ''} — ₹{Number(s.totals?.ctc || 0).toLocaleString('en-IN')}/mo</option>)}
          </select>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label">Offered CTC (₹) * {form.salary_structure_id && <span style={{ color: '#a78bfa', fontWeight: 400 }}>· from structure</span>}</label>
            <input type="number" className="input-3d text-sm" placeholder="800000" value={form.offered_ctc} onChange={set('offered_ctc')} readOnly={!!form.salary_structure_id} style={form.salary_structure_id ? { opacity: 0.7 } : undefined} />
          </div>
          <div><label className="label">Joining Date *</label><input type="date" className="input-3d text-sm" value={form.joining_date} onChange={set('joining_date')} /></div>
        </div>

        {preview && (
          <div>
            <label className="label">Salary Breakup Preview</label>
            <SalarySheet breakdown={preview.breakdown} structureName={preview.name} />
            <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>This breakup is frozen onto the offer letter at generation.</p>
          </div>
        )}
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Probation Period</label><select className="input-3d text-sm" value={form.probation_period} onChange={set('probation_period')}><option>3 months</option><option>6 months</option><option>None</option></select></div>
          <div><label className="label">Notice Period</label><select className="input-3d text-sm" value={form.notice_period} onChange={set('notice_period')}><option>1 month</option><option>2 months</option><option>3 months</option></select></div>
        </div>
        <div><label className="label">Offer Validity Date</label><input type="date" className="input-3d text-sm" value={form.validity_date} onChange={set('validity_date')} /></div>
      </div>
    </Drawer>
  )
}
