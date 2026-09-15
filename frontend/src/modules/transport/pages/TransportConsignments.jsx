import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Boxes, Loader2, Package, Trash2, Eye, Weight, Layers } from 'lucide-react'
import { transportConsignmentApi, transportOrderApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import DataTable from '@/components/ui/DataTable'
import PagerBar from '@/components/ui/PagerBar'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { fmtDateTime } from '../constants'

/**
 * Consignments — the commercial shipment (STOS-CTD §8).
 *
 * Requirements on screen: `ORD-004` (belongs to an order), `CTD-002` (customer
 * reachable), `CTD-003` (an order's consignments).
 *
 * ── THE NEW TRANSPORT DIALECT ────────────────────────────────────────────
 * Mirrors modules/accounts/pages/Bills.jsx, not the four Transport pages that
 * already ship: Tailwind classes for layout, var(--…) tokens for every colour,
 * zero raw hex, and the shared kit rather than a hand-rolled table. The older
 * Transport pages stay as they are until their own migration commit.
 *
 * ── THERE IS NO STATUS COLUMN, AND THAT IS THE POINT ─────────────────────
 * STOS-CTD §11 puts consignment status in a lifecycle engine that does not
 * exist (D-44). The API refuses a `status` filter outright rather than
 * accepting and ignoring one, so this page offers no status chips either —
 * filter buttons that did nothing would be the same lie in a different place.
 * What IS shown is what the data can honestly answer: the order it belongs to,
 * the customer, the cargo, and how many trips are carrying it.
 */
export default function TransportConsignments() {
  const toast = useToast()
  const qc = useQueryClient()

  const [search, setSearchRaw] = useState('')
  const [pageNo, setPageNo] = useState(1)
  // Narrowing the list must return to page 1, or you sit past the new end.
  const setSearch = (v) => { setSearchRaw(v); setPageNo(1) }

  const [drawer, setDrawer] = useState(false)
  const [editing, setEditing] = useState(null)      // row being edited
  const [viewing, setViewing] = useState(null)      // detail slide-over
  const [deleting, setDeleting] = useState(null)
  const [form, setForm] = useState(EMPTY)

  const { data: page, isLoading } = useQuery({
    queryKey: ['transport', 'consignments', search, pageNo],
    queryFn: () => transportConsignmentApi.list({ search: search || undefined, page: pageNo, per_page: 25 }),
    placeholderData: (prev) => prev,
  })
  const rows = page?.data ?? []

  // Orders to choose from. The API takes order_id, so the picker needs the list;
  // 200 is the server's own clamp, so this asks for what it will actually give.
  const { data: orderPage } = useQuery({
    queryKey: ['transport', 'orders', 'for-consignment'],
    queryFn: () => transportOrderApi.list({ per_page: 200 }),
  })
  const orders = orderPage?.data ?? []

  const invalidate = () => qc.invalidateQueries({ queryKey: ['transport', 'consignments'] })
  const sf = (k, v) => setForm((p) => ({ ...p, [k]: v }))

  const create = useMutation({
    mutationFn: () => transportConsignmentApi.create(clean(form)),
    onSuccess: (row) => {
      toast.success(`Consignment ${row?.consignment_number ?? ''} created`)
      setDrawer(false); setForm(EMPTY); invalidate()
    },
    onError: (e) => toast.error(e?.message || 'The consignment could not be created.'),
  })

  const update = useMutation({
    // order_id is deliberately not sent: a consignment cannot be re-parented,
    // because its number was allocated against that order and its customer
    // denormalised from it.
    mutationFn: () => transportConsignmentApi.update(editing.id, clean(form, { withOrder: false })),
    onSuccess: () => { toast.success('Consignment updated'); setEditing(null); setForm(EMPTY); invalidate() },
    onError: (e) => toast.error(e?.message || 'The consignment could not be updated.'),
  })

  const remove = useMutation({
    mutationFn: () => transportConsignmentApi.remove(deleting.id),
    onSuccess: () => { toast.success('Consignment deleted'); setDeleting(null); invalidate() },
    // The refusal a trip-carried consignment raises is a real answer, not a
    // failure — it is shown in full rather than replaced with "something failed".
    onError: (e) => { toast.error(e?.message || 'That consignment could not be deleted.'); setDeleting(null) },
  })

  const openEdit = (row) => {
    setForm({
      order_id: String(row.order_id ?? ''),
      customer_reference: row.customer_reference ?? '',
      cargo_description: row.cargo_description ?? '',
      service_type: row.service_type ?? '',
      special_handling: row.special_handling ?? '',
      package_count: row.package_count ?? '',
      gross_weight_kg: row.gross_weight_kg ?? '',
      volume_cbm: row.volume_cbm ?? '',
    })
    setEditing(row)
  }

  /* ── Summary. Counted from the page in hand, and labelled as such. ───── */
  const withCargo = rows.filter((r) => r.cargo_description || r.package_count || r.gross_weight_kg).length
  const onTrips = rows.filter((r) => (r.trips_count ?? 0) > 0).length
  const packages = rows.reduce((sum, r) => sum + (Number(r.package_count) || 0), 0)

  const columns = [
    {
      key: 'consignment_number', label: 'Consignment', sortable: true,
      render: (r) => (
        <div>
          <span className="font-bold" style={{ color: 'var(--text-h)' }}>{r.consignment_number}</span>
          {r.customer_reference && (
            <div className="text-xs" style={{ color: 'var(--text-muted)' }}>Ref {r.customer_reference}</div>
          )}
        </div>
      ),
    },
    {
      key: 'order', label: 'Order',
      render: (r) => (
        <div>
          <span style={{ color: 'var(--text-h)' }}>{r.order?.order_number ?? '—'}</span>
          {r.order?.order_status && (
            <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.order.order_status.replace(/_/g, ' ')}</div>
          )}
        </div>
      ),
    },
    {
      key: 'customer', label: 'Customer',
      render: (r) => <span style={{ color: 'var(--text-body)' }}>{r.customer?.company ?? '—'}</span>,
    },
    {
      key: 'cargo', label: 'Cargo',
      render: (r) => (
        <div className="text-xs" style={{ color: 'var(--text-body)' }}>
          {r.cargo_description
            ? <div className="line-clamp-1" style={{ color: 'var(--text-h)' }}>{r.cargo_description}</div>
            : <span style={{ color: 'var(--text-faint)' }}>Not described</span>}
          <div className="flex gap-2 mt-0.5" style={{ color: 'var(--text-muted)' }}>
            {r.package_count ? <span>{r.package_count} pkg</span> : null}
            {r.gross_weight_kg ? <span>{Number(r.gross_weight_kg).toLocaleString('en-IN')} kg</span> : null}
            {r.volume_cbm ? <span>{Number(r.volume_cbm).toLocaleString('en-IN')} cbm</span> : null}
          </div>
        </div>
      ),
    },
    {
      key: 'trips_count', label: 'Trips', align: 'right', sortable: true,
      render: (r) => (
        <span className="px-2 py-0.5 rounded-full text-[11px] font-bold"
          style={(r.trips_count ?? 0) > 0
            ? { background: 'rgba(16,185,129,0.14)', color: 'var(--color-success-500)' }
            : { background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
          {r.trips_count ?? 0}
        </span>
      ),
    },
    {
      key: 'actions', label: '',
      render: (r) => (
        <div className="flex items-center gap-1.5 justify-end">
          <button title="View details" onClick={() => setViewing(r)}
            className="p-1.5 rounded-lg hover:opacity-80 transition-opacity"
            style={{ color: 'var(--color-info-500)' }}>
            <Eye size={14} />
          </button>
          <button title="Edit consignment" onClick={() => openEdit(r)}
            className="px-3 py-1.5 rounded-lg text-xs font-bold"
            style={{ background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
            Edit
          </button>
          <button title="Delete consignment" onClick={() => setDeleting(r)}
            className="p-1.5 rounded-lg hover:opacity-80"
            style={{ color: 'var(--color-danger-500)' }}>
            <Trash2 size={13} />
          </button>
        </div>
      ),
    },
  ]

  return (
    <div className="space-y-5 animate-fade-in">
      {/* Header — same shape as Bills.jsx */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center"
            style={{ background: 'rgba(124,58,237,0.12)' }}>
            <Boxes size={18} style={{ color: 'var(--accent)' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Consignments</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              What is being shipped, against which order. A consignment may carry one container, several, or loose cargo.
            </p>
          </div>
        </div>
        <button onClick={() => { setForm(EMPTY); setDrawer(true) }} className="btn-3d flex items-center gap-2">
          <Plus size={15} /> New Consignment
        </button>
      </div>

      {/* Summary. Labelled "on this page" because that is what it counts —
          a total implying the whole workspace would be wrong past page one. */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {[
          { label: 'On this page', value: rows.length, color: 'var(--text-h)', icon: Layers },
          { label: 'Cargo described', value: withCargo, color: 'var(--color-info-500)', icon: Package },
          { label: 'On a trip', value: onTrips, color: 'var(--color-success-500)', icon: Boxes },
          { label: 'Packages', value: packages.toLocaleString('en-IN'), color: 'var(--text-h)', icon: Weight },
        ].map(({ label, value, color }) => (
          <div key={label} className="kpi-3d">
            <p className="text-[10px] uppercase font-bold" style={{ color: 'var(--text-muted)' }}>{label}</p>
            <p className="text-2xl font-black mt-1" style={{ color }}>{value}</p>
          </div>
        ))}
      </div>

      {/* Search. No status chips — see the file docblock and D-44. */}
      <div className="flex gap-2 flex-wrap items-center">
        {/* Wrapped rather than given a className: Input spreads props AFTER its
            own className, so passing one would strip the input-3d styling. */}
        <div className="w-full max-w-md">
          <Input value={search} onChange={(e) => setSearch(e.target.value)}
            placeholder="Search consignment no., customer ref, cargo or service type…" />
        </div>
        {search && (
          <button onClick={() => setSearch('')} className="px-3 py-1.5 rounded-xl text-xs font-bold"
            style={{ background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
            Clear
          </button>
        )}
      </div>

      {isLoading ? (
        <Loader2 className="animate-spin mx-auto my-10" style={{ color: 'var(--text-muted)' }} />
      ) : (
        <>
          <DataTable columns={columns} rows={rows} keyField="id"
            emptyState={
              <div className="text-center py-10">
                <Boxes size={26} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>No consignments yet</p>
                <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                  {search ? 'Nothing matches that search.' : 'Create one against an approved transport order.'}
                </p>
              </div>
            } />
          <PagerBar meta={page} onPage={setPageNo} unit="consignments" className="mt-3" />
        </>
      )}

      {/* ── Create ───────────────────────────────────────── */}
      <Drawer open={drawer} onClose={() => setDrawer(false)} title="New Consignment"
        footer={
          <button onClick={() => create.mutate()} disabled={create.isPending || !form.order_id} className="btn-3d w-full">
            {create.isPending ? 'Creating…' : 'Create Consignment'}
          </button>
        }>
        <ConsignmentForm form={form} sf={sf} orders={orders} />
      </Drawer>

      {/* ── Edit ─────────────────────────────────────────── */}
      <Drawer open={!!editing} onClose={() => setEditing(null)}
        title={`Edit ${editing?.consignment_number ?? 'Consignment'}`}
        footer={
          <button onClick={() => update.mutate()} disabled={update.isPending} className="btn-3d w-full">
            {update.isPending ? 'Saving…' : 'Save Changes'}
          </button>
        }>
        <ConsignmentForm form={form} sf={sf} orders={orders} lockOrder />
      </Drawer>

      {/* ── Detail ───────────────────────────────────────── */}
      <Drawer open={!!viewing} onClose={() => setViewing(null)} title={viewing?.consignment_number ?? ''}>
        {viewing && <ConsignmentDetail id={viewing.id} />}
      </Drawer>

      {/* ConfirmDialog renders unconditionally — it has no `open` prop — so the
          caller gates it. Mounting it always would put a modal over the list. */}
      {deleting && (
        <ConfirmDialog
          title="Delete this consignment?"
          message={
            (deleting.trips_count ?? 0) > 0
              ? `${deleting.consignment_number} is carried by ${deleting.trips_count} trip(s). The server will refuse this — a trip's history must not point at nothing.`
              : `${deleting.consignment_number} will be removed. Its number stays out of circulation and is never reissued.`
          }
          confirmLabel={remove.isPending ? 'Deleting…' : 'Delete'}
          tone="danger"
          onConfirm={() => remove.mutate()}
          onCancel={() => setDeleting(null)}
        />
      )}
    </div>
  )
}

/* ══════════ pieces ══════════ */

const EMPTY = {
  order_id: '', customer_reference: '', cargo_description: '',
  service_type: '', special_handling: '',
  package_count: '', gross_weight_kg: '', volume_cbm: '',
}

/**
 * Strip empty strings so a blank optional field is omitted rather than sent as
 * "", which the numeric rules would reject.
 */
function clean(form, { withOrder = true } = {}) {
  const out = {}
  for (const [k, v] of Object.entries(form)) {
    if (k === 'order_id' && !withOrder) continue
    if (v === '' || v === null || v === undefined) continue
    out[k] = ['package_count', 'gross_weight_kg', 'volume_cbm'].includes(k) ? Number(v) : v
  }
  return out
}

function ConsignmentForm({ form, sf, orders, lockOrder = false }) {
  return (
    <div className="space-y-4">
      <FormField label="Transport Order" required
        hint={lockOrder
          ? 'A consignment cannot be moved to another order — its number was issued against this one.'
          : 'The order this shipment belongs to.'}>
        <Select value={form.order_id} onChange={(e) => sf('order_id', e.target.value)} disabled={lockOrder}>
          <option value="">Choose an order…</option>
          {orders.map((o) => (
            <option key={o.id} value={o.id}>
              {o.order_number} — {o.customer?.company ?? 'Customer'} ({String(o.order_status ?? '').replace(/_/g, ' ')})
            </option>
          ))}
        </Select>
      </FormField>

      <FormField label="Customer Reference" hint="The customer's own PO or booking number. Searchable.">
        <Input value={form.customer_reference} onChange={(e) => sf('customer_reference', e.target.value)}
          placeholder="e.g. PO-8891" />
      </FormField>

      <FormField label="Cargo" hint="What is being moved. Required only in the sense that a consignment describing nothing is not much use.">
        <Textarea rows={3} value={form.cargo_description} onChange={(e) => sf('cargo_description', e.target.value)}
          placeholder="e.g. 48 drums, palletised, non-hazardous" />
      </FormField>

      <FormField label="Service Type">
        <Input value={form.service_type} onChange={(e) => sf('service_type', e.target.value)}
          placeholder="e.g. Container Haulage" />
      </FormField>

      <div className="grid grid-cols-3 gap-3">
        <FormField label="Packages">
          <Input type="number" min="1" value={form.package_count}
            onChange={(e) => sf('package_count', e.target.value)} placeholder="48" />
        </FormField>
        <FormField label="Weight (kg)">
          <Input type="number" min="0" step="0.001" value={form.gross_weight_kg}
            onChange={(e) => sf('gross_weight_kg', e.target.value)} placeholder="12450.5" />
        </FormField>
        <FormField label="Volume (cbm)">
          <Input type="number" min="0" step="0.001" value={form.volume_cbm}
            onChange={(e) => sf('volume_cbm', e.target.value)} placeholder="28.125" />
        </FormField>
      </div>

      <FormField label="Special Handling">
        <Textarea rows={2} value={form.special_handling} onChange={(e) => sf('special_handling', e.target.value)}
          placeholder="Anything the crew must know" />
      </FormField>
    </div>
  )
}

/**
 * The detail slide-over. Fetches on open rather than reusing the list row,
 * because the list does not carry the trips or the audit trail.
 */
function ConsignmentDetail({ id }) {
  const { data, isLoading } = useQuery({
    queryKey: ['transport', 'consignments', id],
    queryFn: () => transportConsignmentApi.get(id),
  })

  if (isLoading) {
    return <Loader2 className="animate-spin mx-auto my-10" style={{ color: 'var(--text-muted)' }} />
  }

  const c = data?.consignment
  if (!c) return <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Not found.</p>

  const trips = data?.trips ?? []
  const audit = data?.audit ?? []

  return (
    <div className="space-y-5">
      <Section title="Shipment">
        <KV label="Consignment" value={c.consignment_number} strong />
        <KV label="Customer reference" value={c.customer_reference} />
        <KV label="Service type" value={c.service_type} />
        <KV label="Created" value={fmtDateTime(c.created_at)} />
      </Section>

      <Section title="Commercial">
        <KV label="Order" value={c.order?.order_number} strong />
        <KV label="Order status" value={String(c.order?.order_status ?? '').replace(/_/g, ' ')} />
        <KV label="Customer" value={c.customer?.company} />
      </Section>

      <Section title="Cargo">
        <KV label="Description" value={c.cargo_description} />
        <KV label="Packages" value={c.package_count} />
        <KV label="Weight" value={c.gross_weight_kg ? `${Number(c.gross_weight_kg).toLocaleString('en-IN')} kg` : null} />
        <KV label="Volume" value={c.volume_cbm ? `${Number(c.volume_cbm).toLocaleString('en-IN')} cbm` : null} />
        <KV label="Special handling" value={c.special_handling} />
      </Section>

      <Section title={`Trips (${trips.length})`}>
        {trips.length === 0
          ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Not yet carried by any trip.</p>
          : trips.map((t) => (
            <div key={t.id} className="flex items-center justify-between py-1.5 border-b last:border-0"
              style={{ borderColor: 'var(--border)' }}>
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{t.trip_number}</span>
              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{String(t.status ?? '').replace(/_/g, ' ')}</span>
            </div>
          ))}
      </Section>

      {/* No container panel yet: transport_containers is the next step of this
          block. An empty "Containers" section would imply the feature exists. */}

      <Section title="History">
        {audit.length === 0
          ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Nothing recorded yet.</p>
          : audit.map((e) => (
            <div key={e.id} className="py-1.5 border-b last:border-0" style={{ borderColor: 'var(--border)' }}>
              <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>
                {String(e.action ?? '').replace('transport.consignment.', '').replace(/_/g, ' ')}
              </p>
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                {e.actor_name || 'System'} · {fmtDateTime(e.occurred_at)}
              </p>
            </div>
          ))}
      </Section>
    </div>
  )
}

function Section({ title, children }) {
  return (
    <div>
      <p className="text-[10px] uppercase font-bold mb-2" style={{ color: 'var(--text-muted)', letterSpacing: '.05em' }}>
        {title}
      </p>
      <div className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        {children}
      </div>
    </div>
  )
}

function KV({ label, value, strong = false }) {
  return (
    <div className="flex items-baseline justify-between gap-3 py-1">
      <span className="text-xs shrink-0" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span className={`text-sm text-right ${strong ? 'font-bold' : ''}`}
        style={{ color: value ? 'var(--text-h)' : 'var(--text-faint)' }}>
        {value || '—'}
      </span>
    </div>
  )
}
