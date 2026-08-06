import { useState, useEffect, useRef, Fragment } from 'react'
import { createPortal } from 'react-dom'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Building2, Phone, Receipt, Wallet, CreditCard,
  Globe, Linkedin, Facebook, Instagram, Twitter,
  Package, Users2, UserPlus, Link2, Plus, Trash2, Eye, EyeOff, Upload,
  FileText, KeyRound, Bell, StickyNote, MapPin, Edit2, X, ChevronDown,
  ClipboardList, FileX, IndianRupee, RefreshCw, FileSignature, Percent, Truck, LifeBuoy, Paperclip, Send,
} from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import CustomFieldInput, { cfWidthStyle } from '../components/CustomFieldInput'
import ParentCompanyPicker from '../components/ParentCompanyPicker'
import StepperNav from '../components/StepperNav'
import ConfirmIconButton from '../components/ConfirmIconButton'
import ToggleSwitch from '../components/ToggleSwitch'
import ContactPermissions, { DEFAULT_PERMISSIONS, DEFAULT_NOTIFICATIONS, permissionsFromLegacy } from '../components/ContactPermissions'
import NotesTab from '../components/NotesTab'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { MoneyToggle, useMoneyFmt } from '@/components/ui/Money'
import SupportTab from '../components/SupportTab'
import MapTab from '../components/MapTab'
import CustomFieldForm from '../components/CustomFieldForm'
import AdminOrderPicker from '../components/AdminOrderPicker'
import RecordTab from '../components/RecordTab'
import { CONTRACTS, EXPENSES, SUBSCRIPTIONS, PRE_ALERTS, PACKAGES, SHIPMENTS } from '../components/recordSchemas'
import { CURRENCIES, LANGUAGES } from '../components/customerFormConstants'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const d10 = s => (s ? String(s).slice(0, 10) : '—')

// Full legacy tab set, minus the excluded sections (Projects, Tasks, Delivery
// Notes, Domain Manager, Appointments). Support = read-only Helpdesk loop-in.
// "Attachments" is the legacy "Files" tab (renamed).
// Tabs grouped for the profile navigation (meeting feedback: the flat list of
// 24 buttons was hard to scan). Grouping mirrors the sidebar's mental model.
const TAB_GROUPS = [
  { label: 'Overview', color: '#7c3aed', tabs: [
    ['Profile', Building2], ['Contacts', Users2], ['Notes', StickyNote], ['Reminders', Bell],
  ]},
  { label: 'Finance', color: '#0d9488', tabs: [
    ['Statement', Wallet], ['Invoices', Receipt], ['Payments', CreditCard], ['Proposals', FileText],
    ['Estimates', ClipboardList], ['Credit Notes', FileX], ['Expenses', IndianRupee],
    ['Subscriptions', RefreshCw], ['Contracts', FileSignature], ['Tax', Percent],
  ]},
  { label: 'Logistics', color: '#2563eb', tabs: [
    ['Address Book', MapPin], ['Recipients', UserPlus], ['Pre-Alert', Send], ['Packages', Package], ['Shipping', Truck],
  ]},
  { label: 'More', color: '#d97706', tabs: [
    ['Support', LifeBuoy], ['Vault', KeyRound], ['Attachments', Paperclip], ['Map', Globe], ['TPV & Leads', Link2],
  ]},
]
const TABS = TAB_GROUPS.flatMap(g => g.tabs.map(([t]) => t))

// Tabs whose owning module isn't built in Sangoe yet — shown (to match the
// legacy layout) with an honest placeholder instead of fake data. Value = the
// module the feature belongs to.
// Tabs backed by the generic per-customer RecordTab (schema-driven CRUD).
const RECORD_TABS = {
  Contracts: CONTRACTS,
  Expenses: EXPENSES,
  Subscriptions: SUBSCRIPTIONS,
  'Pre-Alert': PRE_ALERTS,
  Packages: PACKAGES,
  Shipping: SHIPMENTS,
}

