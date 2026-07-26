import { useState, useEffect } from 'react'
import {
  X, Plus, Star, ChevronRight, ChevronDown, Users, History, Eye, Loader2, Play, SkipForward,
} from 'lucide-react'
import Drawer from '@/components/ui/Drawer'
import DateTimePicker from '@/modules/hr/components/DateTimePicker'
import SkipRoundDialog from '@/modules/hr/components/SkipRoundDialog'
import { hrApi } from '@/services/hrApi'
import {
  INTERVIEW_ROUNDS, INTERVIEW_MODES, canonicalRound, INTERVIEW_STATUS_COLORS,
} from '@/modules/hr/constants'
import {
  roundGateIn, normalizeLead, initialsOf, desigOf, isOptionalRound,
} from '@/modules/hr/components/interviewFlow'

const EMPTY_FORM = { candidate_id: '', round_name: 'HR Round', mode: 'online', interviewer_name: '', interviewers: [], scheduled_at: '', venue: '', meet_link: '', reminder_minutes: 0 }

// Interviewer panel types → existing CRM user roles (no duplicate users).
const PANEL_TYPES = [
  { key: 'staff', label: 'Internal Staff' },
  { key: 'client', label: 'Client' },
  { key: 'vendor', label: 'Vendor' },
  { key: 'third_party_vendor', label: 'Third Party Vendor' },
]
const REMINDER_OPTIONS = [
  { label: 'No reminder', v: 0 },
  { label: '1 Day Before', v: 1440 },
  { label: '3 Days Before', v: 4320 },
  { label: '7 Days Before', v: 10080 },
  { label: 'Custom', v: 'custom' },
]
const statusStyle = s => ({ color: INTERVIEW_STATUS_COLORS[s] || '#6b7280', bg: `${INTERVIEW_STATUS_COLORS[s] || '#6b7280'}20` })

/* ── Enterprise searchable Lead-Interviewer dropdown ──────────────────────────
   Reuses the existing interview-panel user list (hrApi.interviews.panelUsers).
   Rich rows: avatar, name, designation/department, employee id.               */
