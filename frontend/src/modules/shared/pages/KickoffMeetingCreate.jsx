import { useState, useEffect, useCallback, useRef } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft, CalendarDays, Clock, MapPin, Users, Plus, Trash2,
  AlertTriangle, ChevronRight, Laptop, Building2, CheckCircle2,
} from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { kickoffApi } from '@/services/kickoffApi'
import { meetingApi } from '@/services/meetingApi'
import { tpvApi } from '@/services/tpvApi'
import { KO_MODES } from '../kickoffConstants'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Field, TextInput, SelectInput,
} from '@/components/ui/kit3d'
import RichTextEditor from '@/components/ui/RichTextEditor'

// ── Platform options for online meetings ─────────────────────────────────────
const PLATFORM_OPTIONS = [
  ['google_meet', 'Google Meet'],
  ['zoom',        'Zoom'],
  ['teams',       'Microsoft Teams'],
  ['stub',        'Generic Link (stub)'],
]

// ── helpers ──────────────────────────────────────────────────────────────────
const toLocalDate = (iso) => {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const p = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}
const toLocalTime = (iso) => {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const p = n => String(n).padStart(2, '0')
  return `${p(d.getHours())}:${p(d.getMinutes())}`
}
const combineDateTime = (date, time) => {
  if (!date) return ''
  return `${date}T${time || '09:00'}:00`
}

const EMPTY_MOM = () => ({ id: Date.now() + Math.random(), description: '', responsible: '', remarks: '', target_date: '' })
const EMPTY_PARTICIPANT = () => ({ id: Date.now() + Math.random(), name: '', role: '', organisation: '' })

// ── Section header matching KickoffMeetingDetail style ───────────────────────
function SectionTitle({ icon: Icon, children }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16 }}>
      <Icon size={15} style={{ color: '#a78bfa' }} />
      <h2 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{children}</h2>
    </div>
  )
}

// ── Error banner ─────────────────────────────────────────────────────────────
function ErrBanner({ msg }) {
  if (!msg) return null
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 16, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
      <AlertTriangle size={15} style={{ color: '#ef4444', flexShrink: 0 }} />
      <span style={{ fontSize: 13, color: 'var(--text-h)' }}>{msg}</span>
    </div>
  )
}

// ── Main page component ───────────────────────────────────────────────────────
/**
 * Full-page "New Kickoff Meeting" form.
 *
 * Replaces the old MeetingModal popup in KickoffMeetings.jsx.
 * Calls kickoffApi.schedule() with the same payload — no backend changes.
 */
