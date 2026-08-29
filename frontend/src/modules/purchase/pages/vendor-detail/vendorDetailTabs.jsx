import { useState, useEffect, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { Save, ExternalLink, ClipboardList, Rocket, Inbox, Plus, CalendarClock, CheckCircle2, Trash2, Users, User, HardHat, FileText, Paperclip, StickyNote, ShieldCheck } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { projectApi, PROJECT_STATUS } from '@/services/projectApi'
import { helpdeskApi } from '@/services/helpdeskApi'
import ProjectFormDrawer from '@/modules/projects/components/ProjectFormDrawer'
// The REAL create forms from each module, opened by this workspace's Add buttons
// with the vendor preselected. Never a reduced copy — one form per document type,
// so validations, catalog pickers and contract rates cannot drift.
import { NewRfqModal } from '@/modules/purchase/pages/PurchaseRfqs'
import { NewContractModal } from '@/modules/purchase/pages/PurchaseContracts'
import { NewOrderModal } from '@/modules/purchase/pages/PurchaseOrders'
import { NewInvoiceModal } from '@/modules/purchase/pages/PurchaseInvoices'
import { NewDebitNoteModal } from '@/modules/purchase/pages/PurchaseDebitNotes'
import NewTicketModal from '@/modules/helpdesk/components/NewTicketModal'
import TaskFormDrawer from '@/modules/tasks/components/TaskFormDrawer'
import VendorAddModal from '@/modules/tpv/components/VendorAddModal'
import { useQueryClient } from '@tanstack/react-query'
// The three SHARED vendor panels. They are not TPV-owned despite their path:
// each sits on a polymorphic table and takes an `api` prop, so pointing them at
// purchaseApi.vendors is a data-source swap, not a fork. Duplicating them would
// mean two file browsers and two notes UIs drifting apart.
import { VendorNotes } from '@/modules/tpv/components/VendorNotesPanel'
import { VendorReminders } from '@/modules/tpv/components/VendorRemindersPanel'
import { VendorAttachments } from '@/modules/tpv/components/VendorAttachmentsPanel'
import { Overlay, ModalFooter, Field, TextInput, inputStyle } from '@/components/ui/kit3d'
import PurchaseVendorForm, { validatePurchaseVendor } from '@/modules/purchase/components/PurchaseVendorForm'
import PurchaseVendorDocumentsReadOnly from '@/modules/purchase/components/PurchaseVendorDocumentsReadOnly'
import PurchaseVendorContacts from '@/modules/purchase/components/PurchaseVendorContacts'
// Purchase-owned Prequalification + Due-Diligence panels (mirror the TPV ones).
// Both hit only /api/purchase/vendors/* — no TPV table or endpoint is touched.
import PurchasePrequalificationPanel from '@/modules/purchase/components/PurchasePrequalificationPanel'
import PurchaseDueDiligencePanel from '@/modules/purchase/components/PurchaseDueDiligencePanel'
// The customers panel is SHARED with the TPV workspace (same reasoning as Notes /
// Reminders / Attachments): it takes the full `api` object and reads
// api.vendors.customers, so passing purchaseApi points it at the Purchase link
// column (clients.purchase_vendor_id). A data-source swap, not a fork.
import { VendorCustomers } from '@/modules/tpv/components/VendorCustomersPanel'
import { useVendorWorkspace } from './vendorWorkspaceContext'
import VendorTasksPanel from '@/components/vendor/VendorTasksPanel'
import {
  fmtMoney, fmtDate,
  poStatusCfg, pinvStatusCfg, contractStatusCfg, dnStatusCfg, quoteStatusCfg, contractTypeLabel,
} from '@/modules/purchase/constants'

/**
 * Purchase Vendor Detail — tab content. Every tab reads Purchase-owned APIs
 * (purchaseApi → /api/purchase/*), scoped to the current PurchaseVendor.
 *
 * Three panels are SHARED with the TPV workspace rather than copied: Notes,
 * Reminders and Attachments. They live under modules/tpv/ for historical
 * reasons, but each is backed by a POLYMORPHIC table and takes an `api` prop, so
 * this passes purchaseApi.vendors and the same component serves both modules
 * against different routes. That is a data-source swap, not a shared data store —
 * the two vendor modules still share no table and no endpoint. Forking them would
 * leave two file browsers and two notes UIs to keep in step.
 *
 * Projects, Tickets and Expenses reach this vendor through the projects table
 * (link_type='purchase_vendor'), so they use projectApi/helpdeskApi with an
 * explicit vendor_type — the same integer is a different company under
 * 'tpv_vendor'.
 */

/* ── shared bits ─────────────────────────────────────────────────────────── */

const card = { padding: 18 }
const th = { textAlign: 'left', padding: '9px 12px', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em', color: 'var(--text-muted)', fontWeight: 700 }
const td = { padding: '9px 12px', color: 'var(--text-muted)', fontSize: 13 }

function Badge({ cfg }) {
  const c = cfg || { label: '—', color: '#6b7280', bg: 'rgba(107,114,128,0.15)' }
  return <span style={{ fontSize: 11, fontWeight: 700, color: c.color, background: c.bg, padding: '2px 9px', borderRadius: 999 }}>{c.label}</span>
}

function TabHead({ title, count, actionLabel, onAction, addLabel, onAdd }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, gap: 10 }}>
      <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{title}{typeof count === 'number' && <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}> · {count}</span>}</h2>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        {actionLabel && <button onClick={onAction} style={linkBtn}><ExternalLink size={13} /> {actionLabel}</button>}
        {addLabel && <button onClick={onAdd} style={primaryBtn}><Plus size={14} /> {addLabel}</button>}
      </div>
    </div>
  )
}

/**
 * Reusable vendor-scoped list — fetches THIS vendor's records from a Purchase API
 * and renders them inside the workspace. Nothing here navigates away.
 *
 * `AddModal`, when given, is the module's OWN create form. It is rendered with
 * `presetVendorId` so the vendor is already chosen, and the list refreshes on
 * save. Callers pass the real form (NewRfqModal, NewOrderModal, …) — never a
 * reduced copy.
 *
 * The fetcher is held in a ref rather than a dependency: every call site passes
 * an inline arrow, so a fresh identity on each render would refire the request
 * on every state change (including opening the modal).
 */
