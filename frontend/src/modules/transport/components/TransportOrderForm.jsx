import { useState, useEffect } from 'react'
import { Hash, Building2, MapPin, Truck, FileText } from 'lucide-react'
import { fetchClientOptions } from '@/services/customerApi'
import { ORDER_PRIORITIES, ORDER_SOURCES } from '../constants'

/**
 * Transport Order create/edit form (SNG-TRN-006).
 *
 * Sections follow the same Section/Field primitives PurchaseVendorForm uses, so
 * the module looks like the rest of the CRM rather than like itself.
 *
 * The grouping — Identity → Customer & Route → Service → Additional — answers
 * one question per section: who is this for, where does it go, what service and
 * when, anything else. That ordering is the one structural idea taken from the
 * old zignls shipment form (sender / recipient / shipping / extras as separate
 * panels rather than one flat list). Nothing else from it is used: not its
 * fields, not its domain, not its styling.
 *
 * EVERY FIELD HERE IS IN THE APPROVED SCOPE. Deliberately absent, and flagged
 * rather than added: container/consignment (no registry entry), rate lookup
 * (SNG-TRN-005), vehicle/driver (SNG-TRN-009), viability (SNG-TRN-008).
 *
 * order_number and order_status are not inputs — the number is allocated by the
 * numbering engine and the status always starts at Draft.
 *
 * Controlled: the parent owns `value` and receives the next object via onChange.
 */

const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const selectStyle = { ...inputStyle, cursor: 'pointer' }
const readonlyStyle = { ...inputStyle, background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'not-allowed' }
const areaStyle = { ...inputStyle, minHeight: 74, resize: 'vertical', fontFamily: 'inherit' }

/** Required-field validation. Returns the first message or null. */
export function validateTransportOrder(v) {
  if (!v.customer_id) return 'Choose the customer this order is for.'
  if (!v.pickup_location?.address?.trim()) return 'A pickup address is required.'
  if (!v.delivery_location?.address?.trim()) return 'A delivery address is required.'
  if (!v.required_at) return 'Set the date and time the order is required by.'
  if (!v.service_type?.trim()) return 'Service type is required.'
  if (!v.priority) return 'Every order must carry a priority.'
  if (!v.source) return 'Record where this order came from.'
  return null
}

export const emptyTransportOrder = () => ({
  customer_id: '',
  customer_reference: '',
  pickup_location: { address: '', city: '', state: '', pincode: '', contact: '' },
  delivery_location: { address: '', city: '', state: '', pincode: '', contact: '' },
  required_at: '',
  service_type: '',
  priority: 'Normal',
  source: 'manual',
  rate_reference: '',
  route: '',
  special_requirements: '',
  billing_requirements: '',
})

function Section({ icon: Icon, title, children }) {
  return (
    <div style={{ marginBottom: 6 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '4px 0 12px' }}>
        <Icon size={15} style={{ color: '#7C3AED' }} />
        <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.03em' }}>{title}</h3>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>{children}</div>
    </div>
  )
}

function Field({ label, required, children, full }) {
  return (
    <div style={full ? { gridColumn: '1 / -1' } : undefined}>
      <label className="label" style={{ display: 'block', marginBottom: 4 }}>
        {label}{required && <span style={{ color: '#ef4444' }}> *</span>}
      </label>
      {children}
    </div>
  )
}

/** Pickup and delivery are the same shape, so they render from one component. */
function LocationFields({ prefix, value, onPart }) {
  return (
    <>
      <Field label="Address" required full>
        <input value={value?.address || ''} onChange={onPart('address')} placeholder={`${prefix} address`} style={inputStyle} />
      </Field>
      <Field label="City">
        <input value={value?.city || ''} onChange={onPart('city')} style={inputStyle} />
      </Field>
      <Field label="State">
        <input value={value?.state || ''} onChange={onPart('state')} style={inputStyle} />
      </Field>
      <Field label="PIN code">
        <input value={value?.pincode || ''} onChange={onPart('pincode')} style={inputStyle} />
      </Field>
      <Field label="Contact">
        <input value={value?.contact || ''} onChange={onPart('contact')} placeholder="Name or phone" style={inputStyle} />
      </Field>
    </>
  )
}

