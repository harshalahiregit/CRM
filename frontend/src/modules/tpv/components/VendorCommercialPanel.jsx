import { useState, useEffect, useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FileText, FileSignature, ShoppingCart, ReceiptIndianRupee, FileMinus,
  BookOpenText, Wallet, Link2, Unlink, Loader2, AlertTriangle, Plus,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { purchaseApi } from '@/services/purchaseApi'
import { SectionTable } from './VendorSectionTable'
// The Purchase module's own create forms, reused rather than reimplemented — a
// document raised here is built and saved by exactly the code that builds one
// raised in Purchase.
import { NewRfqModal } from '@/modules/purchase/pages/PurchaseRfqs'
import { NewContractModal } from '@/modules/purchase/pages/PurchaseContracts'
import { NewOrderModal } from '@/modules/purchase/pages/PurchaseOrders'
import { NewInvoiceModal } from '@/modules/purchase/pages/PurchaseInvoices'
import { NewDebitNoteModal } from '@/modules/purchase/pages/PurchaseDebitNotes'
import { Overlay, ModalFooter, Field, SelectInput, InfoBox } from '@/components/ui/kit3d'

/**
 * The Commercial group — quotations, contracts, orders, invoices, debit notes,
 * statement and payments.
 *
 * Commercial documents key to purchase_vendors.purchase_vendor_id and are wholly
 * Purchase-owned; the Purchase module was decoupled from the shared vendor master
 * on purpose. So this shows nothing of its own: when the same company is
 * registered in BOTH modules, an admin links the two records and these tabs read
 * the real Purchase lists through that link. Unlinked, they say so rather than
 * implying the vendor has no commercial history.
 *
 * The link is explicit and audited — never a name match. Showing one company's
 * invoices on another's page is worse than showing none.
 */

const money = (n) => (n === null || n === undefined || n === '')
  ? '—'
  : `₹${Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const asDate = (d) => (d ? new Date(d).toLocaleDateString() : '—')

const Status = ({ value }) => (
  <span style={{
    display: 'inline-flex', padding: '2px 8px', borderRadius: 20, fontSize: 11, fontWeight: 700,
    textTransform: 'capitalize', color: 'var(--text-muted)', background: 'var(--bg-input)',
  }}>{String(value || '—').replace(/_/g, ' ')}</span>
)

/**
 * One descriptor per tab: where the rows come from and how they read.
 *
 * `fetch` takes the linked purchase_vendor_id for the five Purchase-list types,
 * or the TPV vendor id for the two derived ones — which is why each descriptor
 * declares `via` rather than the caller guessing.
 *
 * `open` returns [path, navigateOptions] for the REAL Purchase route. Only
 * contracts and RFQs have a detail page of their own; orders, invoices and debit
 * notes open a modal on their list screen, so Purchase navigates to the list with
 * `state.highlight` and the row is outlined there. Those three list pages read
 * location.state.highlight — this follows the same convention rather than
 * inventing detail URLs that do not exist.
 */
const DOCS = {
  // Purchase labels its RFQ screen "Quotations", so this tab mirrors it: the RFQs
  // this vendor was invited to, and the same New RFQ form — not the raw
  // purchase_quotations rows, which are the quotes recorded INSIDE an RFQ and have
  // no screen of their own.
  'Quotation': {
    icon: FileText, title: 'Quotations (RFQ)', via: 'purchase',
    hint: 'RFQs this vendor has been asked to quote on. Select one to open it.',
    fetch: (pvId) => purchaseApi.rfqs.list({ purchase_vendor_id: pvId }),
    open: (r) => [`/app/purchase/quotations/${r.id}`, undefined],
    // An RFQ takes a LIST of recipients, so it seeds the picked ids rather than
    // a single vendor field — hence its own prop name.
    add: { label: 'New RFQ', Modal: NewRfqModal, asList: true, after: (id) => `/app/purchase/quotations/${id}` },
    search: ['rfq_number', 'title', 'status', 'department'],
    columns: [
      { key: 'rfq_number', label: 'RFQ', render: r => <b>{r.rfq_number || `#${r.id}`}</b> },
      { key: 'title', label: 'Title', render: r => r.title || '—' },
      { key: 'status', label: 'Status', render: r => <Status value={r.status} /> },
      { key: 'quotations_count', label: 'Quotes', render: r => r.quotations_count ?? 0 },
      { key: 'closes_at', label: 'Deadline', render: r => asDate(r.closes_at) },
    ],
  },
  'Contracts': {
    icon: FileSignature, title: 'Contracts', via: 'purchase',
    hint: 'Purchase contracts held with this vendor.',
    fetch: (pvId) => purchaseApi.contracts.list({ purchase_vendor_id: pvId }),
    open: (r) => [`/app/purchase/contracts/${r.id}`, undefined],
    add: { label: 'New Contract', Modal: NewContractModal, after: (id) => `/app/purchase/contracts/${id}` },
    search: ['contract_number', 'title', 'status'],
    columns: [
      { key: 'contract_number', label: 'Contract', render: r => <b>{r.contract_number || `#${r.id}`}</b> },
      { key: 'title', label: 'Title', render: r => r.title || '—' },
      { key: 'status', label: 'Status', render: r => <Status value={r.status} /> },
      // Contracts carry a ceiling and what has been drawn against it, not a total.
      { key: 'spend_ceiling', label: 'Ceiling', render: r => money(r.spend_ceiling) },
      { key: 'consumed_amount', label: 'Consumed', render: r => money(r.consumed_amount) },
      { key: 'end_date', label: 'Ends', render: r => asDate(r.end_date) },
    ],
  },
  'Purchase Order': {
    icon: ShoppingCart, title: 'Purchase Orders', via: 'purchase',
    hint: 'Orders raised on this vendor.',
    fetch: (pvId) => purchaseApi.orders.list({ purchase_vendor_id: pvId }),
    open: (r) => ['/app/purchase/orders', { state: { highlight: r.id } }],
    add: { label: 'New Order', Modal: NewOrderModal },
    search: ['po_number', 'status'],
    columns: [
      { key: 'po_number', label: 'PO', render: r => <b>{r.po_number || `#${r.id}`}</b> },
      { key: 'status', label: 'Status', render: r => <Status value={r.status} /> },
      { key: 'total', label: 'Value', render: r => money(r.total) },
      { key: 'order_date', label: 'Ordered', render: r => asDate(r.order_date) },
      { key: 'expected_delivery_date', label: 'Expected', render: r => asDate(r.expected_delivery_date) },
    ],
  },
  'Purchase Invoice': {
    icon: ReceiptIndianRupee, title: 'Purchase Invoices', via: 'purchase',
    hint: 'Invoices received from this vendor.',
    fetch: (pvId) => purchaseApi.invoices.list({ purchase_vendor_id: pvId }),
    open: (r) => ['/app/purchase/invoices', { state: { highlight: r.id } }],
    add: { label: 'New Invoice', Modal: NewInvoiceModal },
    search: ['invoice_number', 'status'],
    columns: [
      { key: 'invoice_number', label: 'Invoice', render: r => <b>{r.invoice_number || `#${r.id}`}</b> },
      { key: 'status', label: 'Status', render: r => <Status value={r.status} /> },
      { key: 'total', label: 'Value', render: r => money(r.total) },
      { key: 'balance', label: 'Outstanding', render: r => money(r.balance) },
      { key: 'invoice_date', label: 'Dated', render: r => asDate(r.invoice_date) },
      { key: 'due_date', label: 'Due', render: r => asDate(r.due_date) },
    ],
  },
  'Debit Note': {
    icon: FileMinus, title: 'Debit Notes', via: 'purchase',
    hint: 'Debit notes raised against this vendor.',
    fetch: (pvId) => purchaseApi.debitNotes.list({ purchase_vendor_id: pvId }),
    open: (r) => ['/app/purchase/debit-notes', { state: { highlight: r.id } }],
    add: { label: 'New Debit Note', Modal: NewDebitNoteModal },
    search: ['debit_number', 'status'],
    columns: [
      { key: 'debit_number', label: 'Debit Note', render: r => <b>{r.debit_number || `#${r.id}`}</b> },
      { key: 'status', label: 'Status', render: r => <Status value={r.status} /> },
      { key: 'total', label: 'Value', render: r => money(r.total) },
      { key: 'debit_date', label: 'Dated', render: r => asDate(r.debit_date) },
    ],
  },
  'Payments': {
    icon: Wallet, title: 'Payments', via: 'vendor',
    hint: 'Payments made against this vendor’s purchase invoices.',
    fetch: (vId) => tpvApi.vendors.purchase.payments(vId),
    // A payment has no screen of its own — it belongs to an invoice, so open that.
    open: (r) => ['/app/purchase/invoices', { state: { highlight: r.purchase_invoice_id } }],
    search: ['reference', 'payment_mode_label'],
    columns: [
      { key: 'payment_date', label: 'Date', render: r => asDate(r.payment_date) },
      { key: 'invoice', label: 'Invoice', render: r => r.invoice?.invoice_number || '—' },
      { key: 'amount', label: 'Amount', render: r => <b>{money(r.amount)}</b> },
      { key: 'payment_mode_label', label: 'Mode', render: r => r.payment_mode_label || '—' },
      { key: 'reference', label: 'Reference', render: r => r.reference || '—' },
    ],
  },
}