function VendorScopedList({
  title, fetcher, columns, statusCfg, onRowClick,
  AddModal, addLabel, addAsList = false, emptyText, onAdd,
}) {
  const { vendor } = useVendorWorkspace()
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [adding, setAdding] = useState(false)
  const [nonce, setNonce] = useState(0)
  const fetchRef = useRef(fetcher)
  fetchRef.current = fetcher

  useEffect(() => {
    let alive = true
    setRows(null)
    setLoadError(null)
    fetchRef.current(vendor.id)
      .then((r) => { if (alive) setRows(Array.isArray(r) ? r : (r?.data ?? [])) })
      .catch((e) => { if (alive) { setRows([]); setLoadError(e) } })
    return () => { alive = false }
  }, [vendor.id, nonce])

  return (
    <div className="card-3d" style={card}>
      {adding && AddModal && (
        <AddModal
          {...(addAsList ? { presetVendorIds: [vendor.id] } : { presetVendorId: vendor.id })}
          onClose={() => setAdding(false)}
          onDone={() => { setAdding(false); setNonce(n => n + 1) }}
          onSaved={() => { setAdding(false); setNonce(n => n + 1) }}
        />
      )}
      {/* onAdd takes precedence: a couple of modules expose a full-page create
          screen rather than a modal, and navigating there beats a second form. */}
      <TabHead title={title} count={rows?.length}
        addLabel={(AddModal || onAdd) ? addLabel : null}
        onAdd={onAdd || (() => setAdding(true))} />
      {loadError ? <LoadError error={loadError} onRetry={() => setNonce(n => n + 1)} />
        : rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : rows.length === 0 ? <Empty text={emptyText || `No ${title.toLowerCase()} for this vendor`} />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead><tr style={{ background: 'var(--bg-input)' }}>
                  {columns.map((c) => <th key={c.header} style={th}>{c.header}</th>)}
                  {statusCfg && <th style={th}>Status</th>}
                </tr></thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id}
                      onClick={onRowClick ? () => onRowClick(row) : undefined}
                      style={{ borderTop: '1px solid var(--border)', cursor: onRowClick ? 'pointer' : 'default' }}>
                      {columns.map((c) => <td key={c.header} style={c.strong ? { ...td, color: 'var(--text-h)', fontWeight: 700 } : td}>{c.cell(row)}</td>)}
                      {statusCfg && <td style={td}><Badge cfg={statusCfg(row.status)} /></td>}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
    </div>
  )
}

function Empty({ text }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '32px 0', color: 'var(--text-muted)' }}>
      <Inbox size={26} style={{ opacity: 0.6 }} />
      <span style={{ fontSize: 13 }}>{text}</span>
    </div>
  )
}

/* ── General ─────────────────────────────────────────────────────────────── */

export function ProfileTab() {
  const { vendor, reload } = useVendorWorkspace()
  const [form, setForm] = useState(vendor)
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState(null)

  useEffect(() => { setForm(vendor); setMsg(null) }, [vendor])

  const save = async () => {
    const invalid = validatePurchaseVendor(form)
    if (invalid) { setMsg({ type: 'err', text: invalid }); return }
    setSaving(true); setMsg(null)
    try {
      await purchaseApi.vendors.update(vendor.id, form)
      setMsg({ type: 'ok', text: 'Vendor saved.' })
      reload?.()
    } catch (e) {
      const errors = e?.response?.data?.errors
      setMsg({ type: 'err', text: errors ? Object.values(errors).flat()[0] : (e?.response?.data?.message || 'Could not save vendor.') })
    } finally { setSaving(false) }
  }

  return (
    <div className="card-3d" style={card}>
      <TabHead title="Profile" />
      <PurchaseVendorForm value={form} onChange={setForm} mode="edit" />
      <div style={{ marginTop: 18, paddingTop: 14, borderTop: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
        <span style={{ fontSize: 12, color: msg?.type === 'err' ? '#ef4444' : '#10b981' }}>{msg?.text || ''}</span>
        <button onClick={save} disabled={saving} style={primaryBtn}><Save size={14} /> {saving ? 'Saving…' : 'Save Changes'}</button>
      </div>
    </div>
  )
}

export function ContactsTab() {
  const { vendor } = useVendorWorkspace()
  return <PurchaseVendorContacts vendorId={vendor.id} />
}

/**
 * Prequalification — a Purchase-native scored questionnaire → Qualified /
 * Conditional / Not Qualified, the mirror of the TPV VendorPrequalificationPanel.
 * `manage` is on: this is the admin/staff surface; the write is admin-gated
 * server-side, so staff see the form but the PUT refuses them.
 */
function PrequalificationTab() {
  const { vendor } = useVendorWorkspace()
  return <PurchasePrequalificationPanel vendorId={vendor.id} manage />
}

/**
 * Due Diligence — the Purchase-native verification checklist (company / document
 * / licence / insurance verification, background & reference checks, prior
 * performance / incident / compliance history) rolling up to Cleared / Rejected.
 */
function DueDiligenceTab() {
  const { vendor } = useVendorWorkspace()
  return <PurchaseDueDiligencePanel vendorId={vendor.id} manage />
}

/**
 * Overview — a live per-vendor dashboard: count cards (customers, contacts,
 * workers, documents, attachments, notes) fetched from GET
 * /purchase/vendors/{id}/overview, so the numbers always match the other tabs.
 * The Purchase-side mirror of the TPV VendorOverview.
 */
function OverviewTab() {
  const { vendor } = useVendorWorkspace()
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    let alive = true
    setData(null); setErr('')
    purchaseApi.vendors.overview(vendor.id)
      .then(d => { if (alive) setData(d) })
      .catch(e => { if (alive) setErr(e?.response?.data?.message || 'Could not load the overview.') })
    return () => { alive = false }
  }, [vendor.id])

  const c = data?.counts || {}
  const cards = [
    { label: 'Customers', value: c.customers, icon: Users },
    { label: 'Contacts', value: c.contacts, icon: User },
    { label: 'Workers', value: c.workers, icon: HardHat },
    { label: 'Documents', value: c.documents, icon: FileText },
    { label: 'Attachments', value: c.attachments, icon: Paperclip },
    { label: 'Notes', value: c.notes, icon: StickyNote },
  ]
  const summary = [
    ['Company', vendor.company_name],
    ['Vendor Code', vendor.purchase_vendor_code],
    ['Account Status', vendor.status_label || vendor.status],
    ['Category', vendor.category || '—'],
    ['Type', vendor.registration_type_label || vendor.vendor_type || '—'],
    ['Created', vendor.created_at ? new Date(vendor.created_at).toLocaleDateString() : '—'],
  ]

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: 12 }}>
        {cards.map(card => (
          <div key={card.label} className="card-3d" style={{ padding: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.04em', color: 'var(--text-muted)' }}>{card.label}</span>
              <card.icon size={16} style={{ color: '#a78bfa' }} />
            </div>
            <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--text-h)', marginTop: 6 }}>
              {data ? (card.value ?? 0) : '—'}
            </div>
          </div>
        ))}
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: 0 }}>{err}</p>}

      <div className="card-3d" style={card}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
          <ShieldCheck size={16} style={{ color: '#a78bfa' }} />
          <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Vendor Summary</span>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 24px' }}>
          {summary.map(([k, val]) => (
            <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 7 }}>
              <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
              <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, textAlign: 'right' }}>{val || '—'}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

