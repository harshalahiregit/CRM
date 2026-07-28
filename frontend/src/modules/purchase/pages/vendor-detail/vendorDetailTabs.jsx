import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { Save, ExternalLink, ClipboardList, Rocket, Inbox } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import PurchaseVendorForm, { validatePurchaseVendor } from '@/modules/purchase/components/PurchaseVendorForm'
import PurchaseVendorDocumentsReadOnly from '@/modules/purchase/components/PurchaseVendorDocumentsReadOnly'
import PurchaseVendorContacts from '@/modules/purchase/components/PurchaseVendorContacts'
import { useVendorWorkspace } from './vendorWorkspaceContext'
import {
  fmtMoney, fmtDate,
  poStatusCfg, pinvStatusCfg, contractStatusCfg, dnStatusCfg, quoteStatusCfg, contractTypeLabel,
} from '@/modules/purchase/constants'

/**
 * Purchase Vendor Detail — tab content. Every tab consumes Purchase-owned APIs
 * only (purchaseApi → /api/purchase/*), scoped to the current PurchaseVendor.
 * No TPV / shared-Vendor component, API or context is imported here.
 */

/* ── shared bits ─────────────────────────────────────────────────────────── */

const card = { padding: 18 }
const th = { textAlign: 'left', padding: '9px 12px', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em', color: 'var(--text-muted)', fontWeight: 700 }
const td = { padding: '9px 12px', color: 'var(--text-muted)', fontSize: 13 }

function Badge({ cfg }) {
  const c = cfg || { label: '—', color: '#6b7280', bg: 'rgba(107,114,128,0.15)' }
  return <span style={{ fontSize: 11, fontWeight: 700, color: c.color, background: c.bg, padding: '2px 9px', borderRadius: 999 }}>{c.label}</span>
}

function TabHead({ title, count, actionLabel, onAction }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, gap: 10 }}>
      <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{title}{typeof count === 'number' && <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}> · {count}</span>}</h2>
      {actionLabel && <button onClick={onAction} style={linkBtn}><ExternalLink size={13} /> {actionLabel}</button>}
    </div>
  )
}

/** Reusable vendor-scoped list — fetches THIS vendor's records from a Purchase API. */
function VendorScopedList({ title, fetcher, columns, statusCfg, moduleLabel, modulePath }) {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()
  const [rows, setRows] = useState(null)

  useEffect(() => {
    let alive = true
    fetcher(vendor.id)
      .then((r) => { if (alive) setRows(Array.isArray(r) ? r : (r?.data ?? [])) })
      .catch(() => { if (alive) setRows([]) })
    return () => { alive = false }
  }, [vendor.id, fetcher])

  return (
    <div className="card-3d" style={card}>
      <TabHead title={title} count={rows?.length} actionLabel={moduleLabel ? `Open in ${moduleLabel}` : null} onAction={() => navigate(modulePath)} />
      {rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : rows.length === 0 ? <Empty text={`No ${title}`} />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead><tr style={{ background: 'var(--bg-input)' }}>{columns.map((c) => <th key={c.header} style={th}>{c.header}</th>)}<th style={th}>Status</th></tr></thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} style={{ borderTop: '1px solid var(--border)' }}>
                      {columns.map((c) => <td key={c.header} style={c.strong ? { ...td, color: 'var(--text-h)', fontWeight: 700 } : td}>{c.cell(row)}</td>)}
                      <td style={td}><Badge cfg={statusCfg?.(row.status)} /></td>
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
    title="Quotations" moduleLabel="Quotations" modulePath="/app/purchase/quotations"
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
  const [err, setErr] = useState(null)

  useEffect(() => {
    let alive = true
    purchaseApi.contracts.list({ purchase_vendor_id: vendor.id })
      .then((r) => { if (alive) setRows(Array.isArray(r) ? r : (r?.data ?? [])) })
      .catch(() => { if (alive) setRows([]) })
    return () => { alive = false }
  }, [vendor.id])

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
      <TabHead title="Contracts" count={rows?.length} actionLabel="Open in Contracts" onAction={() => navigate('/app/purchase/contracts')} />
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{err}</p>}
      {rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : rows.length === 0 ? <Empty text="No Contracts" />
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
    title="Purchase Orders" moduleLabel="Orders" modulePath="/app/purchase/orders"
    fetcher={(vid) => purchaseApi.orders.list({ purchase_vendor_id: vid })}
    statusCfg={poStatusCfg}
    columns={[
      { header: 'PO #', cell: refOf, strong: true },
      { header: 'Amount', cell: amtOf },
      { header: 'Expected', cell: (r) => fmtDate(r.expected_by || r.expected_at) },
    ]} />
}

export function InvoicesTab() {
  return <VendorScopedList
    title="Purchase Invoices" moduleLabel="Invoices" modulePath="/app/purchase/invoices"
    fetcher={(vid) => purchaseApi.invoices.list({ purchase_vendor_id: vid })}
    statusCfg={pinvStatusCfg}
    columns={[
      { header: 'Invoice #', cell: refOf, strong: true },
      { header: 'Amount', cell: amtOf },
      { header: 'Date', cell: (r) => fmtDate(r.invoice_date || r.created_at) },
    ]} />
}

export function DebitNotesTab() {
  return <VendorScopedList
    title="Debit Notes" moduleLabel="Debit Notes" modulePath="/app/purchase/debit-notes"
    fetcher={(vid) => purchaseApi.debitNotes.list({ purchase_vendor_id: vid })}
    statusCfg={dnStatusCfg}
    columns={[
      { header: 'Reference', cell: refOf, strong: true },
      { header: 'Amount', cell: amtOf },
      { header: 'Date', cell: (r) => fmtDate(r.created_at) },
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

export const TAB_ELEMENTS = {
  profile: <ProfileTab />,
  contacts: <ContactsTab />,
  onboarding: <OnboardingTab />,
  quotations: <QuotationsTab />,
  contracts: <ContractsTab />,
  'purchase-orders': <OrdersTab />,
  'purchase-invoices': <InvoicesTab />,
  'debit-notes': <DebitNotesTab />,
}

const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 8, background: '#7C3AED', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const linkBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 7, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12, fontWeight: 700 }
const linkInline = { background: 'none', border: 'none', color: '#7C3AED', cursor: 'pointer', fontWeight: 700, padding: 0, fontSize: 14 }