export default function KickoffMeetingCreate() {
  const navigate = useNavigate()
  const { user } = useAuth()

  // ── data sources ────────────────────────────────────────────────────────
  const [vendors, setVendors]   = useState([])
  const [contacts, setContacts] = useState([])   // vendor contacts for participant picker

  // Optional ?vendor=<id> prefill — lets callers (e.g. Purchase Vendors) open this
  // page pre-scoped to a specific vendor. Backward compatible: no param → unchanged.
  const [searchParams] = useSearchParams()
  const preVendorId = searchParams.get('vendor')

  useEffect(() => {
    tpvApi.vendors.list().then(r => {
      const list = r?.data ?? r ?? []
      // A vendor passed via ?vendor= may not be in the (TPV-filtered) picker — fetch
      // it from the shared master and merge so it can be selected.
      if (preVendorId && !list.some(v => String(v.id) === String(preVendorId))) {
        tpvApi.vendors.get(preVendorId)
          .then(res => { const v = res?.data ?? res; setVendors(v?.id ? [v, ...list] : list) })
          .catch(() => setVendors(list))
      } else {
        setVendors(list)
      }
    }).catch(() => {})
    if (preVendorId) {
      setForm(f => ({ ...f, subject_id: preVendorId }))
      tpvApi.contacts.list(preVendorId).then(r => setContacts(r?.data ?? r)).catch(() => {})
    }
  }, [preVendorId])

  // ── form state ──────────────────────────────────────────────────────────
  const [form, setForm] = useState({
    subject_id:       '',
    title:            '',
    meeting_date:     '',
    meeting_time:     '09:00',
    planned_date:     '',
    duration_minutes: 60,
    mode:             'onsite',
    location:         '',   // City / Location
    location_detail:  '',   // Venue / Address
    agenda:           '',
    is_completed:     false,
    meeting_platform: 'stub',  // used when mode = 'online'
  })
  const [participants, setParticipants] = useState([])  // [{ id, name, role, organisation }]
  const [momItems,     setMomItems]     = useState([])  // [{ id, description, responsible, remarks, target_date }]

  // Tenant default meeting platform: prefills the picker, and an admin can
  // point it at whatever they just chose.
  const [defaultPlatform, setDefaultPlatform] = useState(null)
  const [savingPlatform, setSavingPlatform]   = useState(false)

  const [saving, setSaving]           = useState(false)
  const [err,    setErr]              = useState(null)
  const [generatingLink, setGenLink]  = useState(false)  // post-save link generation

  // ── Fetch tenant default platform preference on mount ────────────────────
  useEffect(() => {
    meetingApi.getDefaultPlatform().then(d => {
      if (d?.platform) {
        setDefaultPlatform(d.platform)
        setForm(f => ({ ...f, meeting_platform: d.platform }))
      }
    }).catch(() => {})
  }, [])

  // ── load vendor contacts when vendor changes ─────────────────────────────
  const loadContacts = useCallback((vendorId) => {
    if (!vendorId) { setContacts([]); return }
    tpvApi.contacts.list(vendorId).then(r => setContacts(r?.data ?? r)).catch(() => setContacts([]))
  }, [])

  const set = (k) => (e) => {
    const val = e.target.type === 'checkbox' ? e.target.checked : e.target.value
    setForm(f => ({ ...f, [k]: val }))
    if (k === 'subject_id') loadContacts(e.target.value)
  }

  // ── participants helpers ─────────────────────────────────────────────────
  const addParticipant = () => setParticipants(p => [...p, EMPTY_PARTICIPANT()])
  const removeParticipant = (id) => setParticipants(p => p.filter(x => x.id !== id))
  const setParticipant = (id, k, v) =>
    setParticipants(p => p.map(x => x.id === id ? { ...x, [k]: v } : x))

  // quick-add from vendor contact dropdown
  const addFromContact = (contactId) => {
    const c = contacts.find(x => String(x.id) === String(contactId))
    if (!c) return
    if (participants.some(p => p.name === c.full_name)) return  // already added
    setParticipants(p => [...p, {
      id:           Date.now() + Math.random(),
      name:         c.full_name ?? '',
      role:         c.designation ?? '',
      organisation: c.company_name ?? '',
    }])
  }

  // ── MOM helpers ─────────────────────────────────────────────────────────
  const addMom    = () => setMomItems(m => [...m, EMPTY_MOM()])
  const removeMom = (id) => setMomItems(m => m.filter(x => x.id !== id))
  const setMom    = (id, k, v) =>
    setMomItems(m => m.map(x => x.id === id ? { ...x, [k]: v } : x))

  // ── save ────────────────────────────────────────────────────────────────
  const save = async () => {
    if (!form.subject_id)   { setErr('Please select a Third Party Vendor.'); return }
    if (!form.meeting_date) { setErr('Meeting Date is required.'); return }
    // Location only required for on-site meetings
    if (form.mode !== 'online' && !form.location) { setErr('City / Location is required.'); return }
    setSaving(true); setErr(null)
    try {
      const scheduled_at = combineDateTime(form.meeting_date, form.meeting_time)
      const payload = {
        subject_type:     'vendor',
        subject_id:       form.subject_id,
        title:            form.title || undefined,
        scheduled_at,
        planned_date:     form.planned_date || undefined,
        duration_minutes: Number(form.duration_minutes) || undefined,
        mode:             form.mode,
        location:         form.mode !== 'online' ? form.location         : undefined,
        location_detail:  form.mode !== 'online' ? form.location_detail  : undefined,
        agenda:           form.agenda || undefined,
        is_completed:     form.is_completed,
        // Extended fields — backend uses what it knows, ignores the rest
        attendees: participants
          .filter(p => p.name.trim())
          .map(({ name, role, organisation }) => ({ name, role, organisation })),
        mom_items: momItems
          .filter(m => m.description.replace(/<[^>]*>/g, '').trim())
          .map(({ description, responsible, remarks, target_date }) =>
            ({ description, responsible, remarks, target_date: target_date || undefined })),
      }
      const created = await kickoffApi.schedule(payload)
      const newId = (created?.data ?? created)?.id

      // Auto-generate online meeting link immediately after save
      if (newId && form.mode === 'online') {
        setGenLink(true)
        try {
          await meetingApi.generateLink(newId, form.meeting_platform)
        } catch (_) {
          // Non-fatal — the detail page shows a "Generate link" button as fallback
        } finally {
          setGenLink(false)
        }
      }

      navigate(newId ? `/app/tpv/kickoff/${newId}` : '/app/tpv/kickoff')
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save the meeting.')
      setSaving(false)
    }
  }

  // ── render ───────────────────────────────────────────────────────────────
  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <style>{`@keyframes koSpin{to{transform:rotate(360deg)}}.ko-spin{animation:koSpin .9s linear infinite}`}</style>

      {/* ── Page Header ──────────────────────────────────────────────── */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 22, flexWrap: 'wrap', gap: 14 }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
          <button onClick={() => navigate('/app/tpv/kickoff')}
            style={{ width: 34, height: 34, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 3, flexShrink: 0 }}>
            <ArrowLeft size={16} />
          </button>
          <div>
            {/* Breadcrumb */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--text-muted)', marginBottom: 4 }}>
              <span style={{ cursor: 'pointer', color: '#a78bfa' }} onClick={() => navigate('/app/tpv/kickoff')}>Kickoff Meetings</span>
              <ChevronRight size={12} />
              <span>Create New</span>
            </div>
            <h1 style={{ color: 'var(--text-h)', fontSize: 23, fontWeight: 900, margin: 0, letterSpacing: '-0.02em' }}>
              New Kickoff Meeting
            </h1>
            <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>
              Schedule a pre-onboarding meeting with a third-party vendor.
            </p>
          </div>
        </div>

        {/* Meeting Completed toggle */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 16px', borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <CheckCircle2 size={16} style={{ color: form.is_completed ? '#10b981' : 'var(--text-muted)' }} />
          <span style={{ fontSize: 13, fontWeight: 600, color: form.is_completed ? '#10b981' : 'var(--text-muted)' }}>
            Meeting Completed
          </span>
          <button
            onClick={() => setForm(f => ({ ...f, is_completed: !f.is_completed }))}
            style={{
              width: 44, height: 24, borderRadius: 999, border: 'none', cursor: 'pointer', padding: 0,
              background: form.is_completed ? 'linear-gradient(135deg,#10b981,#059669)' : 'var(--bg-input)',
              position: 'relative', transition: 'background .2s ease', flexShrink: 0,
              boxShadow: form.is_completed ? '0 4px 12px rgba(16,185,129,0.4)' : 'none',
            }}>
            <span style={{
              position: 'absolute', top: 3, left: form.is_completed ? 23 : 3,
              width: 18, height: 18, borderRadius: '50%', background: '#fff',
              transition: 'left .2s cubic-bezier(.34,1.56,.64,1)',
              boxShadow: '0 1px 4px rgba(0,0,0,0.3)',
            }} />
          </button>
        </div>
      </div>

      <ErrBanner msg={err} />

      {/* ── Two-column layout ────────────────────────────────────────── */}
      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }}>

        {/* ── LEFT COLUMN ────────────────────────────────────────────── */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>

          {/* Section 1 — Vendor & Participants */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={Users}>Vendor &amp; Participants</SectionTitle>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <Field label="Third Party Vendor *">
                <SelectInput value={form.subject_id} onChange={set('subject_id')} pairs
                  options={[['', 'Select a vendor…'], ...vendors.map(v => [v.id, v.company_name])]} />
              </Field>

              <Field label="Meeting Title (optional — defaults to vendor name)">
                <TextInput value={form.title} onChange={set('title')} placeholder="e.g. Kickoff — Acme Contractors" />
              </Field>

              {/* Participants */}
              <div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                  <label style={labelStyle}>Participants</label>
                  <div style={{ display: 'flex', gap: 8 }}>
                    {/* Quick-add from vendor contacts */}
                    {contacts.length > 0 && (
                      <select
                        onChange={e => { addFromContact(e.target.value); e.target.value = '' }}
                        style={{ ...inputStyle, width: 'auto', fontSize: 12, padding: '5px 10px' }}>
                        <option value="">+ Add from contacts</option>
                        {contacts.map(c => (
                          <option key={c.id} value={c.id}>{c.full_name} ({c.designation || 'Contact'})</option>
                        ))}
                      </select>
                    )}
                    <button onClick={addParticipant} style={addBtn}>
                      <Plus size={13} /> Add Person
                    </button>
                  </div>
                </div>

                {participants.length === 0 ? (
                  <div style={{ padding: '16px', borderRadius: 10, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center' }}>
                    <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>
                      No participants added yet. Click <strong>Add Person</strong> or pick from vendor contacts above.
                    </p>
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    {participants.map((p, i) => (
                      <div key={p.id} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: 8, padding: '12px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', alignItems: 'end' }}>
                        <Field label={`Name ${i + 1}`}>
                          <TextInput value={p.name} onChange={e => setParticipant(p.id, 'name', e.target.value)} placeholder="Full name" />
                        </Field>
                        <Field label="Role / Designation">
                          <TextInput value={p.role} onChange={e => setParticipant(p.id, 'role', e.target.value)} placeholder="e.g. Site Engineer" />
                        </Field>
                        <Field label="Organisation">
                          <TextInput value={p.organisation} onChange={e => setParticipant(p.id, 'organisation', e.target.value)} placeholder="Company name" />
                        </Field>
                        <button onClick={() => removeParticipant(p.id)}
                          title="Remove participant"
                          style={{ width: 32, height: 32, borderRadius: 8, border: '1px solid rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)', color: '#ef4444', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, marginBottom: 1 }}>
                          <Trash2 size={13} />
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Section 2 — Schedule & Location */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={CalendarDays}>Schedule &amp; Location</SectionTitle>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
              <Field label="Meeting Date *">
                <TextInput type="date" value={form.meeting_date} onChange={set('meeting_date')} />
              </Field>
              <Field label="Meeting Time *">
                <TextInput type="time" value={form.meeting_time} onChange={set('meeting_time')} />
              </Field>
              <Field label="City / Location *">
                <TextInput value={form.location} onChange={set('location')} placeholder="e.g. Mumbai" />
              </Field>
              <Field label="Venue / Address">
                <TextInput value={form.location_detail} onChange={set('location_detail')} placeholder="e.g. Site office, Gate 1, Building A" />
              </Field>
              <Field label="Planned Date (optional)">
                <TextInput type="date" value={form.planned_date} onChange={set('planned_date')} />
              </Field>
              <Field label="Duration (minutes)">
                <TextInput type="number" min={15} step={15} value={form.duration_minutes} onChange={set('duration_minutes')} placeholder="60" />
              </Field>
            </div>
          </div>

          {/* Section 3 — Meeting Mode */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={MapPin}>Meeting Mode</SectionTitle>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              {KO_MODES.map(([val, label]) => {
                const on = form.mode === val
                const Icon = val === 'online' ? Laptop : Building2
                return (
                  <button key={val} type="button" onClick={() => setForm(f => ({ ...f, mode: val }))}
                    className="pr-node"
                    style={{
                      display: 'flex', alignItems: 'center', gap: 12, padding: '14px 16px', borderRadius: 14,
                      cursor: 'pointer', border: `1.5px solid ${on ? '#7C3AED' : 'var(--border)'}`,
                      background: on ? 'linear-gradient(135deg,rgba(124,58,237,0.14),rgba(124,58,237,0.06))' : 'var(--bg-input)',
                      boxShadow: on ? '0 8px 22px -10px rgba(124,58,237,0.5)' : 'none',
                      color: on ? '#a78bfa' : 'var(--text-muted)',
                      fontWeight: 700, fontSize: 14,
                    }}>
                    <span style={{ width: 38, height: 38, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', background: on ? 'rgba(124,58,237,0.2)' : 'var(--bg-card)', flexShrink: 0 }}>
                      <Icon size={18} />
                    </span>
                    <span>{label}</span>
                    {on && <CheckCircle2 size={16} style={{ marginLeft: 'auto', color: '#7C3AED' }} />}
                  </button>
                )
              })}
            </div>

            {/* Conditional: location fields (on-site) OR platform picker (online) */}
            {form.mode !== 'online' ? (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 16 }}>
                <Field label="City / Location *">
                  <TextInput value={form.location} onChange={set('location')} placeholder="e.g. Mumbai" />
                </Field>
                <Field label="Venue / Address">
                  <TextInput value={form.location_detail} onChange={set('location_detail')} placeholder="e.g. Site office, Gate 1" />
                </Field>
              </div>
            ) : (
              <div style={{ marginTop: 16 }}>
                <Field label="Meeting Platform">
                  <SelectInput
                    value={form.meeting_platform}
                    onChange={set('meeting_platform')}
                    pairs
                    options={PLATFORM_OPTIONS}
                  />
                </Field>

                {/* Admins can make the current choice the tenant-wide default,
                    which is what prefills this field on the next meeting. */}
                {user?.role === 'admin' && (
                  <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 10 }}>
                    <button type="button" disabled={savingPlatform || form.meeting_platform === defaultPlatform}
                      onClick={async () => {
                        setSavingPlatform(true)
                        try {
                          await meetingApi.savePlatformSetting(form.meeting_platform)
                          setDefaultPlatform(form.meeting_platform)
                        } catch { /* leave the default as it was */ }
                        finally { setSavingPlatform(false) }
                      }}
                      style={{
                        padding: '6px 11px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: 'pointer',
                        background: 'transparent', border: '1px solid var(--border)',
                        color: form.meeting_platform === defaultPlatform ? 'var(--text-muted)' : '#a78bfa',
                        opacity: savingPlatform ? 0.6 : 1,
                      }}>
                      {form.meeting_platform === defaultPlatform ? 'This is the default' : 'Set as default'}
                    </button>
                    {savingPlatform && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Saving…</span>}
                  </div>
                )}
                <div style={{ marginTop: 10, display: 'flex', alignItems: 'center', gap: 8, padding: '10px 13px', borderRadius: 10, background: 'rgba(124,58,237,0.07)', border: '1px solid rgba(124,58,237,0.22)' }}>
                  <Laptop size={13} style={{ color: '#a78bfa', flexShrink: 0 }} />
                  <span style={{ fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.45 }}>
                    A meeting link will be <strong style={{ color: 'var(--text-h)' }}>automatically generated</strong> when you save.
                    {generatingLink && <span style={{ color: '#a78bfa', marginLeft: 6 }}>Generating link…</span>}
                  </span>
                </div>
              </div>
            )}

            {/* Agenda */}
            <div style={{ marginTop: 16 }}>
              <Field label="Agenda">
                <textarea
                  value={form.agenda}
                  onChange={set('agenda')}
                  rows={3}
                  placeholder="HSSE induction, scope walk, document checklist…"
                  style={{ ...inputStyle, resize: 'vertical', fontFamily: 'inherit', lineHeight: 1.5 }}
                />
              </Field>
            </div>
          </div>

          {/* Section 4 — Minutes of Meeting (MOM) */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
              <SectionTitle icon={Clock}>Minutes of Meeting (MOM)</SectionTitle>
              <button onClick={addMom} style={addBtn}>
                <Plus size={13} /> Add Item
              </button>
            </div>

            {momItems.length === 0 ? (
              <div style={{ padding: '20px', borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center' }}>
                <Clock size={22} style={{ color: 'var(--text-muted)', opacity: 0.4, marginBottom: 6 }} />
                <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>
                  No MOM items yet. Click <strong>Add Item</strong> to capture action points.
                </p>
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                {momItems.map((item, i) => (
                  <div key={item.id} style={{ padding: '16px', borderRadius: 14, background: 'var(--bg-input)', border: '1px solid var(--border)', position: 'relative' }}>
                    {/* Item header */}
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
                      <span style={{ fontSize: 11, fontWeight: 800, color: '#a78bfa', textTransform: 'uppercase', letterSpacing: '0.06em' }}>Item {i + 1}</span>
                      <button onClick={() => removeMom(item.id)}
                        title="Remove item"
                        style={{ width: 28, height: 28, borderRadius: 8, border: '1px solid rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)', color: '#ef4444', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                        <Trash2 size={12} />
                      </button>
                    </div>

                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                      {/* Description — full width, rich text */}
                      <div style={{ gridColumn: '1/-1' }}>
                        <label style={{ ...labelStyle, display: 'block', marginBottom: 6 }}>Description *</label>
                        <RichTextEditor
                          value={item.description}
                          onChange={v => setMom(item.id, 'description', v)}
                          placeholder="Describe the action point, decision, or discussion item…"
                          minHeight={100}
                        />
                      </div>

                      {/* Responsible Person — searchable multi-select */}
                      <Field label="Responsible Person">
                        <ResponsiblePicker
                          value={item.responsible}
                          onChange={v => setMom(item.id, 'responsible', v)}
                          contacts={contacts}
                        />
                      </Field>

                      {/* Target Date */}
                      <Field label="Target Date">
                        <TextInput
                          type="date"
                          value={item.target_date}
                          onChange={e => setMom(item.id, 'target_date', e.target.value)}
                        />
                      </Field>

                      {/* Remarks — full width, rich text */}
                      <div style={{ gridColumn: '1/-1' }}>
                        <label style={{ ...labelStyle, display: 'block', marginBottom: 6 }}>Remarks / Notes</label>
                        <RichTextEditor
                          value={item.remarks}
                          onChange={v => setMom(item.id, 'remarks', v)}
                          placeholder="Any additional notes or context…"
                          minHeight={90}
                        />
                      </div>
                    </div>
                  </div>
                ))}

                {/* Add another item button at the bottom */}
                <button onClick={addMom} style={{ ...addBtn, justifyContent: 'center', width: '100%' }}>
                  <Plus size={13} /> Add Another Item
                </button>
              </div>
            )}
          </div>

        </div>{/* end left column */}

        {/* ── RIGHT COLUMN — summary card ─────────────────────────────── */}
        <div style={{ position: 'sticky', top: 16 }}>
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={CalendarDays}>Meeting Summary</SectionTitle>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <SummaryRow label="Vendor">
                {form.subject_id
                  ? (vendors.find(v => String(v.id) === String(form.subject_id))?.company_name || '—')
                  : <span style={{ color: 'var(--text-muted)', fontStyle: 'italic' }}>Not selected</span>}
              </SummaryRow>

              <SummaryRow label="Date &amp; Time">
                {form.meeting_date
                  ? `${form.meeting_date} at ${form.meeting_time}`
                  : <span style={{ color: 'var(--text-muted)', fontStyle: 'italic' }}>Not set</span>}
              </SummaryRow>

              <SummaryRow label="Mode">
                {KO_MODES.find(([v]) => v === form.mode)?.[1] || '—'}
              </SummaryRow>

              <SummaryRow label="Location">
                {form.location || <span style={{ color: 'var(--text-muted)', fontStyle: 'italic' }}>Not set</span>}
              </SummaryRow>

              {form.location_detail && (
                <SummaryRow label="Venue">{form.location_detail}</SummaryRow>
              )}

              <SummaryRow label="Duration">
                {form.duration_minutes ? `${form.duration_minutes} min` : '—'}
              </SummaryRow>

              <SummaryRow label="Participants">
                {participants.filter(p => p.name.trim()).length || 0}
              </SummaryRow>

              <SummaryRow label="MOM Items">
                {momItems.filter(m => m.description.replace(/<[^>]*>/g, '').trim()).length || 0}
              </SummaryRow>

              {form.is_completed && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 10, background: 'rgba(16,185,129,0.1)', border: '1px solid rgba(16,185,129,0.3)' }}>
                  <CheckCircle2 size={13} style={{ color: '#10b981', flexShrink: 0 }} />
                  <span style={{ fontSize: 12, fontWeight: 700, color: '#10b981' }}>Marked as Completed</span>
                </div>
              )}
            </div>

            {/* ── Bottom buttons ─────────────────────────────────────── */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 22, borderTop: '1px solid var(--border)', paddingTop: 18 }}>
              {err && (
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 7, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
                  <AlertTriangle size={13} style={{ color: '#ef4444', flexShrink: 0, marginTop: 1 }} />
                  <span style={{ fontSize: 12, color: 'var(--text-h)' }}>{err}</span>
                </div>
              )}
              <button onClick={save} disabled={saving || generatingLink}
                style={{
                  display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 7,
                  padding: '11px 20px', borderRadius: 11, border: 'none', cursor: (saving || generatingLink) ? 'wait' : 'pointer',
                  fontSize: 13.5, fontWeight: 800, color: '#fff',
                  background: (saving || generatingLink) ? 'rgba(124,58,237,0.5)' : 'linear-gradient(145deg,#a78bfa,#7C3AED)',
                  boxShadow: (saving || generatingLink) ? 'none' : '0 8px 22px -6px rgba(124,58,237,.6)',
                }}>
                {generatingLink ? 'Generating link…' : saving ? 'Saving…' : 'Save Meeting'}
              </button>
              <button onClick={() => navigate('/app/tpv/kickoff')} disabled={saving}
                style={{
                  display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6,
                  padding: '10px 20px', borderRadius: 11, cursor: 'pointer', fontSize: 13, fontWeight: 600,
                  color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)',
                }}>
                Cancel
              </button>
            </div>
          </div>
        </div>{/* end right column */}

      </div>{/* end grid */}
    </div>
  )
}

// ── tiny summary row ──────────────────────────────────────────────────────────
function SummaryRow({ label, children }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 9 }}>
      <span style={{ color: 'var(--text-muted)', fontSize: 12, flexShrink: 0 }}>{label}</span>
      <span style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)', textAlign: 'right' }}>{children}</span>
    </div>
  )
}