/**
 * Customer tab — customers directly linked to this Purchase vendor
 * (clients.purchase_vendor_id). Reuses the shared VendorCustomers panel with the
 * full purchaseApi, so Add creates a real Customer record on the Purchase link.
 */
function CustomerTab() {
  const { vendor } = useVendorWorkspace()
  return <VendorCustomers vendorId={vendor.id} vendorName={vendor.company_name} manage api={purchaseApi} />
}

export function OnboardingTab() {
  const { vendor, onboarding } = useVendorWorkspace()
  const navigate = useNavigate()
  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <div className="card-3d" style={card}>
        <TabHead title="Onboarding" actionLabel={onboarding ? 'Open Wizard' : null} onAction={() => navigate(`/app/purchase/onboarding/${onboarding.id}`)} />
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          {onboarding
            ? <><ClipboardList size={18} style={{ color: '#7C3AED' }} /><span style={{ fontSize: 14, color: 'var(--text-muted)' }}>Status: <strong style={{ color: 'var(--text-h)' }}>{onboarding.status_label || onboarding.status}</strong> · Step {onboarding.current_step || 1}/6</span></>
            : <><Rocket size={18} style={{ color: '#7C3AED' }} /><span style={{ fontSize: 14, color: 'var(--text-muted)' }}>No onboarding started for this vendor yet. Start it from the <button onClick={() => navigate('/app/purchase/onboarding')} style={{ ...linkInline }}>Vendor Onboarding</button> workspace.</span></>}
        </div>
      </div>
      <div className="card-3d" style={card}>
        <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 12px', textTransform: 'uppercase', letterSpacing: '.03em' }}>Documents</h3>
        <PurchaseVendorDocumentsReadOnly vendorId={vendor.id} />
      </div>
    </div>
  )
}

/* ── Commercial (vendor-scoped Purchase modules) ─────────────────────────── */

const refOf = (r) => r.reference || r.number || r.code || `#${r.id}`
const amtOf = (r) => fmtMoney(r.grand_total ?? r.total ?? r.total_amount ?? r.amount ?? 0, r.currency)

export function QuotationsTab() {
  return <VendorScopedList
    title="Quotations"
    // Add opens the Purchase module's OWN RFQ form with this vendor already on
    // the recipient list — an RFQ takes a LIST of vendors, hence addAsList.
    AddModal={NewRfqModal} addLabel="Add Quotation" addAsList
    fetcher={(vid) => purchaseApi.quotations.list({ purchase_vendor_id: vid })}
    statusCfg={quoteStatusCfg}
    columns={[
      { header: 'Reference', cell: refOf, strong: true },
      { header: 'RFQ', cell: (r) => r.purchase_rfq?.reference || r.rfq_reference || '—' },
      { header: 'Amount', cell: amtOf },
      { header: 'Received', cell: (r) => fmtDate(r.created_at) },
    ]} />
}

/**
 * Contracts — reads the existing Purchase Contracts module
 * (purchase_contracts, vendor-scoped by purchase_vendor_id). Read-only: view
 * and download only, no create/edit/delete/upload — editing stays in the
 * Contracts module itself.
 *
 * Note: the Sales/CRM contract tables (sales_contracts, client_contracts) are
 * keyed by client_id and hold no vendor reference, so they are not a source
 * for a vendor's contracts.
 */
