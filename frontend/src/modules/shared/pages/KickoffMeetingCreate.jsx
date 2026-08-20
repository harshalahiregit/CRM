import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { useNavigate, useSearchParams, useParams } from 'react-router-dom'
import {
  ArrowLeft, CalendarDays, Clock, MapPin, Users, Plus, Trash2,
  AlertTriangle, ChevronRight, Laptop, Building2, CheckCircle2, Send, Download,
  FileText, History, RotateCcw,
} from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { kickoffApi } from '@/services/kickoffApi'
import { meetingApi } from '@/services/meetingApi'
import { tpvApi } from '@/services/tpvApi'
import { KO_MODES, actStatusCfg, issueStatusCfg } from '../kickoffConstants'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Field, TextInput, SelectInput,
} from '@/components/ui/kit3d'
import RichTextEditor from '@/components/ui/RichTextEditor'
// Reused, not rebuilt: the same type-to-search combobox the HR module uses, so
// there is one dropdown look across the app.
import MultiSearchSelect from '@/components/ui/MultiSearchSelect'

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
// Rich-text fields store HTML; the carry-forward list shows them as plain text.
const stripHtml = (s) => (s || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()

const EMPTY_MOM = () => ({ id: Date.now() + Math.random(), description: '', responsible: '', responsible_org: '', remarks: '', target_date: '', priority: '', status: 'Open', action_ref: '', carried_from_id: null, carried_from_label: '' })
const EMPTY_PARTICIPANT = () => ({ id: Date.now() + Math.random(), name: '', role: '', organisation: '', phone: '', designation: '', side: '' })
const EMPTY_AGENDA = () => ({ id: Date.now() + Math.random(), item: '', owner: '', duration_minutes: '', priority: '' })
const EMPTY_DECISION = () => ({ id: Date.now() + Math.random(), decision: '', decided_by: '', impact: '', effective_date: '', status: 'Active' })
const EMPTY_ISSUE = () => ({ id: Date.now() + Math.random(), title: '', category: '', severity: '', owner: '', due_date: '', status: 'Open', issue_ref: '', converted_to: '', carried_from_id: null, carried_from_label: '' })

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

  // Options carry ids, so duplicate company names stay distinguishable. The
  // sublabel disambiguates visually where the name repeats.
  const vendorOptions = useMemo(() => {
    const seen = new Map()
    vendors.forEach(v => {
      const base = (v.company_name || '').trim() || `Vendor #${v.id}`
      seen.set(base, (seen.get(base) || 0) + 1)
    })
    return vendors.map(v => {
      const base = (v.company_name || '').trim() || `Vendor #${v.id}`
      return { id: String(v.id), label: base, sublabel: seen.get(base) > 1 ? `#${v.id}` : (v.email || '') }
    })
  }, [vendors])

  // The chosen vendors, in order. The FIRST is the primary: it goes to the
  // backend as subject_id (stored on kickoffable_*) and drives the contacts
  // picker, exactly as the old single select did. The rest ride along in
  // subject_ids. Keeping subject_id in sync here means every downstream reader
  // (validation, summary panel, contacts) is untouched.
  const [vendorIds, setVendorIdsRaw] = useState([])
  const setVendorIds = (next) => {
    setVendorIdsRaw(next)
    const primary = next[0] || ''
    setForm(f => ({ ...f, subject_id: primary }))
    loadContacts(primary)
  }



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
      setVendorIdsRaw([String(preVendorId)])
      tpvApi.contacts.list(preVendorId).then(r => setContacts(r?.data ?? r)).catch(() => {})
    }
  }, [preVendorId])

  // ── form state ──────────────────────────────────────────────────────────
  const [form, setForm] = useState({
    subject_id:       '',
    meeting_type:     'kickoff',
    title:            '',
    meeting_date:     '',
    meeting_time:     '09:00',
    meeting_end_time: '',
    planned_date:     '',
    duration_minutes: 60,
    mode:             'onsite',
    location:         '',   // City / Location
    location_detail:  '',   // Venue / Address
    agenda:           '',
    // Meeting.docx §2 detail fields.
    priority:         '',
    confidentiality:  '',
    chairperson:      '',
    coordinator:      '',
    department:       '',
    client_name:      '',
    work_package:     '',
    project_id:       '',       // soft link into the Projects module (§16)
    is_completed:     false,
    meeting_platform: 'stub',  // used when mode = 'online'
  })
  const [projects, setProjects] = useState([])   // { id, name, project_code, client_name, ... }
  const [participants, setParticipants] = useState([])  // [{ id, name, role, organisation }]
  const [momItems,     setMomItems]     = useState([])  // [{ id, description, responsible, remarks, target_date }]
  const [agendaItems,  setAgendaItems]  = useState([])  // [{ id, item, owner, duration_minutes, priority }]
  const [decisions,    setDecisions]    = useState([])  // Decision register
  const [issues,       setIssues]       = useState([])  // Issues raised
  // Configurable catalogue (config/meetings.php).
  const [meetingTypes, setMeetingTypes] = useState({ kickoff: 'Kickoff Meeting' })
  const [priorities,   setPriorities]   = useState(['Low', 'Medium', 'High'])
  const [severities,   setSeverities]   = useState(['Low', 'Medium', 'High', 'Critical'])
  const [categories,   setCategories]   = useState([])
  const [templates,    setTemplates]    = useState({})   // per-type standard agendas
  const [mtgPriorities, setMtgPriorities] = useState(['Low', 'Medium', 'High', 'Urgent'])
  const [confLevels,    setConfLevels]    = useState(['Public', 'Internal', 'Confidential', 'Restricted'])

  // ── edit mode ───────────────────────────────────────────────────────────
  // /kickoff/:id/edit renders this same page. There is deliberately no second
  // form: this is the only screen carrying participants, MOM items, meeting
  // mode and the split venue fields, so a separate edit form would immediately
  // drift from it.
  const { id: editId } = useParams()
  const isEdit = Boolean(editId)
  const [loading, setLoading] = useState(isEdit)
  // Set when the loaded meeting already has a join link, so saving an edit does
  // not mint a new one and invalidate what the vendor was sent.
  const [existingLink, setExistingLink] = useState(false)

  // The PERSISTED status, as opposed to the unsaved is_completed toggle. Send
  // MOM / Download PDF act on the server record, and the backend refuses to
  // publish a meeting that is not actually Completed — so showing them off the
  // local toggle would offer a button that is guaranteed to fail.
  const [savedStatus, setSavedStatus] = useState(null)
  const [canComplete, setCanComplete] = useState(true)
  const [momBusy,     setMomBusy]     = useState(null)   // 'send' | 'pdf'
  const [momNote,     setMomNote]     = useState(null)
  // Both conditions matter. The saved status is what the SERVER will accept —
  // publishForAck refuses anything that is not Completed, so offering the
  // buttons off the unsaved toggle alone would guarantee a failed request. The
  // toggle is what the USER currently intends, so switching it off hides them
  // straight away rather than leaving live actions on a meeting being reopened.
  const isSavedCompleted = savedStatus === 'Completed' && form.is_completed

  useEffect(() => {
    if (!isEdit) return
    kickoffApi.get(editId)
      .then(res => {
        const m = res?.data ?? res
        const at = m.scheduled_at ? new Date(m.scheduled_at) : null
        const pad = n => String(n).padStart(2, '0')

        // Every vendor on the meeting, primary first — the server already orders
        // subject_list that way. Falls back to the single `subject` for a meeting
        // saved before multi-vendor existed, so an old record still loads.
        setVendorIdsRaw(
          Array.isArray(m.subject_list) && m.subject_list.length
            ? m.subject_list.map(s => String(s.subject_id ?? s.id))
            : (m.subject?.id ? [String(m.subject.id)] : [])
        )

        setForm({
          subject_id:       m.subject?.id ? String(m.subject.id) : '',
          meeting_type:     m.meeting_type || 'kickoff',
          title:            m.title || '',
          meeting_date:     at ? `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}` : '',
          meeting_time:     at ? `${pad(at.getHours())}:${pad(at.getMinutes())}` : '09:00',
          meeting_end_time: m.end_at ? `${pad(new Date(m.end_at).getHours())}:${pad(new Date(m.end_at).getMinutes())}` : '',
          planned_date:     m.planned_date ? String(m.planned_date).slice(0, 10) : '',
          duration_minutes: m.duration_minutes ?? 60,
          mode:             m.mode || 'onsite',
          // `location` is the city; `venue` is what the form calls location_detail.
          location:         m.city || m.location || '',
          location_detail:  m.venue || '',
          agenda:           m.agenda || '',
          priority:         m.priority || '',
          confidentiality:  m.confidentiality || '',
          chairperson:      m.chairperson || '',
          coordinator:      m.coordinator || '',
          department:       m.department || '',
          client_name:      m.client_name || '',
          work_package:     m.work_package || '',
          project_id:       m.project_id || '',
          is_completed:     m.status === 'Completed',
          meeting_platform: m.meeting_platform || 'stub',
        })

        setParticipants((m.attendees || []).map(a => ({
          id: a.id, name: a.name || '', role: a.role || '', organisation: a.organisation || '',
          phone: a.phone || '', designation: a.designation || '', side: a.side || '',
        })))

        setMomItems((m.mom_items || []).map(i => ({
          id:              i.id,
          action_ref:      i.action_ref || '',
          status:          i.status || 'Open',
          description:     i.description || '',
          responsible:     i.responsible_names || i.responsible?.name || '',
          responsible_org: i.responsible_org || '',
          remarks:         i.remark || '',
          target_date:     i.target_date ? String(i.target_date).slice(0, 10) : '',
          priority:        i.priority || '',
        })))

        setAgendaItems((m.agenda_items || []).map(a => ({
          id:               a.id,
          item:             a.item || '',
          owner:            a.owner_names || a.owner?.name || '',
          duration_minutes: a.duration_minutes ?? '',
          priority:         a.priority || '',
        })))

        setDecisions((m.decisions || []).map(d => ({
          id: d.id, decision: d.decision || '',
          decided_by: d.decided_by_names || d.decided_by?.name || '',
          impact: d.impact || '',
          effective_date: d.effective_date ? String(d.effective_date).slice(0, 10) : '',
          status: d.status || 'Active',
        })))

        setIssues((m.issues || []).map(i => ({
          id: i.id, issue_ref: i.issue_ref || '', status: i.status || 'Open', converted_to: i.converted_to || '',
          title: i.title || '', description: i.description || '',
          category: i.category || '', severity: i.severity || '',
          owner: i.owner_names || i.owner?.name || '',
          due_date: i.due_date ? String(i.due_date).slice(0, 10) : '',
        })))

        setExistingLink(Boolean(m.meeting_link))
        setSavedStatus(m.status || null)
        // can_complete is computed server-side: false until scheduled_at passes.
        setCanComplete(m.can_complete !== false)
        if (m.subject?.id) loadContacts(m.subject.id)
      })
      .catch(() => setErr('Could not load this meeting.'))
      .finally(() => setLoading(false))
    // loadContacts is stable (useCallback with no deps that change here)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editId, isEdit])

  // Tenant default meeting platform: prefills the picker, and an admin can
  // point it at whatever they just chose.
  const [defaultPlatform, setDefaultPlatform] = useState(null)
  const [savingPlatform, setSavingPlatform]   = useState(false)

  const [saving, setSaving]           = useState(false)
  const [err,    setErr]              = useState(null)
  const [generatingLink, setGenLink]  = useState(false)  // post-save link generation

  // ── Meeting-type catalogue (config-driven) ───────────────────────────────
  useEffect(() => {
    kickoffApi.meetingTypes().then(d => {
      if (d?.types && Object.keys(d.types).length) setMeetingTypes(d.types)
      if (Array.isArray(d?.priorities) && d.priorities.length) setPriorities(d.priorities)
      if (Array.isArray(d?.issue_severities) && d.issue_severities.length) setSeverities(d.issue_severities)
      if (Array.isArray(d?.issue_categories)) setCategories(d.issue_categories)
      if (d?.templates && typeof d.templates === 'object') setTemplates(d.templates)
      if (Array.isArray(d?.meeting_priorities) && d.meeting_priorities.length) setMtgPriorities(d.meeting_priorities)
      if (Array.isArray(d?.confidentiality) && d.confidentiality.length) setConfLevels(d.confidentiality)
    }).catch(() => {})
    // Projects for the §16 picker — a soft link, so failure just leaves it empty.
    kickoffApi.projects().then(d => { if (Array.isArray(d)) setProjects(d) }).catch(() => {})
  }, [])

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
      ...EMPTY_PARTICIPANT(),
      name:         c.full_name ?? '',
      designation:  c.designation ?? '',
      organisation: c.company_name ?? '',
      phone:        c.phone ?? c.mobile ?? '',
      side:         'external',   // vendor contacts are the external side
    }])
  }

  // ── MOM helpers ─────────────────────────────────────────────────────────
  /** Same generate-then-fetch pattern the listing uses, so behaviour matches. */
  const downloadMom = async () => {
    setMomBusy('pdf'); setMomNote(null)
    try {
      const fresh = await kickoffApi.get(editId)
      if (!((fresh?.data ?? fresh)?.mom_path)) await kickoffApi.generateMom(editId)
      const blob = await kickoffApi.momBlob(editId)
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href = url; a.download = `MOM-${editId}.pdf`
      document.body.appendChild(a); a.click(); a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch (e) {
      setMomNote({ ok: false, msg: e?.response?.data?.message || 'Could not generate the MOM PDF.' })
    } finally { setMomBusy(null) }
  }

  const addMom    = () => setMomItems(m => [...m, EMPTY_MOM()])
  const removeMom = (id) => setMomItems(m => m.filter(x => x.id !== id))
  const setMom    = (id, k, v) =>
    setMomItems(m => m.map(x => x.id === id ? { ...x, [k]: v } : x))

  // ── agenda-item helpers ──────────────────────────────────────────────────
  const addAgenda    = () => setAgendaItems(a => [...a, EMPTY_AGENDA()])
  const removeAgenda = (id) => setAgendaItems(a => a.filter(x => x.id !== id))
  const setAgenda    = (id, k, v) =>
    setAgendaItems(a => a.map(x => x.id === id ? { ...x, [k]: v } : x))

  // ── decision + issue helpers ─────────────────────────────────────────────
  const addDecision    = () => setDecisions(d => [...d, EMPTY_DECISION()])
  const removeDecision = (id) => setDecisions(d => d.filter(x => x.id !== id))
  const setDecision    = (id, k, v) => setDecisions(d => d.map(x => x.id === id ? { ...x, [k]: v } : x))
  const addIssue    = () => setIssues(i => [...i, EMPTY_ISSUE()])
  const removeIssue = (id) => setIssues(i => i.filter(x => x.id !== id))
  const setIssue    = (id, k, v) => setIssues(i => i.map(x => x.id === id ? { ...x, [k]: v } : x))

  // ── agenda template loader ────────────────────────────────────────────────
  // A meeting type's standard agenda, appended to the builder on demand. Rows
  // already present (same topic) are skipped, so loading twice is harmless and a
  // template never clobbers what the user has already typed.
  const templateForType = templates[form.meeting_type] || []
  const loadTemplate = () => {
    if (!templateForType.length) return
    setAgendaItems(prev => {
      const seen = new Set(prev.map(a => (a.item || '').trim().toLowerCase()))
      const additions = templateForType
        .filter(t => t.item && !seen.has(t.item.trim().toLowerCase()))
        .map(t => ({ ...EMPTY_AGENDA(), item: t.item, duration_minutes: t.duration_minutes ?? '', priority: t.priority || '' }))
      return [...prev, ...additions]
    })
  }

  // ── carry-forward from previous meetings ──────────────────────────────────
  // Still-open actions/issues from this vendor's earlier meetings, pulled in as
  // fresh rows that point back at their origin (carried_from_id) — so the same
  // item is never carried twice and the new meeting starts with unfinished work.
  const [carry,     setCarry]     = useState(null)   // { actions:[], issues:[] } | null
  const [carryBusy, setCarryBusy] = useState(false)
  const [carryErr,  setCarryErr]  = useState(null)

  const originLabel = (o) => o ? `${o.reference || o.title || 'Meeting'}${o.date ? ' · ' + o.date : ''}` : ''

  const fetchCarryForward = async () => {
    if (!form.subject_id) { setCarryErr('Select a vendor first.'); return }
    setCarryBusy(true); setCarryErr(null)
    try {
      const d = await kickoffApi.carryForward({
        subject_type: 'vendor',
        subject_id: form.subject_id,
        exclude_meeting_id: editId || undefined,
      })
      setCarry({
        actions: d?.actions || [],
        issues: d?.issues || [],
        previousAgenda: d?.previous_agenda || null,
        previousStats: d?.previous_stats || null,
      })
    } catch (e) {
      setCarryErr(e?.response?.data?.message || 'Could not load previous items.')
    } finally { setCarryBusy(false) }
  }

  // §3 copy-agenda-from-previous: append the previous meeting's agenda items,
  // skipping any topic already present (same rule as the template loader).
  const copyPreviousAgenda = () => {
    const items = carry?.previousAgenda?.items || []
    if (!items.length) return
    setAgendaItems(prev => {
      const seen = new Set(prev.map(a => (a.item || '').trim().toLowerCase()))
      const additions = items
        .filter(t => t.item && !seen.has(t.item.trim().toLowerCase()))
        .map(t => ({ ...EMPTY_AGENDA(), item: t.item, owner: t.owner_names || '', duration_minutes: t.duration_minutes ?? '', priority: t.priority || '' }))
      return [...prev, ...additions]
    })
  }

  const carryAction = (a) => setMomItems(prev => (
    prev.some(m => m.carried_from_id === a.id) ? prev : [...prev, {
      ...EMPTY_MOM(),
      description:     a.description || '',
      responsible:     a.responsible_names || '',
      responsible_org: a.responsible_org || '',
      target_date:     a.target_date || '',
      priority:        a.priority || '',
      carried_from_id: a.id,
      carried_from_label: `${a.action_ref || 'Action'}${a.origin ? ' · ' + originLabel(a.origin) : ''}`,
    }]
  ))
  const carryIssueRow = (it) => setIssues(prev => (
    prev.some(x => x.carried_from_id === it.id) ? prev : [...prev, {
      ...EMPTY_ISSUE(),
      title:       it.title || '',
      description: it.description || '',
      category:    it.category || '',
      severity:    it.severity || '',
      owner:       it.owner_names || '',
      due_date:    it.due_date || '',
      carried_from_id: it.id,
      carried_from_label: `${it.issue_ref || 'Issue'}${it.origin ? ' · ' + originLabel(it.origin) : ''}`,
    }]
  ))

  // Which carried items are already on this meeting — to mark them "Added".
  const addedActionOrigins = new Set(momItems.map(m => m.carried_from_id).filter(Boolean))
  const addedIssueOrigins  = new Set(issues.map(i => i.carried_from_id).filter(Boolean))

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
        // Full set. The backend keeps the first on kickoffable_* and
        // writes the rest to kickoff_meeting_subjects.
        subject_ids:      vendorIds,
        meeting_type:     form.meeting_type || 'kickoff',
        title:            form.title || undefined,
        scheduled_at,
        end_at:           form.meeting_end_time ? combineDateTime(form.meeting_date, form.meeting_end_time) : undefined,
        planned_date:     form.planned_date || undefined,
        duration_minutes: Number(form.duration_minutes) || undefined,
        mode:             form.mode,
        // On-site and hybrid both have a physical location; online does not.
        location:         form.mode !== 'online' ? form.location         : undefined,
        location_detail:  form.mode !== 'online' ? form.location_detail  : undefined,
        agenda:           form.agenda || undefined,
        // Meeting.docx §2 detail fields.
        priority:         form.priority || undefined,
        confidentiality:  form.confidentiality || undefined,
        chairperson:      form.chairperson || undefined,
        coordinator:      form.coordinator || undefined,
        department:       form.department || undefined,
        client_name:      form.client_name || undefined,
        work_package:     form.work_package || undefined,
        project_id:       form.project_id || undefined,
        is_completed:     form.is_completed,
        // Extended fields — backend uses what it knows, ignores the rest
        attendees: participants
          .filter(p => p.name.trim())
          .map(({ name, role, organisation, phone, designation, side }) => ({
            name, role, organisation,
            phone: phone || undefined, designation: designation || undefined, side: side || undefined,
          })),
        mom_items: momItems
          .filter(m => m.description.replace(/<[^>]*>/g, '').trim())
          .map(({ id, description, responsible, remarks, target_date, priority, responsible_org, carried_from_id }) => ({
            // Integer id = an existing server row → the backend upserts it and
            // keeps its Action-Engine state. A client temp id (non-integer) is new.
            id: Number.isInteger(id) ? id : undefined,
            description, responsible, remarks,
            target_date: target_date || undefined,
            priority: priority || undefined,
            responsible_org: responsible_org || undefined,
            // Provenance for a carried-forward action; the backend sets it only on
            // create and refuses to carry the same origin twice.
            carried_from_id: carried_from_id || undefined,
          })),
        agenda_items: agendaItems
          .filter(a => a.item.trim())
          .map(({ item, owner, duration_minutes, priority }) => ({
            item,
            owner: owner || undefined,
            duration_minutes: Number(duration_minutes) || undefined,
            priority: priority || undefined,
          })),
        decisions: decisions
          .filter(d => d.decision.trim())
          .map(({ id, decision, decided_by, impact, effective_date, status }) => ({
            id: Number.isInteger(id) ? id : undefined,
            decision, decided_by: decided_by || undefined, impact: impact || undefined,
            effective_date: effective_date || undefined, status: status || undefined,
          })),
        issues: issues
          .filter(i => i.title.trim())
          .map(({ id, title, description, category, severity, owner, due_date, carried_from_id }) => ({
            id: Number.isInteger(id) ? id : undefined,
            title, description: description || undefined,
            category: category || undefined, severity: severity || undefined,
            owner: owner || undefined, due_date: due_date || undefined,
            carried_from_id: carried_from_id || undefined,
          })),
      }
      // Same payload either way — update() and schedule() accept identical shapes,
      // so edit reuses the whole form and its validation unchanged.
      const saved = isEdit
        ? await kickoffApi.update(editId, payload)
        : await kickoffApi.schedule(payload)
      const newId = (saved?.data ?? saved)?.id ?? editId

      // Auto-generate online meeting link immediately after save. On edit, only
      // when there isn't one already — regenerating would invalidate a link the
      // vendor has already been sent.
      if (newId && (form.mode === 'online' || form.mode === 'hybrid') && !(isEdit && existingLink)) {
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
              <span>{isEdit ? 'Edit' : 'Create New'}{loading ? ' · loading…' : ''}</span>
            </div>
            <h1 style={{ color: 'var(--text-h)', fontSize: 23, fontWeight: 900, margin: 0, letterSpacing: '-0.02em' }}>
              {isEdit ? `Edit Kickoff Meeting #${editId}` : 'New Kickoff Meeting'}
            </h1>
            <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>
              Schedule a pre-onboarding meeting with a third-party vendor.
            </p>
          </div>
        </div>

        {/* Meeting Completed toggle + the actions it unlocks */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 16px', borderRadius: 12, background: isSavedCompleted ? 'rgba(16,185,129,0.08)' : 'var(--bg-card)', border: `1px solid ${isSavedCompleted ? 'rgba(16,185,129,0.35)' : 'var(--border)'}` }}>
          <CheckCircle2 size={16} style={{ color: form.is_completed ? '#10b981' : 'var(--text-muted)' }} />
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 13, fontWeight: 600, color: form.is_completed ? '#10b981' : 'var(--text-muted)' }}>
              Meeting Completed
            </div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
              {canComplete ? 'Toggle once the meeting has taken place' : 'Available once the scheduled time has passed'}
            </div>
          </div>
          <button
            disabled={!canComplete && !form.is_completed}
            title={canComplete ? '' : 'This meeting is still in the future.'}
            onClick={() => canComplete && setForm(f => ({ ...f, is_completed: !f.is_completed }))}
            style={{
              opacity: (!canComplete && !form.is_completed) ? 0.45 : 1,
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

          {/* Only once the meeting is Completed ON THE SERVER. Both actions hit
              the saved record, and publish is refused for any other status. */}
          {isSavedCompleted && (
            <div style={{ marginLeft: 'auto', display: 'inline-flex', gap: 8 }}>
              {/* Distribution is gated by approval now — the full submit → approve →
                  distribute workflow lives on the meeting detail page. */}
              <button onClick={() => navigate(`/app/tpv/kickoff/${editId}`)}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9,
                  fontSize: 12.5, fontWeight: 700, cursor: 'pointer', border: 'none', color: '#fff',
                  background: 'linear-gradient(145deg,#f59e0b,#d97706)' }}>
                <Send size={13} /> Minutes approval
              </button>
              <button onClick={downloadMom} disabled={momBusy !== null}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9,
                  fontSize: 12.5, fontWeight: 700, cursor: momBusy ? 'not-allowed' : 'pointer', border: 'none', color: '#fff',
                  background: 'linear-gradient(145deg,#ef4444,#dc2626)', opacity: momBusy ? 0.6 : 1 }}>
                <Download size={13} /> {momBusy === 'pdf' ? 'Preparing…' : 'Download PDF'}
              </button>
            </div>
          )}
        </div>
        {momNote && (
          <div style={{ marginTop: 8, padding: '9px 14px', borderRadius: 10, fontSize: 12.5,
            background: momNote.ok ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)',
            border: `1px solid ${momNote.ok ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)'}`,
            color: momNote.ok ? '#10b981' : '#ef4444' }}>
            {momNote.msg}
          </div>
        )}
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
                {/* Type-to-search. The native <select> had no search, which is
                    unusable once a tenant has more than a screenful of vendors. */}
                {/* Multi-select. The FIRST vendor is the primary: it is what the
                    backend stores on kickoffable_* and what drives the contacts
                    picker below, so the order here is meaningful. */}
                <MultiSearchSelect
                  value={vendorIds}
                  onChange={setVendorIds}
                  options={vendorOptions}
                  placeholder="Search and select one or more vendors…"
                  emptyText="No vendor matches that search"
                  loading={!vendors.length}
                  primaryHint="Primary"
                />
              </Field>

              <Field label="Meeting Type">
                <SelectInput
                  value={form.meeting_type}
                  onChange={set('meeting_type')}
                  pairs
                  options={Object.entries(meetingTypes)}
                />
              </Field>

              <Field label="Meeting Title (optional — defaults to vendor name)">
                <TextInput value={form.title} onChange={set('title')} placeholder="e.g. Kickoff — Acme Contractors" />
              </Field>

              {/* Meeting details (Meeting.docx §2) */}
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <Field label="Priority">
                  <SelectInput value={form.priority} onChange={set('priority')} pairs
                    options={[['', '—'], ...mtgPriorities.map(p => [p, p])]} />
                </Field>
                <Field label="Confidentiality">
                  <SelectInput value={form.confidentiality} onChange={set('confidentiality')} pairs
                    options={[['', '—'], ...confLevels.map(c => [c, c])]} />
                </Field>
                <Field label="Chairperson">
                  <TextInput value={form.chairperson} onChange={set('chairperson')} placeholder="Name" />
                </Field>
                <Field label="Meeting Coordinator">
                  <TextInput value={form.coordinator} onChange={set('coordinator')} placeholder="Name" />
                </Field>
                <Field label="Department">
                  <TextInput value={form.department} onChange={set('department')} placeholder="e.g. HSE / Projects" />
                </Field>
                <Field label="Client (optional)">
                  <TextInput value={form.client_name} onChange={set('client_name')} placeholder="Client name" />
                </Field>
                {projects.length > 0 && (
                  <Field label="Project (optional)">
                    <SelectInput value={form.project_id} onChange={set('project_id')} pairs
                      options={[['', '— none —'], ...projects.map(p => [
                        String(p.id),
                        `${p.name}${p.project_code ? ` (${p.project_code})` : ''}`,
                      ])]} />
                  </Field>
                )}
                <div style={{ gridColumn: '1/-1' }}>
                  <Field label="Work Package (optional)">
                    <TextInput value={form.work_package} onChange={set('work_package')} placeholder="e.g. WP-03 Structural" />
                  </Field>
                </div>
              </div>

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
                      <div key={p.id} style={{ padding: '12px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                          <span style={{ fontSize: 11, fontWeight: 800, color: '#a78bfa' }}>Participant {i + 1}</span>
                          <button onClick={() => removeParticipant(p.id)} title="Remove participant"
                            style={{ width: 28, height: 28, borderRadius: 8, border: '1px solid rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)', color: '#ef4444', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                            <Trash2 size={12} />
                          </button>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8 }}>
                          <Field label="Name">
                            <TextInput value={p.name} onChange={e => setParticipant(p.id, 'name', e.target.value)} placeholder="Full name" />
                          </Field>
                          <Field label="Organisation">
                            <TextInput value={p.organisation} onChange={e => setParticipant(p.id, 'organisation', e.target.value)} placeholder="Company name" />
                          </Field>
                          <Field label="Side">
                            <SelectInput value={p.side} onChange={e => setParticipant(p.id, 'side', e.target.value)} pairs
                              options={[['', '—'], ['internal', 'Internal'], ['external', 'External']]} />
                          </Field>
                          <Field label="Role in meeting">
                            <TextInput value={p.role} onChange={e => setParticipant(p.id, 'role', e.target.value)} placeholder="e.g. Chair, Note-taker" />
                          </Field>
                          <Field label="Designation">
                            <TextInput value={p.designation} onChange={e => setParticipant(p.id, 'designation', e.target.value)} placeholder="e.g. Site Engineer" />
                          </Field>
                          <Field label="Phone">
                            <TextInput value={p.phone} onChange={e => setParticipant(p.id, 'phone', e.target.value)} placeholder="Mobile" />
                          </Field>
                        </div>
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
              <Field label="Start Time *">
                <TextInput type="time" value={form.meeting_time} onChange={set('meeting_time')} />
              </Field>
              <Field label="End Time (optional)">
                <TextInput type="time" value={form.meeting_end_time} onChange={set('meeting_end_time')} />
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
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12 }}>
              {KO_MODES.map(([val, label]) => {
                const on = form.mode === val
                const Icon = val === 'onsite' ? Building2 : Laptop
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

            {/* On-site AND hybrid have a physical location. */}
            {form.mode !== 'online' && (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 16 }}>
                <Field label="City / Location *">
                  <TextInput value={form.location} onChange={set('location')} placeholder="e.g. Mumbai" />
                </Field>
                <Field label="Venue / Address">
                  <TextInput value={form.location_detail} onChange={set('location_detail')} placeholder="e.g. Site office, Gate 1" />
                </Field>
              </div>
            )}
            {/* Online AND hybrid have a joining platform. */}
            {form.mode !== 'onsite' && (
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

            {/* Agenda builder — structured items (topic · owner · duration · priority) */}
            <div style={{ marginTop: 18 }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10, gap: 8, flexWrap: 'wrap' }}>
                <label style={labelStyle}>Agenda</label>
                <div style={{ display: 'flex', gap: 8 }}>
                  {/* Standard agenda for the selected type — appended, never destructive. */}
                  {templateForType.length > 0 && (
                    <button type="button" onClick={loadTemplate} style={addBtn}
                      title={`Load the standard ${meetingTypes[form.meeting_type] || ''} agenda`}>
                      <FileText size={13} /> Load {meetingTypes[form.meeting_type] || 'standard'} template
                    </button>
                  )}
                  <button type="button" onClick={addAgenda} style={addBtn}>
                    <Plus size={13} /> Add Item
                  </button>
                </div>
              </div>

              {agendaItems.length === 0 ? (
                <div style={{ padding: '16px', borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center' }}>
                  <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>
                    No agenda items yet. Click <strong>Add Item</strong> to build the agenda.
                  </p>
                </div>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {agendaItems.map((a, i) => (
                    <div key={a.id} style={{ display: 'grid', gridTemplateColumns: '22px 1fr 0.9fr 0.7fr 0.8fr 30px', gap: 8, alignItems: 'center' }}>
                      <span style={{ fontSize: 12, fontWeight: 800, color: '#a78bfa', textAlign: 'center' }}>{i + 1}</span>
                      <TextInput value={a.item} onChange={e => setAgenda(a.id, 'item', e.target.value)} placeholder="Agenda item / topic" />
                      <TextInput value={a.owner} onChange={e => setAgenda(a.id, 'owner', e.target.value)} placeholder="Owner" />
                      <TextInput type="number" min="1" value={a.duration_minutes} onChange={e => setAgenda(a.id, 'duration_minutes', e.target.value)} placeholder="min" />
                      <SelectInput value={a.priority} onChange={e => setAgenda(a.id, 'priority', e.target.value)} pairs
                        options={[['', 'Priority'], ...priorities.map(p => [p, p])]} />
                      <button type="button" onClick={() => removeAgenda(a.id)} title="Remove item"
                        style={{ width: 28, height: 28, borderRadius: 8, border: '1px solid rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)', color: '#ef4444', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                        <Trash2 size={12} />
                      </button>
                    </div>
                  ))}
                </div>
              )}

              {/* Free-text agenda notes stay for anything the structured rows don't capture. */}
              <div style={{ marginTop: 14 }}>
                <Field label="Agenda notes (optional)">
                  <textarea
                    value={form.agenda}
                    onChange={set('agenda')}
                    rows={2}
                    placeholder="Any extra context, links, or a copied agenda…"
                    style={{ ...inputStyle, resize: 'vertical', fontFamily: 'inherit', lineHeight: 1.5 }}
                  />
                </Field>
              </div>
            </div>
          </div>

          {/* Carry forward — open actions/issues from this vendor's earlier meetings */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, gap: 10, flexWrap: 'wrap' }}>
              <SectionTitle icon={History}>Carry Forward</SectionTitle>
              <button type="button" onClick={fetchCarryForward} disabled={carryBusy || !form.subject_id}
                style={{ ...addBtn, opacity: (carryBusy || !form.subject_id) ? 0.55 : 1, cursor: (carryBusy || !form.subject_id) ? 'not-allowed' : 'pointer' }}
                title={form.subject_id ? "Pull open items from this vendor's earlier meetings" : 'Select a vendor first'}>
                <RotateCcw size={13} /> {carryBusy ? 'Loading…' : (carry ? 'Refresh' : 'Load previous open items')}
              </button>
            </div>
            <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 12px', lineHeight: 1.5 }}>
              Bring still-open actions and issues from this vendor's earlier meetings into this one. Each is added as a fresh, linked row — nothing is ever carried twice.
            </p>

            {carryErr && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, marginBottom: 12, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>
                <AlertTriangle size={13} style={{ color: '#ef4444', flexShrink: 0 }} />
                <span style={{ fontSize: 12, color: 'var(--text-h)' }}>{carryErr}</span>
              </div>
            )}

            {/* §11 — what the previous meeting left behind, at a glance. */}
            {carry?.previousStats && (
              <div style={{ padding: '12px 14px', borderRadius: 12, marginBottom: 14, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', marginBottom: 10 }}>
                  <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                    Last meeting: {originLabel(carry.previousStats.origin) || carry.previousStats.meeting_type_label}
                  </div>
                  <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    <MiniTag>{carry.previousStats.status_label}</MiniTag>
                    <MiniTag>{carry.previousStats.mom_status_label}</MiniTag>
                    {carry.previousStats.acknowledged && <MiniTag tone="#10b981">Acknowledged</MiniTag>}
                  </div>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 8 }}>
                  <PrevStat n={carry.previousStats.decisions} label="Decisions" />
                  <PrevStat n={carry.previousStats.open_actions} label="Open actions" tone={carry.previousStats.open_actions ? '#f59e0b' : undefined} />
                  <PrevStat n={carry.previousStats.open_issues} label="Open issues" tone={carry.previousStats.open_issues ? '#ef4444' : undefined} />
                </div>
              </div>
            )}

            {/* §3 — reuse the previous meeting's agenda in this one. */}
            {carry?.previousAgenda?.items?.length > 0 && (
              <button type="button" onClick={copyPreviousAgenda}
                style={{ ...addBtn, width: '100%', justifyContent: 'center', marginBottom: 14 }}
                title={`Append ${carry.previousAgenda.items.length} agenda item(s) from ${originLabel(carry.previousAgenda.origin)}`}>
                <FileText size={13} /> Copy agenda from last meeting ({carry.previousAgenda.items.length})
              </button>
            )}

            {carry && (
              (carry.actions.length === 0 && carry.issues.length === 0) ? (
                <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>
                  No open actions or issues from previous meetings for this vendor.
                </p>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                  {carry.actions.length > 0 && (
                    <div>
                      <div style={carryHeadStyle}>Open actions ({carry.actions.length})</div>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                        {carry.actions.map(a => (
                          <CarryItem key={`a${a.id}`}
                            refCode={a.action_ref} title={stripHtml(a.description) || '(no description)'}
                            overdue={a.is_overdue} added={addedActionOrigins.has(a.id)}
                            onAdd={() => carryAction(a)}
                            meta={[a.status_label, a.priority, a.target_date && `due ${a.target_date}`, a.origin && `from ${originLabel(a.origin)}`]} />
                        ))}
                      </div>
                    </div>
                  )}
                  {carry.issues.length > 0 && (
                    <div>
                      <div style={carryHeadStyle}>Open issues ({carry.issues.length})</div>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                        {carry.issues.map(it => (
                          <CarryItem key={`i${it.id}`}
                            refCode={it.issue_ref} title={it.title || '(untitled)'}
                            overdue={it.is_overdue} added={addedIssueOrigins.has(it.id)}
                            onAdd={() => carryIssueRow(it)}
                            meta={[it.status_label, it.severity, it.category, it.due_date && `due ${it.due_date}`, it.origin && `from ${originLabel(it.origin)}`]} />
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )
            )}
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
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ fontSize: 11, fontWeight: 800, color: '#a78bfa', textTransform: 'uppercase', letterSpacing: '0.06em' }}>Action {i + 1}</span>
                        {item.action_ref && <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)' }}>{item.action_ref}</span>}
                        {item.action_ref && (() => { const c = actStatusCfg(item.status); return (
                          <span style={{ fontSize: 10, fontWeight: 800, padding: '1px 7px', borderRadius: 6, background: c.bg, color: c.color }}>{c.label}</span>
                        )})()}
                        {item.carried_from_label && (
                          <span title="Carried forward from a previous meeting" style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10, fontWeight: 700, color: '#0ea5e9' }}>
                            <RotateCcw size={10} /> {item.carried_from_label}
                          </span>
                        )}
                      </span>
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

                      {/* Priority */}
                      <Field label="Priority">
                        <SelectInput value={item.priority} onChange={e => setMom(item.id, 'priority', e.target.value)} pairs
                          options={[['', '—'], ...priorities.map(p => [p, p])]} />
                      </Field>

                      {/* Responsible organisation */}
                      <Field label="Responsible Org">
                        <TextInput value={item.responsible_org} onChange={e => setMom(item.id, 'responsible_org', e.target.value)} placeholder="e.g. Vendor / PMC" />
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

          {/* Section 5 — Decision register */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
              <SectionTitle icon={CheckCircle2}>Decisions</SectionTitle>
              <button onClick={addDecision} style={addBtn}><Plus size={13} /> Add Decision</button>
            </div>
            {decisions.length === 0 ? (
              <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>No decisions recorded yet.</p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {decisions.map((d, i) => (
                  <div key={d.id} style={{ padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                      <span style={{ fontSize: 11, fontWeight: 800, color: '#a78bfa' }}>Decision {i + 1}</span>
                      <button onClick={() => removeDecision(d.id)} style={{ width: 26, height: 26, borderRadius: 7, border: '1px solid rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)', color: '#ef4444', cursor: 'pointer' }}><Trash2 size={12} /></button>
                    </div>
                    <TextInput value={d.decision} onChange={e => setDecision(d.id, 'decision', e.target.value)} placeholder="Decision taken…" />
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 10 }}>
                      <Field label="Decision maker"><TextInput value={d.decided_by} onChange={e => setDecision(d.id, 'decided_by', e.target.value)} placeholder="Name" /></Field>
                      <Field label="Impact"><TextInput value={d.impact} onChange={e => setDecision(d.id, 'impact', e.target.value)} placeholder="e.g. Schedule / Cost" /></Field>
                      <Field label="Effective date"><TextInput type="date" value={d.effective_date} onChange={e => setDecision(d.id, 'effective_date', e.target.value)} /></Field>
                      <Field label="Status"><SelectInput value={d.status} onChange={e => setDecision(d.id, 'status', e.target.value)} pairs options={[['Active', 'Active'], ['Superseded', 'Superseded'], ['Rescinded', 'Rescinded']]} /></Field>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Section 6 — Issues raised */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
              <SectionTitle icon={AlertTriangle}>Issues Raised</SectionTitle>
              <button onClick={addIssue} style={addBtn}><Plus size={13} /> Add Issue</button>
            </div>
            {issues.length === 0 ? (
              <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>No issues raised yet.</p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {issues.map((it, i) => (
                  <div key={it.id} style={{ padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ fontSize: 11, fontWeight: 800, color: '#a78bfa' }}>Issue {i + 1}</span>
                        {it.issue_ref && <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)' }}>{it.issue_ref}</span>}
                        {it.issue_ref && (() => { const c = issueStatusCfg(it.status); return <span style={{ fontSize: 10, fontWeight: 800, padding: '1px 7px', borderRadius: 6, background: c.bg, color: c.color }}>{c.label}</span> })()}
                        {it.converted_to && <span style={{ fontSize: 10, fontWeight: 800, color: '#ef4444' }}>→ {it.converted_to}</span>}
                        {it.carried_from_label && (
                          <span title="Carried forward from a previous meeting" style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10, fontWeight: 700, color: '#0ea5e9' }}>
                            <RotateCcw size={10} /> {it.carried_from_label}
                          </span>
                        )}
                      </span>
                      <button onClick={() => removeIssue(it.id)} style={{ width: 26, height: 26, borderRadius: 7, border: '1px solid rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)', color: '#ef4444', cursor: 'pointer' }}><Trash2 size={12} /></button>
                    </div>
                    <TextInput value={it.title} onChange={e => setIssue(it.id, 'title', e.target.value)} placeholder="Issue…" />
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 10 }}>
                      <Field label="Category"><SelectInput value={it.category} onChange={e => setIssue(it.id, 'category', e.target.value)} pairs options={[['', '—'], ...categories.map(c => [c, c])]} /></Field>
                      <Field label="Severity"><SelectInput value={it.severity} onChange={e => setIssue(it.id, 'severity', e.target.value)} pairs options={[['', '—'], ...severities.map(s => [s, s])]} /></Field>
                      <Field label="Owner"><TextInput value={it.owner} onChange={e => setIssue(it.id, 'owner', e.target.value)} placeholder="Name" /></Field>
                      <Field label="Due date"><TextInput type="date" value={it.due_date} onChange={e => setIssue(it.id, 'due_date', e.target.value)} /></Field>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

        </div>{/* end left column */}

        {/* ── RIGHT COLUMN — summary card ─────────────────────────────── */}
        <div style={{ position: 'sticky', top: 16 }}>
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={CalendarDays}>Meeting Summary</SectionTitle>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {/* Every selected vendor, primary first — the summary must agree
                  with the chips above, not show only the first one. */}
              <SummaryRow label={vendorIds.length > 1 ? `Vendors (${vendorIds.length})` : 'Vendor'}>
                {vendorIds.length === 0
                  ? <span style={{ color: 'var(--text-muted)', fontStyle: 'italic' }}>Not selected</span>
                  : (
                    <span style={{ display: 'inline-flex', flexDirection: 'column', gap: 3, alignItems: 'flex-end' }}>
                      {vendorIds.map((id, i) => {
                        const o = vendorOptions.find(x => String(x.id) === String(id))
                        return (
                          <span key={id}>
                            {o?.label || `#${id}`}
                            {i === 0 && vendorIds.length > 1 && (
                              <span style={{ fontSize: 9, fontWeight: 800, color: '#a78bfa', marginLeft: 5 }}>PRIMARY</span>
                            )}
                          </span>
                        )
                      })}
                    </span>
                  )}
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

// ── carry-forward row ─────────────────────────────────────────────────────────
/**
 * One open action/issue offered for carry-forward. Shows its ref, a one-line
 * title, and a meta strip (status · priority/severity · due · origin meeting).
 * The Add button flips to a static "Added" chip once it is on the new meeting.
 */
function CarryItem({ refCode, title, meta, overdue, added, onAdd }) {
  const bits = (meta || []).filter(Boolean)
  return (
    <div style={{
      display: 'flex', alignItems: 'flex-start', gap: 10, padding: '10px 12px', borderRadius: 10,
      background: 'var(--bg-input)', border: `1px solid ${overdue ? 'rgba(239,68,68,0.35)' : 'var(--border)'}`,
    }}>
      <div style={{ minWidth: 0, flex: 1 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 3 }}>
          {refCode && <span style={{ fontSize: 10.5, fontWeight: 800, color: '#a78bfa' }}>{refCode}</span>}
          {overdue && <span style={{ fontSize: 9.5, fontWeight: 800, color: '#ef4444', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Overdue</span>}
        </div>
        <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)', lineHeight: 1.4, overflow: 'hidden', textOverflow: 'ellipsis', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical' }}>
          {title}
        </div>
        {bits.length > 0 && (
          <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 3 }}>{bits.join('  ·  ')}</div>
        )}
      </div>
      {added ? (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11.5, fontWeight: 700, color: '#10b981', flexShrink: 0, padding: '5px 6px' }}>
          <CheckCircle2 size={13} /> Added
        </span>
      ) : (
        <button type="button" onClick={onAdd} style={{ ...addBtn, padding: '6px 11px', flexShrink: 0 }}>
          <Plus size={12} /> Add
        </button>
      )}
    </div>
  )
}

const carryHeadStyle = {
  fontSize: 11.5, fontWeight: 800, color: 'var(--text-muted)',
  textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 8,
}

// ── previous-meeting stat helpers (§11) ───────────────────────────────────────
function MiniTag({ children, tone }) {
  return (
    <span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 999,
      color: tone || 'var(--text-muted)', background: tone ? `${tone}1a` : 'var(--bg-card)', border: `1px solid ${tone ? `${tone}55` : 'var(--border)'}` }}>
      {children}
    </span>
  )
}
function PrevStat({ n, label, tone }) {
  return (
    <div style={{ textAlign: 'center', padding: '8px 4px', borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div style={{ fontSize: 18, fontWeight: 900, color: tone || 'var(--text-h)', lineHeight: 1 }}>{n ?? 0}</div>
      <div style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.03em', marginTop: 4 }}>{label}</div>
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
