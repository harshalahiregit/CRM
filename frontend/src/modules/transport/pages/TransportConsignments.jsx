import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { Plus, Boxes, Loader2, Package, Trash2, Eye, Weight, Layers } from 'lucide-react'
import { transportConsignmentApi, transportOrderApi, transportContainerApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import DataTable from '@/components/ui/DataTable'
import PagerBar from '@/components/ui/PagerBar'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { fmtDateTime, ORDER_STATUS_LABEL, TRIP_STATUS_LABEL } from '../constants'

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

  /*
   * DEEP LINK — /app/transport/consignments?open=<id>
   *
   * A consignment has no detail ROUTE: its detail is this drawer. The trip page
   * still needs to link to one, so it links here and names the id. Adding a
   * consignments/:id route for that one button would give consignments two
   * different detail experiences depending on how you arrived.
   *
   * The parameter is consumed and cleared, so a refresh or a back-navigation
   * does not reopen a drawer the user has deliberately closed.
   */
  const [params, setParams] = useSearchParams()
  const openId = params.get('open')

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

  useEffect(() => {
    if (!openId) return

    // CLEARED FIRST, DELIBERATELY, AND THIS IS LOAD-BEARING.
    //
    // It does two jobs at once: a refresh or a back-navigation will not reopen
    // a drawer the user has closed, AND the parameter is the guard — once it is
    // gone `openId` is null and this effect cannot run again.
    //
    // The obvious version of this — a ref that remembers the id, plus a
    // `cancelled` flag in the cleanup — is WRONG HERE, and silently so.
    // main.jsx renders under StrictMode, so in development React mounts,
    // unmounts and remounts: the first effect's cleanup sets `cancelled`, the
    // in-flight response is discarded, and the remount is refused by the ref.
    // The request is made, nothing happens, and nothing errors. That was the
    // first version of this fix, and the network panel is what found it.
    setParams({}, { replace: true })

    transportConsignmentApi.get(openId)
      // Open on the real record, so the drawer title is the consignment number
      // rather than a blank header over a spinner.
      .then((d) => {
        if (d?.consignment) setViewing(d.consignment)
        else toast.error('That consignment could not be found.')
      })
      // A missing id, another workspace's id, or one the user may not see all
      // arrive here. Land on the list with its own honest empty state rather
      // than a drawer onto nothing.
      .catch((e) => toast.error(e?.message || 'That consignment could not be opened.'))

    // `openId` alone: `toast` is a new object every render (Toast.jsx builds its
    // context value as a plain literal) and `setParams` is not guaranteed
    // stable either, so listing them would re-run this on unrelated renders.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openId])

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
          <span style={{ color: 'var(--text-h)' }}>{r.order?.order_number ?? 'No order'}</span>
          {/* The label, not the stored code. Swapping an underscore for a space
              still leaves the database's word on a client's screen. */}
          {r.order?.order_status && (
            <div className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {ORDER_STATUS_LABEL[r.order.order_status] || r.order.order_status}
            </div>
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
                {/* See the note on the Orders list — "No consignments yet" was
                    shown to workspaces that had plenty, because a search matched
                    none of them. */}
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
                  {search ? `Nothing here matches “${search}”` : 'No consignments yet'}
                </p>
                <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                  {search
                    ? 'Try the consignment number, the customer reference, or the order.'
                    : 'Create one against an approved transport order.'}
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
              {o.order_number} — {o.customer?.company ?? 'Customer'} ({ORDER_STATUS_LABEL[o.order_status] || o.order_status})
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
        <KV label="Customer reference" value={c.customer_reference} empty="None given" />
        <KV label="Service type" value={c.service_type} />
        <KV label="Created" value={fmtDateTime(c.created_at)} />
      </Section>

      <Section title="Commercial">
        <KV label="Order" value={c.order?.order_number} strong />
        <KV label="Order status" value={ORDER_STATUS_LABEL[c.order?.order_status] || c.order?.order_status} />
        <KV label="Customer" value={c.customer?.company} />
      </Section>

      <Section title="Cargo">
        <KV label="Description" value={c.cargo_description} empty="Not described" />
        <KV label="Packages" value={c.package_count} />
        <KV label="Weight" value={c.gross_weight_kg ? `${Number(c.gross_weight_kg).toLocaleString('en-IN')} kg` : null} />
        <KV label="Volume" value={c.volume_cbm ? `${Number(c.volume_cbm).toLocaleString('en-IN')} cbm` : null} />
        <KV label="Special handling" value={c.special_handling} empty="Nothing special" />
      </Section>

      <Section title={`Trips (${trips.length})`}>
        {trips.length === 0
          ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Not yet carried by any trip.</p>
          : trips.map((t) => (
            <div key={t.id} className="flex items-center justify-between py-1.5 border-b last:border-0"
              style={{ borderColor: 'var(--border)' }}>
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{t.trip_number}</span>
              {/* The label, not the stored code. `pod_verified` with its
                  underscore swapped for a space is still the database's word,
                  not the customer's. */}
              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                {TRIP_STATUS_LABEL[t.status] || t.status}
              </span>
            </div>
          ))}
      </Section>

      {/* STOS-CTD §8 — "a consignment may contain one container; contain
          multiple containers". Read from the consignment side, which is the
          only place that clause is visible: the Containers screen shows the
          relationship the other way round. */}
      <ConsignmentContainers id={id} />

      <Section title="History">
        {audit.length === 0
          ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Nothing recorded yet.</p>
          : audit.map((e) => (
            <div key={e.id} className="py-1.5 border-b last:border-0" style={{ borderColor: 'var(--border)' }}>
              {/* It read "created" and "updated" — the action key with its
                  prefix stripped. The order detail page has always rendered
                  these as sentences ("Trip created from this order", "Status
                  changed — Submitted → Approved"); this one was left behind. */}
              <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>
                {CONSIGNMENT_EVENT[e.action] || humaniseAction(e.action)}
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

/**
 * The containers on this consignment — STOS-CTD §8, current and historical.
 *
 * `detached_at` is what separates "on it now" from "was on it", so both are
 * shown rather than filtering the history away: a consignment that has had a
 * container swapped is a real situation, and hiding the swap would make the
 * remaining row look like the only one there has ever been.
 */
function ConsignmentContainers({ id }) {
  const { data: rows, isLoading } = useQuery({
    queryKey: ['transport', 'consignments', id, 'containers'],
    queryFn: () => transportContainerApi.forConsignment(id),
  })

  const list = rows ?? []
  const current = list.filter((r) => !r.detached_at)

  return (
    /* The count used to be `current.length` while the list below rendered
       BOTH current and historical attachments — a header reading "(1)" above
       two rows. It now counts what it is actually the header of, and names the
       split when there is one. */
    <Section title={
      current.length === list.length
        ? `Containers (${current.length})`
        : `Containers (${current.length} on it now, ${list.length - current.length} before)`
    }>
      {isLoading ? (
        <Loader2 className="animate-spin my-2" style={{ color: 'var(--text-muted)' }} />
      ) : list.length === 0 ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          No container on this consignment. Loose cargo does not need one.
        </p>
      ) : (
        list.map((r) => (
          <div key={r.id} className="flex items-center justify-between py-1.5 border-b last:border-0"
            style={{ borderColor: 'var(--border)' }}>
            <div>
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
                {r.container?.container_number ?? `#${r.container_id}`}
              </span>
              {r.container?.container_type && (
                <span className="text-xs ml-2" style={{ color: 'var(--text-muted)' }}>
                  {r.container.container_type}
                </span>
              )}
            </div>
            <span className="text-[11px] font-bold"
              style={{ color: r.detached_at ? 'var(--text-muted)' : 'var(--color-success-500)' }}>
              {r.detached_at ? `until ${fmtDateTime(r.detached_at)}` : 'On it now'}
            </span>
          </div>
        ))
      )}
    </Section>
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

/**
 * A labelled fact.
 *
 * An absent value says WHAT IS ABSENT, in words. It used to render "—", which
 * on the cargo card gave a client two rows of punctuation and no idea whether
 * the volume was unknown, zero, or not applicable. `empty` lets each field say
 * the true thing; "Not recorded" is the safe default because it is the one
 * claim always available — we do not have it.
 */
/**
 * What happened to a consignment, in a sentence.
 *
 * An action key with its prefix stripped is still the key — "created" is not a
 * sentence and "container_attached" is a column name. Anything unmapped is
 * humanised rather than hidden, so a new action reads as English on the day it
 * ships instead of waiting for somebody to notice.
 */
const CONSIGNMENT_EVENT = {
  'transport.consignment.created': 'Consignment created',
  'transport.consignment.updated': 'Details changed',
  'transport.consignment.deleted': 'Consignment removed',
  'transport.container.attached': 'Container attached',
  'transport.container.detached': 'Container detached',
}

const humaniseAction = (a) => {
  const tail = String(a ?? '').replace(/^transport\.[a-z_]+\./, '').replace(/[._]/g, ' ')

  return tail ? tail.charAt(0).toUpperCase() + tail.slice(1) : 'Something happened'
}

function KV({ label, value, strong = false, empty = 'Not recorded' }) {
  const shown = value === 0 || value ? value : empty

  return (
    <div className="flex items-baseline justify-between gap-3 py-1">
      <span className="text-xs shrink-0" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span className={`text-sm text-right ${strong ? 'font-bold' : ''}`}
        style={{ color: (value === 0 || value) ? 'var(--text-h)' : 'var(--text-faint)' }}>
        {shown}
      </span>
    </div>
  )
}
