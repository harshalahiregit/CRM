import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Plus, Container, Loader2, Link2, Unlink, Eye, History, Boxes, CheckCircle2,
} from 'lucide-react'
import { transportContainerApi, transportConsignmentApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import DataTable from '@/components/ui/DataTable'
import PagerBar from '@/components/ui/PagerBar'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { fmtDateTime } from '../constants'

/**
 * Containers — the physical transport unit (STOS-CTD §8).
 *
 * Requirements on screen: `MDM-008` (the master), `CTD-001` (find a container by
 * its number, however typed), `CTD-003` (which shipment it is on), and §7's
 * "allowed historically but not simultaneously".
 *
 * ── THE TRANSPORT DIALECT ────────────────────────────────────────────────
 * Mirrors TransportConsignments.jsx, which mirrors accounts/pages/Bills.jsx:
 * Tailwind for layout, var(--…) tokens for every colour, zero raw hex, and the
 * shared kit rather than a hand-rolled table.
 *
 * ── THERE IS NO EDIT AND NO DELETE, AND THAT IS DELIBERATE ───────────────
 * The API offers neither, so this page offers neither. The container number is
 * the identity: editing it would silently rewrite the association history §7
 * requires be kept, and deleting the container would destroy that history with
 * it. A container leaves a consignment by being DETACHED, which keeps the row.
 * Buttons for operations the server refuses would be a lie with a spinner.
 *
 * ── AND NO STATUS FILTER ─────────────────────────────────────────────────
 * A container has no status of its own — §8 puts the commercial facts on the
 * consignment. The API refuses `?status=` outright rather than accepting and
 * ignoring it, so there are no status chips here either. What IS offered is the
 * one thing the data can answer honestly: whether it is on a consignment now.
 */

const EMPTY = { container_number: '', container_type: '' }

export default function TransportContainers() {
  const toast = useToast()
  const qc = useQueryClient()

  const [search, setSearchRaw] = useState('')
  const [attached, setAttachedRaw] = useState('')     // '' | '1' | '0'
  const [pageNo, setPageNo] = useState(1)
  // Narrowing the list must return to page 1, or you sit past the new end.
  const setSearch = (v) => { setSearchRaw(v); setPageNo(1) }
  const setAttached = (v) => { setAttachedRaw(v); setPageNo(1) }

  const [drawer, setDrawer] = useState(false)
  const [viewing, setViewing] = useState(null)
  const [attaching, setAttaching] = useState(null)    // container being attached
  const [detaching, setDetaching] = useState(null)
  const [form, setForm] = useState(EMPTY)
  const [pickedConsignment, setPickedConsignment] = useState('')

  const { data: page, isLoading } = useQuery({
    queryKey: ['transport', 'containers', search, attached, pageNo],
    queryFn: () => transportContainerApi.list({
      search: search || undefined,
      // Only 1/0 are accepted — '' must not be sent, or the server reads it as
      // a filter and refuses it.
      attached: attached === '' ? undefined : attached,
      page: pageNo, per_page: 25,
    }),
    placeholderData: (prev) => prev,
  })
  const rows = page?.data ?? []

  // Consignments to attach to. 200 is the server's own clamp, so this asks for
  // what it will actually give.
  const { data: consignmentPage } = useQuery({
    queryKey: ['transport', 'consignments', 'for-container'],
    queryFn: () => transportConsignmentApi.list({ per_page: 200 }),
  })
  const consignments = consignmentPage?.data ?? []

  const invalidate = () => qc.invalidateQueries({ queryKey: ['transport', 'containers'] })
  const sf = (k, v) => setForm((p) => ({ ...p, [k]: v }))

  const create = useMutation({
    mutationFn: () => transportContainerApi.create({
      container_number: form.container_number.trim(),
      container_type: form.container_type.trim() || undefined,
    }),
    onSuccess: (row) => {
      toast.success(`Container ${row?.container_number ?? ''} added`)
      setDrawer(false); setForm(EMPTY); invalidate()
    },
    // The duplicate refusal explains WHY two different-looking strings clash.
    // It is a real answer, so it is shown in full rather than replaced.
    onError: (e) => toast.error(e?.message || 'The container could not be added.'),
  })

  const attach = useMutation({
    mutationFn: () => transportContainerApi.attach(attaching.id, Number(pickedConsignment)),
    onSuccess: () => {
      toast.success(`${attaching.container_number} attached`)
      setAttaching(null); setPickedConsignment(''); invalidate()
    },
    onError: (e) => toast.error(e?.message || 'That container could not be attached.'),
  })

  const detach = useMutation({
    mutationFn: () => transportContainerApi.detach(detaching.id),
    onSuccess: () => { toast.success(`${detaching.container_number} detached`); setDetaching(null); invalidate() },
    onError: (e) => { toast.error(e?.message || 'That container could not be detached.'); setDetaching(null) },
  })

  /* ── Summary. Counted from the page in hand, and labelled as such. ───── */
  const onConsignment = rows.filter((r) => (r.active_attachments_count ?? 0) > 0).length
  const everUsed = rows.filter((r) => (r.attachments_count ?? 0) > 0).length

  const columns = [
    {
      key: 'container_number', label: 'Container', sortable: true,
      render: (r) => (
        <div>
          <span className="font-bold" style={{ color: 'var(--text-h)' }}>{r.container_number}</span>
          {/* The normalised key is shown only when it differs from what was
              typed — otherwise it is the same string twice. */}
          {r.container_number_normalized && r.container_number_normalized !== r.container_number && (
            <div className="text-[11px]" style={{ color: 'var(--text-faint)' }}>
              matched as {r.container_number_normalized}
            </div>
          )}
        </div>
      ),
    },
    {
      key: 'container_type', label: 'Type',
      render: (r) => (
        <span style={{ color: r.container_type ? 'var(--text-body)' : 'var(--text-faint)' }}>
          {r.container_type || 'Not recorded'}
        </span>
      ),
    },
    {
      key: 'where', label: 'Where it is now',
      render: (r) => {
        const on = (r.active_attachments_count ?? 0) > 0
        return on ? (
          <span className="inline-flex items-center gap-1.5 text-xs font-bold"
            style={{ color: 'var(--color-success-500)' }}>
            <CheckCircle2 size={13} /> On a consignment
          </span>
        ) : (
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Free</span>
        )
      },
    },
    {
      key: 'attachments_count', label: 'Times used', align: 'right', sortable: true,
      render: (r) => (
        <span className="px-2 py-0.5 rounded-full text-[11px] font-bold"
          style={(r.attachments_count ?? 0) > 0
            ? { background: 'var(--bg-input)', color: 'var(--text-body)' }
            : { background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
          {r.attachments_count ?? 0}
        </span>
      ),
    },
    {
      key: 'actions', label: '',
      render: (r) => {
        const on = (r.active_attachments_count ?? 0) > 0
        return (
          <div className="flex items-center gap-1.5 justify-end">
            <button title="History" onClick={() => setViewing(r)}
              className="p-1.5 rounded-lg hover:opacity-80 transition-opacity"
              style={{ color: 'var(--color-info-500)' }}>
              <Eye size={14} />
            </button>
            {on ? (
              <button onClick={() => setDetaching(r)}
                className="px-3 py-1.5 rounded-lg text-xs font-bold inline-flex items-center gap-1.5"
                style={{ background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
                <Unlink size={12} /> Detach
              </button>
            ) : (
              <button onClick={() => { setPickedConsignment(''); setAttaching(r) }}
                className="px-3 py-1.5 rounded-lg text-xs font-bold inline-flex items-center gap-1.5"
                style={{ background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
                <Link2 size={12} /> Attach
              </button>
            )}
          </div>
        )
      },
    },
  ]

  return (
    <div className="space-y-5 animate-fade-in">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center"
            style={{ background: 'rgba(124,58,237,0.12)' }}>
            <Container size={18} style={{ color: 'var(--accent)' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Containers</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              The physical box. A container can be on one consignment at a time, and is reused afterwards.
            </p>
          </div>
        </div>
        <button onClick={() => { setForm(EMPTY); setDrawer(true) }} className="btn-3d flex items-center gap-2">
          <Plus size={15} /> Add Container
        </button>
      </div>

      {/* Summary. Labelled "on this page" because that is what it counts — a
          total implying the whole workspace would be wrong past page one. */}
      <div className="grid grid-cols-3 gap-3">
        {[
          { label: 'On this page', value: rows.length, color: 'var(--text-h)' },
          { label: 'On a consignment', value: onConsignment, color: 'var(--color-success-500)' },
          { label: 'Used at least once', value: everUsed, color: 'var(--color-info-500)' },
        ].map(({ label, value, color }) => (
          <div key={label} className="kpi-3d">
            <p className="text-[10px] uppercase font-bold" style={{ color: 'var(--text-muted)' }}>{label}</p>
            <p className="text-2xl font-black mt-1" style={{ color }}>{value}</p>
          </div>
        ))}
      </div>

      <div className="flex gap-2 flex-wrap items-center">
        {/* Wrapped rather than given a className: Input spreads props AFTER its
            own className, so passing one would strip the input-3d styling. */}
        <div className="w-full max-w-md">
          <Input value={search} onChange={(e) => setSearch(e.target.value)}
            placeholder="Search container number — spacing and case do not matter…" />
        </div>
        {/* The one filter the data can answer. No status chips — see docblock. */}
        {[
          { v: '', label: 'All' },
          { v: '1', label: 'On a consignment' },
          { v: '0', label: 'Free' },
        ].map(({ v, label }) => (
          <button key={label} onClick={() => setAttached(v)}
            className="px-3 py-1.5 rounded-xl text-xs font-bold"
            // Accent-TINTED rather than accent-filled: the filled version needs a
            // readable foreground, and this project has no token for text on the
            // accent — the pages that do it fall back to a raw #fff, which is
            // the one thing Transport may not use.
            style={attached === v
              ? { background: 'rgba(124,58,237,0.16)', color: 'var(--accent)', border: '1px solid var(--accent)' }
              : { background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
            {label}
          </button>
        ))}
      </div>

      {isLoading ? (
        <Loader2 className="animate-spin mx-auto my-10" style={{ color: 'var(--text-muted)' }} />
      ) : (
        <>
          <DataTable columns={columns} rows={rows} keyField="id"
            emptyState={
              <div className="text-center py-10">
                <Container size={26} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>No containers yet</p>
                <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                  {search || attached
                    ? 'Nothing matches that filter.'
                    : 'Add a container, then attach it to a consignment.'}
                </p>
              </div>
            } />
          <PagerBar meta={page} onPage={setPageNo} unit="containers" className="mt-3" />
        </>
      )}

      {/* ── Add ──────────────────────────────────────────── */}
      <Drawer open={drawer} onClose={() => setDrawer(false)} title="Add Container"
        footer={
          <button onClick={() => create.mutate()}
            disabled={create.isPending || !form.container_number.trim()} className="btn-3d w-full">
            {create.isPending ? 'Adding…' : 'Add Container'}
          </button>
        }>
        <div className="space-y-4">
          <FormField label="Container number" required
            hint="Stored exactly as you type it. Spacing, case and dashes are ignored when matching, so ABCD1234567 and abcd-123456-7 are the same container.">
            <Input value={form.container_number} onChange={(e) => sf('container_number', e.target.value)}
              placeholder="ABCD1234567" />
          </FormField>
          <FormField label="Type"
            hint="Free text — no document in the specification defines a list of container types, so none is invented here.">
            <Input value={form.container_type} onChange={(e) => sf('container_type', e.target.value)}
              placeholder="40ft High Cube" />
          </FormField>
        </div>
      </Drawer>

      {/* ── Attach ───────────────────────────────────────── */}
      <Drawer open={!!attaching} onClose={() => setAttaching(null)}
        title={`Attach ${attaching?.container_number ?? ''}`}
        footer={
          <button onClick={() => attach.mutate()} disabled={attach.isPending || !pickedConsignment}
            className="btn-3d w-full">
            {attach.isPending ? 'Attaching…' : 'Attach to Consignment'}
          </button>
        }>
        <div className="space-y-4">
          <FormField label="Consignment" required
            hint="A container can only be on one consignment at a time. Detach it before moving it to another.">
            <Select value={pickedConsignment} onChange={(e) => setPickedConsignment(e.target.value)}>
              <option value="">Choose a consignment…</option>
              {consignments.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.consignment_number}
                  {c.customer_reference ? ` · ${c.customer_reference}` : ''}
                  {c.cargo_description ? ` · ${c.cargo_description.slice(0, 40)}` : ''}
                </option>
              ))}
            </Select>
          </FormField>
          {consignments.length === 0 && (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              There are no consignments yet. Create one first — a container is always attached to a shipment,
              never to a trip directly.
            </p>
          )}
        </div>
      </Drawer>

      {/* ── History ──────────────────────────────────────── */}
      <Drawer open={!!viewing} onClose={() => setViewing(null)} title={viewing?.container_number ?? ''}>
        {viewing && <ContainerHistory id={viewing.id} />}
      </Drawer>

      {/* ConfirmDialog renders unconditionally — it has no `open` prop — so the
          caller gates it. Mounting it always would put a modal over the list. */}
      {detaching && (
        <ConfirmDialog
          title="Detach this container?"
          message={`${detaching.container_number} comes off its consignment and becomes free to use again. `
            + 'The record of where it has been is kept — nothing is deleted.'}
          confirmLabel={detach.isPending ? 'Detaching…' : 'Detach'}
          onConfirm={() => detach.mutate()}
          onCancel={() => setDetaching(null)}
        />
      )}
    </div>
  )
}

/**
 * STOS-CTD §7 — "maintain historical associations".
 *
 * Every consignment this container has been on, newest first, with the current
 * one marked. This is the whole reason detach does not delete the row.
 */
function ContainerHistory({ id }) {
  const { data, isLoading } = useQuery({
    queryKey: ['transport', 'containers', 'detail', id],
    queryFn: () => transportContainerApi.get(id),
  })

  if (isLoading) {
    return <Loader2 className="animate-spin mx-auto my-8" style={{ color: 'var(--text-muted)' }} />
  }

  const container = data?.container
  const history = data?.history ?? []

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3">
        <Fact label="Entered as" value={container?.container_number} />
        <Fact label="Matched as" value={container?.container_number_normalized} />
        <Fact label="Type" value={container?.container_type} />
        <Fact label="Times used" value={String(history.length)} />
      </div>

      <div>
        <p className="text-[10px] uppercase font-bold mb-2 flex items-center gap-1.5"
          style={{ color: 'var(--text-muted)' }}>
          <History size={12} /> Where it has been
        </p>

        {history.length === 0 ? (
          <div className="text-center py-8 rounded-xl"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <Boxes size={22} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              This container has not been on a consignment yet.
            </p>
          </div>
        ) : (
          <div className="space-y-2">
            {history.map((h) => {
              const current = !h.detached_at
              return (
                <div key={h.id} className="p-3 rounded-xl"
                  style={{
                    background: 'var(--bg-input)',
                    border: `1px solid ${current ? 'var(--color-success-500)' : 'var(--border)'}`,
                  }}>
                  <div className="flex items-center justify-between gap-2 flex-wrap">
                    <span className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>
                      {h.consignment?.consignment_number ?? `Consignment #${h.consignment_id}`}
                    </span>
                    {current && (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full"
                        style={{ background: 'rgba(16,185,129,0.14)', color: 'var(--color-success-500)' }}>
                        ON IT NOW
                      </span>
                    )}
                  </div>
                  <div className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                    Attached {fmtDateTime(h.attached_at)}
                    {h.detached_at ? ` · detached ${fmtDateTime(h.detached_at)}` : ''}
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </div>
    </div>
  )
}

function Fact({ label, value }) {
  return (
    <div>
      <p className="text-[10px] uppercase font-bold" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-sm font-bold mt-0.5"
        style={{ color: value ? 'var(--text-h)' : 'var(--text-faint)' }}>
        {value || 'Not recorded'}
      </p>
    </div>
  )
}