export function VendorCommercial({ tab, vendorId, vendorName, manage }) {
  const navigate = useNavigate()
  const doc = DOCS[tab]                     // undefined for Purchase Statement

  const [link, setLink] = useState(null)    // { linked_id, suggested_id, candidates }
  const [linking, setLinking] = useState(false)
  const [addOpen, setAddOpen] = useState(false)
  const [rows, setRows] = useState([])
  const [statement, setStatement] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const loadLink = useCallback(() => {
    setLoading(true)
    tpvApi.vendors.purchase.matches(vendorId)
      .then(setLink)
      .catch(e => setError(e?.response?.data?.message || 'Could not check the Purchase link.'))
      .finally(() => setLoading(false))
  }, [vendorId])

  useEffect(() => { loadLink() }, [loadLink])

  const pvId = link?.linked_id ?? null

  // Rows only exist once the two records are linked.
  useEffect(() => {
    if (!link || !pvId) { setRows([]); setStatement(null); return }

    setLoading(true); setError(null)
    const p = tab === 'Purchase Statement'
      ? tpvApi.vendors.purchase.statement(vendorId).then(setStatement)
      : (doc.via === 'purchase' ? doc.fetch(pvId) : doc.fetch(vendorId))
          .then(d => setRows(Array.isArray(d) ? d : (d?.data ?? [])))

    p.catch(e => setError(e?.response?.data?.message || e?.title || 'Could not load records.'))
      .finally(() => setLoading(false))
  }, [link, pvId, tab, doc, vendorId])

  if (loading && !link) {
    return <div style={{ padding: 20, color: 'var(--text-muted)', fontSize: 13, display: 'flex', gap: 8, alignItems: 'center' }}>
      <Loader2 size={14} className="rfq-spin" /> Loading…
    </div>
  }

  if (!pvId) {
    return (
      <>
        {linking && <LinkModal vendorId={vendorId} vendorName={vendorName} link={link}
          onClose={() => setLinking(false)} onSaved={() => { setLinking(false); loadLink() }} />}
        <NotLinked
          vendorId={vendorId} vendorName={vendorName} manage={manage} link={link}
          onLink={() => setLinking(true)} onCreated={loadLink}
        />
      </>
    )
  }

  const linked = link.candidates?.find(c => String(c.id) === String(pvId))

  return (
    <>
      {linking && <LinkModal vendorId={vendorId} vendorName={vendorName} link={link}
        onClose={() => setLinking(false)} onSaved={() => { setLinking(false); loadLink() }} />}

      <LinkedBanner linked={linked} manage={manage} onChange={() => setLinking(true)} />

      {/* The Purchase module's OWN create form for this document type, with the
          vendor already chosen. Not a reduced copy: the same catalog picker,
          contract rates, line items and save path as raising one in Purchase.
          On save we go to the new record if it has a page, otherwise the list
          highlights it — and either way the tab reloads behind. */}
      {addOpen && doc?.add?.Modal && (
        <doc.add.Modal
          {...(doc.add.asList ? { presetVendorIds: [pvId] } : { presetVendorId: pvId })}
          onClose={() => setAddOpen(false)}
          onDone={(id) => {
            setAddOpen(false)
            doc.add.after && id
              ? navigate(doc.add.after(id))
              : navigate(...doc.open({ id }))
          }}
        />
      )}

      {tab === 'Purchase Statement'
        ? <Statement data={statement} loading={loading} error={error} />
        : (
          <SectionTable
            icon={doc.icon} title={doc.title} hint={doc.hint}
            loading={loading} error={error} rows={rows}
            searchFields={doc.search}
            onAdd={doc.add && manage ? () => setAddOpen(true) : undefined}
            addLabel={doc.add?.label}
            onRowClick={doc.open ? (r) => navigate(...doc.open(r)) : undefined}
            columns={doc.columns}
          />
        )}
    </>
  )
}

