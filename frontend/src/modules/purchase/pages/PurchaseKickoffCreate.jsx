import { useState, useEffect, useCallback } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft, CalendarDays, Users, Plus, Trash2, AlertTriangle, Loader2, UserPlus,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { PK_MODES } from '@/modules/purchase/kickoffConstants'
import { KIT3D_STYLE, Field, TextInput, SelectInput } from '@/components/ui/kit3d'

/**
 * Purchase kickoff — schedule a new meeting against a Purchase vendor. Consumes
 * ONLY purchaseApi (kickoff / vendors / contacts). Purchase-owned: no TPV imports,
 * no shared kickoff page/component.
 */
export default function PurchaseKickoffCreate() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const preVendorId = searchParams.get('vendor')

  const [vendors, setVendors]   = useState([])
  const [contacts, setContacts] = useState([])
  const [meetingTypes, setMeetingTypes] = useState({ kickoff: 'Kickoff Meeting' })
  const [saving, setSaving]     = useState(false)
  const [err, setErr]           = useState(null)

  const [form, setForm] = useState({
    purchase_vendor_id: preVendorId || '',
    meeting_type: 'kickoff',
    title: '',
    reference: '',
    scheduled_at: '',
    end_at: '',
    duration_minutes: 60,
    mode: 'onsite',
    location: '',
    priority: '',
    confidentiality: '',
    chairperson: '',
    coordinator: '',
    organizer: '',
    department: '',
    client_name: '',
    agenda: '',
  })
  const [participants, setParticipants] = useState([]) // {key, purchase_contact_id?, name, email, role, organisation}
  const [manual, setManual] = useState({ name: '', email: '', role: '', organisation: '' })

  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))

  useEffect(() => {
    purchaseApi.vendors.list().then(r => setVendors(r?.data ?? r ?? [])).catch(() => {})
    purchaseApi.kickoff.meetingTypes()
      .then(r => { if (r?.types) setMeetingTypes(r.types) })
      .catch(() => {})
  }, [])

  // Load the selected vendor's contacts for the participant picker.
  const loadContacts = useCallback((vendorId) => {
    if (!vendorId) { setContacts([]); return }
    purchaseApi.contacts.list(vendorId).then(r => setContacts(r?.data ?? r ?? [])).catch(() => setContacts([]))
  }, [])
  useEffect(() => { loadContacts(form.purchase_vendor_id) }, [form.purchase_vendor_id, loadContacts])

  const contactName = (c) => c.full_name || [c.first_name, c.last_name].filter(Boolean).join(' ') || c.name || 'Contact'
  const hasContact  = (id) => participants.some(p => p.purchase_contact_id === id)

  const addContact = (c) => {
    if (hasContact(c.id)) return
    setParticipants(ps => [...ps, {
      key: `c${c.id}`, purchase_contact_id: c.id,
      name: contactName(c), email: c.email || '', role: c.designation || '', organisation: '',
    }])
  }
  const addManual = () => {
    if (!manual.name.trim()) return
    setParticipants(ps => [...ps, { key: `m${Date.now()}`, ...manual }])
    setManual({ name: '', email: '', role: '', organisation: '' })
  }
  const removeParticipant = (key) => setParticipants(ps => ps.filter(p => p.key !== key))

  const save = async () => {
    if (!form.purchase_vendor_id) { setErr('Select a purchase vendor.'); return }
    setSaving(true); setErr(null)
    try {
      const payload = {
        purchase_vendor_id: Number(form.purchase_vendor_id),
        meeting_type: form.meeting_type || 'kickoff',
        title: form.title || undefined,
        reference: form.reference || undefined,
        agenda: form.agenda || undefined,
        scheduled_at: form.scheduled_at || undefined,
        end_at: form.end_at || undefined,
        duration_minutes: Number(form.duration_minutes) || undefined,
        mode: form.mode,
        location: form.location || undefined,
        priority: form.priority || undefined,
        confidentiality: form.confidentiality || undefined,
        chairperson: form.chairperson || undefined,
        coordinator: form.coordinator || undefined,
        organizer: form.organizer || undefined,
        department: form.department || undefined,
        client_name: form.client_name || undefined,
        participants: participants.map(p => ({
          purchase_contact_id: p.purchase_contact_id || undefined,
          name: p.name || undefined,
          email: p.email || undefined,
          role: p.role || undefined,
          organisation: p.organisation || undefined,
        })),
      }
      const created = await purchaseApi.kickoff.create(payload)
      const m = created?.data ?? created
      navigate(m?.id ? `/app/purchase/kickoff/${m.id}` : '/app/purchase/kickoff')
    } catch (e) {
      setErr(e?.response?.data?.message || Object.values(e?.response?.data?.errors || {})[0]?.[0] || 'Could not schedule the meeting.')
      setSaving(false)
    }
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <style>{`@keyframes pkSpin{to{transform:rotate(360deg)}}.pk-spin{animation:pkSpin .9s linear infinite}`}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18 }}>
        <button onClick={() => navigate('/app/purchase/kickoff')} style={{ width: 34, height: 34, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 3, flexShrink: 0 }}>
          <ArrowLeft size={16} />
        </button>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>MEETINGS</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 23, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Schedule Meeting</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Kickoff, vendor review, HSE and more — pick a meeting type. Kickoff is the pre-onboarding meeting.</p>
        </div>
      </div>

      {err && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 16, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
          <AlertTriangle size={15} style={{ color: '#ef4444', flexShrink: 0 }} />
          <span style={{ fontSize: 13, color: 'var(--text-h)' }}>{err}</span>
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }}>
        {/* Left — meeting details */}
        <div className="pr-glass" style={{ padding: 22 }}>
          <SectionTitle icon={CalendarDays}>Meeting details</SectionTitle>
          <div style={{ marginTop: 14 }}>
            <Field label="Purchase Vendor *" full>
              <SelectInput value={form.purchase_vendor_id} onChange={set('purchase_vendor_id')} pairs
                options={[['', 'Select a purchase vendor…'], ...vendors.map(v => [String(v.id), v.company_name || v.purchase_vendor_code || `Vendor #${v.id}`])]} />
            </Field>
            <Field label="Meeting Type *" full>
              <SelectInput value={form.meeting_type} onChange={set('meeting_type')} pairs
                options={Object.entries(meetingTypes).map(([k, label]) => [k, label])} />
            </Field>
            <Field label="Title (optional — defaults to the vendor name)" full>
              <TextInput value={form.title} onChange={set('title')} placeholder="Kickoff — Acme Supplies" />
            </Field>
            <Field label="Reference (optional)" full>
              <TextInput value={form.reference} onChange={set('reference')} placeholder="e.g. PO-2026-0042" />
            </Field>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12 }}>
              <Field label="Start"><TextInput type="datetime-local" value={form.scheduled_at} onChange={set('scheduled_at')} /></Field>
              <Field label="End"><TextInput type="datetime-local" value={form.end_at} onChange={set('end_at')} /></Field>
              <Field label="Duration (min)"><TextInput type="number" value={form.duration_minutes} onChange={set('duration_minutes')} /></Field>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.6fr', gap: 12 }}>
              <Field label="Mode"><SelectInput value={form.mode} onChange={set('mode')} options={PK_MODES} pairs /></Field>
              <Field label={form.mode === 'online' ? 'Meeting link' : 'Location'}>
                <TextInput value={form.location} onChange={set('location')} placeholder={form.mode === 'online' ? 'https://…' : 'Site office, Gate 1'} />
              </Field>
            </div>
            {/* Governance meta (Meeting.docx §2) */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              <Field label="Priority"><SelectInput value={form.priority} onChange={set('priority')} pairs options={[['', '—'], ['Low', 'Low'], ['Medium', 'Medium'], ['High', 'High'], ['Urgent', 'Urgent']]} /></Field>
              <Field label="Confidentiality"><SelectInput value={form.confidentiality} onChange={set('confidentiality')} pairs options={[['', '—'], ['Public', 'Public'], ['Internal', 'Internal'], ['Confidential', 'Confidential'], ['Restricted', 'Restricted']]} /></Field>
              <Field label="Chairperson"><TextInput value={form.chairperson} onChange={set('chairperson')} placeholder="Chairperson" /></Field>
              <Field label="Coordinator"><TextInput value={form.coordinator} onChange={set('coordinator')} placeholder="Coordinator" /></Field>
              <Field label="Organizer"><TextInput value={form.organizer} onChange={set('organizer')} placeholder="Organizer" /></Field>
              <Field label="Department"><TextInput value={form.department} onChange={set('department')} placeholder="Department" /></Field>
              <Field label="Client (optional)" full><TextInput value={form.client_name} onChange={set('client_name')} placeholder="Client name" /></Field>
            </div>
            <Field label="Agenda" full>
              <textarea value={form.agenda} onChange={set('agenda')} rows={3} placeholder="Scope walk, commercial terms, document checklist"
                style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
            </Field>
          </div>
        </div>

        {/* Right — participants */}
        <div className="pr-glass" style={{ padding: 22 }}>
          <SectionTitle icon={Users}>Participants <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {participants.length}</span></SectionTitle>

          {/* Chosen participants */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 12 }}>
            {participants.length === 0 && (
              <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>No participants yet. Add from vendor contacts or enter manually.</p>
            )}
            {participants.map(p => (
              <div key={p.key} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 11px', borderRadius: 11, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div style={{ width: 30, height: 30, borderRadius: '50%', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>
                  {(p.name || '?').charAt(0).toUpperCase()}
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{p.name}
                    {p.purchase_contact_id && <span title="Linked to a vendor contact" style={{ marginLeft: 6, fontSize: 10, fontWeight: 700, color: '#10b981' }}>● linked</span>}
                  </div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{[p.role, p.email].filter(Boolean).join(' · ') || '—'}</div>
                </div>
                <button onClick={() => removeParticipant(p.key)} title="Remove"
                  style={{ width: 28, height: 28, borderRadius: 8, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg-card)', border: '1px solid var(--border)', color: '#ef4444' }}>
                  <Trash2 size={13} />
                </button>
              </div>
            ))}
          </div>

          {/* Vendor contacts */}
          {form.purchase_vendor_id && contacts.length > 0 && (
            <div style={{ marginTop: 16 }}>
              <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 7 }}>From vendor contacts</div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 7 }}>
                {contacts.map(c => (
                  <button key={c.id} onClick={() => addContact(c)} disabled={hasContact(c.id)}
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 999, fontSize: 12, fontWeight: 700, cursor: hasContact(c.id) ? 'default' : 'pointer',
                      background: hasContact(c.id) ? 'rgba(16,185,129,0.12)' : 'var(--bg-input)',
                      border: `1px solid ${hasContact(c.id) ? 'rgba(16,185,129,0.4)' : 'var(--border)'}`,
                      color: hasContact(c.id) ? '#10b981' : 'var(--text-h)', opacity: hasContact(c.id) ? 0.8 : 1 }}>
                    <UserPlus size={12} /> {contactName(c)}
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Manual add */}
          <div style={{ marginTop: 16 }}>
            <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 7 }}>Add manually</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <TextInput value={manual.name} onChange={e => setManual(m => ({ ...m, name: e.target.value }))} placeholder="Name" />
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                <TextInput value={manual.email} onChange={e => setManual(m => ({ ...m, email: e.target.value }))} placeholder="Email" />
                <TextInput value={manual.role} onChange={e => setManual(m => ({ ...m, role: e.target.value }))} placeholder="Role" />
              </div>
              <TextInput value={manual.organisation} onChange={e => setManual(m => ({ ...m, organisation: e.target.value }))} placeholder="Organisation" />
              <button onClick={addManual} disabled={!manual.name.trim()}
                style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6, padding: '9px 12px', borderRadius: 10, cursor: manual.name.trim() ? 'pointer' : 'not-allowed',
                  fontSize: 12.5, fontWeight: 700, color: '#a78bfa', background: 'rgba(124,58,237,0.1)', border: '1px solid rgba(124,58,237,0.35)', opacity: manual.name.trim() ? 1 : 0.6 }}>
                <Plus size={14} /> Add participant
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Footer actions */}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
        <button onClick={() => navigate('/app/purchase/kickoff')} style={ghostBtn}>Cancel</button>
        <button onClick={save} disabled={saving} style={{ ...solidBtn, opacity: saving ? 0.7 : 1 }}>
          {saving ? <Loader2 size={15} className="pk-spin" /> : <CalendarDays size={15} />} Schedule meeting
        </button>
      </div>
    </div>
  )
}

const SectionTitle = ({ icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
    <Icon size={15} style={{ color: '#a78bfa' }} />
    <h2 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{children}</h2>
  </div>
)

const solidBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 18px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 700, color: '#fff', border: 'none',
  background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)',
}
const ghostBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 16px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)',
}
