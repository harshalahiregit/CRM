import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { useNavigate, useSearchParams, useParams } from 'react-router-dom'
import {
  ArrowLeft, CalendarDays, Clock, MapPin, Users, Plus, Trash2,
  AlertTriangle, ChevronRight, Laptop, Building2, CheckCircle2, Send, Download,
  FileText, History, RotateCcw, Sparkles, Search, LayoutTemplate,
} from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
// Resolves per call to the meeting engine of the module in the URL — the
// shared engine under /app/tpv, Purchase's under /app/purchase. Aliased to
// the old name so the call sites below read unchanged.
import { meetingEngineApi as kickoffApi, meetingBase } from '@/services/meetingEngineApi'
import ParticipantGrid from '@/components/meetings/ParticipantGrid'
import { meetingApi } from '@/services/meetingApi'
// The VENDOR api for the module in the URL. The picker, the ?vendor= prefill
// and the contacts list were all pinned to tpvApi, so on /app/purchase this
// page listed TPV's companies — a different table whose ids are unrelated to
// purchase_vendors, so nothing selected here could ever be the right vendor.
import { useVendorModule } from '@/modules/tpv/useVendorModule'
import { KO_MODES, actStatusCfg, issueStatusCfg } from '../kickoffConstants'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Field, TextInput, Overlay,
} from '@/components/ui/kit3d'
// Kickoff dropdowns are searchable (same as Tickets) — this adapter keeps the
// kit3d SelectInput API but renders the type-to-search popover Select.
import SelectInput from '@/components/ui/SearchableSelectInput'
import RichTextEditor from '@/components/ui/RichTextEditor'
// Reused, not rebuilt: the same type-to-search combobox the HR module uses, so
// there is one dropdown look across the app.
import MultiSearchSelect from '@/components/ui/MultiSearchSelect'

// ── Platform options for online meetings ─────────────────────────────────────
// The three services a call is actually held on. Each schedules through its
// own API when the tenant has it configured, and otherwise hands back that
// platform's own start-now link — meet.google.com/new, zoom.us/start — so the
// Join button opens a real meeting either way.
//
// Jitsi used to head this list because it was the one option that needed no
// account and could run inside the CRM. Both of those have gone: the call is
// on the real service now. Meetings saved with the old value still open — the
// server accepts it and moves them onto the default. See
// OnlineMeetingService::ACCEPTED.
const PLATFORM_OPTIONS = [
  ['google_meet', 'Google Meet'],
  ['zoom',        'Zoom'],
  ['teams',       'Microsoft Teams'],
]
// Kept in step with OnlineMeetingService::DEFAULT_PLATFORM.
const DEFAULT_PLATFORM = 'google_meet'
const PLATFORM_KEYS = PLATFORM_OPTIONS.map(([k]) => k)

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
/**
 * The instant a date box and a time box name, sent so the server cannot mistake it.
 *
 * This used to send a bare `2026-09-04T14:30:00`. A bare wall clock does not
 * name a moment until you say in which timezone, and each end of the wire
 * assumed a different one: the server read it as UTC and published it back as
 * UTC, the browser localised it a second time, and a meeting entered at 09:00
 * reappeared at 14:30. Re-saving then stored 14:30 and it slid another +05:30
 * down the day on every edit.
 *
 * An ISO instant with its offset is unambiguous, so the meeting lands on the
 * hour that was typed — and a colleague in another country reads it correctly
 * converted to their own clock rather than shifted.
 */
const combineDateTime = (date, time) => {
  if (!date) return ''
  const at = new Date(`${date}T${time || '09:00'}:00`)
  return Number.isNaN(at.getTime()) ? '' : at.toISOString()
}
/**
 * The END instant, given the meeting's date and its start/end clock times.
 *
 * End was previously combined with the START date unconditionally, so a meeting
 * running 23:00 -> 00:30 produced an end BEFORE its start. The backend rejects
 * that (`end_at` must be `after:scheduled_at`), so a late meeting simply could
 * not be saved and the error pointed at a field the user had filled correctly.
 * An end at or before the start means the next day.
 */