/** Running account ledger — invoices debit, payments and debit notes credit. */
function Statement({ data, loading, error }) {
  const lines = data?.lines ?? []

  const cols = ['Date', 'Type', 'Reference', 'Debit', 'Credit', 'Balance']

  return (
    <div className="pr-glass" style={{ padding: 18, borderRadius: 14 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
        <BookOpenText size={15} style={{ color: '#a78bfa' }} />
        <h3 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>Purchase Statement</h3>
      </div>
      <p style={{ margin: '0 0 14px', fontSize: 12, color: 'var(--text-muted)' }}>
        Invoices debit the account; payments and debit notes credit it. Oldest first.
      </p>

      {loading ? <span style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading…</span>
        : error ? <span style={{ color: '#ef4444', fontSize: 13 }}>{error}</span>
        : lines.length === 0 ? <span style={{ color: 'var(--text-muted)', fontSize: 13 }}>No commercial activity for this vendor yet.</span>
        : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
              <thead>
                <tr>{cols.map((h, i) => (
                  <th key={h} style={{
                    padding: '8px 10px', textAlign: i >= 3 ? 'right' : 'left', whiteSpace: 'nowrap',
                    fontSize: 10, fontWeight: 700, letterSpacing: '0.05em', textTransform: 'uppercase',
                    color: 'var(--text-muted)', borderBottom: '1px solid var(--border)',
                  }}>{h}</th>
                ))}</tr>
              </thead>
              <tbody>
                {lines.map((l, i) => (
                  <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '8px 10px', whiteSpace: 'nowrap' }}>{asDate(l.date)}</td>
                    <td style={{ padding: '8px 10px' }}><Status value={l.type} /></td>
                    <td style={{ padding: '8px 10px', color: 'var(--text-h)' }}>{l.reference || '—'}</td>
                    <td style={{ padding: '8px 10px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{l.debit ? money(l.debit) : '—'}</td>
                    <td style={{ padding: '8px 10px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{l.credit ? money(l.credit) : '—'}</td>
                    <td style={{ padding: '8px 10px', textAlign: 'right', fontWeight: 700, fontVariantNumeric: 'tabular-nums', color: 'var(--text-h)' }}>{money(l.balance)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr style={{ borderTop: '2px solid var(--border)' }}>
                  <td colSpan={5} style={{ padding: '10px', textAlign: 'right', fontWeight: 800, color: 'var(--text-muted)', fontSize: 11.5, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Closing balance
                  </td>
                  <td style={{ padding: '10px', textAlign: 'right', fontWeight: 900, fontSize: 14, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                    {money(data?.closing_balance)}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}
    </div>
  )
}

function NotLinked({ vendorId, vendorName, manage, link, onLink, onCreated }) {
  const suggestion = link?.candidates?.find(c => String(c.id) === String(link?.suggested_id))
  const hasCandidates = (link?.candidates?.length ?? 0) > 0
  const [creating, setCreating] = useState(false)
  const [err, setErr] = useState('')

  const create = async () => {
    setCreating(true); setErr('')
    try { await tpvApi.vendors.purchase.createRecord(vendorId); onCreated() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not create the Purchase record.') }
    finally { setCreating(false) }
  }

  return (
    <div className="pr-glass" style={{ padding: 28, borderRadius: 14, textAlign: 'center' }}>
      <Unlink size={26} strokeWidth={1.7} style={{ color: 'var(--text-muted)', marginBottom: 12 }} />
      <h3 style={{ margin: '0 0 6px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>
        No Purchase record linked
      </h3>
      <p style={{ margin: '0 auto 16px', maxWidth: 520, fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.6 }}>
        Commercial documents belong to the Purchase module and key to a Purchase Vendor record.
        Link <strong style={{ color: 'var(--text-h)' }}>{vendorName}</strong> to its Purchase record to
        see its quotations, contracts, orders, invoices, debit notes, payments and statement here.
      </p>

      {suggestion && (
        <div style={{ maxWidth: 460, margin: '0 auto 14px' }}>
          <InfoBox>
            Likely match: <strong>{suggestion.company_name}</strong>
            {suggestion.purchase_vendor_code ? ` · ${suggestion.purchase_vendor_code}` : ''}
          </InfoBox>
        </div>
      )}

      {err && (
        <div style={{ maxWidth: 460, margin: '0 auto 12px', padding: '9px 12px', borderRadius: 10, fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>
          {err}
        </div>
      )}

      {manage ? (
        <div style={{ display: 'inline-flex', gap: 10, flexWrap: 'wrap', justifyContent: 'center' }}>
          {/* Registering the company in Purchase is the unblocking step when it
              is not there at all — the far more common case than a missed link. */}
          <button type="button" onClick={create} disabled={creating} style={{
            display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 18px', borderRadius: 10,
            border: 'none', background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff',
            fontWeight: 700, fontSize: 13, cursor: creating ? 'wait' : 'pointer', opacity: creating ? 0.7 : 1,
          }}>
            <Plus size={14} /> {creating ? 'Creating…' : 'Create Purchase Record'}
          </button>

          {/* Only worth offering when there is something to link to. */}
          {hasCandidates && (
            <button type="button" onClick={onLink} style={{
              display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 18px', borderRadius: 10,
              border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)',
              fontWeight: 700, fontSize: 13, cursor: 'pointer',
            }}>
              <Link2 size={14} /> Link Existing Record
            </button>
          )}
        </div>
      ) : (
        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>An administrator can link the records.</p>
      )}
    </div>
  )
}

function LinkedBanner({ linked, manage, onChange }) {
  return (
    <div style={{
      display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap',
      padding: '9px 14px', borderRadius: 10, marginBottom: 14,
      background: 'var(--bg-card)', border: '1px solid var(--border)',
    }}>
      <Link2 size={13} style={{ color: '#0ca30c', flexShrink: 0 }} />
      <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>
        Reading Purchase record{' '}
        <strong style={{ color: 'var(--text-h)' }}>{linked?.company_name || '—'}</strong>
        {linked?.purchase_vendor_code ? ` · ${linked.purchase_vendor_code}` : ''}
      </span>
      {manage && (
        <button type="button" onClick={onChange} style={{
          marginLeft: 'auto', border: '1px solid var(--border)', background: 'var(--bg-input)',
          color: 'var(--text-muted)', borderRadius: 8, padding: '4px 10px', fontSize: 11.5,
          fontWeight: 700, cursor: 'pointer',
        }}>Change</button>
      )}
    </div>
  )
}

function LinkModal({ vendorId, vendorName, link, onClose, onSaved }) {
  const [pick, setPick] = useState(String(link?.linked_id ?? link?.suggested_id ?? ''))
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const options = useMemo(() => ([
    ['', '— Not linked —'],
    ...(link?.candidates ?? []).map(c => [
      String(c.id),
      `${c.company_name}${c.purchase_vendor_code ? ` · ${c.purchase_vendor_code}` : ''}${c.email ? ` · ${c.email}` : ''}`,
    ]),
  ]), [link])

  const save = async () => {
    setBusy(true); setErr('')
    try {
      await tpvApi.vendors.purchase.link(vendorId, pick ? Number(pick) : null)
      onSaved()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save the link.')
    } finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={640}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Link Purchase Record</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 14px' }}>
        Point <strong style={{ color: 'var(--text-h)' }}>{vendorName}</strong> at the Purchase Vendor record for the same company.
      </p>

      <InfoBox tone="danger">
        <AlertTriangle size={13} style={{ display: 'inline', marginRight: 6, verticalAlign: '-2px' }} />
        This decides whose invoices and payments appear on this page. Confirm it is the same legal entity —
        matching on name alone is not enough.
      </InfoBox>

      {err && (
        <div style={{ padding: '9px 12px', borderRadius: 10, margin: '12px 0', fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>
          {err}
        </div>
      )}

      <Field label="Purchase Vendor">
        <SelectInput value={pick} onChange={e => setPick(e.target.value)} pairs options={options} />
      </Field>

      <ModalFooter onClose={onClose} onConfirm={save} loading={busy}
        confirmLabel={pick ? 'Link Record' : 'Unlink'} />
    </Overlay>
  )
}