export default function CustomerDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const toast = useToast()

  const mfmt = useMoneyFmt()
  const [client, setClient] = useState(null)
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState('Profile')
  const [tabData, setTabData] = useState({})   // { [tab]: data }
  const [recordOptions, setRecordOptions] = useState({ expenseCategories: [], projects: [] })
  const [tabLoading, setTabLoading] = useState(false)

  const loadClient = () => customerApi.get(id).then(setClient)

  useEffect(() => {
    loadClient()
      .catch(e => { toast.error(e.message); nav('/app/customers') })
      .finally(() => setLoading(false))
  }, [id])

  // One-click active/inactive toggle (same as the old CRM's customer switch).
  const toggleActive = async () => {
    try {
      const res = await customerApi.toggleActive(id)
      setClient(c => ({ ...c, active: res.active }))
      toast.success(res.active ? 'Customer activated' : 'Customer deactivated')
    } catch (e) { toast.error(e.message) }
  }

  // Lazy-load each tab's data the first time it's opened.
  const TAB_FETCHERS = {
    Contacts: customerApi.contacts.list,
    Tax: customerApi.taxSummary,
    Support: customerApi.tickets,
    Invoices: customerApi.invoices,
    Payments: customerApi.payments,
    Proposals: customerApi.proposals,
    Estimates: customerApi.estimates,
    'Credit Notes': customerApi.creditNotes,
    Statement: customerApi.statement,
    Notes: customerApi.notes.list,
    Reminders: customerApi.reminders.list,
    Vault: customerApi.vault.list,
    Attachments: customerApi.attachments.list,
    'Address Book': customerApi.addresses.list,
    Recipients: customerApi.recipients.list,
  }

  const refreshTab = async (t) => {
    const fetcher = TAB_FETCHERS[t]
    if (!fetcher) return
    try {
      const data = await fetcher(id)
      setTabData(prev => ({ ...prev, [t]: data }))
    } catch (e) {
      // Surface the error and leave the tab uncached so re-entry retries it,
      // rather than silently showing a blank pane forever.
      toast.error(e.message || `Failed to load ${t}`)
      setTabData(prev => { const next = { ...prev }; delete next[t]; return next })
    }
  }

  // Picklist sources for schema-driven record tabs (fetched once, lazily).
  useEffect(() => {
    if (tab !== 'Expenses' || recordOptions.expenseCategories.length) return
    Promise.all([
      customerApi.expenseCategories.list().catch(() => []),
      customerApi.projects.list().catch(() => []),
    ]).then(([cats, projects]) => setRecordOptions({
      // Categories persist as text; projects persist as an ID so the relation
      // is real the moment the Projects module ships.
      expenseCategories: cats.filter(c => c.active).map(c => c.name),
      projects: projects.map(p => ({ value: p.id, label: p.name })),
    }))
  }, [tab])

  useEffect(() => {
    if (!TAB_FETCHERS[tab] || tabData[tab] !== undefined) return
    setTabLoading(true)
    refreshTab(tab).finally(() => setTabLoading(false))
  }, [tab])

  // "Create new X" button that jumps to the Sales create flow with this
  // customer preselected.
  const createBtn = (label, to) => (
    <button onClick={() => nav(to)} className="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
      <Plus size={13} /> {label}
    </button>
  )

  if (loading) return <div className="p-6"><div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} /></div>
  if (!client) return null

  const fin = client.financials || {}
  const social = client.social_links || {}
  const socialLinks = [
    { k: 'website', url: client.website, Icon: Globe },
    { k: 'linkedin', url: social.linkedin, Icon: Linkedin },
    { k: 'facebook', url: social.facebook, Icon: Facebook },
    { k: 'instagram', url: social.instagram, Icon: Instagram },
    { k: 'twitter', url: social.twitter, Icon: Twitter },
  ].filter(s => s.url)

  const INFO = [
    { label: 'Outstanding Balance', value: mfmt(fin.outstanding), icon: Wallet, color: '#ef4444' },
    { label: 'Available Credit', value: mfmt(fin.available_credit), icon: CreditCard, color: '#10b981' },
    { label: 'GST Number', value: client.gst_number || '—', icon: Receipt, color: '#7C3AED' },
    { label: 'Phone', value: client.phone || '—', icon: Phone, color: '#3b82f6' },
  ]

  const data = tabData[tab]

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease]">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => nav('/app/customers')} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div className="w-12 h-12 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <Building2 size={22} style={{ color: 'var(--accent)' }} />
          </div>
          <div>
            <h1 className="font-black" style={{ fontSize: 'clamp(1.2rem,2vw,1.6rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{client.company}</h1>
            <div className="flex items-center gap-2 mt-0.5">
              <ToggleSwitch checked={client.active} onChange={toggleActive} title="Toggle active/inactive" size="sm" />
              <span className="text-[10px] font-bold" style={{ color: client.active ? '#10b981' : '#94a3b8' }}>{client.active ? 'Active' : 'Inactive'}</span>
              {client.parent_company && <span className="text-xs ml-1" style={{ color: 'var(--text-muted)' }}>Parent: <b style={{ color: 'var(--text-h)' }}>{client.parent_company}</b></span>}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2">
        <MoneyToggle />
        {socialLinks.length > 0 && (
          <div className="flex items-center gap-1.5">
            {socialLinks.map(({ k, url, Icon }) => (
              <a key={k} href={url.startsWith('http') ? url : `https://${url}`} target="_blank" rel="noreferrer"
                className="w-8 h-8 rounded-xl flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}>
                <Icon size={14} style={{ color: 'var(--text-muted)' }} />
              </a>
            ))}
          </div>
        )}
        </div>
      </div>

      {/* Basic-info KPI block */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {INFO.map(({ label, value, icon: Icon, color }) => (
          <div key={label} className="card-3d flex items-center gap-3" style={{ padding: '18px' }}>
            <div className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ background: `${color}1a` }}>
              <Icon size={18} style={{ color }} />
            </div>
            <div className="min-w-0">
              <p className="font-black truncate" style={{ color: 'var(--text-h)', fontSize: '1.1rem' }}>{value}</p>
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{label}</p>
            </div>
          </div>
        ))}
      </div>

      {/* Two-column layout: grouped section menu (left) + content (right) */}
      <div className="flex flex-col lg:flex-row gap-5 items-start">
        {/* Section side-menu */}
        <nav className="profile-sidenav card-3d w-full lg:w-60 lg:flex-shrink-0" style={{ padding: '10px' }}>
          {TAB_GROUPS.map(g => (
            <div key={g.label} className="sidenav-group" style={{ '--g': g.color }}>
              <span className="sidenav-label">{g.label}</span>
              {g.tabs.map(([t, Icon]) => (
                <button key={t} onClick={() => setTab(t)} className={`sidenav-item${tab === t ? ' is-active' : ''}`} style={{ '--g': g.color }}>
                  <span className="sidenav-ico"><Icon size={14} /></span>
                  <span className="truncate">{t}</span>
                </button>
              ))}
            </div>
          ))}
        </nav>

        {/* ── Tab content ── */}
        <div className="flex-1 min-w-0 w-full space-y-6">
      {tab === 'Profile' && <ProfileTab client={client} reload={loadClient} toast={toast} />}
      {tab === 'Contacts' && <ContactsTab id={id} contacts={data ?? client.contacts} reload={() => refreshTab('Contacts')} toast={toast} />}
      {tab === 'Address Book' && <AddressBookTab id={id} addresses={data} reload={() => refreshTab('Address Book')} toast={toast} />}
      {tab === 'Recipients' && <RecipientsTab id={id} recipients={data} reload={() => refreshTab('Recipients')} toast={toast} />}

      {tabLoading && TAB_FETCHERS[tab] && data === undefined && (
        <div className="skeleton h-24 rounded-2xl" style={{ background: 'var(--border)' }} />
      )}

      {tab === 'Notes' && <NotesTab id={id} client={client} notes={data} reload={() => refreshTab('Notes')} toast={toast} />}
      {tab === 'Statement' && data && <StatementTab statement={data} />}
      {tab === 'Invoices' && data && <DocTable columns={INVOICE_COLS} rows={data} empty="No invoices for this customer." action={createBtn('New Invoice', `/app/sales/invoices?client_id=${id}&new=1`)} renderExpanded={r => <InvoicePayments invoice={r} />} />}
      {tab === 'Payments' && data && <DocTable columns={PAYMENT_COLS} rows={data} empty="No payments recorded." />}
      {tab === 'Proposals' && data && <DocTable columns={PROPOSAL_COLS} rows={data} empty="No proposals for this customer." action={createBtn('New Proposal', `/app/sales/proposals/new?client_id=${id}`)} />}
      {tab === 'Estimates' && data && <DocTable columns={ESTIMATE_COLS} rows={data} empty="No estimates for this customer." action={createBtn('New Estimate', `/app/sales/estimates?client_id=${id}&new=1`)} />}
      {tab === 'Credit Notes' && data && <DocTable columns={CREDIT_COLS} rows={data} empty="No credit notes." action={createBtn('New Credit Note', `/app/sales/credit-notes?client_id=${id}&new=1`)} />}
      {tab === 'Tax' && data && <TaxTab tax={data} />}
      {tab === 'Support' && data && <SupportTab id={id} tickets={data} reload={() => refreshTab('Support')} toast={toast} />}
      {tab === 'Vault' && <VaultTab id={id} entries={data} reload={() => refreshTab('Vault')} toast={toast} />}
      {tab === 'Reminders' && <RemindersTab id={id} reminders={data} reload={() => refreshTab('Reminders')} toast={toast} />}
      {tab === 'Attachments' && <AttachmentsTab id={id} files={data} reload={() => refreshTab('Attachments')} toast={toast} />}
      {tab === 'Map' && <MapTab client={client} reload={loadClient} toast={toast} />}
      {tab === 'TPV & Leads' && <RelatedTab client={client} />}

      {RECORD_TABS[tab] && <RecordTab clientId={id} schema={RECORD_TABS[tab]} dynamicOptions={recordOptions} />}
        </div>
      </div>
    </div>
  )
}

/* ══════════════ Tab components ══════════════ */

// Sequence per meeting 1.1: Details → Billing & Shipping → Customer Admins → Custom Fields.
const PROFILE_SUBTABS = ['Customer Details', 'Billing & Shipping', 'Customer Admins', 'Custom Fields']
const PROFILE_STEPS = [
  { key: 'Customer Details', label: 'Customer Details' },
  { key: 'Billing & Shipping', label: 'Billing & Shipping' },
  { key: 'Customer Admins', label: 'Customer Admins' },
  { key: 'Custom Fields', label: 'Custom Fields', optional: true },
]

/**
 * Editable customer profile with the legacy sub-tab layout
 * (Customer Details / Custom Fields / Billing & Shipping / Customer Admins).
 * Saves via customerApi.update, then refreshes the parent.
 */
