import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertCircle, CheckCircle2, Clock, ArrowRight } from 'lucide-react'
import Drawer from '@/components/ui/Drawer'
import { hrApi } from '@/services/hrApi'
import SalarySheet from '@/modules/hr/components/SalarySheet'

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
}) {
  const navigate = useNavigate()
  const [form, setForm] = useState(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [structures, setStructures] = useState([])
  const [preview, setPreview] = useState(null)   // { name, breakdown } when a structure is linked

  const fixed = candidateId != null
  const match = fixed ? eligible.find(o => Number(o.candidate_id) === Number(candidateId)) : null
  const blocked = fixed && !match

  useEffect(() => {
    if (!open) return
    setPreview(null)
    const src = match || null
    setForm({
      ...EMPTY_FORM,
      candidate_id: fixed ? String(candidateId) : '',
      position: src?.position || '',
      department: src?.department || '',
    })
    // Active salary structures the recruiter can base the offer on (Salary Engine).
    hrApi.payroll.salaryStructures.list({ status: 'Active' }).then(r => setStructures(r.data || [])).catch(() => setStructures([]))
  }, [open, candidateId, match, fixed])

  if (!open) return null

  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))

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
      open onClose={onClose} title="Generate Offer Letter" width="min(640px, 95vw)"
      footer={(
        <div className="flex gap-3">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={create} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: saving ? 0.7 : 1 }}>{saving ? 'Generating…' : 'Generate'}</button>
        </div>
      )}
    >
      <div className="space-y-3">
        {fixed ? (
          <div>
            <label className="label">Candidate</label>
            <div className="text-sm font-bold px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
              {candidateName || match?.candidate_name}
            </div>
          </div>
        ) : (
          <div>
            <label className="label">Candidate * <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>(onboarding approved)</span></label>
            <select className="input-3d text-sm" value={form.candidate_id}
              onChange={e => { const o = eligible.find(x => String(x.candidate_id) === e.target.value); setForm({ ...form, candidate_id: e.target.value, position: o?.position || '', department: o?.department || '' }) }}>
              <option value="">Select candidate...</option>
              {eligible.map(o => <option key={o.id} value={o.candidate_id}>{o.candidate_name} — {o.position}</option>)}
            </select>
          </div>
        )}

        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Position</label><input className="input-3d text-sm" value={form.position} onChange={set('position')} /></div>
          <div><label className="label">Department</label><input className="input-3d text-sm" value={form.department} onChange={set('department')} /></div>
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