export function ContractsTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [err, setErr] = useState(null)
  const [adding, setAdding] = useState(false)
  const [nonce, setNonce] = useState(0)

  useEffect(() => {
    let alive = true
    setRows(null)
    setLoadError(null)
    purchaseApi.contracts.list({ purchase_vendor_id: vendor.id })
      .then((r) => { if (alive) setRows(Array.isArray(r) ? r : (r?.data ?? [])) })
      .catch((e) => { if (alive) { setRows([]); setLoadError(e) } })
    return () => { alive = false }
  }, [vendor.id, nonce])

  const download = async (row) => {
    setErr(null)
    try { window.open(await purchaseApi.contracts.download(row.id), '_blank', 'noopener') }
    catch { setErr('No signed document is attached to this contract.') }
  }

  const cols = [
    { header: 'Contract No', cell: (r) => r.contract_number || `#${r.id}`, strong: true },
    { header: 'Title', cell: (r) => r.title || '—' },
    { header: 'Type', cell: (r) => contractTypeLabel(r.type) },
    { header: 'Start', cell: (r) => fmtDate(r.start_date) },
    { header: 'End', cell: (r) => fmtDate(r.end_date) },
    { header: 'Ceiling', cell: (r) => fmtMoney(r.spend_ceiling, r.currency), num: true },
    { header: 'Owner', cell: (r) => r.creator?.name || '—' },
    { header: 'Created', cell: (r) => fmtDate(r.created_at) },
  ]

  return (
    <div className="card-3d" style={card}>
      {/* The Contracts module's own form, with this vendor preselected. */}
      {adding && (
        <NewContractModal
          presetVendorId={vendor.id}
          onClose={() => setAdding(false)}
          onDone={() => { setAdding(false); setNonce(n => n + 1) }}
        />
      )}
      <TabHead title="Contracts" count={rows?.length} addLabel="Add Contract" onAdd={() => setAdding(true)} />
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{err}</p>}
      {loadError ? <LoadError error={loadError} onRetry={() => setNonce(n => n + 1)} />
        : rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : rows.length === 0 ? <Empty text="No contracts for this vendor" />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead><tr style={{ background: 'var(--bg-input)' }}>
                  {cols.map((c) => <th key={c.header} style={{ ...th, textAlign: c.num ? 'right' : 'left' }}>{c.header}</th>)}
                  <th style={th}>Status</th><th style={th} />
                </tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id} style={{ borderTop: '1px solid var(--border)' }}>
                      {cols.map((c) => (
                        <td key={c.header} style={{ ...td, textAlign: c.num ? 'right' : 'left', ...(c.strong ? { color: 'var(--text-h)', fontWeight: 700 } : {}) }}>{c.cell(r)}</td>
                      ))}
                      <td style={td}><Badge cfg={contractStatusCfg(r.status)} /></td>
                      <td style={{ ...td, textAlign: 'right', whiteSpace: 'nowrap' }}>
                        <button onClick={() => navigate(`/app/purchase/contracts/${r.id}`)} style={linkBtn}>View</button>
                        {r.document_path && <button onClick={() => download(r)} style={{ ...linkBtn, marginLeft: 6 }}>Download</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
    </div>
  )
}

export function OrdersTab() {
  return <VendorScopedList
    title="Purchase Orders"
    AddModal={NewOrderModal} addLabel="Add Purchase Order"
    fetcher={(vid) => purchaseApi.orders.list({ purchase_vendor_id: vid })}
    statusCfg={poStatusCfg}
    columns={[
      { header: 'PO #', cell: refOf, strong: true },
      { header: 'Amount', cell: amtOf },
      { header: 'Expected', cell: (r) => fmtDate(r.expected_delivery_date || r.expected_by || r.expected_at) },
    ]} />
}

export function InvoicesTab() {
  return <VendorScopedList
    title="Purchase Invoices"
    AddModal={NewInvoiceModal} addLabel="Add Purchase Invoice"
    fetcher={(vid) => purchaseApi.invoices.list({ purchase_vendor_id: vid })}
    statusCfg={pinvStatusCfg}
    columns={[
      { header: 'Invoice #', cell: refOf, strong: true },
      { header: 'Amount', cell: amtOf },
      { header: 'Outstanding', cell: (r) => fmtMoney(r.balance, r.currency) },
      { header: 'Date', cell: (r) => fmtDate(r.invoice_date || r.created_at) },
    ]} />
}

export function DebitNotesTab() {
  return <VendorScopedList
    title="Debit Notes"
    AddModal={NewDebitNoteModal} addLabel="Add Debit Note"
    fetcher={(vid) => purchaseApi.debitNotes.list({ purchase_vendor_id: vid })}
    statusCfg={dnStatusCfg}
    columns={[
      { header: 'Reference', cell: refOf, strong: true },
      { header: 'Amount', cell: amtOf },
      { header: 'Date', cell: (r) => fmtDate(r.debit_date || r.created_at) },
    ]} />
}

/* ── Placeholder ─────────────────────────────────────────────────────────── */

export function ComingSoonTab({ label }) {
  return (
    <div className="card-3d" style={{ ...card, minHeight: '46vh', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 12, textAlign: 'center' }}>
      <div style={{ width: 60, height: 60, borderRadius: 18, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 26, background: 'linear-gradient(135deg,rgba(124,58,237,0.15),rgba(91,33,182,0.08))', border: '1px solid rgba(124,58,237,0.2)' }}>🚧</div>
      <div>
        <h2 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{label}</h2>
        <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: '4px 0 0' }}>This Purchase Vendor module is coming soon.</p>
      </div>
    </div>
  )
}

/* ── Route element map — key (URL segment) → element ──────────────────────── */


/**
 * Tasks raised against this Purchase vendor (tasks.rel_type='purchase_vendor').
 *
 * A Purchase Vendor has no User account, so it can never be a task ASSIGNEE — the
 * relation link is the only way a task can belong to one. Read-only here; the link
 * is set in the Task module's "Related To" field.
 */
function TasksTab() {
  const { vendor } = useVendorWorkspace()
  const qc = useQueryClient()
  const [adding, setAdding] = useState(false)
  const key = ['purchase-vendor-tasks', vendor?.id]

  if (!vendor?.id) return null

  return (
    <>
      {/* The Task module's OWN drawer, with this vendor pre-linked and the
          relation locked — the same treatment the TPV Tasks tab gets. */}
      <TaskFormDrawer
        open={adding}
        onClose={() => setAdding(false)}
        defaults={{ rel_type: 'purchase_vendor', rel_id: vendor.id }}
        lockRel
        lockRelLabel={vendor.company_name}
        onSaved={() => qc.invalidateQueries({ queryKey: key })}
      />
      <VendorTasksPanel
        queryKey={key}
        fetcher={() => purchaseApi.vendors.tasks(vendor.id)}
        accent="#7C3AED"
        searchable
        addLabel="Add Task"
        onAdd={() => setAdding(true)}
        emptyHint={'Add a task here, or set a task’s “Related To” → “Purchase Vendor” → this vendor.'}
      />
    </>
  )
}

/* ── Commercial: statement + payments ────────────────────────────────────── */

/**
 * Payments made against this vendor's purchase invoices.
 *
 * A payment has no vendor column — it hangs off an invoice, so the server walks
 * purchase_invoices for this vendor and derives the list. Hand-rolled rather than
 * VendorScopedList because payments have no status column and money is
 * right-aligned.
 */
function PaymentsTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)

  useEffect(() => {
    let alive = true
    purchaseApi.vendors.payments(vendor.id)
      .then(r => { if (alive) setRows(Array.isArray(r) ? r : (r?.data ?? [])) })
      .catch((e) => { if (alive) { setRows([]); setLoadError(e) } })
    return () => { alive = false }
  }, [vendor.id])

  const total = (rows ?? []).reduce((s, r) => s + Number(r.amount || 0), 0)

  return (
    <div className="card-3d" style={card}>
      {/* No Add: a payment is recorded AGAINST an invoice, through the invoice's
          own Record Payment action — there is no standalone payment form, and
          inventing one would be a second way to write the same row. */}
      <TabHead title="Payments" count={rows?.length} />
      {loadError ? <LoadError error={loadError} onRetry={null} />
        : rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : rows.length === 0 ? <Empty text="No payments recorded against this vendor's invoices" />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead><tr style={{ background: 'var(--bg-input)' }}>
                  {['Date', 'Invoice', 'Mode', 'Reference'].map(h => <th key={h} style={th}>{h}</th>)}
                  <th style={{ ...th, textAlign: 'right' }}>Amount</th>
                </tr></thead>
                <tbody>
                  {rows.map(p => (
                    <tr key={p.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={td}>{fmtDate(p.payment_date)}</td>
                      <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>{p.invoice?.invoice_number || '—'}</td>
                      <td style={td}>{p.payment_mode_label || '—'}</td>
                      <td style={td}>{p.reference || '—'}</td>
                      <td style={{ ...td, textAlign: 'right', color: 'var(--text-h)', fontWeight: 700 }}>{fmtMoney(p.amount)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr style={{ borderTop: '2px solid var(--border)' }}>
                  <td colSpan={4} style={{ ...td, textAlign: 'right', fontWeight: 700 }}>Total paid</td>
                  <td style={{ ...td, textAlign: 'right', color: 'var(--text-h)', fontWeight: 800 }}>{fmtMoney(total)}</td>
                </tr></tfoot>
              </table>
            </div>
          )}
    </div>
  )
}

/**
 * Running account statement.
 *
 * Invoices debit the account; payments and debit notes credit it. Derived from
 * the same three tables the other Commercial tabs show — there is no separate
 * ledger store in Purchase or in the accounts module to read instead.
 */
function StatementTab() {
  const { vendor } = useVendorWorkspace()
  const [data, setData] = useState(null)

  useEffect(() => {
    let alive = true
    purchaseApi.vendors.statement(vendor.id)
      .then(d => { if (alive) setData(d) })
      .catch(() => { if (alive) setData({ lines: [], closing_balance: 0 }) })
    return () => { alive = false }
  }, [vendor.id])

  const lines = data?.lines ?? []

  return (
    <div className="card-3d" style={card}>
      <TabHead title="Purchase Statement" count={lines.length} />
      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '-6px 0 12px' }}>
        Invoices debit the account; payments and debit notes credit it. Oldest first.
      </p>
      {data === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : lines.length === 0 ? <Empty text="No commercial activity for this vendor yet" />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead><tr style={{ background: 'var(--bg-input)' }}>
                  {['Date', 'Type', 'Reference'].map(h => <th key={h} style={th}>{h}</th>)}
                  {['Debit', 'Credit', 'Balance'].map(h => <th key={h} style={{ ...th, textAlign: 'right' }}>{h}</th>)}
                </tr></thead>
                <tbody>
                  {lines.map((l, i) => (
                    <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={td}>{fmtDate(l.date)}</td>
                      <td style={td}>{l.type}</td>
                      <td style={{ ...td, color: 'var(--text-h)' }}>{l.reference || '—'}</td>
                      <td style={{ ...td, textAlign: 'right' }}>{l.debit ? fmtMoney(l.debit) : '—'}</td>
                      <td style={{ ...td, textAlign: 'right' }}>{l.credit ? fmtMoney(l.credit) : '—'}</td>
                      <td style={{ ...td, textAlign: 'right', color: 'var(--text-h)', fontWeight: 700 }}>{fmtMoney(l.balance)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr style={{ borderTop: '2px solid var(--border)' }}>
                  <td colSpan={5} style={{ ...td, textAlign: 'right', fontWeight: 700 }}>Closing balance</td>
                  <td style={{ ...td, textAlign: 'right', color: 'var(--text-h)', fontWeight: 800, fontSize: 15 }}>
                    {fmtMoney(data?.closing_balance)}
                  </td>
                </tr></tfoot>
              </table>
            </div>
          )}
    </div>
  )
}

/* ── General: Medical & Training ─────────────────────────────────────────── */

/**
 * Purchase keeps medicals and trainings NORMALISED — one row per record, not one
 * per worker — so these list the history rather than projecting a latest value.
 */
function MedicalTab() {
  const { vendor } = useVendorWorkspace()

  return (
    <VendorScopedList
      title="Medical Records"
      fetcher={(vid) => purchaseApi.workforce.medicals(vid)}
      statusCfg={(s) => ({ label: s || '—', color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' })}
      columns={[
        { header: 'Worker', strong: true, cell: (r) => r.worker?.full_name || '—' },
        { header: 'Code', cell: (r) => r.worker?.worker_code || '—' },
        { header: 'Exam Date', cell: (r) => fmtDate(r.exam_date) },
        { header: 'Expires', cell: (r) => fmtDate(r.expiry_date) },
        { header: 'Fitness', cell: (r) => r.fitness_status || '—' },
      ]}
      key={`med-${vendor.id}`}
    />
  )
}

function TrainingTab() {
  const { vendor } = useVendorWorkspace()

  return (
    <VendorScopedList
      title="Training Records"
      fetcher={(vid) => purchaseApi.workforce.trainings(vid)}
      statusCfg={(s) => ({ label: s || '—', color: '#8b5cf6', bg: 'rgba(139,92,246,0.15)' })}
      columns={[
        { header: 'Worker', strong: true, cell: (r) => r.worker?.full_name || '—' },
        { header: 'Code', cell: (r) => r.worker?.worker_code || '—' },
        { header: 'Training', cell: (r) => r.title || r.name || '—' },
        { header: 'Completed', cell: (r) => fmtDate(r.completed_at || r.training_date) },
        { header: 'Expires', cell: (r) => fmtDate(r.expiry_date) },
      ]}
      key={`trn-${vendor.id}`}
    />
  )
}

/* ── Execution: Project / Expenses / Ticket / Meeting ────────────────────── */

/**
 * Projects awarded to this Purchase vendor.
 *
 * projects.vendor_id is only unique WITHIN a link_type, so vendor_type is always
 * sent — the same integer is a different company under 'tpv_vendor'. Creating
 * opens the Projects module's OWN drawer with this vendor preset and locked.
 */
function ProjectTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()
  const [rows, setRows] = useState(null)
  const [adding, setAdding] = useState(false)

  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => {
      setLoadError(null)
    projectApi.list({ vendor_id: vendor.id, vendor_type: 'purchase_vendor' })
      .then(d => setRows(d?.data ?? d ?? []))
      .catch(e => { setRows([]); setLoadError(e) })
  }, [vendor.id])

  useEffect(() => { load() }, [load])

  return (
    <>
      {/* Rendered OUTSIDE the card: .card-3d sets transform-style/will-change,
          which makes it the containing block for position:fixed descendants, so
          a drawer nested inside it is pinned to the card instead of the viewport.
          ProjectFormDrawer positions itself rather than using kit3d's portalled
          Overlay, so it has to be a sibling. */}
      <ProjectFormDrawer
        open={adding}
        onClose={() => setAdding(false)}
        onSaved={load}
        preset={{ link_type: 'purchase_vendor', vendor_id: vendor.id }}
        presetLabel={vendor.company_name}
      />
    <div className="card-3d" style={card}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, gap: 10 }}>
        <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>
          Projects{rows && <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}> · {rows.length}</span>}
        </h2>
        <button onClick={() => setAdding(true)} style={primaryBtn}><Plus size={14} /> Add Project</button>
      </div>
      {loadError ? <LoadError error={loadError} onRetry={load} />
        : rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : rows.length === 0 ? <Empty text="No projects for this vendor" />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead><tr style={{ background: 'var(--bg-input)' }}>
                  {['Project', 'Status', 'Progress', 'Start', 'Deadline'].map(h => <th key={h} style={th}>{h}</th>)}
                </tr></thead>
                <tbody>
                  {rows.map(p => (
                    <tr key={p.id} onClick={() => navigate(`/app/projects/${p.id}`)}
                      style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                      <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>{p.name}</td>
                      <td style={td}><Badge cfg={PROJECT_STATUS[p.status]} /></td>
                      <td style={td}>{Math.max(0, Math.min(100, Number(p.progress) || 0))}%</td>
                      <td style={td}>{fmtDate(p.start_date)}</td>
                      <td style={td}>{fmtDate(p.deadline)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
    </div>
    </>
  )
}

/**
 * Project expenses across every project this vendor is linked to.
 *
 * Add uses the same generic VendorAddModal + projectApi.addExpense the TPV
 * Expenses tab uses — the project module exposes no dedicated expense form, so
 * this IS the real one, not a reduced copy. It is blocked outright when the
 * vendor has no projects, because an expense hangs off a project.
 */
function ExpensesTab() {
  const { vendor } = useVendorWorkspace()
  const [data, setData] = useState(null)
  const [projects, setProjects] = useState([])
  const [adding, setAdding] = useState(false)
  const [nonce, setNonce] = useState(0)

  useEffect(() => {
    let alive = true
    setData(null)
    projectApi.vendorExpenses(vendor.id, 'purchase_vendor')
      .then(d => { if (alive) setData(d?.data ?? d) })
      .catch(() => { if (alive) setData({ rows: [], total: 0 }) })
    return () => { alive = false }
  }, [vendor.id, nonce])

  useEffect(() => {
    let alive = true
    projectApi.list({ vendor_id: vendor.id, vendor_type: 'purchase_vendor' })
      .then(d => { if (alive) setProjects(d?.data ?? d ?? []) })
      .catch(() => {})
    return () => { alive = false }
  }, [vendor.id])

  const rows = data?.rows ?? []

  return (
    <div className="card-3d" style={card}>
      {adding && (
        <VendorAddModal
          title={`New Expense — ${vendor.company_name}`} submitLabel="Add Expense"
          blockedReason={projects.length ? null : 'This vendor has no projects yet. An expense is recorded against a project.'}
          fields={[
            { name: 'project_id', label: 'Project', type: 'select', required: true,
              options: projects.map(p => ({ value: p.id, label: p.name })) },
            { name: 'title', label: 'Title', type: 'text', required: true },
            { name: 'amount', label: 'Amount', type: 'number', required: true },
            { name: 'expense_date', label: 'Date', type: 'date', required: true },
            { name: 'category', label: 'Category', type: 'text' },
            { name: 'billable', label: 'Billable', type: 'checkbox' },
            { name: 'note', label: 'Note', type: 'textarea' },
          ]}
          onClose={() => setAdding(false)}
          onSubmit={({ project_id, ...rest }) => projectApi.addExpense(project_id, rest)}
          onSaved={() => { setAdding(false); setNonce(n => n + 1) }}
        />
      )}
      <TabHead title="Expenses" count={rows.length} addLabel="Add Expense" onAdd={() => setAdding(true)} />
      {data === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : rows.length === 0 ? <Empty text="No expenses on this vendor's projects" />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead><tr style={{ background: 'var(--bg-input)' }}>
                  {['Date', 'Title', 'Project', 'Category'].map(h => <th key={h} style={th}>{h}</th>)}
                  <th style={{ ...th, textAlign: 'right' }}>Amount</th>
                </tr></thead>
                <tbody>
                  {rows.map(e => (
                    <tr key={e.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={td}>{fmtDate(e.expense_date)}</td>
                      <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>{e.title}</td>
                      <td style={td}>{e.project_name || '—'}</td>
                      <td style={td}>{e.category || '—'}</td>
                      <td style={{ ...td, textAlign: 'right', color: 'var(--text-h)', fontWeight: 700 }}>{fmtMoney(e.amount)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr style={{ borderTop: '2px solid var(--border)' }}>
                  <td colSpan={4} style={{ ...td, textAlign: 'right', fontWeight: 700 }}>Total</td>
                  <td style={{ ...td, textAlign: 'right', color: 'var(--text-h)', fontWeight: 800 }}>{fmtMoney(data?.total)}</td>
                </tr></tfoot>
              </table>
            </div>
          )}
    </div>
  )
}

/**
 * Tickets raised on this vendor's projects — a ticket has no vendor column and
 * reaches one THROUGH its project, resolved server-side.
 *
 * Add opens the Helpdesk module's real NewTicketModal, seeded with only THIS
 * vendor's projects so the project picker cannot reach another vendor's work.
 */
function TicketTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [projects, setProjects] = useState([])
  const [settings, setSettings] = useState(null)
  const [adding, setAdding] = useState(false)
  const [nonce, setNonce] = useState(0)

  useEffect(() => {
    let alive = true
    setRows(null)
    setLoadError(null)
    helpdeskApi.tickets.list({ vendor_id: vendor.id, vendor_type: 'purchase_vendor' })
      .then(r => { if (alive) setRows(Array.isArray(r) ? r : (r?.data ?? [])) })
      .catch((e) => { if (alive) { setRows([]); setLoadError(e) } })
    return () => { alive = false }
  }, [vendor.id, nonce])

  // The create modal's project picker — this vendor's projects only.
  useEffect(() => {
    let alive = true
    projectApi.list({ vendor_id: vendor.id, vendor_type: 'purchase_vendor' })
      .then(d => { if (alive) setProjects(d?.data ?? d ?? []) })
      .catch(() => {})
    helpdeskApi.settings.all().then(s => { if (alive) setSettings(s) }).catch(() => {})
    return () => { alive = false }
  }, [vendor.id])

  return (
    <>
      {/* Outside the card for the same reason as ProjectFormDrawer — see there. */}
      {adding && (
        <NewTicketModal
          title={`New Ticket — ${vendor.company_name}`}
          settings={settings} projects={projects}
          draft={{ source: 'purchase' }}
          onClose={() => setAdding(false)}
          onCreated={() => { setAdding(false); setNonce(n => n + 1) }}
        />
      )}
    <div className="card-3d" style={card}>
      <TabHead title="Tickets" count={rows?.length} addLabel="Add Ticket" onAdd={() => setAdding(true)} />
      {loadError ? <LoadError error={loadError} onRetry={() => setNonce(n => n + 1)} />
        : rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : rows.length === 0 ? <Empty text="No tickets on this vendor's projects" />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead><tr style={{ background: 'var(--bg-input)' }}>
                  {['#', 'Subject', 'Status', 'Priority', 'Project', 'Assigned', 'Created', 'Updated'].map(h => <th key={h} style={th}>{h}</th>)}
                </tr></thead>
                <tbody>
                  {rows.map(t => (
                    <tr key={t.id} onClick={() => navigate(`/app/helpdesk/tickets/${t.id}`)}
                      style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                      <td style={td}>#{t.id}</td>
                      <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>{t.subject}</td>
                      <td style={td}><Badge cfg={{
                        label: String(t.status || '—').replace(/_/g, ' '),
                        color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)',
                      }} /></td>
                      <td style={{ ...td, textTransform: 'capitalize' }}>{t.priority || '—'}</td>
                      <td style={td}>{t.project?.name || '—'}</td>
                      <td style={td}>{t.assignee?.name || '—'}</td>
                      <td style={td}>{fmtDate(t.created_at)}</td>
                      <td style={td}>{fmtDate(t.updated_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
    </div>
    </>
  )
}

/**
 * Kickoff meetings — Purchase owns its own engine (purchase_kickoff_* tables).
 *
 * The list renders here, but Add navigates: the real create form is a full PAGE
 * (PurchaseKickoffCreate at /app/purchase/kickoff/new), not a modal. Building a
 * modal version would be exactly the duplicate form to avoid, so the Add button
 * opens the module's own screen pre-scoped to this vendor.
 */
function MeetingTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()

  return (
    <VendorScopedList
      title="Meetings"
      addLabel="Add Meeting"
      onAdd={() => navigate(`/app/purchase/kickoff/new?vendor=${vendor.id}`)}
      fetcher={(vid) => purchaseApi.kickoff.list({ purchase_vendor_id: vid })}
      statusCfg={(s) => ({ label: String(s || '—').replace(/_/g, ' '), color: '#7C3AED', bg: 'rgba(124,58,237,0.15)' })}
      columns={[
        { header: 'Title', strong: true, cell: (m) => m.title || `Meeting #${m.id}` },
        { header: 'Type', cell: (m) => m.meeting_type_label || 'Kickoff Meeting' },
        { header: 'Reference', cell: (m) => m.reference || '—' },
        { header: 'Scheduled', cell: (m) => fmtDate(m.scheduled_at) },
        { header: 'Mode', cell: (m) => m.mode || '—' },
      ]}
    />
  )
}

/* ── Execution: the three shared polymorphic panels ──────────────────────── */

// Thin adapters: read the vendor from the workspace context (tab elements are
// pre-instantiated, so they take no props) and hand the shared panel the
// Purchase api group.

function PurchaseVendorNotes() {
  const { vendor } = useVendorWorkspace()
  return <VendorNotes vendorId={vendor.id} vendorName={vendor.company_name} manage api={purchaseApi.vendors} />
}

function PurchaseVendorReminders() {
  const { vendor } = useVendorWorkspace()
  return <VendorReminders vendorId={vendor.id} vendorName={vendor.company_name} manage api={purchaseApi.vendors} />
}

function PurchaseVendorAttachments() {
  const { vendor } = useVendorWorkspace()
  return <VendorAttachments vendorId={vendor.id} manage api={purchaseApi.vendors} />
}

/* ── Execution: Appointments ─────────────────────────────────────────────── */

/**
 * Appointments against this vendor, on the shared `appointments` table.
 *
 * Reads the Purchase-gated mirror rather than /api/sales/appointments: that route
 * is behind auth:sanctum alone and takes subject_type as a free string, so vendor
 * appointments there would be readable by any authenticated login.
 */
function PurchaseVendorAppointments() {
  const { vendor } = useVendorWorkspace()
  const [rows, setRows] = useState(null)
  const [adding, setAdding] = useState(false)

  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => {
      setLoadError(null)
    purchaseApi.vendors.appointments.list(vendor.id)
      .then(r => setRows(Array.isArray(r) ? r : (r?.data ?? [])))
      .catch(e => { setRows([]); setLoadError(e) })
  }, [vendor.id])

  useEffect(() => { load() }, [load])

  const complete = async (a) => {
    const outcome = window.prompt(`What happened at “${a.title}”?`)
    if (!outcome?.trim()) return
    try { await purchaseApi.vendors.appointments.complete(vendor.id, a.id, { outcome }); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not complete the appointment.') }
  }

  const remove = async (a) => {
    if (!window.confirm(`Delete “${a.title}”?`)) return
    try { await purchaseApi.vendors.appointments.remove(vendor.id, a.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not delete the appointment.') }
  }

  return (
    <div className="card-3d" style={card}>
      {adding && <AppointmentModal vendor={vendor} onClose={() => setAdding(false)}
        onSaved={() => { setAdding(false); load() }} />}

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, gap: 10 }}>
        <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>
          Appointments{rows && <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}> · {rows.length}</span>}
        </h2>
        <button onClick={() => setAdding(true)} style={primaryBtn}><Plus size={14} /> Add Appointment</button>
      </div>

      {loadError ? <LoadError error={loadError} onRetry={load} />
        : rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : rows.length === 0 ? <Empty text="No appointments with this vendor" />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead><tr style={{ background: 'var(--bg-input)' }}>
                  {['Title', 'When', 'Location', 'Assigned', 'Status', ''].map(h => <th key={h} style={th}>{h}</th>)}
                </tr></thead>
                <tbody>
                  {rows.map(a => (
                    <tr key={a.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>{a.title}</td>
                      <td style={td}>{fmtDate(a.starts_at)}</td>
                      <td style={td}>{a.location || '—'}</td>
                      <td style={td}>{a.assignee?.name || '—'}</td>
                      <td style={td}><Badge cfg={{
                        label: a.status || 'scheduled',
                        color: a.status === 'scheduled' ? '#f59e0b' : '#0ca30c',
                        bg: a.status === 'scheduled' ? 'rgba(245,158,11,0.15)' : 'rgba(12,163,12,0.15)',
                      }} /></td>
                      <td style={{ ...td, textAlign: 'right', whiteSpace: 'nowrap' }}>
                        {a.status === 'scheduled' && (
                          <button onClick={() => complete(a)} title="Complete" style={iconBtn}><CheckCircle2 size={13} /></button>
                        )}
                        <button onClick={() => remove(a)} title="Delete" style={{ ...iconBtn, color: '#ef4444' }}><Trash2 size={13} /></button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
    </div>
  )
}

function AppointmentModal({ vendor, onClose, onSaved }) {
  const [f, setF] = useState({ title: '', starts_at: '', ends_at: '', location: '', description: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.title.trim()) { setErr('A title is required.'); return }
    if (!f.starts_at) { setErr('A start time is required.'); return }

    setBusy(true); setErr('')
    try {
      // The subject is set server-side from the route — never posted.
      await purchaseApi.vendors.appointments.create(vendor.id, f)
      onSaved()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save the appointment.')
    } finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={600}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>New Appointment</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        With <strong style={{ color: 'var(--text-h)' }}>{vendor.company_name}</strong>.
      </p>

      {err && (
        <div style={{ padding: '9px 12px', borderRadius: 10, marginBottom: 12, fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>
          {err}
        </div>
      )}

      <Field label="Title"><TextInput value={f.title} onChange={set('title')} placeholder="e.g. Quarterly review" /></Field>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Starts"><input type="datetime-local" value={f.starts_at} onChange={set('starts_at')} style={inputStyle} /></Field>
        <Field label="Ends"><input type="datetime-local" value={f.ends_at} onChange={set('ends_at')} style={inputStyle} /></Field>
      </div>

      <Field label="Location"><TextInput value={f.location} onChange={set('location')} placeholder="Site, office or a meeting link" /></Field>

      <Field label="Notes" full>
        <textarea value={f.description} onChange={set('description')} rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
      </Field>

      <ModalFooter onClose={onClose} onConfirm={save} loading={busy}
        disabled={!f.title.trim() || !f.starts_at} confirmLabel="Add Appointment" />
    </Overlay>
  )
}

export const TAB_ELEMENTS = {
  // General
  overview: <OverviewTab />,
  profile: <ProfileTab />,
  contacts: <ContactsTab />,
  customer: <CustomerTab />,
  medical: <MedicalTab />,
  training: <TrainingTab />,
  onboarding: <OnboardingTab />,
  // Compliance — Purchase-native prequalification + due-diligence (mirror TPV)
  prequalification: <PrequalificationTab />,
  'due-diligence': <DueDiligenceTab />,
  // Commercial — all native to purchase_vendor_id
  quotations: <QuotationsTab />,
  contracts: <ContractsTab />,
  'purchase-orders': <OrdersTab />,
  'purchase-invoices': <InvoicesTab />,
  'debit-notes': <DebitNotesTab />,
  statement: <StatementTab />,
  payments: <PaymentsTab />,
  // Execution
  project: <ProjectTab />,
  tasks: <TasksTab />,
  expenses: <ExpensesTab />,
  appointment: <PurchaseVendorAppointments />,
  meeting: <MeetingTab />,
  notes: <PurchaseVendorNotes />,
  attachments: <PurchaseVendorAttachments />,
  ticket: <TicketTab />,
  reminders: <PurchaseVendorReminders />,
  // todo, kb, vault and the five Performance tabs have no backing table
  // anywhere in the schema — they fall through to ComingSoonTab.
}

const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 8, background: '#7C3AED', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const linkBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 7, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12, fontWeight: 700 }
const linkInline = { background: 'none', border: 'none', color: '#7C3AED', cursor: 'pointer', fontWeight: 700, padding: 0, fontSize: 14 }
const iconBtn = { border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', borderRadius: 7, padding: '4px 7px', cursor: 'pointer', marginLeft: 6 }