function ProfileTab({ client, reload, toast }) {
  const [sub, setSub] = useState('Customer Details')
  const [saving, setSaving] = useState(false)
  const [groupOptions, setGroupOptions] = useState([])
  const [cfDefs, setCfDefs] = useState([])
  const [quickField, setQuickField] = useState(false)
  const [adminData, setAdminData] = useState({ assigned: [], assignable: [] })

  const seed = () => {
    const cf = {}
    ;(client.custom_fields ?? []).forEach(f => { cf[f.id] = f.value ?? '' })
    return {
      company: client.company || '', gst_number: client.gst_number || '', phone: client.phone || '',
      website: client.website || '', parent_company: client.parent_company || '',
      opening_balance: client.opening_balance ?? '', opening_balance_date: client.opening_balance_date ? String(client.opening_balance_date).slice(0, 10) : '',
      default_currency: client.default_currency || 'INR', default_language: client.default_language || 'English',
      show_primary_contact: !!client.show_primary_contact,
      foundation_date: client.foundation_date ? String(client.foundation_date).slice(0, 10) : '',
      dob: client.dob ? String(client.dob).slice(0, 10) : '', anniversary_date: client.anniversary_date ? String(client.anniversary_date).slice(0, 10) : '',
      address: client.address || '', city: client.city || '', state: client.state || '', zip: client.zip || '', country: client.country || '',
      billing_street: client.billing_street || '', billing_city: client.billing_city || '', billing_state: client.billing_state || '', billing_zip: client.billing_zip || '', billing_country: client.billing_country || '',
      shipping_street: client.shipping_street || '', shipping_city: client.shipping_city || '', shipping_state: client.shipping_state || '', shipping_zip: client.shipping_zip || '', shipping_country: client.shipping_country || '',
      social_links: client.social_links || {},
      group_ids: (client.groups ?? []).map(g => g.id),
      custom_fields: cf,
      apply_to_previous_documents: false,
      apply_to_previous_credit_notes: false,
    }
  }
  const [form, setForm] = useState(seed)
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => {
    customerApi.groups.list().then(setGroupOptions).catch(() => {})
    customerApi.customFields.list().then(setCfDefs).catch(() => {})
    customerApi.admins(client.id).then(setAdminData).catch(() => {})
  }, [client.id])

  const toggleGroup = (gid) => setForm(p => ({ ...p, group_ids: p.group_ids.includes(gid) ? p.group_ids.filter(x => x !== gid) : [...p.group_ids, gid] }))
  const addGroup = async () => {
    const name = window.prompt('New group name'); if (!name?.trim()) return
    try { const g = await customerApi.groups.create({ name: name.trim() }); setGroupOptions(o => [...o, g]); toggleGroup(g.id) } catch (e) { toast.error(e.message) }
  }
  // Ordered admin sync — payload order = fallback order (primary → 2nd → …)
  const changeAdminOrder = async (orderedIds) => {
    try { const updated = await customerApi.syncAdmins(client.id, orderedIds); setAdminData(d => ({ ...d, assigned: updated })) } catch (e) { toast.error(e.message) }
  }

  const save = async () => {
    if (!form.company.trim()) { setSub('Customer Details'); return toast.error('Company name is required') }
    setSaving(true)
    try {
      await customerApi.update(client.id, {
        ...form,
        // NOT-NULL numeric (DB default 0) — send 0 for an empty box, never null.
        opening_balance: form.opening_balance === '' ? 0 : form.opening_balance,
        opening_balance_date: form.opening_balance_date || null,
      })
      toast.success('Profile saved')
      reload()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const copyToBilling = () => setForm(p => ({ ...p, billing_street: p.address, billing_city: p.city, billing_state: p.state, billing_zip: p.zip, billing_country: p.country }))
  const copyBillingToShipping = () => setForm(p => ({ ...p, shipping_street: p.billing_street, shipping_city: p.billing_city, shipping_state: p.billing_state, shipping_zip: p.billing_zip, shipping_country: p.billing_country }))

  return (
    <div className="card-3d" style={{ padding: 0 }}>
      {/* Step-progress nav (edit mode: any step is clickable) */}
      <div className="px-6 pt-6 pb-5" style={{ borderBottom: '1px solid var(--border)' }}>
        <StepperNav steps={PROFILE_STEPS} current={sub} completed={new Set(PROFILE_SUBTABS.filter(t => t !== sub))} onStepClick={setSub} />
      </div>

      <div className="p-6">
        {sub === 'Customer Details' && (
          <div className="space-y-4">
            <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
              <input type="checkbox" checked={form.show_primary_contact} onChange={e => sf('show_primary_contact', e.target.checked)} />
              Show primary contact full name on Invoices, Estimates, Payments, Credit Notes
            </label>
            <div className="max-w-2xl"><label className="label">Company / Client Name *</label><input className="input-3d text-sm" value={form.company} onChange={e => sf('company', e.target.value)} /></div>
            <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
              <div><label className="label">GST Number</label><input className="input-3d text-sm" value={form.gst_number} onChange={e => sf('gst_number', e.target.value)} /></div>
              <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone} onChange={e => sf('phone', e.target.value)} /></div>
              <div><label className="label">Website</label><input className="input-3d text-sm" value={form.website} onChange={e => sf('website', e.target.value)} /></div>
              <div><label className="label">Parent Company</label><ParentCompanyPicker value={form.parent_company} onChange={v => sf('parent_company', v)} excludeId={id} /></div>
              <div><label className="label">Opening Balance</label><input type="number" className="input-3d text-sm" value={form.opening_balance} onChange={e => sf('opening_balance', e.target.value)} /></div>
              <div><label className="label">Balance as of</label><input type="date" className="input-3d text-sm" value={form.opening_balance_date} onChange={e => sf('opening_balance_date', e.target.value)} /></div>
              <div><label className="label">Currency</label><select className="input-3d text-sm" value={form.default_currency} onChange={e => sf('default_currency', e.target.value)}>{CURRENCIES.map(c => <option key={c} value={c}>{c}</option>)}</select></div>
              <div><label className="label">Default Language</label><select className="input-3d text-sm" value={form.default_language} onChange={e => sf('default_language', e.target.value)}>{LANGUAGES.map(l => <option key={l} value={l}>{l}</option>)}</select></div>
            </div>
            <div>
              <div className="flex items-center justify-between mb-1"><label className="label mb-0">Groups</label><button type="button" onClick={addGroup} className="text-xs font-bold flex items-center gap-1" style={{ color: 'var(--accent)' }}><Plus size={12} /> New group</button></div>
              <div className="flex gap-1.5 flex-wrap">
                {groupOptions.length === 0 ? <span className="text-xs" style={{ color: 'var(--text-muted)' }}>No groups yet.</span> : groupOptions.map(g => {
                  const on = form.group_ids.includes(g.id)
                  return <button key={g.id} type="button" onClick={() => toggleGroup(g.id)} className="px-2.5 py-1 rounded-lg text-[11px] font-bold" style={{ background: on ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: on ? '#fff' : 'var(--text-muted)', border: '1px solid var(--border)' }}>{g.name}</button>
                })}
              </div>
            </div>
            <p className="label-caps pt-4 mt-1" style={{ color: 'var(--accent)' }}>Registered Address</p>
            <div><label className="label">Address</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.address} onChange={e => sf('address', e.target.value)} /></div>
            <div className="grid grid-cols-2 gap-4">
              <div><label className="label">City</label><input className="input-3d text-sm" value={form.city} onChange={e => sf('city', e.target.value)} /></div>
              <div><label className="label">State</label><input className="input-3d text-sm" value={form.state} onChange={e => sf('state', e.target.value)} /></div>
              <div><label className="label">ZIP / PIN</label><input className="input-3d text-sm" value={form.zip} onChange={e => sf('zip', e.target.value)} /></div>
              <div><label className="label">Country</label><input className="input-3d text-sm" value={form.country} onChange={e => sf('country', e.target.value)} /></div>
            </div>
            <p className="label-caps pt-4 mt-1" style={{ color: 'var(--accent)' }}>Key Dates</p>
            <div className="grid grid-cols-3 gap-4">
              <div><label className="label">Founded</label><input type="date" className="input-3d text-sm" value={form.foundation_date} onChange={e => sf('foundation_date', e.target.value)} /></div>
              <div><label className="label">DOB</label><input type="date" className="input-3d text-sm" value={form.dob} onChange={e => sf('dob', e.target.value)} /></div>
              <div><label className="label">Anniversary</label><input type="date" className="input-3d text-sm" value={form.anniversary_date} onChange={e => sf('anniversary_date', e.target.value)} /></div>
            </div>
            <p className="label-caps pt-4 mt-1" style={{ color: 'var(--accent)' }}>Social Links</p>
            <div className="grid grid-cols-2 gap-4">
              {[['linkedin', 'LinkedIn'], ['facebook', 'Facebook'], ['instagram', 'Instagram'], ['twitter', 'Twitter / X']].map(([k, label]) => (
                <div key={k}>
                  <label className="label">{label}</label>
                  <input className="input-3d text-sm" value={form.social_links?.[k] || ''}
                    onChange={e => setForm(p => ({ ...p, social_links: { ...(p.social_links || {}), [k]: e.target.value } }))} />
                </div>
              ))}
            </div>
          </div>
        )}

        {sub === 'Custom Fields' && (
          <div className="space-y-4">
            <div className="flex justify-end">
              <button type="button" onClick={() => setQuickField(q => !q)} className="text-xs font-bold flex items-center gap-1" style={{ color: 'var(--accent)' }}>
                <Plus size={12} /> New field
              </button>
            </div>
            {quickField && (
              <div className="p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <CustomFieldForm
                  fieldTo="customers"
                  onSaved={() => { setQuickField(false); customerApi.customFields.list().then(setCfDefs).catch(() => {}) }}
                  onCancel={() => setQuickField(false)}
                />
              </div>
            )}
            {cfDefs.length === 0 ? <p className="text-sm py-6 text-center" style={{ color: 'var(--text-muted)' }}>No custom fields defined.</p> : (
              <div className="flex flex-wrap gap-4">
                {cfDefs.map(def => (
                  <div key={def.id} style={cfWidthStyle(def.bs_column)}>
                    <label className="label">{def.name}{def.required ? ' *' : ''}</label>
                    <CustomFieldInput def={def} value={form.custom_fields?.[def.id] ?? def.default_value ?? ''} onChange={v => setForm(p => ({ ...p, custom_fields: { ...(p.custom_fields || {}), [def.id]: v } }))} />
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {sub === 'Billing & Shipping' && (
          <div className="space-y-5">
            <div className="grid md:grid-cols-2 gap-6">
              <div className="space-y-3">
                <div className="flex items-center justify-between"><p className="label-caps" style={{ color: 'var(--accent)' }}>Billing Address</p><button type="button" onClick={copyToBilling} className="text-[11px] font-bold" style={{ color: 'var(--accent)' }}>Same as Customer Info</button></div>
                <div><label className="label">Street</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.billing_street} onChange={e => sf('billing_street', e.target.value)} /></div>
                <div><label className="label">City</label><input className="input-3d text-sm" value={form.billing_city} onChange={e => sf('billing_city', e.target.value)} /></div>
                <div><label className="label">State</label><input className="input-3d text-sm" value={form.billing_state} onChange={e => sf('billing_state', e.target.value)} /></div>
                <div className="grid grid-cols-2 gap-3">
                  <div><label className="label">Zip Code</label><input className="input-3d text-sm" value={form.billing_zip} onChange={e => sf('billing_zip', e.target.value)} /></div>
                  <div><label className="label">Country</label><input className="input-3d text-sm" value={form.billing_country} onChange={e => sf('billing_country', e.target.value)} /></div>
                </div>
              </div>
              <div className="space-y-3">
                <div className="flex items-center justify-between"><p className="label-caps" style={{ color: 'var(--accent)' }}>Shipping Address</p><button type="button" onClick={copyBillingToShipping} className="text-[11px] font-bold" style={{ color: 'var(--accent)' }}>Copy Billing Address</button></div>
                <div><label className="label">Street</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.shipping_street} onChange={e => sf('shipping_street', e.target.value)} /></div>
                <div><label className="label">City</label><input className="input-3d text-sm" value={form.shipping_city} onChange={e => sf('shipping_city', e.target.value)} /></div>
                <div><label className="label">State</label><input className="input-3d text-sm" value={form.shipping_state} onChange={e => sf('shipping_state', e.target.value)} /></div>
                <div className="grid grid-cols-2 gap-3">
                  <div><label className="label">Zip Code</label><input className="input-3d text-sm" value={form.shipping_zip} onChange={e => sf('shipping_zip', e.target.value)} /></div>
                  <div><label className="label">Country</label><input className="input-3d text-sm" value={form.shipping_country} onChange={e => sf('shipping_country', e.target.value)} /></div>
                </div>
              </div>
            </div>
            <div className="p-4 rounded-xl space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <label className="flex items-start gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                <input type="checkbox" className="mt-0.5" checked={form.apply_to_previous_documents} onChange={e => sf('apply_to_previous_documents', e.target.checked)} />
                <span>Update the shipping/billing info on all previous invoices/estimates <span style={{ color: '#f59e0b' }}>(includes paid — product decision)</span></span>
              </label>
              <label className="flex items-start gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                <input type="checkbox" className="mt-0.5" checked={form.apply_to_previous_credit_notes} onChange={e => sf('apply_to_previous_credit_notes', e.target.checked)} />
                <span>Update the shipping/billing info on all previous credit notes</span>
              </label>
            </div>
          </div>
        )}

        {sub === 'Customer Admins' && (
          <div className="space-y-3 max-w-xl">
            <AdminOrderPicker
              staff={adminData.assignable}
              value={adminData.assigned.map(a => a.id)}
              onChange={changeAdminOrder}
            />
          </div>
        )}
      </div>

      {/* Submit */}
      <div className="flex justify-end px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
        <button onClick={save} disabled={saving} className="px-6 py-2.5 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          {saving ? 'Saving…' : 'Submit'}
        </button>
      </div>
    </div>
  )
}

const EMPTY_CONTACT = {
  first_name: '', last_name: '', title: '', email: '', phone: '',
  avatar: '', direction: '', password: '',
  is_primary: false, active: true,
  permissions: DEFAULT_PERMISSIONS, email_notifications: DEFAULT_NOTIFICATIONS, emails_enabled: true,
  custom_fields: {},
}

// Random strong-ish password for the "generate" button.
function genPassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%'
  return Array.from({ length: 12 }, () => chars[Math.floor(Math.random() * chars.length)]).join('')
}

function ContactsTab({ id, contacts, reload, toast }) {
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY_CONTACT)
  const [confirmDel, setConfirmDel] = useState(null)
  const [cfDefs, setCfDefs] = useState([])
  const [showPwd, setShowPwd] = useState(false)
  const rows = contacts ?? []

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => { customerApi.customFields.list('contacts').then(setCfDefs).catch(() => {}) }, [])

  const seedCf = (c) => {
    const cf = {}
    ;(c?.custom_fields ?? []).forEach(f => { cf[f.id] = f.value ?? '' })
    return cf
  }

  const openCreate = () => { setEditing(null); setShowPwd(false); setForm({ ...EMPTY_CONTACT, custom_fields: {} }); setModal(true) }
  const openEdit = (c) => {
    setEditing(c); setShowPwd(false)
    setForm({
      first_name: c.first_name || '', last_name: c.last_name || '', title: c.title || '',
      email: c.email || '', phone: c.phone || '', avatar: c.avatar || '', direction: c.direction || '',
      password: '',
      is_primary: !!c.is_primary, active: c.active !== false,
      permissions: c.permissions ?? permissionsFromLegacy(c.email_notifications),
      email_notifications: { ...DEFAULT_NOTIFICATIONS, ...(c.email_notifications || {}) },
      emails_enabled: c.emails_enabled !== false,
      custom_fields: seedCf(c),
    })
    setModal(true)
  }

  const save = async () => {
    if (!form.first_name.trim()) return toast.error('First name required')
    if (form.password && form.password.length < 6) return toast.error('Portal password must be at least 6 characters')
    // Blank password → omit entirely (backend keeps the existing one).
    const payload = { ...form, password: form.password || undefined }
    try {
      if (editing) { await customerApi.contacts.update(id, editing.id, payload); toast.success('Contact updated') }
      else { await customerApi.contacts.create(id, payload); toast.success('Contact added') }
      setModal(false); reload()
    } catch (e) { toast.error(e.message) }
  }
  const toggleActive = async (c) => { try { await customerApi.contacts.toggleActive(id, c.id); reload() } catch (e) { toast.error(e.message) } }
  const doDelete = async () => { try { await customerApi.contacts.remove(id, confirmDel.id); toast.success('Contact deleted'); reload() } catch (e) { toast.error(e.message) } finally { setConfirmDel(null) } }

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
          <Plus size={14} /> New Contact
        </button>
      </div>

      <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              {['Full Name', 'Email', 'Position', 'Phone', 'Primary', 'Active', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps whitespace-nowrap">{h}</th>)}
            </tr></thead>
            <tbody>
              {!rows.length ? (
                <tr><td colSpan="7" className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No contacts yet. Click “New Contact”.</td></tr>
              ) : rows.map(c => (
                <tr key={c.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>
                    <div className="flex items-center gap-2.5">
                      <div className="w-8 h-8 rounded-full overflow-hidden flex-shrink-0 flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
                        {c.avatar ? <img src={c.avatar} alt="" className="w-full h-full object-cover" /> : <span className="text-[11px] font-black" style={{ color: 'var(--accent)' }}>{(c.first_name?.[0] || '?').toUpperCase()}</span>}
                      </div>
                      {c.name || `${c.first_name} ${c.last_name || ''}`}
                    </div>
                  </td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{c.email ? <a href={`mailto:${c.email}`} style={{ color: 'var(--accent)' }}>{c.email}</a> : '—'}</td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{c.title || '—'}</td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{c.phone || '—'}</td>
                  <td className="py-3 px-4">{c.is_primary ? <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)' }}>Primary</span> : '—'}</td>
                  <td className="py-3 px-4">
                    <ToggleSwitch checked={c.active !== false} onChange={() => toggleActive(c)} title="Toggle contact active/inactive" size="sm" />
                  </td>
                  <td className="py-3 px-4">
                    <div className="flex gap-1">
                      <button onClick={() => openEdit(c)} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)]" title="Edit"><Edit2 size={12} style={{ color: 'var(--text-muted)' }} /></button>
                      <button onClick={() => setConfirmDel(c)} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)]" title="Delete"><Trash2 size={12} style={{ color: '#f87171' }} /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {modal && createPortal(
        <>
          <div className="drawer-backdrop" onClick={() => setModal(false)} />
          <div className="drawer-panel" style={{ width: 'min(560px, 96vw)' }}>
            <div className="drawer-header">
              <div>
                <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{editing ? 'Edit Contact' : 'New Contact'}</h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{editing ? 'Update this contact’s details' : 'Add a person under this customer'}</p>
              </div>
              <button onClick={() => setModal(false)} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            <div className="drawer-body space-y-4">
              {/* Profile image */}
              <div className="flex items-center gap-4">
                <div className="w-16 h-16 rounded-2xl overflow-hidden flex-shrink-0 flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid var(--border)' }}>
                  {form.avatar
                    ? <img src={form.avatar} alt="" className="w-full h-full object-cover" />
                    : <span className="font-black text-lg" style={{ color: 'var(--accent)' }}>{(form.first_name?.[0] || '?').toUpperCase()}</span>}
                </div>
                <div>
                  <label className="label">Profile Image</label>
                  <div className="flex items-center gap-2">
                    <label className="px-3 py-1.5 rounded-lg text-xs font-bold cursor-pointer" style={{ background: 'var(--bg-input)', color: 'var(--accent)', border: '1px solid var(--border)' }}>
                      Upload
                      <input type="file" accept="image/*" className="hidden" onChange={e => {
                        const f = e.target.files?.[0]; if (!f) return
                        if (f.size > 400 * 1024) return toast.error('Image must be under 400 KB')
                        const r = new FileReader(); r.onload = () => sf('avatar', r.result); r.readAsDataURL(f)
                      }} />
                    </label>
                    {form.avatar && <button type="button" onClick={() => sf('avatar', '')} className="text-xs font-bold" style={{ color: '#f87171' }}>Remove</button>}
                  </div>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">First Name *</label><input className="input-3d text-sm" value={form.first_name} onChange={e => sf('first_name', e.target.value)} /></div>
                <div><label className="label">Last Name</label><input className="input-3d text-sm" value={form.last_name} onChange={e => sf('last_name', e.target.value)} /></div>
              </div>
              <div><label className="label">Position / Title</label><input className="input-3d text-sm" value={form.title} onChange={e => sf('title', e.target.value)} /></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Email</label><input className="input-3d text-sm" value={form.email} onChange={e => sf('email', e.target.value)} /></div>
                <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone} onChange={e => sf('phone', e.target.value)} /></div>
              </div>
              <div><label className="label">Document Direction</label>
                <select className="input-3d text-sm" value={form.direction || ''} onChange={e => sf('direction', e.target.value)}>
                  <option value="">System default</option>
                  <option value="ltr">LTR (left-to-right)</option>
                  <option value="rtl">RTL (right-to-left)</option>
                </select>
              </div>
              <div>
                <label className="label">Portal Password</label>
                <div className="flex items-center gap-2">
                  <input
                    type={showPwd ? 'text' : 'password'}
                    className="input-3d text-sm flex-1"
                    autoComplete="new-password"
                    placeholder={editing?.has_password ? '••••••••  (leave blank to keep)' : 'Set a login password'}
                    value={form.password || ''}
                    onChange={e => sf('password', e.target.value)}
                  />
                  <button type="button" onClick={() => setShowPwd(s => !s)} title={showPwd ? 'Hide' : 'Show'}
                    className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                    {showPwd ? <EyeOff size={14} /> : <Eye size={14} />}
                  </button>
                  <button type="button" onClick={() => { sf('password', genPassword()); setShowPwd(true) }} title="Generate password"
                    className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ border: '1px solid var(--border)', color: 'var(--accent)' }}>
                    <RefreshCw size={14} />
                  </button>
                </div>
                <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                  The contact's own login for the client portal (min 6 chars). Not a CRM staff account.
                  {editing?.last_password_change && <> · Last changed {d10(editing.last_password_change)}</>}
                </p>
              </div>
              <div className="flex items-center gap-5">
                <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                  <input type="checkbox" checked={form.is_primary} onChange={e => sf('is_primary', e.target.checked)} /> Primary contact
                </label>
                <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                  <input type="checkbox" checked={form.active} onChange={e => sf('active', e.target.checked)} /> Active
                </label>
              </div>
              <ContactPermissions
                permissions={form.permissions}
                notifications={form.email_notifications}
                emailsEnabled={form.emails_enabled}
                onChange={({ permissions, email_notifications, emails_enabled }) => setForm(p => ({ ...p, permissions, email_notifications, emails_enabled }))}
              />

              {cfDefs.length > 0 && (
                <div>
                  <p className="label-caps mb-2" style={{ color: 'var(--accent)' }}>Custom Fields</p>
                  <div className="flex flex-wrap gap-3">
                    {cfDefs.map(def => (
                      <div key={def.id} style={cfWidthStyle(def.bs_column)}>
                        <label className="label">{def.name}{def.required ? ' *' : ''}</label>
                        <CustomFieldInput def={def}
                          value={form.custom_fields?.[def.id] ?? def.default_value ?? ''}
                          onChange={v => setForm(p => ({ ...p, custom_fields: { ...(p.custom_fields || {}), [def.id]: v } }))} />
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>

            <div className="drawer-footer">
              <button onClick={() => setModal(false)} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={save} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white hover:scale-[1.01] transition-all" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>{editing ? 'Save Changes' : 'Add Contact'}</button>
            </div>
          </div>
        </>,
        document.body,
      )}

      {confirmDel && createPortal(
        <ConfirmDialog title="Delete contact?" message={`Remove ${confirmDel.name || confirmDel.first_name} from this customer?`} confirmLabel="Delete" onConfirm={doDelete} onCancel={() => setConfirmDel(null)} />,
        document.body,
      )}
    </div>
  )
}


function StatementTab({ statement }) {
  const mfmt = useMoneyFmt()
  return (
    <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
      <div className="px-4 py-3 flex items-center justify-between" style={{ borderBottom: '1px solid var(--border)' }}>
        <p className="label-caps" style={{ color: 'var(--accent)' }}>Account Statement</p>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Opening: <b style={{ color: 'var(--text-h)' }}>{mfmt(statement.opening_balance)}</b> · Closing: <b style={{ color: 'var(--text-h)' }}>{mfmt(statement.closing_balance)}</b></p>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-xs">
          <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
            {['Date', 'Type', 'Reference', 'Debit', 'Credit', 'Balance'].map(h => <th key={h} className="py-3 px-4 text-left label-caps">{h}</th>)}
          </tr></thead>
          <tbody>
            {!statement.lines?.length ? (
              <tr><td colSpan="6" className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No transactions.</td></tr>
            ) : statement.lines.map((l, i) => (
              <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{d10(l.date)}</td>
                <td className="py-3 px-4" style={{ color: 'var(--text-h)' }}>{l.type}</td>
                <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{l.ref}</td>
                <td className="py-3 px-4" style={{ color: '#ef4444' }}>{l.debit ? mfmt(l.debit) : '—'}</td>
                <td className="py-3 px-4" style={{ color: '#10b981' }}>{l.credit ? mfmt(l.credit) : '—'}</td>
                <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{mfmt(l.balance)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// GST is due by the 10th of the month after the invoice month; an unpaid
// invoice past that date is flagged overdue (meeting item 6).
function gstDueDate(invoiceDate) {
  if (!invoiceDate) return null
  const [y, m] = String(invoiceDate).slice(0, 10).split('-').map(Number)
  if (!y || !m) return null
  return `${m === 12 ? y + 1 : y}-${String(m === 12 ? 1 : m + 1).padStart(2, '0')}-10`
}

function TaxTab({ tax }) {
  const mfmt = useMoneyFmt()
  const today = new Date().toISOString().slice(0, 10)
  const rows = (tax.invoices ?? []).map(r => {
    const due = gstDueDate(r.date)
    return { ...r, gst_due: due, gst_status: r.gst_paid ? 'Paid' : (due && due < today ? '⚠ Overdue' : 'Unpaid') }
  })
  return (
    <div className="space-y-4">
      {/* GST Unpaid goes LAST (meeting item 6) */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <TaxTile label="GST Total" value={mfmt(tax.gst_total)} color="#7C3AED" />
        <TaxTile label="GST Paid" value={mfmt(tax.gst_paid)} color="#10b981" />
        <TaxTile label="TDS Deducted" value={mfmt(tax.tds_deducted)} color="#f59e0b" />
        <TaxTile label="GST Unpaid" value={mfmt(tax.gst_unpaid)} color="#ef4444" />
      </div>
      <DocTable
        columns={[
          { key: 'number', label: 'Invoice', bold: true },
          { key: 'date', label: 'Date', fmt: d10 },
          { key: 'total', label: 'Total', money: true },
          { key: 'gst_amount', label: 'GST Amount', money: true },
          { key: 'gst_due', label: 'GST Due By', fmt: d10 },
          { key: 'gst_status', label: 'GST Status', render: v => <span style={{ color: v === 'Paid' ? '#10b981' : v === 'Unpaid' ? 'var(--text-muted)' : '#ef4444', fontWeight: 700 }}>{v}</span> },
          { key: 'status', label: 'Doc Status' },
        ]}
        rows={rows}
        empty="No invoices for this customer yet."
        title="Invoice GST Breakdown"
      />
    </div>
  )
}



// Old-CRM vault visibility levels.
const VAULT_VISIBILITY = [
  { value: 1, label: 'Visible to all staff members who have access to this customer', badge: 'All staff' },
  { value: 2, label: 'Visible only to administrators', badge: 'Admins only' },
  { value: 3, label: 'Visible only to me', hint: '(administrators are not excluded)', badge: 'Private' },
]
const EMPTY_VAULT = { title: '', username: '', password: '', url: '', notes: '', visibility: 1, share_in_projects: false }

function VaultTab({ id, entries, reload, toast }) {
  const [form, setForm] = useState(EMPTY_VAULT)
  const [adding, setAdding] = useState(false)
  const [revealed, setRevealed] = useState({})
  const add = async () => {
    if (!form.title.trim()) return toast.error('Title required')
    try { await customerApi.vault.create(id, form); setForm(EMPTY_VAULT); setAdding(false); reload() }
    catch (e) { toast.error(e.message) }
  }
  const reveal = async (eid) => {
    try { const r = await customerApi.vault.reveal(id, eid); setRevealed(p => ({ ...p, [eid]: r.password })) }
    catch (e) { toast.error(e.message) }
  }
  const del = async (eid) => { try { await customerApi.vault.remove(id, eid); reload() } catch (e) { toast.error(e.message) } }
  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={() => setAdding(a => !a)} className="px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-1" style={{ background: 'var(--bg-input)', color: 'var(--accent)', border: '1px solid var(--border)' }}><Plus size={13} /> New credential</button>
      </div>
      {adding && (
        <div className="card-3d space-y-3" style={{ padding: '16px' }}>
          <div className="grid grid-cols-2 gap-3">
            <input className="input-3d text-sm" placeholder="Title *" value={form.title} onChange={e => setForm(p => ({ ...p, title: e.target.value }))} />
            <input className="input-3d text-sm" placeholder="URL" value={form.url} onChange={e => setForm(p => ({ ...p, url: e.target.value }))} />
            <input className="input-3d text-sm" placeholder="Username" value={form.username} onChange={e => setForm(p => ({ ...p, username: e.target.value }))} />
            <input className="input-3d text-sm" placeholder="Password" value={form.password} onChange={e => setForm(p => ({ ...p, password: e.target.value }))} />
          </div>
          <div>
            <label className="label">Notes</label>
            <RichTextEditor value={form.notes} onChange={v => setForm(p => ({ ...p, notes: v }))} placeholder="Server details, access instructions, anything…" minHeight={140} />
          </div>

          <div>
            <p className="label-caps mb-2" style={{ color: 'var(--accent)' }}>Visibility</p>
            <div className="space-y-1.5 p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              {VAULT_VISIBILITY.map(o => (
                <label key={o.value} className="flex items-start gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-h)' }}>
                  <input type="radio" name="vault-visibility" className="mt-0.5" checked={Number(form.visibility) === o.value}
                    onChange={() => setForm(p => ({ ...p, visibility: o.value }))} />
                  <span>{o.label}{o.hint && <span style={{ color: 'var(--text-muted)' }}> {o.hint}</span>}</span>
                </label>
              ))}
            </div>
          </div>

          <label className="flex items-start gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-h)' }}>
            <input type="checkbox" className="mt-0.5" checked={!!form.share_in_projects}
              onChange={e => setForm(p => ({ ...p, share_in_projects: e.target.checked }))} />
            <span>Share this vault entry in projects with project members
              <span style={{ color: 'var(--text-muted)' }}> — applies to this customer's projects once the Projects module ships.</span>
            </span>
          </label>

          <div className="flex justify-end gap-2">
            <button onClick={() => setAdding(false)} className="px-3 py-1.5 rounded-lg text-xs" style={{ color: 'var(--text-muted)' }}>Cancel</button>
            <button onClick={add} className="px-4 py-1.5 rounded-lg text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Save</button>
          </div>
        </div>
      )}
      {!entries?.length ? <Empty text="No stored credentials." icon={KeyRound} /> : entries.map(v => {
        const vis = VAULT_VISIBILITY.find(o => o.value === Number(v.visibility)) || VAULT_VISIBILITY[0]
        return (
          <div key={v.id} className="card-3d" style={{ padding: 0, overflow: 'hidden' }}>
            <div className="flex items-start gap-3 p-4">
              <div className="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0" style={{ background: 'rgba(124,58,237,0.12)' }}>
                <KeyRound size={17} style={{ color: 'var(--accent)' }} />
              </div>

              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{v.title}</p>
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>{vis.badge}</span>
                  {v.share_in_projects && <span className="px-2 py-0.5 rounded-full text-[10px] font-bold" style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)' }}>Shared in projects</span>}
                </div>

                {/* Credentials */}
                <div className="flex flex-wrap items-center gap-x-5 gap-y-1 mt-2 text-xs">
                  <span style={{ color: 'var(--text-muted)' }}>
                    <span className="label-caps mr-1.5" style={{ fontSize: '9px' }}>User</span>
                    <b style={{ color: 'var(--text-h)' }}>{v.username || '—'}</b>
                  </span>
                  <span className="flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
                    <span className="label-caps" style={{ fontSize: '9px' }}>Pass</span>
                    <b style={{ color: 'var(--text-h)', fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' }}>
                      {revealed[v.id] || '••••••••'}
                    </b>
                    <button onClick={() => revealed[v.id] ? setRevealed(p => ({ ...p, [v.id]: null })) : reveal(v.id)}
                      title={revealed[v.id] ? 'Hide password' : 'Reveal password'}
                      className="p-1 rounded-lg hover:bg-[rgba(124,58,237,0.1)]">
                      {revealed[v.id] ? <EyeOff size={12} style={{ color: 'var(--accent)' }} /> : <Eye size={12} style={{ color: 'var(--text-muted)' }} />}
                    </button>
                  </span>
                  {v.url && (
                    <a href={v.url.startsWith('http') ? v.url : `https://${v.url}`} target="_blank" rel="noreferrer"
                      className="flex items-center gap-1 font-bold" style={{ color: 'var(--accent)' }}>
                      Open <Globe size={11} />
                    </a>
                  )}
                </div>

                {v.notes && (
                  <div className="rich-content text-[11px] mt-2.5 pt-2.5" style={{ color: 'var(--text-muted)', borderTop: '1px solid var(--border)' }}
                    dangerouslySetInnerHTML={{ __html: v.notes }} />
                )}
              </div>

              <ConfirmIconButton onConfirm={() => del(v.id)} title="Delete credential?" message="This vault entry will be permanently removed." />
            </div>
          </div>
        )
      })}
    </div>
  )
}

function RemindersTab({ id, reminders, reload, toast }) {
  const [form, setForm] = useState({ description: '', remind_at: '' })
  const add = async () => {
    if (!form.description.trim() || !form.remind_at) return toast.error('Description & date required')
    try { await customerApi.reminders.create(id, form); setForm({ description: '', remind_at: '' }); reload() }
    catch (e) { toast.error(e.message) }
  }
  const del = async (rid) => { try { await customerApi.reminders.remove(id, rid); reload() } catch (e) { toast.error(e.message) } }
  return (
    <div className="space-y-4">
      <div className="card-3d flex gap-3 items-end" style={{ padding: '16px' }}>
        <div className="flex-1"><label className="label">Reminder</label><input className="input-3d text-sm" placeholder="e.g. Follow up on renewal" value={form.description} onChange={e => setForm(p => ({ ...p, description: e.target.value }))} /></div>
        <div><label className="label">Date</label><input type="date" className="input-3d text-sm" value={form.remind_at} onChange={e => setForm(p => ({ ...p, remind_at: e.target.value }))} /></div>
        <button onClick={add} className="px-4 py-2.5 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Add</button>
      </div>
      {!reminders?.length ? <Empty text="No reminders set." icon={Bell} /> : reminders.map(r => (
        <div key={r.id} className="card-3d flex items-center justify-between" style={{ padding: '16px' }}>
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ background: 'rgba(245,158,11,0.12)' }}><Bell size={14} style={{ color: '#f59e0b' }} /></div>
            <div>
              <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{r.description}</p>
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Due {d10(r.remind_at)}{r.assignee ? ` · ${r.assignee.name}` : ''}</p>
            </div>
          </div>
          <ConfirmIconButton onConfirm={() => del(r.id)} title="Delete reminder?" message="This reminder will be permanently removed." />
        </div>
      ))}
    </div>
  )
}

function AttachmentsTab({ id, files, reload, toast }) {
  const ref = useRef(null)
  const upload = async (e) => {
    const file = e.target.files?.[0]; e.target.value = ''
    if (!file) return
    try { await customerApi.attachments.upload(id, file); toast.success('Uploaded'); reload() }
    catch (err) { toast.error(err.message) }
  }
  const del = async (aid) => { try { await customerApi.attachments.remove(id, aid); reload() } catch (e) { toast.error(e.message) } }
  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <input ref={ref} type="file" className="hidden" onChange={upload} />
        <button onClick={() => ref.current?.click()} className="px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-1" style={{ background: 'var(--bg-input)', color: 'var(--accent)', border: '1px solid var(--border)' }}><Upload size={13} /> Upload file</button>
      </div>
      {!files?.length ? <Empty text="No attachments." icon={FileText} /> : (
        <div className="grid md:grid-cols-2 gap-3">
          {files.map(f => (
            <div key={f.id} className="card-3d flex items-center justify-between" style={{ padding: '14px' }}>
              <a href={f.url} target="_blank" rel="noreferrer" className="flex items-center gap-3 min-w-0">
                <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><FileText size={14} style={{ color: 'var(--accent)' }} /></div>
                <div className="min-w-0">
                  <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>{f.file_name}</p>
                  <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{(f.file_size / 1024).toFixed(0)} KB · {d10(f.created_at)}</p>
                </div>
              </a>
              <ConfirmIconButton onConfirm={() => del(f.id)} title="Delete file?" message="This attachment will be permanently removed." />
            </div>
          ))}
        </div>
      )}
    </div>
  )
}



function AddressBookTab({ id, addresses, reload, toast }) {
  const empty = { label: '', address: '', city: '', state: '', zip: '', country: '' }
  const [form, setForm] = useState(empty)
  const [adding, setAdding] = useState(false)
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const add = async () => {
    if (!form.address.trim() && !form.city.trim()) return toast.error('Enter an address')
    try { await customerApi.addresses.create(id, form); setForm(empty); setAdding(false); reload() }
    catch (e) { toast.error(e.message) }
  }
  const del = async (aid) => { try { await customerApi.addresses.remove(id, aid); reload() } catch (e) { toast.error(e.message) } }
  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={() => setAdding(a => !a)} className="px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-1" style={{ background: 'var(--bg-input)', color: 'var(--accent)', border: '1px solid var(--border)' }}><Plus size={13} /> New address</button>
      </div>
      {adding && (
        <div className="card-3d space-y-3" style={{ padding: '16px' }}>
          <div className="grid grid-cols-2 gap-3">
            <input className="input-3d text-sm" placeholder="Label (e.g. Warehouse)" value={form.label} onChange={e => sf('label', e.target.value)} />
            <input className="input-3d text-sm" placeholder="Country" value={form.country} onChange={e => sf('country', e.target.value)} />
          </div>
          <textarea rows={2} className="input-3d text-sm resize-none" placeholder="Street address" value={form.address} onChange={e => sf('address', e.target.value)} />
          <div className="grid grid-cols-3 gap-3">
            <input className="input-3d text-sm" placeholder="City" value={form.city} onChange={e => sf('city', e.target.value)} />
            <input className="input-3d text-sm" placeholder="State" value={form.state} onChange={e => sf('state', e.target.value)} />
            <input className="input-3d text-sm" placeholder="ZIP" value={form.zip} onChange={e => sf('zip', e.target.value)} />
          </div>
          <div className="flex justify-end gap-2">
            <button onClick={() => setAdding(false)} className="px-3 py-1.5 rounded-lg text-xs" style={{ color: 'var(--text-muted)' }}>Cancel</button>
            <button onClick={add} className="px-4 py-1.5 rounded-lg text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Save</button>
          </div>
        </div>
      )}
      {!addresses?.length ? <Empty text="No saved addresses." icon={MapPin} /> : (
        <div className="grid md:grid-cols-2 gap-3">
          {addresses.map(a => (
            <div key={a.id} className="card-3d flex items-start justify-between gap-3" style={{ padding: '16px' }}>
              <div>
                {a.label && <p className="font-bold text-sm mb-0.5" style={{ color: 'var(--text-h)' }}>{a.label}</p>}
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{[a.address, a.city, a.state, a.zip, a.country].filter(Boolean).join(', ')}</p>
              </div>
              <ConfirmIconButton onConfirm={() => del(a.id)} title="Delete address?" message="This address will be permanently removed." />
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function RecipientsTab({ id, recipients, reload, toast }) {
  const empty = { name: '', company: '', email: '', phone: '', address: '', city: '', country: '' }
  const [form, setForm] = useState(empty)
  const [adding, setAdding] = useState(false)
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const add = async () => {
    if (!form.name.trim()) return toast.error('Name required')
    try { await customerApi.recipients.create(id, form); setForm(empty); setAdding(false); reload() }
    catch (e) { toast.error(e.message) }
  }
  const del = async (rid) => { try { await customerApi.recipients.remove(id, rid); reload() } catch (e) { toast.error(e.message) } }
  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={() => setAdding(a => !a)} className="px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-1" style={{ background: 'var(--bg-input)', color: 'var(--accent)', border: '1px solid var(--border)' }}><Plus size={13} /> New recipient</button>
      </div>
      {adding && (
        <div className="card-3d space-y-3" style={{ padding: '16px' }}>
          <div className="grid grid-cols-2 gap-3">
            <input className="input-3d text-sm" placeholder="Name *" value={form.name} onChange={e => sf('name', e.target.value)} />
            <input className="input-3d text-sm" placeholder="Company" value={form.company} onChange={e => sf('company', e.target.value)} />
            <input className="input-3d text-sm" placeholder="Email" value={form.email} onChange={e => sf('email', e.target.value)} />
            <input className="input-3d text-sm" placeholder="Phone" value={form.phone} onChange={e => sf('phone', e.target.value)} />
            <input className="input-3d text-sm" placeholder="City" value={form.city} onChange={e => sf('city', e.target.value)} />
            <input className="input-3d text-sm" placeholder="Country" value={form.country} onChange={e => sf('country', e.target.value)} />
          </div>
          <input className="input-3d text-sm" placeholder="Address" value={form.address} onChange={e => sf('address', e.target.value)} />
          <div className="flex justify-end gap-2">
            <button onClick={() => setAdding(false)} className="px-3 py-1.5 rounded-lg text-xs" style={{ color: 'var(--text-muted)' }}>Cancel</button>
            <button onClick={add} className="px-4 py-1.5 rounded-lg text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Save</button>
          </div>
        </div>
      )}
      {!recipients?.length ? <Empty text="No recipients saved." icon={Users2} /> : (
        <div className="grid md:grid-cols-2 gap-3">
          {recipients.map(r => (
            <div key={r.id} className="card-3d flex items-start justify-between gap-3" style={{ padding: '16px' }}>
              <div>
                <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{r.name}{r.company ? ` · ${r.company}` : ''}</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{[r.email, r.phone].filter(Boolean).join(' · ') || '—'}</p>
                {(r.address || r.city || r.country) && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{[r.address, r.city, r.country].filter(Boolean).join(', ')}</p>}
              </div>
              <ConfirmIconButton onConfirm={() => del(r.id)} title="Delete recipient?" message="This recipient will be permanently removed." />
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function RelatedTab({ client }) {
  return (
    <div className="grid md:grid-cols-3 gap-4">
      <RelatedCard icon={Package} title="Vendors" hint="Purchase vendors linked to this customer appear here once the Purchase module ships." />
      <RelatedCard icon={Users2} title="Third-Party Vendors"
        hint={client.vendor_id ? `Linked TPV #${client.vendor_id}. Details load once the TPV module ships.` : 'No third-party vendor linked. Available once the TPV module ships.'} />
      <RelatedCard icon={UserPlus} title="Leads" hint={client.lead_id ? `Converted from lead #${client.lead_id}.` : 'This customer was not converted from a lead.'} />
    </div>
  )
}

/* ══════════════ Shared helpers ══════════════ */

const INVOICE_COLS = [
  { key: 'number', label: 'Number', bold: true },
  { key: 'date', label: 'Date', fmt: d10 },
  { key: 'due_date', label: 'Due', fmt: d10 },
  { key: 'total', label: 'Total', money: true },
  { key: 'balance', label: 'Balance', money: true },
  { key: 'status', label: 'Status' },
]
const PAYMENT_COLS = [
  { key: 'date', label: 'Date', fmt: d10 },
  { key: 'invoice_number', label: 'Invoice', bold: true },
  { key: 'amount', label: 'Amount', money: true },
  { key: 'mode', label: 'Mode' },
  { key: 'tds_amount', label: 'TDS', money: true },
]
const PROPOSAL_COLS = [
  { key: 'subject', label: 'Subject', bold: true },
  { key: 'total', label: 'Total', money: true },
  { key: 'status', label: 'Status' },
  { key: 'created_at', label: 'Created', fmt: d10 },
]
const ESTIMATE_COLS = [
  { key: 'reference', label: 'Reference', bold: true },
  { key: 'estimate_type', label: 'Type', render: v => v === 'proforma' ? 'Proforma Invoice' : 'Estimate' },
  { key: 'subject', label: 'Subject' },
  { key: 'date', label: 'Date', fmt: d10 },
  { key: 'total', label: 'Total', money: true },
  { key: 'status', label: 'Status' },
]
const CREDIT_COLS = [
  { key: 'number', label: 'Number', bold: true },
  { key: 'date', label: 'Date', fmt: d10 },
  { key: 'total', label: 'Total', money: true },
  { key: 'remaining', label: 'Remaining', money: true },
  { key: 'status', label: 'Status' },
]

function DocTable({ columns, rows, empty, title, action, renderExpanded }) {
  const mfmt = useMoneyFmt()
  const [open, setOpen] = useState({})
  const cols = renderExpanded ? columns.length + 1 : columns.length
  return (
    <div className="space-y-3">
      {action && <div className="flex justify-end">{action}</div>}
      <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
      {title && <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}><p className="label-caps" style={{ color: 'var(--accent)' }}>{title}</p></div>}
      <div className="overflow-x-auto">
        <table className="w-full text-xs">
          <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
            {renderExpanded && <th style={{ width: 32 }} />}
            {columns.map(c => <th key={c.key} className="py-3 px-4 text-left label-caps whitespace-nowrap">{c.label}</th>)}
          </tr></thead>
          <tbody>
            {!rows?.length ? (
              <tr><td colSpan={cols} className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>{empty}</td></tr>
            ) : rows.map((r, i) => {
              const key = r.id ?? i
              const expanded = !!open[key]
              return (
                <Fragment key={key}>
                  <tr style={{ borderBottom: '1px solid var(--border)' }}>
                    {renderExpanded && (
                      <td className="py-3 pl-3">
                        <button onClick={() => setOpen(p => ({ ...p, [key]: !p[key] }))} className="p-1 rounded-lg hover:bg-[rgba(124,58,237,0.08)]" title={expanded ? 'Collapse' : 'Show payments'}>
                          <ChevronDown size={13} style={{ color: 'var(--text-muted)', transform: expanded ? 'rotate(180deg)' : 'none', transition: 'transform 0.15s' }} />
                        </button>
                      </td>
                    )}
                    {columns.map(c => {
                      const raw = r[c.key]
                      const val = c.money ? mfmt(raw) : c.render ? c.render(raw) : c.fmt ? c.fmt(raw) : (raw ?? '—')
                      return <td key={c.key} className="py-3 px-4" style={{ color: c.bold ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: c.bold ? 700 : 400 }}>{val}</td>
                    })}
                  </tr>
                  {expanded && (
                    <tr style={{ borderBottom: '1px solid var(--border)' }}>
                      <td colSpan={cols} className="px-6 py-3" style={{ background: 'rgba(124,58,237,0.03)' }}>{renderExpanded(r)}</td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>
      </div>
    </div>
  )
}

/** Payments mini-table shown under an expanded invoice row. */
function InvoicePayments({ invoice }) {
  const mfmt = useMoneyFmt()
  const pays = invoice.payments || []
  if (!pays.length) return <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No payments recorded against this invoice.</p>
  return (
    <table className="w-full text-xs">
      <thead><tr>
        {['Date', 'Amount', 'Mode', 'Txn ID', 'TDS', 'Note'].map(h => <th key={h} className="py-1.5 pr-4 text-left label-caps" style={{ fontSize: '10px' }}>{h}</th>)}
      </tr></thead>
      <tbody>
        {pays.map(p => (
          <tr key={p.id}>
            <td className="py-1.5 pr-4" style={{ color: 'var(--text-muted)' }}>{d10(p.date)}</td>
            <td className="py-1.5 pr-4 font-bold" style={{ color: '#10b981' }}>{mfmt(p.amount)}</td>
            <td className="py-1.5 pr-4" style={{ color: 'var(--text-muted)' }}>{p.mode || '—'}</td>
            <td className="py-1.5 pr-4" style={{ color: 'var(--text-muted)' }}>{p.transaction_id || '—'}</td>
            <td className="py-1.5 pr-4" style={{ color: 'var(--text-muted)' }}>{Number(p.tds_amount) ? mfmt(p.tds_amount) : '—'}</td>
            <td className="py-1.5" style={{ color: 'var(--text-muted)' }}>{p.note || '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function Empty({ text, icon: Icon = FileText }) {
  return (
    <div className="card-3d text-center" style={{ padding: '40px 20px' }}>
      <Icon size={22} style={{ color: 'var(--text-muted)', margin: '0 auto 8px' }} />
      <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{text}</p>
    </div>
  )
}

function TaxTile({ label, value, color }) {
  return (
    <div className="card-3d" style={{ padding: '16px' }}>
      <p className="text-[11px] mb-1" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="font-black text-lg" style={{ color }}>{value}</p>
    </div>
  )
}

function RelatedCard({ icon: Icon, title, hint }) {
  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <div className="flex items-center gap-2 mb-3">
        <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
          <Icon size={15} style={{ color: 'var(--accent)' }} />
        </div>
        <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{title}</p>
      </div>
      <p className="text-xs leading-relaxed flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}><Link2 size={11} /> {hint}</p>
    </div>
  )
}