const combineEndDateTime = (date, startTime, endTime) => {
  if (!date || !endTime) return ''
  if (!startTime || endTime > startTime) return combineDateTime(date, endTime)
  const next = new Date(`${date}T00:00:00`)
  next.setDate(next.getDate() + 1)
  const pad2 = (n) => String(n).padStart(2, '0')
  const nextDate = `${next.getFullYear()}-${pad2(next.getMonth() + 1)}-${pad2(next.getDate())}`
  return combineDateTime(nextDate, endTime)
}
// Rich-text fields store HTML; the carry-forward list shows them as plain text.
const stripHtml = (s) => (s || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
const truncate = (s, n) => { const t = (s || '').trim(); return t.length > n ? t.slice(0, n - 1) + '…' : t }

const EMPTY_MOM = () => ({ id: Date.now() + Math.random(), description: '', responsible: '', responsible_org: '', remarks: '', target_date: '', priority: '', status: 'Open', action_ref: '', carried_from_id: null, carried_from_label: '', agenda_key: '', depends_key: '' })
// Meeting.docx §5: e-mail is a participant field, and it is the one that makes
// the invitation and the MOM distribution actually reach the person. user_id
// links the row to a Sangoe identity so in-app notifications and action
// auto-assignment work; vendor_contact_id does the same on the vendor side.
const EMPTY_PARTICIPANT = () => ({ id: Date.now() + Math.random(), name: '', email: '', role: '', organisation: '', phone: '', designation: '', side: '', user_id: '', vendor_contact_id: '', party: '', party_ref: '' })
// §7's chain is Agenda -> Discussion -> Decision -> Action; discussion and
// decision belong to the agenda item, not to one meeting-level minutes blob.
// Meeting.docx §5's participant roles used to be a dropdown on every
// participant card. The attendance sheet shows the designation from the
// person's own record instead, which is the same answer without asking: a site
// engineer picked from a vendor's workforce does not need somebody to also
// choose "Vendor representative" from a list. `role` is still on the row and
// still saved — the attendance and MOM screens set it — it is just no longer
// something you fill in before the meeting has happened.

const EMPTY_AGENDA = () => ({ id: Date.now() + Math.random(), item: '', owner: '', duration_minutes: '', priority: '', discussion: '', decision: '', previous_discussion_ref: '', supporting_documents: [] })
const EMPTY_DECISION = () => ({ id: Date.now() + Math.random(), decision: '', decided_by: '', impact: '', effective_date: '', status: 'Active', agenda_key: '' })
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
  const vendorApi = useVendorModule().api
  // The start instant as STORED, so editing an old meeting (adding its
  // minutes, say) is not blocked by the no-past-scheduling rule. Both
  // backends already allow an unchanged past start; the client did not, so
  // a past meeting could not be saved at all.
  const storedStartRef = useRef(null)

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
    // Load ALL vendors for the picker, not just those tagged with the 'tpv'
    // engagement — an admin scheduling a kickoff must be able to pick any vendor
    // (a vendor added without the tpv tag was previously invisible here).
    vendorApi.vendors.list({ engagement: '' }).then(r => {
      const list = r?.data ?? r ?? []
      // A vendor passed via ?vendor= may not be in the (TPV-filtered) picker — fetch
      // it from the shared master and merge so it can be selected.
      if (preVendorId && !list.some(v => String(v.id) === String(preVendorId))) {
        vendorApi.vendors.get(preVendorId)
          .then(res => { const v = res?.data ?? res; setVendors(v?.id ? [v, ...list] : list) })
          .catch(() => setVendors(list))
      } else {
        setVendors(list)
      }
    }).catch(() => {})
    if (preVendorId) {
      setForm(f => ({ ...f, subject_id: preVendorId }))
      setVendorIdsRaw([String(preVendorId)])
      vendorApi.contacts.list(preVendorId).then(r => setContacts(r?.data ?? r)).catch(() => {})
    }
  }, [preVendorId, vendorApi])

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
    organizer:        '',
    coordinator:      '',
    department:       '',
    client_name:      '',
    client_id:        '',      // soft link into the Customer module (§2/§16)
    work_package:     '',
    project_id:       '',       // soft link into the Projects module (§16)
    is_completed:     false,
    meeting_platform: DEFAULT_PLATFORM,  // used when mode = 'online'
  })
  const [projects, setProjects] = useState([])   // { id, name, project_code, client_name, ... }
  // Meeting.docx §2 wants a real Customer on the meeting, and §5 wants
  // participants linked to Sangoe identities. Both are read through the owning
  // module's contract, so this page never touches their tables.
  const [customers, setCustomers] = useState([])
  const [participants, setParticipants] = useState([])  // [{ id, name, designation, party, … }]
  // The four columns of the attendance sheet — Organiser, Client, Vendor,
  // Third-Party Vendor — each with the companies it can pick from. See
  // MeetingPartyDirectory; the people inside a company are fetched per pick.
  const [parties, setParties] = useState([])
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
  // Any template can be loaded, not just the selected type's — the picker below
  // lists them all with a search box over both names and agenda lines.
  const [templatePicker, setTemplatePicker] = useState(false)
  const [templateQuery,  setTemplateQuery]  = useState('')
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

        storedStartRef.current = m.scheduled_at || null
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
          organizer:        m.organizer || '',
          coordinator:      m.coordinator || '',
          department:       m.department || '',
          client_name:      m.client_name || '',
          client_id:        m.client_id || '',
          work_package:     m.work_package || '',
          project_id:       m.project_id || '',
          is_completed:     m.status === 'Completed',
          // A meeting saved before this change holds 'jitsi' or 'stub', and
          // neither is in the dropdown any more — left as-is the select would
          // show blank and silently re-save nothing. Anything unrecognised
          // falls to the default, which is what the server would pick too.
          meeting_platform: PLATFORM_KEYS.includes(m.meeting_platform) ? m.meeting_platform : DEFAULT_PLATFORM,
        })

        setParticipants((m.attendees || []).map(a => ({
          id: a.id, name: a.name || '', role: a.role || '', organisation: a.organisation || '',
          phone: a.phone || '', designation: a.designation || '', side: a.side || '',
          // The identity links must survive an edit — dropping them here would
          // silently unlink every participant the moment the meeting is re-saved,
          // and the roster would go back to being unreachable typed names.
          email: a.email || '', user_id: a.user_id || '', vendor_contact_id: a.vendor_contact_id || '',
          // Which column of the attendance sheet this person sits in, and where
          // they were picked from. Without these a saved meeting reopens with
          // everybody piled into "not yet placed".
          party: a.party || '', party_ref: a.party_ref || '',
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
          // Links are keyed by the linked row's id — on load a row's id IS its
          // server id, so the agenda_item_id / depends_on_id map straight across.
          agenda_key:      i.agenda_item_id || '',
          depends_key:     i.depends_on_id || '',
        })))

        setAgendaItems((m.agenda_items || []).map(a => ({
          id:               a.id,
          item:             a.item || '',
          owner:            a.owner_names || a.owner?.name || '',
          duration_minutes: a.duration_minutes ?? '',
          priority:         a.priority || '',
          discussion:       a.discussion || '',
          decision:         a.decision || '',
          previous_discussion_ref: a.previous_discussion_ref || '',
          supporting_documents: Array.isArray(a.supporting_documents) ? a.supporting_documents : [],
        })))

        setDecisions((m.decisions || []).map(d => ({
          id: d.id, decision: d.decision || '',
          decided_by: d.decided_by_names || d.decided_by?.name || '',
          impact: d.impact || '',
          effective_date: d.effective_date ? String(d.effective_date).slice(0, 10) : '',
          status: d.status || 'Active',
          agenda_key: d.agenda_item_id || '',
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
    // Customers for the §2 picker. Soft load: a failure leaves it empty rather
    // than blocking the whole form.
    kickoffApi.customers().then(d => { if (Array.isArray(d)) setCustomers(d) }).catch(() => {})
    // The attendance-sheet columns. `kickoffApi` here IS the engine proxy (see
    // the import), so this resolves to Purchase's own endpoint under
    // /app/purchase. Also a soft load — if it fails the grid says it is still
    // loading rather than the form refusing to open.
    kickoffApi.parties()
      .then(d => { if (Array.isArray(d?.parties)) setParties(d.parties) })
      .catch(() => {})
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
    vendorApi.contacts.list(vendorId).then(r => setContacts(r?.data ?? r)).catch(() => setContacts([]))
  }, [vendorApi])

  /**
   * The meeting's length, derived from start and end.
   *
   * Computed ONCE and read by both the Duration field and the summary rail.
   * The two used to disagree: the field computed it live, while the rail printed
   * form.duration_minutes — seeded at 60 and never updated again once duration
   * stopped being a manual field. An 11:10 -> 12:09 meeting therefore read
   * "59 min" on the left and "60 min" on the right.
   */
  const durationLabel = useMemo(() => {
    const st = form.meeting_time, en = form.meeting_end_time
    if (!st || !en || st === en) return '—'
    const [h1, m1] = st.split(':').map(Number)
    const [h2, m2] = en.split(':').map(Number)
    // Add a day when the end is earlier than the start, or a 23:00 -> 00:30
    // meeting would read as minus 22.5 hours.
    let mins = (h2 * 60 + m2) - (h1 * 60 + m1)
    if (mins < 0) mins += 24 * 60
    const h = Math.floor(mins / 60), m = mins % 60
    return `${h ? `${h} hr ` : ''}${m ? `${m} min` : (h ? '' : '0 min')}`.trim()
  }, [form.meeting_time, form.meeting_end_time])

  /**
   * Whether the chosen start has already gone by.
   *
   * `min` on <input type="time"> is not a real guard: browsers mark the field
   * invalid but still let the value be picked or typed, and the attribute is
   * computed at render so it goes stale as the clock moves — a form opened at
   * 11:09 happily accepts 11:10 at 11:12. The submit check catches it, but only
   * after the user has filled the whole form, so this says it immediately.
   *
   * An unchanged stored start is fine: editing an old meeting to write up its
   * minutes must not be flagged as an error.
   */
  const startInPast = useMemo(() => {
    if (!form.meeting_date || !form.meeting_time) return false
    const start = new Date(`${form.meeting_date}T${form.meeting_time}`)
    if (Number.isNaN(start.getTime())) return false
    const stored = storedStartRef.current ? new Date(storedStartRef.current) : null
    if (stored && Math.abs(stored.getTime() - start.getTime()) < 60 * 1000) return false
    return start.getTime() < Date.now() - 2 * 60 * 1000
  }, [form.meeting_date, form.meeting_time])

  const set = (k) => (e) => {
    const val = e.target.type === 'checkbox' ? e.target.checked : e.target.value
    setForm(f => ({ ...f, [k]: val }))
    if (k === 'subject_id') loadContacts(e.target.value)
  }

  // ── participants helpers ─────────────────────────────────────────────────
  const removeParticipant = (id) => setParticipants(p => p.filter(x => x.id !== id))

  /**
   * Add somebody from one column of the attendance sheet.
   *
   * The person arrives whole — name, designation, e-mail, organisation — from
   * the record they are registered in, so nothing here is typed and nothing has
   * to be typed again. `party_ref` is what they were picked from
   * ('tpv_worker:12'), and it doubles as the duplicate check: the same worker
   * cannot appear twice, and the picker greys them out.
   *
   * The e-mail comes along and is stored, but the grid never shows it. It is
   * what the invitation needs, not what identifies somebody on a sheet.
   */
  const addFromParty = (person, party) => {
    if (!person || !party) return
    if (person.ref && participants.some(p => p.party_ref === person.ref)) return
    setParticipants(p => [...p, {
      ...EMPTY_PARTICIPANT(),
      name:         person.name ?? '',
      user_id:      person.user_id ?? null,
      email:        person.email ?? '',
      designation:  person.designation ?? '',
      organisation: person.organisation ?? '',
      side:         party.side ?? 'external',
      party:        party.key,
      party_ref:    person.ref ?? '',
    }])
  }

  /** Fetch one company's registered people for the column that picked it. */
  const loadPartyPeople = useCallback(
    (party, entityId) => kickoffApi.partyPeople(party, entityId).then(d => d?.people ?? []),
    [],
  )


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

  // Every type that actually has a standard agenda, with the numbers a person
  // picks on — how many lines it adds and how long it runs.
  const allTemplates = useMemo(() => (
    Object.entries(templates || {})
      .map(([key, items]) => ({
        key,
        label: meetingTypes[key] || key.replace(/_/g, ' '),
        items: Array.isArray(items) ? items : [],
      }))
      .filter(t => t.items.length)
      .map(t => ({
        ...t,
        minutes: t.items.reduce((sum, i) => sum + (Number(i.duration_minutes) || 0), 0),
      }))
      .sort((a, b) => a.label.localeCompare(b.label))
  ), [templates, meetingTypes])

  // Search matches the template's NAME and its agenda lines, so "audit" finds a
  // template whose title never says audit but whose agenda does.
  const templateMatches = useMemo(() => {
    const q = templateQuery.trim().toLowerCase()
    if (!q) return allTemplates
    return allTemplates.filter(t =>
      t.label.toLowerCase().includes(q)
      || t.key.toLowerCase().includes(q)
      || t.items.some(i => (i.item || '').toLowerCase().includes(q)),
    )
  }, [allTemplates, templateQuery])

  const loadTemplate = (typeKey = form.meeting_type) => {
    const templateForType = templates[typeKey] || []
    if (!templateForType.length) return
    setAgendaItems(prev => {
      const seen = new Set(prev.map(a => (a.item || '').trim().toLowerCase()))
      const push = (line, extra = {}) => {
        const k = (line || '').trim().toLowerCase()
        if (!line || seen.has(k)) return null
        seen.add(k)
        return { ...EMPTY_AGENDA(), item: line, ...extra }
      }
      const fromTemplate = templateForType
        .map(t => push(t.item, { duration_minutes: t.duration_minutes ?? '', priority: t.priority || '' }))
        .filter(Boolean)
      // §4: the template also pulls in the vendor's current live status — one
      // agenda line per flagged section (open incidents, pending CAPA, …).
      const fromStatus = (vendorStatus?.sections || [])
        .filter(s => s.flag && s.agenda)
        .map(s => push(s.agenda))
        .filter(Boolean)
      return [...prev, ...fromTemplate, ...fromStatus]
    })
    setTemplatePicker(false)
  }

  // ── carry-forward from previous meetings ──────────────────────────────────
  // Still-open actions/issues from this vendor's earlier meetings, pulled in as
  // fresh rows that point back at their origin (carried_from_id) — so the same
  // item is never carried twice and the new meeting starts with unfinished work.
  const [carry,     setCarry]     = useState(null)   // { actions:[], issues:[] } | null
  const [carryBusy, setCarryBusy] = useState(false)
  const [carryErr,  setCarryErr]  = useState(null)

  // §4 live vendor status — auto-loaded when a vendor is selected, so a template
  // load can pull the vendor's current workforce/compliance/incident/… status
  // straight into the agenda.
  const [vendorStatus, setVendorStatus] = useState(null)
  useEffect(() => {
    if (!form.subject_id) { setVendorStatus(null); return }
    let live = true
    // While editing, exclude THIS meeting from the vendor's history — it is
    // already saved, so counting it made a vendor's first meeting look like a
    // repeat and offered to carry items forward from the meeting on screen.
    kickoffApi.vendorStatus(form.subject_id, editId || undefined)
      .then(d => { if (live) setVendorStatus(d) })
      .catch(() => { if (live) setVendorStatus(null) })
    return () => { live = false }
  }, [form.subject_id, editId])

  // §18 AI — suggest an agenda from the meeting type + vendor status + open items.
  const [aiBusy, setAiBusy] = useState(false)
  const suggestAgenda = async () => {
    setAiBusy(true); setErr(null)
    try {
      const d = await kickoffApi.aiSuggestAgenda({
        meeting_type: form.meeting_type,
        subject_type: form.subject_id ? 'vendor' : undefined,
        subject_id: form.subject_id || undefined,
      })
      const items = d?.items || []
      if (items.length === 0) { setErr('The AI did not return any agenda items — try Load template.'); return }
      setAgendaItems(prev => {
        const seen = new Set(prev.map(a => (a.item || '').trim().toLowerCase()))
        const add = items
          .filter(t => t.item && !seen.has(t.item.trim().toLowerCase()))
          .map(t => ({ ...EMPTY_AGENDA(), item: t.item, priority: t.priority || '' }))
        return [...prev, ...add]
      })
    } catch (e) {
      setErr(e?.response?.data?.message || 'AI agenda suggestion failed.')
    } finally { setAiBusy(false) }
  }

  // Append an agenda item for one live-status section (skips a duplicate topic).
  const addStatusAgenda = (line) => {
    if (!line) return
    setAgendaItems(prev => {
      const seen = new Set(prev.map(a => (a.item || '').trim().toLowerCase()))
      return seen.has(line.trim().toLowerCase()) ? prev : [...prev, { ...EMPTY_AGENDA(), item: line }]
    })
  }

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
    if (!form.meeting_time) { setErr('Start Time is required.'); return }
    if (!form.meeting_end_time) { setErr('End Time is required.'); return }
    // Equal start and end is a zero-length meeting; an EARLIER end means the
    // meeting runs past midnight and ends the next day (see combineEndDateTime).
    if (form.meeting_end_time === form.meeting_time) { setErr('End Time must be after Start Time.'); return }
    // No scheduling into the past — but only for a start the user actually
    // MOVED. Editing an old meeting (to write up its minutes) keeps its original
    // time, which both backends accept and the client used to refuse.
    {
      const startTs = new Date(`${form.meeting_date}T${form.meeting_time}`)
      const stored = storedStartRef.current ? new Date(storedStartRef.current) : null
      const unchanged = stored && Math.abs(stored.getTime() - startTs.getTime()) < 60 * 1000
      if (!unchanged && startTs.getTime() < Date.now() - 2 * 60 * 1000) {
        setErr('The meeting time cannot be in the past.'); return
      }
    }
    // Location only required for on-site meetings
    if (form.mode !== 'online' && !form.location) { setErr('City / Location is required.'); return }
    // The invitation is mandatory to every participant, so each must be reachable —
    // an e-mail, or a linked Sangoe user / vendor contact (who is notified in-app).
    //
    // A person PICKED from the attendance sheet is exempt. They were chosen from
    // a real record, and some of those records legitimately carry no address: a
    // site worker on a vendor's workforce register has a name, a designation and
    // a mobile, and no company e-mail. Blocking the meeting on that would mean
    // the sheet could not record the people who were actually on site — and
    // there is nothing to type here to fix it, because the grid does not offer
    // an e-mail field. The gap belongs to their own record, not to this meeting.
    {
      const unreachable = participants.find(p =>
        p.name?.trim() && !p.email?.trim() && !p.user_id && !p.vendor_contact_id && !p.party_ref)
      if (unreachable) { setErr(`Add an email for "${unreachable.name}" — the invitation is sent to every participant.`); return }
    }
    setSaving(true); setErr(null)
    try {
      const scheduled_at = combineDateTime(form.meeting_date, form.meeting_time)
      const payload = {
        // Only claim a subject when one was actually picked. subject_type and
        // subject_id are a required_with PAIR server-side, so sending 'vendor'
        // beside an empty id is a guaranteed 422 -- which is what stopped an
        // internal meeting being scheduled at all, even though the column is
        // nullable and the service handles a null subject the whole way down.
        // The AI-agenda call three hundred lines up already had this right.
        subject_type:     form.subject_id ? 'vendor' : undefined,
        subject_id:       form.subject_id || undefined,
        // Full set. The backend keeps the first on kickoffable_* and
        // writes the rest to kickoff_meeting_subjects.
        subject_ids:      vendorIds.length ? vendorIds : undefined,
        meeting_type:     form.meeting_type || 'kickoff',
        title:            form.title || undefined,
        scheduled_at,
        // End is mandatory; duration is derived from start→end server-side, so
        // the client no longer sends duration_minutes.
        end_at:           combineEndDateTime(form.meeting_date, form.meeting_time, form.meeting_end_time),
        planned_date:     form.planned_date || undefined,
        mode:             form.mode,
        // On-site and hybrid both have a physical location; online does not.
        location:         form.mode !== 'online' ? form.location         : undefined,
        location_detail:  form.mode !== 'online' ? form.location_detail  : undefined,
        agenda:           form.agenda || undefined,
        // Meeting.docx §2 detail fields.
        priority:         form.priority || undefined,
        confidentiality:  form.confidentiality || undefined,
        chairperson:      form.chairperson || undefined,
        organizer:        form.organizer || undefined,
        coordinator:      form.coordinator || undefined,
        department:       form.department || undefined,
        client_name:      form.client_name || undefined,
        client_id:        form.client_id || undefined,
        work_package:     form.work_package || undefined,
        project_id:       form.project_id || undefined,
        is_completed:     form.is_completed,
        // Extended fields — backend uses what it knows, ignores the rest
        attendees: participants
          .filter(p => p.name.trim())
          .map(({ name, email, role, organisation, phone, designation, side, user_id, vendor_contact_id, party, party_ref }) => ({
            name, role, organisation,
            // Which of the four attendance-sheet columns, and the opaque origin
            // of the pick — so reopening the meeting rebuilds the same grid.
            party: party || undefined,
            party_ref: party_ref || undefined,
            // Without these two the roster is a list of typed names: no invitation
            // reaches anyone, the minutes go nowhere, and an action assigned to a
            // participant can never resolve to a login (Meeting.docx §5).
            email: email || undefined,
            user_id: user_id || undefined,
            vendor_contact_id: vendor_contact_id || undefined,
            phone: phone || undefined, designation: designation || undefined, side: side || undefined,
          })),
        mom_items: momItems
          .filter(m => m.description.replace(/<[^>]*>/g, '').trim())
          .map(({ id, description, responsible, remarks, target_date, priority, responsible_org, carried_from_id, agenda_key, depends_key }) => ({
            // Integer id = an existing server row → the backend upserts it and
            // keeps its Action-Engine state. A client temp id (non-integer) is new.
            id: Number.isInteger(id) ? id : undefined,
            // Stable key the backend uses to resolve agenda / dependency links
            // (§7/§8) — the row's own id, always sent as a string.
            client_key: String(id),
            description, responsible, remarks,
            target_date: target_date || undefined,
            priority: priority || undefined,
            responsible_org: responsible_org || undefined,
            // Agenda link + action dependency, both keyed by the linked row's id.
            agenda_client_key: agenda_key ? String(agenda_key) : '',
            depends_on_client_key: depends_key ? String(depends_key) : '',
            // Provenance for a carried-forward action; the backend sets it only on
            // create and refuses to carry the same origin twice.
            carried_from_id: carried_from_id || undefined,
          })),
        agenda_items: agendaItems
          .filter(a => a.item.trim())
          .map(({ id, item, owner, duration_minutes, priority, discussion, decision, previous_discussion_ref, supporting_documents }) => ({
            id: Number.isInteger(id) ? id : undefined,
            // The key actions/decisions link to — the row's own id, as a string.
            client_key: String(id),
            item,
            owner: owner || undefined,
            duration_minutes: Number(duration_minutes) || undefined,
            priority: priority || undefined,
            discussion: discussion || undefined,
            decision: decision || undefined,
            previous_discussion_ref: previous_discussion_ref || undefined,
            supporting_documents: (supporting_documents && supporting_documents.length) ? supporting_documents : undefined,
          })),
        decisions: decisions
          .filter(d => d.decision.trim())
          .map(({ id, decision, decided_by, impact, effective_date, status, agenda_key }) => ({
            id: Number.isInteger(id) ? id : undefined,
            decision, decided_by: decided_by || undefined, impact: impact || undefined,
            effective_date: effective_date || undefined, status: status || undefined,
            agenda_client_key: agenda_key ? String(agenda_key) : '',
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
          // THROUGH THE MODULE'S API. This line used to call the shared
          // engine's route directly, so a Purchase meeting id was looked up in
          // kickoff_meetings and came back "No query results for model
          // [App\Models\Shared\KickoffMeeting]" — the link was never created.
          await kickoffApi.generateLink(newId, form.meeting_platform)
        } catch (_) {
          // Non-fatal — the detail page shows a "Generate link" button as fallback
        } finally {
          setGenLink(false)
        }
      }

      navigate(newId ? `${meetingBase()}/kickoff/${newId}` : `${meetingBase()}/kickoff`)
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
          <button onClick={() => navigate(`${meetingBase()}/kickoff`)}
            style={{ width: 34, height: 34, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 3, flexShrink: 0 }}>
            <ArrowLeft size={16} />
          </button>
          <div>
            {/* Breadcrumb */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--text-muted)', marginBottom: 4 }}>
              <span style={{ cursor: 'pointer', color: '#a78bfa' }} onClick={() => navigate(`${meetingBase()}/kickoff`)}>Kickoff Meetings</span>
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
              <button onClick={() => navigate(`${meetingBase()}/kickoff/${editId}`)}
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

      {/* ── Two-column layout ──────────────────────────────────────────
          The summary is a READ-ONLY recap; the left column is where all the
          work happens — agenda rows, action items, decisions, issues, each of
          which is a multi-column grid of its own. `auto-fit` with `1fr` split
          the page 50/50, so the recap was as wide as the form and the agenda
          builder was squeezed into half the screen.

          Now the summary is a fixed narrow rail and the form takes everything
          else. `minmax(0, 1fr)` on the left is load-bearing: a grid track's
          default `min-width: auto` refuses to shrink below its content, so the
          wide inner grids would otherwise push the whole layout sideways
          instead of the columns reflowing.

          Below ~1100px it collapses to a single column, where the summary
          stops being sticky and simply follows the form. */}
      <style>{`
        .ko-form-grid {
          display: grid;
          grid-template-columns: minmax(0, 1fr) 300px;
          gap: 16px;
          align-items: start;
        }
        @media (max-width: 1100px) {
          .ko-form-grid { grid-template-columns: minmax(0, 1fr); }
          .ko-form-grid > .ko-summary { position: static !important; }
        }
      `}</style>
      <div className="ko-form-grid">

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
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
                <Field label="Priority">
                  <SelectInput value={form.priority} onChange={set('priority')} pairs
                    options={[['', '—'], ...mtgPriorities.map(p => [p, p])]} />
                </Field>
                <Field label="Confidentiality">
                  <SelectInput value={form.confidentiality} onChange={set('confidentiality')} pairs
                    options={[['', '—'], ...confLevels.map(c => [c, c])]} />
                </Field>
                <Field label="Meeting Organizer">
                  <TextInput value={form.organizer} onChange={set('organizer')} placeholder="Name (defaults to you)" />
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
                {/* The customer this meeting is for. Picking one links the meeting
                    to the Customer module and puts that customer on the §13
                    distribution list; the free-text field below still takes a
                    name for anyone not in the master. */}
                <Field label="Customer">
                  <SelectInput
                    value={form.client_id}
                    onChange={e => {
                      const id = e.target.value
                      const c = customers.find(x => String(x.id) === String(id))
                      setForm(f => ({ ...f, client_id: id, client_name: c ? (c.company || c.name || f.client_name) : f.client_name }))
                    }}
                    pairs
                    options={[['', customers.length ? '— none —' : 'No customers found'],
                      ...customers.map(c => [String(c.id), c.company || c.name || `Customer #${c.id}`])]} />
                </Field>
                <Field label="Client name (free text)">
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

              {/* Participants — the four-column attendance sheet.
                  What was here was a vertical stack of participant cards, each
                  with seven typed fields. Every external attendee was retyped
                  from a record the system already held, so a vendor's site
                  engineer arrived as a fresh string with no designation and no
                  link back to the person. The grid asks which company, then
                  which of their people, and takes both from the record. */}
              <div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10, gap: 10, flexWrap: 'wrap' }}>
                  <label style={labelStyle}>Participants</label>
                  <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                    Pick people from each party — name and designation come from their record.
                  </span>
                </div>

                <ParticipantGrid
                  parties={parties}
                  chosen={participants}
                  onAdd={addFromParty}
                  onRemove={removeParticipant}
                  loadPeople={loadPartyPeople}
                />
              </div>
            </div>
          </div>

          {/* Section 2 — Schedule & Location */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={CalendarDays}>Schedule &amp; Location</SectionTitle>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
              <Field label="Meeting Date *">
                {/* No past dates for a NEW meeting (min = today). When EDITING we
                    drop the floor so an already-stored past date can still be
                    re-selected — the backend blocks only a genuine move to the
                    past. This was the edit-time bug. */}
                <TextInput type="date" min={isEdit ? undefined : new Date().toLocaleDateString('en-CA')} value={form.meeting_date} onChange={set('meeting_date')} />
              </Field>
              <Field label="Start Time *">
                {/* `min` is advisory only — see startInPast. The message below is
                    the part the user actually sees. */}
                <TextInput type="time" min={form.meeting_date === new Date().toLocaleDateString('en-CA') ? new Date().toTimeString().slice(0, 5) : undefined} value={form.meeting_time} onChange={set('meeting_time')} />
                {startInPast && (
                  <span style={{ fontSize: 11, color: '#f87171', fontWeight: 700 }}>
                    That time has already passed — pick a later one.
                  </span>
                )}
              </Field>
              <Field label="End Time *">
                {/* No `min`: an end EARLIER than the start is legitimate and means
                    the meeting runs past midnight. The hint below says so, so it
                    cannot be mistaken for a typo. */}
                <TextInput type="time" value={form.meeting_end_time} onChange={set('meeting_end_time')} />
                {form.meeting_time && form.meeting_end_time && form.meeting_end_time < form.meeting_time && (
                  <span style={{ fontSize: 11, color: '#f59e0b', fontWeight: 700 }}>Ends next day</span>
                )}
              </Field>
              <Field label="Duration">
                {/* Auto-computed from start→end — no longer a manual field. */}
                <div style={{ padding: '10px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', fontSize: 13.5, fontWeight: 700 }}>
                  {durationLabel}
                </div>
              </Field>
              <Field label="Planned Date (optional)">
                <TextInput type="date" value={form.planned_date} onChange={set('planned_date')} />
              </Field>
            </div>
          </div>

          {/* Section 3 — Meeting Mode */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={MapPin}>Meeting Mode</SectionTitle>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))', gap: 12 }}>
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
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, marginTop: 16 }}>
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

            {/* §4 live vendor status — current workforce/compliance/incident/… state,
                each addable as an agenda line (Load template pulls the flagged ones in).
                Only shown for a RECURRING vendor: a first-ever meeting has no history
                worth reviewing, so the panel is hidden until the vendor has met before. */}
            {vendorStatus?.has_history && vendorStatus?.sections?.length > 0 && (
              <div style={{ marginTop: 18, padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 10 }}>
                  <History size={13} style={{ color: '#a78bfa' }} />
                  <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>Live vendor status</span>
                  <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>· {vendorStatus.vendor?.name}</span>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(190px,1fr))', gap: 8 }}>
                  {vendorStatus.sections.map(s => (
                    <div key={s.key} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '7px 10px', borderRadius: 9, background: 'var(--bg-card)', border: `1px solid ${s.flag ? 'rgba(245,158,11,0.35)' : 'var(--border)'}` }}>
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)' }}>{s.label}</div>
                        <div style={{ fontSize: 12.5, fontWeight: 700, color: s.flag ? '#d97706' : 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{s.value}</div>
                      </div>
                      {s.agenda && (
                        <button type="button" onClick={() => addStatusAgenda(s.agenda)} title="Add to agenda"
                          style={{ width: 24, height: 24, borderRadius: 7, flexShrink: 0, cursor: 'pointer', border: '1px solid var(--border)', background: 'var(--bg-input)', color: '#a78bfa', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}>
                          <Plus size={12} />
                        </button>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Template picker — search over every standard agenda. Closes on the
                X or Cancel only, never on a backdrop click. */}
            {templatePicker && (
              <Overlay onClose={() => setTemplatePicker(false)} width={620}>
                <h3 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Load an agenda template</h3>
                <p style={{ margin: '0 0 16px', fontSize: 12.5, color: 'var(--text-muted)' }}>
                  Lines are appended to the agenda you already have — nothing is overwritten, and a
                  topic already on the list is skipped. This does not change the meeting type.
                </p>

                <div style={{ position: 'relative', marginBottom: 14 }}>
                  <Search size={14} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)', pointerEvents: 'none' }} />
                  <input
                    autoFocus
                    value={templateQuery}
                    onChange={e => setTemplateQuery(e.target.value)}
                    placeholder="Search templates by name or agenda line…"
                    style={{ ...inputStyle, paddingLeft: 34 }}
                  />
                </div>

                {templateMatches.length === 0 ? (
                  <div style={{ padding: '24px 16px', borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center' }}>
                    <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>
                      No template matches “{templateQuery}”. Templates are created under
                      Settings → Meeting Types.
                    </p>
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 8, maxHeight: '46vh', overflowY: 'auto' }}>
                    {templateMatches.map(t => (
                      <button
                        key={t.key}
                        type="button"
                        onClick={() => loadTemplate(t.key)}
                        style={{
                          textAlign: 'left', padding: '12px 14px', borderRadius: 10, cursor: 'pointer',
                          border: `1px solid ${t.key === form.meeting_type ? 'rgba(124,58,237,0.45)' : 'var(--border)'}`,
                          background: t.key === form.meeting_type ? 'rgba(124,58,237,0.08)' : 'var(--bg-input)',
                        }}>
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
                          <strong style={{ fontSize: 13, color: 'var(--text-h)' }}>
                            {t.label}
                            {t.key === form.meeting_type && (
                              <span style={{ marginLeft: 8, fontSize: 10, fontWeight: 800, color: '#7C3AED' }}>SELECTED TYPE</span>
                            )}
                          </strong>
                          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                            {t.items.length} item{t.items.length === 1 ? '' : 's'}
                            {t.minutes > 0 && ` · ${t.minutes} min`}
                          </span>
                        </div>
                        <div style={{ marginTop: 6, fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
                          {t.items.slice(0, 4).map(i => i.item).filter(Boolean).join(' · ')}
                          {t.items.length > 4 && ` … +${t.items.length - 4} more`}
                        </div>
                      </button>
                    ))}
                  </div>
                )}

                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 18 }}>
                  <button type="button" onClick={() => setTemplatePicker(false)}
                    style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
                    Cancel
                  </button>
                </div>
              </Overlay>
            )}

            {/* Agenda builder — structured items (topic · owner · duration · priority) */}
            <div style={{ marginTop: 18 }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10, gap: 8, flexWrap: 'wrap' }}>
                <label style={labelStyle}>Agenda</label>
                <div style={{ display: 'flex', gap: 8 }}>
                  {/* Standard agenda for the selected type — appended, never destructive. */}
                  {templateForType.length > 0 && (
                    <button type="button" onClick={() => loadTemplate()} style={addBtn}
                      title={`Load the standard ${meetingTypes[form.meeting_type] || ''} agenda`}>
                      <FileText size={13} /> Load {meetingTypes[form.meeting_type] || 'standard'} template
                    </button>
                  )}
                  {/* Any OTHER template. The one-click above only ever offered the
                      selected type's agenda, so the rest were unreachable here. */}
                  {allTemplates.length > 0 && (
                    <button type="button" onClick={() => { setTemplateQuery(''); setTemplatePicker(true) }} style={addBtn}
                      title="Search and load any agenda template">
                      <LayoutTemplate size={13} /> Browse templates ({allTemplates.length})
                    </button>
                  )}
                  {/* §18 — AI drafts an agenda from the type, vendor status and open items. */}
                  <button type="button" onClick={suggestAgenda} disabled={aiBusy} style={{ ...addBtn, cursor: aiBusy ? 'wait' : 'pointer' }}
                    title="Let AI suggest an agenda from the vendor's current status and open items">
                    <Sparkles size={13} /> {aiBusy ? 'Thinking…' : 'AI suggest'}
                  </button>
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
                  {/* Column headers so the compact fields stay labelled even once
                      the user starts typing (placeholders alone vanish). */}
                  <div style={{ display: 'grid', gridTemplateColumns: '22px 1fr 0.9fr 0.7fr 0.8fr 30px', gap: 8, padding: '0 2px' }}>
                    {['', 'Agenda item', 'Owner', 'Min', 'Priority', ''].map((h, hi) => (
                      <span key={hi} style={{ fontSize: 10.5, fontWeight: 800, letterSpacing: '0.03em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{h}</span>
                    ))}
                  </div>
                  {agendaItems.map((a, i) => (
                    <div key={a.id} style={{ padding: '10px 12px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                      <div style={{ display: 'grid', gridTemplateColumns: '22px 1fr 0.9fr 0.7fr 0.8fr 30px', gap: 8, alignItems: 'center' }}>
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
                      {/* Meeting.docx §7 — what was discussed under this item and what
                          was settled. Filled in during/after the meeting; the action
                          that comes out of it is captured in the Minutes section and
                          linked back to this row. */}
                      {/* Discussion & Decision use the same rich editor as the
                          Minutes Description, with room to write. Auto-fit so the
                          two editors stack on a narrow screen instead of squashing. */}
                      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 10, marginTop: 8, paddingLeft: 30 }}>
                        <div>
                          <label style={agLabel}>Discussion</label>
                          <RichTextEditor value={a.discussion} onChange={v => setAgenda(a.id, 'discussion', v)}
                            placeholder="What was said under this item…" minHeight={150} />
                        </div>
                        <div>
                          <label style={agLabel}>Decision</label>
                          <RichTextEditor value={a.decision} onChange={v => setAgenda(a.id, 'decision', v)}
                            placeholder="Decision taken on this item…" minHeight={150} />
                        </div>
                      </div>
                      {/* Meeting.docx §7 — where this topic was last discussed, and
                          the supporting documents for the item. */}
                      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 10, marginTop: 10, paddingLeft: 30 }}>
                        <div>
                          <label style={agLabel}>Previous discussion reference</label>
                          <TextInput value={a.previous_discussion_ref || ''} onChange={e => setAgenda(a.id, 'previous_discussion_ref', e.target.value)}
                            placeholder="e.g. MTG-2026-0007 · item 3" />
                        </div>
                        <div>
                          <label style={agLabel}>Supporting documents</label>
                          <TextInput value={(a.supporting_documents || []).join(', ')}
                            onChange={e => setAgenda(a.id, 'supporting_documents', e.target.value.split(',').map(s => s.trim()).filter(Boolean))}
                            placeholder="Comma-separated names / links" />
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}

              {/* A roomy free-text notepad for anything the structured rows don't
                  capture — big enough to stay readable with long notes. */}
              <div style={{ marginTop: 14 }}>
                <Field label="Notepad / agenda notes (optional)">
                  <textarea
                    value={form.agenda}
                    onChange={set('agenda')}
                    rows={8}
                    placeholder="Scratch notes, extra context, links, or a pasted agenda — anything that doesn't fit the structured rows above…"
                    style={{ ...inputStyle, resize: 'vertical', fontFamily: 'inherit', lineHeight: 1.6, minHeight: 160 }}
                  />
                </Field>
              </div>
            </div>
          </div>

          {/* Carry forward — open actions/issues from this vendor's earlier meetings.
              Hidden on a vendor's FIRST meeting: there is nothing to carry until the
              vendor has had a prior meeting (has_history). */}
          {vendorStatus?.has_history && (
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
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))', gap: 8 }}>
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
              (carry.issues.length === 0) ? (
                <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>
                  No open issues from previous meetings for this vendor.
                </p>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                  {/* Open ACTIONS are deliberately not offered here. Carrying one
                      forward wrote it into this meeting's minutes, and minutes are
                      no longer authored on the scheduling form — the item would be
                      added to something invisible. Open actions are carried forward
                      on the meeting itself, once it is under way. */}
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
          )}

          /*
           * Section 4 — Minutes of Meeting — used to live here.
           *
           * Minutes record what a meeting DECIDED, so authoring them on the
           * form that schedules it means writing the record of a conversation
           * that has not happened. The same rule now holds on the server:
           * MomGate refuses to generate or upload minutes until the meeting is
           * marked Completed.
           *
           * Minutes are captured on the meeting itself, after it takes place.
           */

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
                    <Field label="Decision *"><TextInput value={d.decision} onChange={e => setDecision(d.id, 'decision', e.target.value)} placeholder="Decision taken…" /></Field>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 10, marginTop: 10 }}>
                      <Field label="Decision maker"><TextInput value={d.decided_by} onChange={e => setDecision(d.id, 'decided_by', e.target.value)} placeholder="Name" /></Field>
                      <Field label="Impact"><TextInput value={d.impact} onChange={e => setDecision(d.id, 'impact', e.target.value)} placeholder="e.g. Schedule / Cost" /></Field>
                      <Field label="Effective date"><TextInput type="date" value={d.effective_date} onChange={e => setDecision(d.id, 'effective_date', e.target.value)} /></Field>
                      <Field label="Status"><SelectInput value={d.status} onChange={e => setDecision(d.id, 'status', e.target.value)} pairs options={[['Active', 'Active'], ['Superseded', 'Superseded'], ['Rescinded', 'Rescinded']]} /></Field>
                      {/* Agenda item this decision was taken under (Meeting.docx §7) */}
                      <Field label="Agenda item">{(() => {
                        const opts = agendaItems.filter(a => a.item.trim())
                        return <SelectInput value={d.agenda_key} onChange={e => setDecision(d.id, 'agenda_key', e.target.value)} pairs
                          options={opts.length
                            ? [['', '— none —'], ...opts.map(a => [String(a.id), truncate(a.item, 40)])]
                            : [['', 'Add agenda items above first']]} />
                      })()}</Field>
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
                    <Field label="Issue *"><TextInput value={it.title} onChange={e => setIssue(it.id, 'title', e.target.value)} placeholder="Describe the issue…" /></Field>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 10, marginTop: 10 }}>
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
        <div className="ko-summary" style={{ position: 'sticky', top: 16 }}>
          <div className="pr-glass" style={{ padding: 16 }}>
            <SectionTitle icon={CalendarDays}>Meeting Summary</SectionTitle>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
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
                {durationLabel}
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
              {/* Blocked, not just warned, while the start is in the past — the
                  submit check would reject it anyway, and refusing up front
                  beats filling in the whole form first. */}
              <button onClick={save} disabled={saving || generatingLink || startInPast}
                title={startInPast ? 'The start time has already passed' : undefined}
                style={{
                  display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 7,
                  padding: '11px 20px', borderRadius: 11, border: 'none',
                  cursor: (saving || generatingLink) ? 'wait' : (startInPast ? 'not-allowed' : 'pointer'),
                  fontSize: 13.5, fontWeight: 800, color: '#fff',
                  background: (saving || generatingLink || startInPast) ? 'rgba(124,58,237,0.5)' : 'linear-gradient(145deg,#a78bfa,#7C3AED)',
                  boxShadow: (saving || generatingLink || startInPast) ? 'none' : '0 8px 22px -6px rgba(124,58,237,.6)',
                }}>
                {generatingLink ? 'Generating link…' : saving ? 'Saving…' : (isEdit ? 'Save Changes' : 'Save as Draft')}
              </button>
              {!isEdit && (
                <div style={{ fontSize: 11.5, color: 'var(--text-muted)', textAlign: 'center', marginTop: -2 }}>
                  Saved as a draft — nobody is notified until you <strong style={{ color: 'var(--text-h)' }}>Publish</strong> it from the meeting page.
                </div>
              )}
              <button onClick={() => navigate(`${meetingBase()}/kickoff`)} disabled={saving}
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
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10, borderBottom: '1px solid var(--border)', paddingBottom: 7 }}>
      <span style={{ color: 'var(--text-muted)', fontSize: 11.5, flexShrink: 0 }}>{label}</span>
      {/* minWidth 0 + overflowWrap: a flex item will not shrink below its
          content by default, so a long company name would push past the edge
          of the narrow rail rather than wrapping inside it. */}
      <span style={{
        fontSize: 12, fontWeight: 600, color: 'var(--text-h)', textAlign: 'right',
        minWidth: 0, overflowWrap: 'anywhere',
      }}>{children}</span>
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

// Small uppercase caption used to label the agenda-row sub-fields.
const agLabel = {
  fontSize: 10.5, fontWeight: 800, letterSpacing: '0.03em',
  textTransform: 'uppercase', color: 'var(--text-muted)',
  display: 'block', marginBottom: 4,
}