function LeadInterviewerSelect({ users = [], leadName = '', leadUser = null, onSelect, loading = false, disabled = false, hint = '' }) {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const filtered = users.filter(u => {
    if (!q) return true
    const s = q.toLowerCase()
    return [u.name, desigOf(u), u.department, u.email, String(u.id)].some(v => (v || '').toString().toLowerCase().includes(s))
  })
  const av = (name, size = 28) => (
    <span className="flex items-center justify-center rounded-full font-bold flex-shrink-0"
      style={{ width: size, height: size, fontSize: size * 0.36, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff' }}>{initialsOf(name)}</span>
  )
  return (
    <div style={{ position: 'relative' }}>
      {leadName ? (
        <div className="rounded-xl px-3 py-2 flex items-center gap-2.5" style={{ background: 'rgba(124,58,237,0.08)', border: '1px solid rgba(124,58,237,0.3)' }}>
          <Star size={15} style={{ color: '#fbbf24', fill: '#fbbf24', flexShrink: 0 }} />
          {av(leadName, 30)}
          <span className="flex-1 min-w-0">
            <span className="block text-[9.5px] font-bold uppercase tracking-wide" style={{ color: '#a78bfa' }}>Lead Interviewer</span>
            <span className="block text-sm font-bold truncate" style={{ color: 'var(--text-h)' }}>{leadName}</span>
            {(desigOf(leadUser) || leadUser?.department) && <span className="block text-[10.5px] truncate" style={{ color: 'var(--text-muted)' }}>{[desigOf(leadUser), leadUser?.department].filter(Boolean).join(' · ')}</span>}
          </span>
          <button type="button" onClick={() => !disabled && setOpen(o => !o)} className="text-[10.5px] font-bold px-2 py-1 rounded-lg flex-shrink-0" style={{ color: '#a78bfa', background: 'var(--bg-card)', border: '1px solid var(--border)' }}>Change</button>
        </div>
      ) : (
        <button type="button" onClick={() => !disabled && setOpen(o => !o)} disabled={disabled} className="w-full input-3d text-sm flex items-center justify-between" style={{ color: 'var(--text-muted)', opacity: disabled ? 0.6 : 1 }}>
          <span className="flex items-center gap-2"><Users size={13} /> Search staff to assign Lead Interviewer…</span><ChevronDown size={14} />
        </button>
      )}
      {open && (
        <>
          <div onClick={() => setOpen(false)} style={{ position: 'fixed', inset: 0, zIndex: 30 }} />
          <div style={{ position: 'absolute', zIndex: 31, top: '100%', left: 0, right: 0, marginTop: 4, background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 12, boxShadow: '0 12px 34px rgba(0,0,0,0.28)', overflow: 'hidden', display: 'flex', flexDirection: 'column', maxHeight: 280 }}>
            <div style={{ padding: 8, borderBottom: '1px solid var(--border)' }}>
              <input autoFocus value={q} onChange={e => setQ(e.target.value)} placeholder="Search name, ID, department, designation…" className="input-3d text-sm w-full" />
            </div>
            <div style={{ overflowY: 'auto' }}>
              {loading ? <p className="px-3 py-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>Loading eligible interviewers…</p>
                : disabled ? <p className="px-3 py-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>{hint}</p>
                : filtered.length === 0 ? <p className="px-3 py-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>No eligible interviewers found.</p>
                : filtered.map(u => (
                  <button key={u.id} type="button" onClick={() => { onSelect(u); setOpen(false); setQ('') }}
                    className="w-full flex items-center gap-2.5 px-3 py-2 text-left" style={{ borderBottom: '1px solid var(--border)', background: 'transparent' }}>
                    {av(u.name)}
                    <span className="flex-1 min-w-0">
                      <span className="block text-sm font-bold truncate" style={{ color: 'var(--text-h)' }}>{u.name}</span>
                      <span className="block text-[10.5px] truncate" style={{ color: 'var(--text-muted)' }}>{[desigOf(u), u.department].filter(Boolean).join(' · ') || u.email || '—'}</span>
                    </span>
                    <span className="text-[9.5px] flex-shrink-0" style={{ color: 'var(--text-muted)', fontFamily: 'ui-monospace,monospace' }}>#{u.id}</span>
                  </button>
                ))}
            </div>
          </div>
        </>
      )}
    </div>
  )
}

/**
 * Schedule / Reschedule Interview — the ONLY place an interview is scheduled.
 *
 * Mounted by both the Interviews queue and the Candidate Detail workspace, so the
 * recruiter never navigates to another page to book a round.
 *
 * `interviews` is the pool used to compute the sequence gate; it is filtered by the
 * currently-selected candidate internally (the queue lets the candidate change).
 */
export default function ScheduleInterviewDrawer({
  open, mode = 'schedule', interview = null, candidateId = null, defaultRound = null,
  candidates = [], interviews = [], showSequence = true,
  onClose, onSaved, onOpenFeedback, onResume, onSkipped, showToast,
}) {
  const [form, setForm] = useState(EMPTY_FORM)
  const [panelType, setPanelType] = useState('staff')
  const [panelUsers, setPanelUsers] = useState([])
  const [panelLoading, setPanelLoading] = useState(false)
  const [panelOrg, setPanelOrg] = useState('')
  const [panelOrgs, setPanelOrgs] = useState([])
  const [reminderMode, setReminderMode] = useState(0)
  const [customDays, setCustomDays] = useState(2)
  const [saving, setSaving] = useState(false)
  const [skipOpen, setSkipOpen] = useState(false)

  const isReschedule = mode === 'reschedule'

  // Initialise the form whenever the drawer opens.
  useEffect(() => {
    if (!open) return
    setPanelType('staff')
    if (isReschedule && interview) {
      setForm({
        candidate_id: interview.candidate_id, round_name: interview.round_name || 'HR Round', mode: interview.mode || 'online',
        interviewer_name: interview.interviewer_name || '',
        interviewers: normalizeLead(interview.interviewers || [], interview.interviewer_name || ''),
        scheduled_at: interview.scheduled_at ? interview.scheduled_at.slice(0, 16) : '',
        venue: interview.venue || '', meet_link: interview.meet_link || '',
        reminder_minutes: interview.reminder_minutes || 0,
      })
      const preset = REMINDER_OPTIONS.find(o => o.v === (interview.reminder_minutes || 0))
      if (preset) setReminderMode(preset.v)
      else { setReminderMode('custom'); setCustomDays(Math.max(1, Math.round((interview.reminder_minutes || 0) / 1440))) }
    } else {
      setForm({ ...EMPTY_FORM, candidate_id: candidateId != null ? String(candidateId) : '', round_name: defaultRound || EMPTY_FORM.round_name })
      setReminderMode(0)
    }
  }, [open, isReschedule, interview, candidateId, defaultRound])

  // Panel Types: Internal Staff loads users directly; Client / Vendor / TPV load
  // organisations first (reusing users.company), then that organisation's users.
  useEffect(() => {
    if (!open) return
    let live = true
    setPanelOrg(''); setPanelUsers([])
    if (panelType === 'staff') {
      setPanelLoading(true)
      hrApi.interviews.panelUsers('staff')
        .then(u => { if (live) setPanelUsers(Array.isArray(u) ? u : []) })
        .catch(() => { if (live) setPanelUsers([]) })
        .finally(() => { if (live) setPanelLoading(false) })
    } else {
      setPanelOrgs([])
      hrApi.interviews.panelOrgs(panelType)
        .then(o => { if (live) setPanelOrgs(Array.isArray(o) ? o : []) })
        .catch(() => { if (live) setPanelOrgs([]) })
    }
    return () => { live = false }
  }, [panelType, open])

  useEffect(() => {
    if (panelType === 'staff' || !panelOrg) return
    let live = true; setPanelLoading(true)
    hrApi.interviews.panelUsers(panelType, panelOrg)
      .then(u => { if (live) setPanelUsers(Array.isArray(u) ? u : []) })
      .catch(() => { if (live) setPanelUsers([]) })
      .finally(() => { if (live) setPanelLoading(false) })
    return () => { live = false }
  }, [panelOrg, panelType])

  if (!open) return null

  /* ── Panel handlers ── */
  const addPanelist = () => setForm(f => ({ ...f, interviewers: [...f.interviewers, { name: '', email: '' }] }))
  const setPanelist = (i, k, v) => setForm(f => ({ ...f, interviewers: f.interviewers.map((p, idx) => idx === i ? { ...p, [k]: v } : p) }))
  const removePanelist = (i) => setForm(f => {
    const p = f.interviewers[i]
    // Lead protection: removing the Lead clears the Lead field (and prompts a re-assign).
    if (p?.is_lead || (f.interviewer_name && p?.name === f.interviewer_name)) {
      // eslint-disable-next-line no-alert
      if (!window.confirm('This person is assigned as Lead Interviewer.\nRemove them and assign another Lead Interviewer?')) return f
      return { ...f, interviewers: f.interviewers.filter((_, idx) => idx !== i), interviewer_name: '' }
    }
    return { ...f, interviewers: f.interviewers.filter((_, idx) => idx !== i) }
  })
  const addUserPanelist = (u) => setForm(f => {
    if ((f.interviewers || []).some(p => p.user_id === u.id || (p.email && p.email === u.email))) return f
    const label = PANEL_TYPES.find(t => t.key === panelType)?.label || panelType
    return { ...f, interviewers: [...f.interviewers, { name: u.name, email: u.email || '', user_id: u.id, type: label }] }
  })
  // Assign the Lead Interviewer: always inside the panel, flagged, first, deduped.
  const setLead = (u) => setForm(f => {
    let list = (f.interviewers || []).map(p => ({ ...p, is_lead: false }))
    const idx = list.findIndex(p => p.user_id === u.id || (p.email && u.email && p.email === u.email) || p.name === u.name)
    if (idx >= 0) list[idx] = { ...list[idx], is_lead: true, name: u.name, user_id: u.id, email: u.email || list[idx].email || '' }
    else {
      const label = PANEL_TYPES.find(t => t.key === panelType)?.label || panelType
      list = [...list, { name: u.name, email: u.email || '', user_id: u.id, type: label, is_lead: true }]
    }
    list = [...list].sort((a, b) => (b.is_lead ? 1 : 0) - (a.is_lead ? 1 : 0))
    return { ...f, interviewers: list, interviewer_name: u.name }
  })
  const applyReminder = (v) => {
    setReminderMode(v)
    if (v === 'custom') setForm(f => ({ ...f, reminder_minutes: Number(customDays) * 1440 }))
    else setForm(f => ({ ...f, reminder_minutes: Number(v) }))
  }
  const applyCustomDays = (d) => { setCustomDays(d); setForm(f => ({ ...f, reminder_minutes: Number(d || 0) * 1440 })) }

  /* ── Sequence data for the selected candidate ── */
  const rounds = (interviews || []).filter(iv => Number(iv.candidate_id) === Number(form.candidate_id))
  const gate = isReschedule ? { state: 'ok' } : roundGateIn(rounds, form.round_name, interview?.id)

  // Skip is offered only for an OPTIONAL round that has never been scheduled for this
  // candidate. Required rounds never show it; an existing record (Scheduled, Completed
  // or already Skipped) hides it; a blocked sequence hides it.
  const alreadyExists = rounds.some(iv => canonicalRound(iv.round_name) === canonicalRound(form.round_name))
  const canSkip = !isReschedule && !!form.candidate_id && isOptionalRound(form.round_name)
    && !alreadyExists && gate.state === 'ok'

  const submit = async () => {
    if (!form.candidate_id || !form.scheduled_at) return showToast?.('Candidate and date/time are required', 'error')
    if (new Date(form.scheduled_at) < new Date()) return showToast?.('Interview cannot be scheduled in the past.', 'error')
    if (form.mode === 'offline' && !form.venue.trim()) return showToast?.('Venue is required for offline interviews', 'error')
    if (!form.interviewer_name?.trim()) return showToast?.('Lead Interviewer is required', 'error')
    if (!(form.interviewers || []).some(p => p.name?.trim())) return showToast?.('At least one interviewer is required', 'error')
    setSaving(true)
    try {
      // Strip the UI-only `is_lead` flag — the backend payload stays exactly as before.
      const payload = { ...form, interviewers: form.interviewers.filter(p => p.name?.trim()).map(({ is_lead, ...rest }) => rest) }
      if (isReschedule && interview) {
        const updated = await hrApi.interviews.update(interview.id, payload)
        showToast?.('Interview rescheduled')
        onSaved?.(updated, 'reschedule')
      } else {
        const iv = await hrApi.interviews.schedule(payload)
        showToast?.('Interview scheduled!')
        onSaved?.(iv, 'schedule')
      }
      onClose?.()
    } catch (e) { showToast?.(e.response?.data?.message || 'Failed', 'error') }
    finally { setSaving(false) }
  }

  const typeLabel = PANEL_TYPES.find(t => t.key === panelType)?.label
  const steps = panelType === 'staff'
    ? [{ n: 1, l: 'Type', ok: true }, { n: 2, l: 'Interviewer', ok: (form.interviewers || []).length > 0 }]
    : [{ n: 1, l: 'Type', ok: true }, { n: 2, l: `${typeLabel}`, ok: !!panelOrg }, { n: 3, l: 'Interviewer', ok: (form.interviewers || []).length > 0 }]

  /* ── The single primary CTA — never a disabled dead-end ── */
  const primary = (() => {
    if (gate.state === 'incomplete') return (
      <button type="button" onClick={() => onOpenFeedback?.(gate.round)}
        className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2"
        style={{ background: 'linear-gradient(135deg,#f59e0b,#d97706)' }}>
        <Star size={14} style={{ fill: '#fff' }} /> Complete {gate.round.round_name}
      </button>
    )
    if (gate.state === 'hold') return (
      <button type="button" onClick={() => onResume?.(gate.round)}
        className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2"
        style={{ background: 'linear-gradient(135deg,#f59e0b,#d97706)' }}>
        <Play size={14} /> Resume {gate.round.round_name}
      </button>
    )
    if (gate.state === 'failed') {
      const cancelled = gate.round.status === 'Cancelled'
      return (
        <button type="button" onClick={() => onOpenFeedback?.(gate.round)}
          className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2"
          style={{ background: 'linear-gradient(135deg,#ef4444,#b91c1c)' }}>
          <Eye size={14} /> {cancelled ? 'View Details' : `View ${gate.round.round_name.replace(' Round', '')} Feedback`}
        </button>
      )
    }
    return (
      <button onClick={submit} disabled={saving}
        className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2"
        style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', cursor: 'pointer', opacity: saving ? 0.7 : 1 }}>
        {saving && <Loader2 size={14} className="animate-spin" />}{isReschedule ? 'Reschedule' : `Schedule ${form.round_name}`}
      </button>
    )
  })()

  return (
    <Drawer
      open onClose={onClose}
      title={isReschedule ? 'Reschedule Interview' : 'Schedule Interview'}
      width="min(720px, 95vw)"
      footer={(
        <div className="flex gap-3">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          {/* Skip never replaces Schedule — the recruiter always has both choices. */}
          {canSkip && (
            <button type="button" onClick={() => setSkipOpen(true)}
              className="flex-1 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2"
              style={{ background: 'transparent', color: '#a78bfa', border: '1.5px solid #a78bfa' }}>
              <SkipForward size={14} /> Skip {form.round_name}
            </button>
          )}
          {primary}
        </div>
      )}
    >
      {/* The single shared skip dialog — same component the workspace uses */}
      <SkipRoundDialog
        open={skipOpen}
        roundName={form.round_name}
        createPayload={{
          candidate_id: form.candidate_id,
          round_name: form.round_name,
          mode: form.mode || 'online',
          scheduled_at: form.scheduled_at || new Date().toISOString().slice(0, 16).replace('T', ' '),
        }}
        onClose={() => setSkipOpen(false)}
        onDone={() => { onSkipped?.(); onClose?.() }}
        showToast={showToast}
      />

      <div className="space-y-3">
        {/* Candidate — only when the caller has not fixed one (queue page) */}
        {!isReschedule && candidateId == null && (
          <div>
            <label className="label">Candidate *</label>
            <select className="input-3d text-sm" value={form.candidate_id} onChange={e => setForm({ ...form, candidate_id: e.target.value })}>
              <option value="">Select candidate...</option>
              {candidates.filter(c => !['Hired', 'Rejected'].includes(c.stage)).map(c => <option key={c.id} value={c.id}>{c.name} — {c.stage}</option>)}
            </select>
          </div>
        )}

        {/* Sequence gate message (the Candidate workspace shows the full timeline instead) */}
        {form.candidate_id && gate.state !== 'ok' && (() => {
          const failed = gate.state === 'failed'
          const held = gate.state === 'hold'
          const rn = gate.round.round_name
          const cancelled = failed && gate.round.status === 'Cancelled'
          const msg = held ? `${rn} is on hold — resume it before scheduling ${form.round_name}.`
            : !failed ? `${rn} is still in progress.`
            : cancelled ? `${rn} was cancelled.`
            : `Candidate did not clear ${rn}.`
          return (
            <div className="flex items-center gap-2 px-2.5 py-2 rounded-lg"
              style={{ background: failed ? 'rgba(248,113,113,0.1)' : 'rgba(245,158,11,0.1)', border: `1px solid ${failed ? 'rgba(248,113,113,0.35)' : 'rgba(245,158,11,0.3)'}` }}>
              <span style={{ fontSize: 12, lineHeight: 1.2 }}>{failed ? '❌' : held ? '🟡' : '⚠'}</span>
              <p className="text-[10.5px] font-semibold" style={{ color: failed ? '#f87171' : '#f59e0b' }}>{msg}</p>
            </div>
          )
        })()}

        {/* Read-only interview sequence (hidden in Candidate Detail — the workspace owns it) */}
        {showSequence && form.candidate_id && (() => {
          const seq = rounds.filter(iv => !interview || iv.id !== interview.id)
          if (!seq.length) return null
          return (
            <div className="rounded-xl px-3 py-2.5" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <p className="label-caps mb-2 flex items-center gap-1.5" style={{ margin: 0 }}><History size={11} /> Interview Sequence <span className="font-medium normal-case" style={{ color: 'var(--text-muted)' }}>· read-only</span></p>
              <div className="space-y-1">
                {seq.map(iv => {
                  const done = iv.status === 'Completed'
                  const col = done ? (iv.result === 'Failed' ? '#f87171' : '#10b981') : iv.status === 'Cancelled' ? 'var(--text-muted)' : '#f59e0b'
                  return (
                    <div key={iv.id} className="flex items-center gap-2 text-xs">
                      <span style={{ color: col }}>{done ? '✓' : '•'}</span>
                      <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{iv.round_name}</span>
                      <span className="text-[10px] font-bold px-1.5 py-0.5 rounded" style={{ background: `${col}1f`, color: col }}>{done && iv.result !== 'Pending' ? iv.result : iv.status}</span>
                    </div>
                  )
                })}
              </div>
            </div>
          )
        })()}

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label">Round</label>
            <select className="input-3d text-sm" value={form.round_name} onChange={e => setForm({ ...form, round_name: e.target.value })}>
              {INTERVIEW_ROUNDS.map(r => {
                // Future rounds stay visible but locked while an earlier round is open/failed.
                const g = roundGateIn(rounds, r, interview?.id)
                const locked = !isReschedule && g.state !== 'ok' && canonicalRound(form.round_name) !== r
                const reason = g.state === 'failed'
                  ? (g.round?.status === 'Cancelled' ? `${g.round?.round_name} was cancelled` : `candidate failed ${g.round?.round_name}`)
                  : g.state === 'hold' ? `${g.round?.round_name} is on hold`
                  : `available after ${g.round?.round_name} completion`
                return <option key={r} value={r} disabled={locked}>{locked ? `🔒 ${r} — ${reason}` : r}</option>
              })}
              {!INTERVIEW_ROUNDS.includes(form.round_name) && <option>{form.round_name}</option>}
            </select>
          </div>
          <div>
            <label className="label">Mode</label>
            <div className="flex rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
              {INTERVIEW_MODES.map(m => (
                <button key={m} type="button" onClick={() => setForm({ ...form, mode: m })} className="flex-1 py-2 text-xs font-bold capitalize" style={{ background: form.mode === m ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: form.mode === m ? '#fff' : 'var(--text-muted)' }}>{m}</button>
              ))}
            </div>
          </div>
        </div>

        <div>
          <label className="label">Lead Interviewer *</label>
          <LeadInterviewerSelect
            users={panelUsers}
            leadName={form.interviewer_name}
            leadUser={panelUsers.find(x => x.id === (form.interviewers || []).find(p => p.is_lead)?.user_id) || null}
            onSelect={setLead}
            loading={panelLoading}
            disabled={panelType !== 'staff' && !panelOrg}
            hint={`Select a ${typeLabel || ''} organisation above to load its team.`}
          />
        </div>

        {/* Interviewer Panel — step-based, reuses existing CRM users */}
        <div className="rounded-xl px-3 py-2.5" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <div className="flex items-center justify-between mb-2 flex-wrap gap-2">
            <label className="label" style={{ margin: 0 }}>Interviewer Panel</label>
            <div className="flex items-center gap-1">
              {steps.map((s, i) => (
                <div key={s.n} className="flex items-center gap-1">
                  <span className="inline-flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.5 rounded-md"
                    style={{ background: s.ok ? 'rgba(16,185,129,0.12)' : 'var(--bg-card,transparent)', color: s.ok ? '#10b981' : 'var(--text-muted)', border: `1px solid ${s.ok ? 'rgba(16,185,129,0.3)' : 'var(--border)'}` }}>
                    {s.ok ? '✓' : s.n} {s.l}
                  </span>
                  {i < steps.length - 1 && <ChevronRight size={9} style={{ color: 'var(--text-muted)' }} />}
                </div>
              ))}
            </div>
          </div>

          <p className="text-[9px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>Step 1 · Interviewer Type</p>
          <div className="flex gap-1 flex-wrap mb-2">
            {PANEL_TYPES.map(t => (
              <button key={t.key} type="button" onClick={() => setPanelType(t.key)} className="px-2.5 py-1.5 rounded-lg text-[11px] font-bold transition-all"
                style={{ background: panelType === t.key ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: panelType === t.key ? '#fff' : 'var(--text-muted)', border: `1px solid ${panelType === t.key ? 'transparent' : 'var(--border)'}` }}>
                {t.label}
              </button>
            ))}
          </div>

          {panelType !== 'staff' && (
            <>
              <p className="text-[9px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>Step 2 · Select {typeLabel}</p>
              <select className="input-3d text-sm mb-2" value={panelOrg} onChange={e => setPanelOrg(e.target.value)}>
                <option value="">Select {typeLabel}…</option>
                {panelOrgs.map(o => <option key={o.company} value={o.company}>{o.company} ({o.members})</option>)}
              </select>
            </>
          )}
          {panelType !== 'staff' && panelOrgs.length === 0 && <p className="text-[10px] mb-1" style={{ color: 'var(--text-muted)' }}>No {typeLabel} organisations found for this tenant.</p>}

          <p className="text-[9px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>
            Step {panelType === 'staff' ? 2 : 3} · Select Interviewer
          </p>
          {panelType !== 'staff' && !panelOrg ? <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Select a {typeLabel} above to load its team.</p>
            : panelLoading ? <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Loading {typeLabel} users…</p>
            : panelUsers.length === 0 ? <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>No {typeLabel} users found{panelType !== 'staff' ? ' in this organisation' : ' for this tenant'}.</p>
            : (
              <div className="flex flex-wrap gap-1.5 max-h-28 overflow-y-auto">
                {panelUsers.map(u => {
                  const added = (form.interviewers || []).some(p => p.user_id === u.id)
                  return (
                    <button key={u.id} type="button" onClick={() => addUserPanelist(u)} disabled={added}
                      className="text-[10px] font-semibold px-2 py-1 rounded-lg flex items-center gap-1"
                      style={{ background: added ? 'rgba(16,185,129,0.12)' : 'var(--bg-input)', color: added ? '#10b981' : 'var(--text-h)', border: `1px solid ${added ? 'rgba(16,185,129,0.3)' : 'var(--border)'}`, cursor: added ? 'default' : 'pointer' }}>
                      {added ? '✓' : <Plus size={9} />} {u.name}
                    </button>
                  )
                })}
              </div>
            )}
        </div>

        {/* Selected panel */}
        <div>
          <div className="flex items-center justify-between mb-1">
            <label className="label" style={{ margin: 0 }}>Selected Interviewers (Panel)</label>
            <button type="button" onClick={addPanelist} className="text-[11px] font-bold flex items-center gap-1" style={{ color: '#a78bfa' }}><Plus size={11} /> Add manually</button>
          </div>
          {form.interviewers.length === 0 && <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Add panel members for multi-interviewer rounds.</p>}
          <div className="space-y-2">
            {form.interviewers.map((p, i) => (
              <div key={i} className="flex gap-2 items-center" style={p.is_lead ? { background: 'rgba(251,191,36,0.08)', borderRadius: 10, padding: 5, border: '1px solid rgba(251,191,36,0.25)' } : undefined}>
                {p.is_lead
                  ? <span className="text-[9px] font-bold px-1.5 py-1 rounded-lg flex-shrink-0 flex items-center gap-1" style={{ background: 'rgba(251,191,36,0.18)', color: '#f59e0b' }}><Star size={9} style={{ fill: '#f59e0b' }} /> Lead</span>
                  : p.type && <span className="text-[9px] font-bold px-1.5 py-1 rounded-lg flex-shrink-0" style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>{p.type}</span>}
                <input className="input-3d text-sm flex-1" placeholder="Name" value={p.name} onChange={e => setPanelist(i, 'name', e.target.value)} />
                <input className="input-3d text-sm flex-1" placeholder="Email (optional)" value={p.email || ''} onChange={e => setPanelist(i, 'email', e.target.value)} />
                <button type="button" onClick={() => removePanelist(i)} className="p-2 rounded-xl flex-shrink-0" style={{ color: '#f87171' }}><X size={14} /></button>
              </div>
            ))}
          </div>
        </div>

        {form.mode === 'offline'
          ? <div><label className="label">Venue *</label><input className="input-3d text-sm" placeholder="e.g. Board Room A, 3rd Floor" value={form.venue} onChange={e => setForm({ ...form, venue: e.target.value })} /></div>
          : <div><label className="label">Meeting Link (optional)</label><input className="input-3d text-sm" placeholder="Auto-generated Google Meet if left blank" value={form.meet_link} onChange={e => setForm({ ...form, meet_link: e.target.value })} /></div>}

        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Date &amp; Time *</label><DateTimePicker value={form.scheduled_at} onChange={v => setForm({ ...form, scheduled_at: v })} /></div>
          <div>
            <label className="label">Reminder</label>
            <select className="input-3d text-sm" value={reminderMode} onChange={e => applyReminder(e.target.value === 'custom' ? 'custom' : Number(e.target.value))}>
              {REMINDER_OPTIONS.map(o => <option key={o.label} value={o.v}>{o.label}</option>)}
            </select>
          </div>
        </div>
        {reminderMode === 'custom' && (
          <div className="flex items-center gap-2">
            <label className="label" style={{ margin: 0 }}>Custom — days before</label>
            <input type="number" min="1" className="input-3d text-sm" style={{ width: 90 }} value={customDays} onChange={e => applyCustomDays(e.target.value)} />
          </div>
        )}

        {isReschedule && interview && (
          <div><label className="label">Status</label><div className="text-xs font-bold px-3 py-2 rounded-xl" style={{ background: statusStyle(interview.status).bg, color: statusStyle(interview.status).color }}>{interview.status}</div></div>
        )}
      </div>
    </Drawer>
  )
}