export default function TransportOrderForm({ value, onChange, mode = 'create' }) {
  const v = value || {}
  const set = (field) => (e) => onChange({ ...v, [field]: e?.target ? e.target.value : e })
  const setLoc = (which) => (part) => (e) =>
    onChange({ ...v, [which]: { ...(v[which] || {}), [part]: e.target.value } })

  // Customers come from the Customer module — Transport consumes that entity,
  // it does not own it (STOS-MAM §7 ownership matrix). fetchClientOptions is
  // that module's own cross-module dropdown helper, so the shape stays correct
  // even if the customer list endpoint changes.
  const [customers, setCustomers] = useState([])
  const [loadingCustomers, setLoadingCustomers] = useState(true)
  useEffect(() => {
    let alive = true
    fetchClientOptions()
      .then((rows) => { if (alive) setCustomers(Array.isArray(rows) ? rows : []) })
      .finally(() => { if (alive) setLoadingCustomers(false) })
    return () => { alive = false }
  }, [])

  return (
    <div style={{ display: 'grid', gap: 18 }}>
      {/* 1 — Identity. Who is this for and how urgent. */}
      <Section icon={Hash} title="Identity">
        {mode === 'edit' && (
          <Field label="Order number">
            <div style={{ position: 'relative' }}>
              <Hash size={13} style={{ position: 'absolute', left: 10, top: 11, color: 'var(--text-muted)' }} />
              <input value={v.order_number || 'Auto-generated'} readOnly tabIndex={-1} style={{ ...readonlyStyle, paddingLeft: 30 }} />
            </div>
          </Field>
        )}
        <Field label="Customer reference">
          <input value={v.customer_reference || ''} onChange={set('customer_reference')} placeholder="The customer's own PO or reference" style={inputStyle} />
        </Field>
        <Field label="Priority" required>
          <select value={v.priority || 'Normal'} onChange={set('priority')} style={selectStyle}>
            {ORDER_PRIORITIES.map((p) => <option key={p} value={p}>{p}</option>)}
          </select>
        </Field>
        <Field label="Source" required>
          <select value={v.source || 'manual'} onChange={set('source')} style={selectStyle}>
            {ORDER_SOURCES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </Field>
      </Section>

      {/* 2 — Customer. Consumed from the Customer module. */}
      <Section icon={Building2} title="Customer">
        <Field label="Customer" required full>
          <select value={v.customer_id || ''} onChange={set('customer_id')} style={selectStyle} disabled={loadingCustomers}>
            <option value="">{loadingCustomers ? 'Loading customers…' : 'Select customer…'}</option>
            {customers.map((c) => <option key={c.id} value={c.id}>{c.company}</option>)}
          </select>
        </Field>
      </Section>

      {/* 3 — Pickup. Its own section: origin and destination are separate
             questions and flattening them into one address block loses that. */}
      <Section icon={MapPin} title="Pickup">
        <LocationFields prefix="Pickup" value={v.pickup_location} onPart={setLoc('pickup_location')} />
      </Section>

      {/* 4 — Delivery. */}
      <Section icon={MapPin} title="Delivery">
        <LocationFields prefix="Delivery" value={v.delivery_location} onPart={setLoc('delivery_location')} />
      </Section>

      {/* 5 — Service. What is being asked for and by when. */}
      <Section icon={Truck} title="Service">
        <Field label="Service type" required>
          <input value={v.service_type || ''} onChange={set('service_type')} placeholder="e.g. Container Haulage" style={inputStyle} />
        </Field>
        <Field label="Required by" required>
          <input type="datetime-local" value={v.required_at || ''} onChange={set('required_at')} style={inputStyle} />
        </Field>
        <Field label="Route">
          <input value={v.route || ''} onChange={set('route')} placeholder="e.g. JNPT → Pune" style={inputStyle} />
        </Field>
        <Field label="Rate / contract reference">
          <input value={v.rate_reference || ''} onChange={set('rate_reference')} placeholder="Agreed commercial reference" style={inputStyle} />
        </Field>
      </Section>

      {/* 6 — Additional. Everything optional, last. */}
      <Section icon={FileText} title="Additional">
        <Field label="Special requirements" full>
          <textarea value={v.special_requirements || ''} onChange={set('special_requirements')} placeholder="Handling, temperature, escort, timing constraints…" style={areaStyle} />
        </Field>
        <Field label="Billing requirements" full>
          <textarea value={v.billing_requirements || ''} onChange={set('billing_requirements')} placeholder="What the customer needs on the invoice" style={areaStyle} />
        </Field>
      </Section>
    </div>
  )
}