// ── ResponsiblePicker — searchable multi-select for MOM responsible persons ──
/**
 * Supports:
 * - Free-text entry (type a name + press Enter or comma)
 * - Quick-pick from vendor contacts dropdown (shown when vendor is selected)
 * - Selected names as removable chips
 * - value is a comma-separated string to keep API payload simple
 */
function ResponsiblePicker({ value, onChange, contacts }) {
  const [inputVal, setInputVal] = useState('')
  const [showDrop, setShowDrop] = useState(false)
  const inputRef = useRef(null)

  const selected = value ? value.split(',').map(s => s.trim()).filter(Boolean) : []

  const add = (name) => {
    const n = name.trim()
    if (!n || selected.includes(n)) return
    onChange([...selected, n].join(', '))
    setInputVal('')
  }

  const remove = (name) => {
    onChange(selected.filter(s => s !== name).join(', '))
  }

  // filtered contacts not already picked
  const filteredContacts = contacts.filter(c => {
    const nm = c.full_name ?? ''
    if (selected.includes(nm)) return false
    if (!inputVal) return true
    return nm.toLowerCase().includes(inputVal.toLowerCase())
  })

  const handleKey = (e) => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault()
      add(inputVal)
    }
    if (e.key === 'Backspace' && !inputVal && selected.length) {
      onChange(selected.slice(0, -1).join(', '))
    }
  }

  return (
    <div style={{ position: 'relative' }}>
      {/* Chip + input container */}
      <div
        onClick={() => inputRef.current?.focus()}
        style={{
          display: 'flex', flexWrap: 'wrap', gap: 5, alignItems: 'center',
          padding: '6px 10px', borderRadius: 8, cursor: 'text',
          background: 'var(--bg-input)', border: '1px solid var(--border)',
          minHeight: 38,
        }}>
        {selected.map(name => (
          <span key={name} style={{
            display: 'inline-flex', alignItems: 'center', gap: 4,
            padding: '2px 8px 2px 10px', borderRadius: 999, fontSize: 11.5, fontWeight: 600,
            background: 'rgba(124,58,237,0.14)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.3)',
          }}>
            {name}
            <button
              type="button"
              onClick={e => { e.stopPropagation(); remove(name) }}
              style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#a78bfa', padding: 0, lineHeight: 1, fontSize: 13, fontWeight: 800 }}>
              ×
            </button>
          </span>
        ))}
        <input
          ref={inputRef}
          value={inputVal}
          onChange={e => { setInputVal(e.target.value); setShowDrop(true) }}
          onFocus={() => setShowDrop(true)}
          onBlur={() => setTimeout(() => setShowDrop(false), 150)}
          onKeyDown={handleKey}
          placeholder={selected.length ? '' : 'Type a name or pick from contacts…'}
          style={{
            border: 'none', outline: 'none', background: 'transparent',
            color: 'var(--text-h)', fontSize: 12.5, minWidth: 120, flex: 1,
          }}
        />
      </div>

      {/* Dropdown */}
      {showDrop && (inputVal || contacts.length > 0) && (
        <div style={{
          position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 100,
          borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)',
          boxShadow: '0 8px 24px rgba(0,0,0,0.25)', marginTop: 4, maxHeight: 200, overflowY: 'auto',
        }}>
          {/* Free-text option */}
          {inputVal.trim() && !selected.includes(inputVal.trim()) && (
            <button
              type="button"
              onMouseDown={() => add(inputVal)}
              style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '9px 12px', border: 'none', background: 'transparent', cursor: 'pointer', color: '#a78bfa', fontSize: 12.5, textAlign: 'left' }}>
              <Plus size={13} /> Add "{inputVal.trim()}"
            </button>
          )}
          {/* Contact suggestions */}
          {filteredContacts.slice(0, 10).map(c => (
            <button
              key={c.id}
              type="button"
              onMouseDown={() => add(c.full_name)}
              style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', width: '100%', padding: '8px 12px', border: 'none', background: 'transparent', cursor: 'pointer', borderTop: '1px solid var(--border)' }}>
              <span style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)' }}>{c.full_name}</span>
              {c.designation && <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>{c.designation}</span>}
            </button>
          ))}
          {filteredContacts.length === 0 && !inputVal && (
            <p style={{ padding: '10px 12px', fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>
              Type a name to add a custom entry.
            </p>
          )}
        </div>
      )}
    </div>
  )
}

// ── style tokens ──────────────────────────────────────────────────────────────
const addBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 13px',
  borderRadius: 9, border: '1px solid rgba(124,58,237,0.35)',
  background: 'rgba(124,58,237,0.08)', color: '#a78bfa',
  cursor: 'pointer', fontSize: 12.5, fontWeight: 700, whiteSpace: 'nowrap',
}
