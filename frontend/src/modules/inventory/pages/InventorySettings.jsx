import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Settings2, Plus, Trash2, Pencil, Check, X, Layers, Lock, AlertTriangle, Search, Upload } from 'lucide-react'
import { inventoryApi, INV_ACCENT, SETTING_TABS } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

/**
 * Inventory Settings (blueprint §9) — every configuration surface the module has.
 *
 * Two kinds of tab share one shell:
 *  • LOOKUP tabs (Units, Types, Groups, Taxes, Colors/Models/Sizes/Styles) edit
 *    master-data lists — eight tabs over six tables, since the four variation
 *    attributes share one table keyed by `kind`.
 *  • CONFIG tabs (min/max defaults, price rule, inventory rules, approvals,
 *    custom fields, reset) edit tenant configuration instead of lists.
 *
 * Reads are open (staff need the lists); writes are admin-only, enforced server-side.
 */

/** The config-style tabs, in blueprint order after the lookup lists. */
const CONFIG_TABS = [
  { key: 'custom_fields', label: 'Custom fields' },
  { key: 'minmax',        label: 'Min/max inventory' },
  { key: 'pricing',       label: 'Price & voucher rule' },
  { key: 'inventory',     label: 'Inventory setting' },
  { key: 'approval',      label: 'Approval setting' },
  { key: 'notifications', label: 'Notifications' },
  { key: 'reset',         label: 'Reset data' },
]

/** The left-nav is split into two named groups so ~15 destinations read as a
 *  short menu instead of one overflowing tab strip. */
const NAV_GROUPS = [
  { label: 'Master data',   items: SETTING_TABS.map(t => ({ ...t, kindOf: 'lookup' })) },
  { label: 'Configuration', items: CONFIG_TABS.map(t => ({ ...t, kindOf: 'config' })) },
]

export default function InventorySettings() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [tab, setTab] = useState({ ...SETTING_TABS[0], kindOf: 'lookup' })

  return (
    <div className="max-w-5xl">
      <header className="flex flex-wrap items-center gap-2 mb-5">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Settings2 size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Inventory Settings</h1>
        {!isAdmin && (
          <span className="flex items-center gap-1 text-[11px] px-2 py-1 rounded-lg"
            style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
            <Lock size={10} /> View only — admins can change master data
          </span>
        )}
      </header>

      <div className="flex flex-col md:flex-row gap-5">
        {/* Grouped nav — a vertical menu on desktop, a horizontal scroll strip on
            phones. Replaces the single overflowing tab bar. */}
        <nav className="flex md:flex-col gap-1 md:w-52 shrink-0 overflow-x-auto md:overflow-visible pb-1 md:pb-0"
          style={{ scrollbarWidth: 'none' }}>
          {NAV_GROUPS.map(g => (
            <div key={g.label} className="flex md:block gap-1">
              <p className="hidden md:block text-[10px] font-black uppercase tracking-wider px-2.5 mt-4 mb-1.5 first:mt-0"
                style={{ color: 'var(--text-muted)' }}>{g.label}</p>
              {g.items.map(t => {
                const on = tab.key === t.key
                return (
                  <button key={t.key} onClick={() => setTab(t)}
                    className={`flex items-center text-xs font-semibold whitespace-nowrap rounded-xl transition-colors text-left md:w-full ${on ? '' : 'hover:bg-[var(--bg-input)]'}`}
                    style={{
                      padding: '8px 12px',
                      color: on ? INV_ACCENT : 'var(--text-muted)',
                      ...(on ? { background: `color-mix(in srgb, ${INV_ACCENT} 13%, transparent)` } : {}),
                    }}>
                    {t.label}
                  </button>
                )
              })}
            </div>
          ))}
        </nav>

        <div className="flex-1 min-w-0">
          {tab.kindOf === 'lookup'
            ? <LookupTab key={tab.key} tab={tab} isAdmin={isAdmin} />
            : tab.key === 'custom_fields'
              ? <CustomFieldsTab isAdmin={isAdmin} />
              : tab.key === 'reset'
                ? <ResetTab isAdmin={isAdmin} />
                : <ConfigTab key={tab.key} which={tab.key} isAdmin={isAdmin} />}
        </div>
      </div>
    </div>
  )
}

/* ── Config tabs (min/max, pricing, inventory, approval) ───────── */

/** Which settings keys each config tab owns, and how to render them. */
const CONFIG_FIELDS = {
  minmax: {
    blurb: 'Defaults applied to every new item. Existing items keep whatever they already have.',
    fields: [
      { key: 'default_min_stock',     label: 'Default minimum stock', type: 'number' },
      { key: 'default_max_stock',     label: 'Default maximum stock', type: 'number', hint: 'Blank = no ceiling' },
      { key: 'default_reorder_point', label: 'Default reorder point', type: 'number' },
      { key: 'low_stock_alerts',      label: 'Show low-stock alerts', type: 'bool' },
    ],
  },
  pricing: {
    blurb: 'How sale prices are derived, and how stock leaving the building is costed.',
    fields: [
      {
        key: 'sale_price_rule', label: 'Sale price rule', type: 'select',
        options: [
          { value: 'profit_ratio', label: 'Cost + profit %' },
          { value: 'fixed', label: 'Fixed price per item' },
          { value: 'manual', label: 'Always typed manually' },
        ],
      },
      { key: 'default_profit_ratio', label: 'Default profit %', type: 'number' },
      {
        key: 'delivery_costing_method', label: 'Delivery voucher method', type: 'select',
        options: [
          { value: 'fifo', label: 'FIFO — oldest stock first' },
          { value: 'lifo', label: 'LIFO — newest stock first' },
          { value: 'average', label: 'Weighted average cost' },
        ],
      },
    ],
  },
  inventory: {
    blurb: 'How the stock engine behaves day to day.',
    fields: [
      { key: 'allow_negative_stock',  label: 'Allow negative stock', type: 'bool', hint: 'Off means an issue that would overdraw a warehouse is refused' },
      { key: 'auto_generate_sku',     label: 'Auto-generate SKU', type: 'bool' },
      { key: 'auto_generate_barcode', label: 'Auto-generate barcode', type: 'bool' },
      { key: 'expiry_alert_days',     label: 'Expiry alert (days ahead)', type: 'number', hint: 'Drives the Items page’s "Expiring soon" alert' },
      // A yearly stocktake is the count everyone plans and nobody finishes.
      { key: 'cycle_count_auto',      label: 'Raise a cycle count every week', type: 'bool', hint: 'Monday 08:00, one random sample per warehouse. Skipped wherever the last sheet is still open' },
      { key: 'cycle_count_sample',    label: 'Places to check each week', type: 'number', hint: 'Nobody can predict which shelf gets picked, so no shelf can be kept tidy just for the audit' },
    ],
  },
  approval: {
    blurb: 'Which documents need a second pair of eyes before they move stock.',
    fields: [
      { key: 'require_approval_receipt',    label: 'Receiving voucher needs approval', type: 'bool' },
      { key: 'require_approval_delivery',   label: 'Delivery voucher needs approval', type: 'bool' },
      { key: 'require_approval_adjustment', label: 'Loss & adjustment needs approval', type: 'bool' },
      { key: 'approval_threshold_amount',   label: 'Only above this amount', type: 'number', hint: '0 = every document' },
    ],
  },
  notifications: {
    blurb: 'Who hears about stock events, and how. Alerts go to admins plus the manager of the warehouse involved — the same people who are allowed to act on them. Turning a category off silences both the bell and the email.',
    fields: [
      { key: 'notify_stock_alerts',     label: 'Low stock & out of stock', type: 'bool', hint: 'Sent the moment a posting pushes an item to or below its reorder point — once per crossing, not repeatedly' },
      { key: 'notify_voucher_activity', label: 'Voucher posted / cancelled', type: 'bool', hint: 'Also tells a warehouse manager when stock is transferred in to them' },
      { key: 'notify_expiry_alerts',    label: 'Expiring batches', type: 'bool', hint: 'Daily digest at 07:00, using the expiry window in Inventory setting' },
      { key: 'notify_count_activity',   label: 'Physical counts', type: 'bool', hint: 'A sheet assigned, finished, or signed off. A submitted count always emails — until it is signed, the ledger is knowingly wrong' },
      { key: 'notify_capacity_alerts',  label: 'Bin housekeeping', type: 'bool', hint: 'Daily digest of bins over their limit and stock sitting at a site with no bin recorded' },
      { key: 'email_alerts',            label: 'Send these by email too', type: 'bool', hint: 'Off keeps the in-app bell working while nothing leaves the building' },
      { key: 'alert_email_extra',       label: 'Also copy this address', type: 'text', placeholder: 'purchasing@company.com', hint: 'Optional — a shared inbox that should see every alert' },
    ],
  },
}

function ConfigTab({ which, isAdmin }) {
  const qc = useQueryClient()
  const spec = CONFIG_FIELDS[which]
  const { data: config, isLoading } = useQuery({ queryKey: ['inv-config'], queryFn: inventoryApi.config.get })
  const [draft, setDraft] = useState(null)
  const [saved, setSaved] = useState(false)
  const [err, setErr] = useState('')

  // The draft only exists once you've edited something — until then the saved
  // config IS the source of truth, so a background refetch can't clobber typing.
  const value = (k) => (draft && k in draft ? draft[k] : config?.[k])
  const set = (k, v) => { setDraft(d => ({ ...(d || {}), [k]: v })); setSaved(false) }

  const save = useMutation({
    mutationFn: () => inventoryApi.config.save(draft || {}),
    onSuccess: (fresh) => {
      qc.setQueryData(['inv-config'], fresh)
      setDraft(null); setSaved(true); setErr('')
    },
    onError: (e) => setErr(e?.message || 'Could not save those settings.'),
  })

  if (isLoading) return <div className="h-40 rounded-2xl animate-pulse" style={{ background: 'var(--bg-card)' }} />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>{spec.blurb}</p>

      <div className="space-y-3.5">
        {spec.fields.map(f => (
          <div key={f.key} className="flex flex-wrap items-center gap-3">
            <div className="flex-1" style={{ minWidth: 210 }}>
              <label className="block text-xs font-bold" style={{ color: 'var(--text-h)' }}>{f.label}</label>
              {f.hint && <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{f.hint}</p>}
            </div>
            <div style={{ width: 210 }}>
              {f.type === 'bool' ? (
                <label className="flex items-center gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-body)' }}>
                  <input type="checkbox" disabled={!isAdmin} checked={Boolean(value(f.key))}
                    onChange={e => set(f.key, e.target.checked)} style={{ accentColor: INV_ACCENT, width: 15, height: 15 }} />
                  {value(f.key) ? 'Enabled' : 'Disabled'}
                </label>
              ) : f.type === 'select' ? (
                <Select size="sm" value={value(f.key) ?? ''} onChange={v => set(f.key, v)} options={f.options} />
              ) : f.type === 'text' ? (
                <input type="text" disabled={!isAdmin} value={value(f.key) ?? ''} placeholder={f.placeholder || ''}
                  onChange={e => set(f.key, e.target.value === '' ? null : e.target.value)}
                  className="w-full rounded-lg outline-none"
                  style={{ padding: '7px 10px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              ) : (
                <input type="number" disabled={!isAdmin} value={value(f.key) ?? ''}
                  onChange={e => set(f.key, e.target.value === '' ? null : Number(e.target.value))}
                  className="w-full rounded-lg outline-none"
                  style={{ padding: '7px 10px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              )}
            </div>
          </div>
        ))}
      </div>

      {err && <p className="text-[11px] mt-3" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {isAdmin && (
        <div className="flex items-center gap-2 mt-4 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
          <button onClick={() => save.mutate()} disabled={!draft || save.isPending}
            className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40"
            style={{ background: INV_ACCENT, color: '#fff' }}>
            <Check size={13} /> {save.isPending ? 'Saving…' : 'Save'}
          </button>
          {draft && <button onClick={() => { setDraft(null); setErr('') }} className="text-xs font-bold" style={{ color: 'var(--text-muted)' }}>Discard</button>}
          {saved && <span className="text-[11px] font-bold" style={{ color: INV_ACCENT }}>Saved</span>}
        </div>
      )}
    </section>
  )
}

/* ── Custom fields tab ─────────────────────────────────────────── */

function CustomFieldsTab({ isAdmin }) {
  const qc = useQueryClient()
  const [entity, setEntity] = useState('product')
  const [draft, setDraft] = useState({ label: '', type: 'text', options: '', required: false })
  const [err, setErr] = useState('')

  const { data: fields = [], isLoading } = useQuery({
    queryKey: ['inv-custom-fields', entity],
    queryFn: () => inventoryApi.customFields.list(entity),
  })

  const refresh = () => qc.invalidateQueries({ queryKey: ['inv-custom-fields'] })

  const add = useMutation({
    mutationFn: () => inventoryApi.customFields.create({
      entity,
      label: draft.label.trim(),
      type: draft.type,
      required: draft.required,
      options: draft.type === 'select'
        ? draft.options.split(',').map(o => o.trim()).filter(Boolean)
        : null,
    }),
    onSuccess: () => { setDraft({ label: '', type: 'text', options: '', required: false }); setErr(''); refresh() },
    onError: (e) => setErr(e?.message || 'Could not add that field.'),
  })

  const del = useMutation({
    mutationFn: (id) => inventoryApi.customFields.remove(id),
    onSuccess: refresh,
    onError: (e) => setErr(e?.message || 'Could not delete that field.'),
  })

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-3">
        <Layers size={14} style={{ color: INV_ACCENT }} />
        <h2 className="font-bold text-xs" style={{ color: 'var(--text-h)' }}>Custom fields</h2>
        <div className="ml-auto" style={{ width: 160 }}>
          <Select size="sm" value={entity} onChange={setEntity}
            options={[{ value: 'product', label: 'On items' }, { value: 'warehouse', label: 'On warehouses' }]} />
        </div>
      </div>
      <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
        Extra fields that appear on the {entity === 'product' ? 'Item' : 'Warehouse'} form. A field's internal name is fixed once created, so saved values are never orphaned.
      </p>

      {isLoading && <div className="h-20 rounded-xl animate-pulse" style={{ background: 'var(--bg-input)' }} />}

      <ul className="space-y-1.5 mb-3">
        {fields.map(f => (
          <li key={f.id} className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
            <span className="flex-1 min-w-0">
              <span className="block text-xs font-semibold" style={{ color: 'var(--text-h)' }}>
                {f.label}{f.required && <span style={{ color: 'var(--color-danger-500)' }}> *</span>}
              </span>
              <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>
                <span className="font-mono">{f.key}</span> · {f.type}
                {f.type === 'select' && f.options?.length ? ` · ${f.options.join(', ')}` : ''}
              </span>
            </span>
            {isAdmin && (
              <button onClick={() => del.mutate(f.id)} aria-label={`Delete ${f.label}`} className="hover:opacity-60">
                <Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} />
              </button>
            )}
          </li>
        ))}
        {!isLoading && fields.length === 0 && (
          <li className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No custom fields yet.</li>
        )}
      </ul>

      {isAdmin && (
        <div className="rounded-xl p-3" style={{ border: '1px dashed var(--border)' }}>
          <div className="grid gap-2" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))' }}>
            <input value={draft.label} onChange={e => setDraft(d => ({ ...d, label: e.target.value }))}
              placeholder="Field label" className="rounded-lg outline-none"
              style={{ padding: '7px 10px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            <Select size="sm" value={draft.type} onChange={v => setDraft(d => ({ ...d, type: v }))}
              options={[
                { value: 'text', label: 'Text' }, { value: 'number', label: 'Number' },
                { value: 'date', label: 'Date' }, { value: 'select', label: 'Dropdown' },
                { value: 'checkbox', label: 'Checkbox' },
              ]} />
            {draft.type === 'select' && (
              <input value={draft.options} onChange={e => setDraft(d => ({ ...d, options: e.target.value }))}
                placeholder="Choices, comma separated" className="rounded-lg outline-none"
                style={{ padding: '7px 10px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            )}
            <label className="flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              <input type="checkbox" checked={draft.required} onChange={e => setDraft(d => ({ ...d, required: e.target.checked }))}
                style={{ accentColor: INV_ACCENT }} /> Required
            </label>
          </div>
          {err && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
          <button onClick={() => add.mutate()} disabled={!draft.label.trim() || add.isPending}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl mt-2.5 disabled:opacity-40"
            style={{ background: INV_ACCENT, color: '#fff' }}>
            <Plus size={13} /> {add.isPending ? 'Adding…' : 'Add field'}
          </button>
        </div>
      )}
    </section>
  )
}

/* ── Reset data tab ────────────────────────────────────────────── */

const RESET_SCOPES = [
  { key: 'movements',     label: 'Stock movements & balances', warn: 'Clears the ledger AND zeroes every balance.' },
  { key: 'vouchers',      label: 'Vouchers', warn: 'Deletes all receiving/delivery/internal/adjustment documents.' },
  { key: 'products',      label: 'Items', warn: 'Deletes every item — and their stock, movements and vouchers with them.' },
  { key: 'custom_fields', label: 'Custom field definitions' },
  { key: 'config',        label: 'Settings (back to defaults)' },
]

function ResetTab({ isAdmin }) {
  const qc = useQueryClient()
  const [scopes, setScopes] = useState(() => new Set())
  const [confirm, setConfirm] = useState('')
  const [result, setResult] = useState(null)
  const [err, setErr] = useState('')

  const toggle = (k) => setScopes(s => { const n = new Set(s); n.has(k) ? n.delete(k) : n.add(k); return n })

  const reset = useMutation({
    mutationFn: () => inventoryApi.config.reset([...scopes], confirm),
    onSuccess: (r) => {
      setResult(r); setScopes(new Set()); setConfirm(''); setErr('')
      qc.invalidateQueries()   // everything inventory-related is now stale
    },
    onError: (e) => setErr(e?.message || 'Reset failed.'),
  })

  if (!isAdmin) {
    return (
      <section className="rounded-2xl p-6 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <Lock size={20} style={{ color: 'var(--text-muted)', margin: '0 auto 8px' }} />
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Only an admin can reset inventory data.</p>
      </section>
    )
  }

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--color-danger-500)' }}>
      <div className="flex items-center gap-2 mb-2">
        <AlertTriangle size={15} style={{ color: 'var(--color-danger-500)' }} />
        <h2 className="font-bold text-xs" style={{ color: 'var(--color-danger-500)' }}>Reset data</h2>
      </div>
      <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        Permanently deletes the data you tick. There is no undo — this is the one action in the module that destroys history.
      </p>

      <ul className="space-y-1.5 mb-4">
        {RESET_SCOPES.map(s => (
          <li key={s.key}>
            <label className="flex items-start gap-2 px-3 py-2 rounded-xl cursor-pointer" style={{ background: 'var(--bg-input)' }}>
              <input type="checkbox" checked={scopes.has(s.key)} onChange={() => toggle(s.key)}
                className="mt-0.5" style={{ accentColor: 'var(--color-danger-500)' }} />
              <span>
                <span className="block text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{s.label}</span>
                {s.warn && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{s.warn}</span>}
              </span>
            </label>
          </li>
        ))}
      </ul>

      <label className="block text-[11px] font-bold mb-1" style={{ color: 'var(--text-muted)' }}>Type RESET to confirm</label>
      <input value={confirm} onChange={e => setConfirm(e.target.value)} placeholder="RESET"
        className="w-full rounded-lg outline-none mb-3"
        style={{ padding: '8px 11px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />

      {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
      {result && (
        <p className="text-[11px] mb-2 px-3 py-2 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-body)' }}>
          Done — {Object.entries(result).map(([k, v]) => `${k}: ${v}`).join(' · ')}
        </p>
      )}

      <button onClick={() => reset.mutate()} disabled={!scopes.size || confirm !== 'RESET' || reset.isPending}
        className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40"
        style={{ background: 'var(--color-danger-500)', color: '#fff' }}>
        <Trash2 size={13} /> {reset.isPending ? 'Resetting…' : 'Reset selected data'}
      </button>
    </section>
  )
}

/* ── One tab = one lookup list ────────────────────────────────── */

function LookupTab({ tab, isAdmin }) {
  const qc = useQueryClient()
  const [draft, setDraft] = useState({ name: '', extra: '' })
  const [editingId, setEditingId] = useState(null)
  const [editDraft, setEditDraft] = useState({ name: '', extra: '' })
  const [err, setErr] = useState('')
  // A tenant's colour list runs to dozens and its commodity types to hundreds;
  // finding out whether "Galvanised sheet" is already there meant reading the
  // whole list, so people added it twice.
  const [q, setQ] = useState('')
  const [bulkOpen, setBulkOpen] = useState(false)

  const params = tab.attrKind ? { kind: tab.attrKind } : {}
  const qk = ['inv-setting', tab.key]

  const { data: rows = [], isLoading } = useQuery({ queryKey: qk, queryFn: () => inventoryApi.settings.list(tab.kind, params) })
  const bust = () => {
    qc.invalidateQueries({ queryKey: qk })
    qc.invalidateQueries({ queryKey: ['inv-settings'] })   // the Item form's dropdowns
  }

  // Each section stores its "extra" value in a different column.
  const payload = (d) => {
    const p = { name: d.name.trim() }
    if (tab.attrKind) p.kind = tab.attrKind
    if (tab.extra === 'short_name') p.short_name = d.extra || null
    if (tab.extra === 'rate') p.rate = Number(d.extra || 0)
    if (tab.extra === 'value') p.value = d.extra || null
    return p
  }

  const add = useMutation({
    mutationFn: () => inventoryApi.settings.create(tab.kind, payload(draft)),
    onSuccess: () => { setDraft({ name: '', extra: '' }); setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not add that.'),
  })
  const save = useMutation({
    mutationFn: (id) => inventoryApi.settings.update(tab.kind, id, payload(editDraft)),
    onSuccess: () => { setEditingId(null); setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not save that.'),
  })
  const del = useMutation({
    mutationFn: (id) => inventoryApi.settings.remove(tab.kind, id),
    onSuccess: () => { setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not delete that.'),
  })

  const extraLabel = { short_name: 'Short (kg)', rate: 'Rate %', value: 'Hex / value' }[tab.extra]
  const rowExtra = (r) => tab.extra === 'short_name' ? r.short_name : tab.extra === 'rate' ? r.rate : r.value

  // Filter on both columns: somebody looking for a colour may remember "#c0392b"
  // rather than the name somebody else gave it.
  const term = q.trim().toLowerCase()
  const shown = term
    ? rows.filter(r => [r.name, rowExtra(r)].some(v => String(v ?? '').toLowerCase().includes(term)))
    : rows

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 160, background: 'var(--bg-card)' }} />

  return (
    <div className="space-y-4">
      <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex flex-wrap items-center gap-2 mb-3">
          <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
            <Layers size={14} style={{ color: INV_ACCENT }} /> {tab.label}
            <span className="font-normal" style={{ color: 'var(--text-muted)' }}>
              {term ? `${shown.length} of ${rows.length}` : rows.length}
            </span>
          </h2>

          <div className="ml-auto flex items-center gap-2">
            {rows.length > 5 && (
              <div className="relative">
                <Search size={12} style={{ position: 'absolute', left: 8, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                <input value={q} onChange={e => setQ(e.target.value)}
                  placeholder={`Search ${tab.label.toLowerCase()}…`} aria-label={`Search ${tab.label}`}
                  className="rounded-lg outline-none" style={{ ...MINI, paddingLeft: 24, width: 170 }} />
              </div>
            )}
            {isAdmin && (
              <button type="button" onClick={() => setBulkOpen(o => !o)}
                className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold"
                style={{ background: bulkOpen ? INV_ACCENT : 'var(--bg-input)', color: bulkOpen ? '#fff' : 'var(--text-body)', border: '1px solid var(--border)' }}>
                <Upload size={11} /> Bulk add
              </button>
            )}
          </div>
        </div>

        {err && <p className="text-xs mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

        {bulkOpen && isAdmin && (
          <BulkAddPanel tab={tab} onDone={() => { setBulkOpen(false); bust() }} onClose={() => setBulkOpen(false)} />
        )}

        <ul className="space-y-1.5 mb-3">
          {shown.map(r => (
            <li key={r.id} className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
              {editingId === r.id ? (
                <>
                  <input value={editDraft.name} onChange={e => setEditDraft(d => ({ ...d, name: e.target.value }))}
                    className="flex-1 rounded-lg outline-none" style={MINI} autoFocus />
                  {tab.extra && (
                    <ExtraInput tab={tab} value={editDraft.extra} onChange={v => setEditDraft(d => ({ ...d, extra: v }))} label={extraLabel} />
                  )}
                  <button onClick={() => save.mutate(r.id)} aria-label="Save" className="hover:opacity-70">
                    <Check size={14} style={{ color: INV_ACCENT }} />
                  </button>
                  <button onClick={() => setEditingId(null)} aria-label="Cancel" className="hover:opacity-70">
                    <X size={14} style={{ color: 'var(--text-muted)' }} />
                  </button>
                </>
              ) : (
                <>
                  {tab.attrKind === 'color' && r.value && (
                    <span className="w-4 h-4 rounded-full shrink-0" style={{ background: r.value, border: '1px solid var(--border)' }} />
                  )}
                  <span className="flex-1 text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{r.name}</span>
                  {rowExtra(r) != null && rowExtra(r) !== '' && (
                    <span className="text-[10px] px-1.5 py-0.5 rounded" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>
                      {tab.extra === 'rate' ? `${rowExtra(r)}%` : rowExtra(r)}
                    </span>
                  )}
                  {tab.kind === 'groups' && (
                    <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                      {(r.subgroups || []).length} sub-groups
                    </span>
                  )}
                  {isAdmin && (
                    <>
                      <button onClick={() => { setEditingId(r.id); setEditDraft({ name: r.name, extra: rowExtra(r) ?? '' }) }}
                        aria-label={`Edit ${r.name}`} className="hover:opacity-70">
                        <Pencil size={12} style={{ color: 'var(--text-muted)' }} />
                      </button>
                      <button onClick={() => del.mutate(r.id)} aria-label={`Delete ${r.name}`} className="hover:opacity-70">
                        <Trash2 size={12} style={{ color: 'var(--color-danger-500)' }} />
                      </button>
                    </>
                  )}
                </>
              )}
            </li>
          ))}
          {shown.length === 0 && (
            <li className="text-xs py-3" style={{ color: 'var(--text-muted)' }}>
              {term ? `Nothing matches “${q}”.` : 'Nothing here yet.'}
            </li>
          )}
        </ul>

        {isAdmin && (
          <form onSubmit={e => { e.preventDefault(); if (draft.name.trim()) add.mutate() }} className="flex flex-wrap gap-2">
            <input value={draft.name} onChange={e => setDraft(d => ({ ...d, name: e.target.value }))}
              placeholder={`New ${tab.label.replace(/s$/, '').toLowerCase()}`}
              className="flex-1 rounded-lg outline-none" style={{ ...MINI, minWidth: 160 }} />
            {tab.extra && (
              <ExtraInput tab={tab} value={draft.extra} onChange={v => setDraft(d => ({ ...d, extra: v }))} label={extraLabel} />
            )}
            <button type="submit" disabled={!draft.name.trim() || add.isPending}
              className="px-3 rounded-lg disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }} aria-label="Add">
              <Plus size={13} />
            </button>
          </form>
        )}
      </section>

      {tab.kind === 'groups' && <SubgroupPanel groups={rows} isAdmin={isAdmin} onChange={bust} />}
    </div>
  )
}

/* ── The "extra" column, which is a colour on one tab ──────────── */

/**
 * The second field on a lookup row — a short name, a tax rate, or a colour.
 *
 * A colour was a plain text box asking for "Hex / value", so choosing one meant
 * knowing that #c0392b is a brick red and typing it correctly. Both ways now
 * work and they are the same field: the swatch opens the operating system's
 * colour picker, and the box beside it still takes anything typed — a hex, or a
 * name like "Brick red" for a tenant who prefers words to codes.
 *
 * The swatch only claims to show a colour when the value actually is one.
 * Painting the chip for "Brick red" would render black and quietly assert the
 * wrong thing.
 */
function ExtraInput({ tab, value, onChange, label }) {
  if (tab.attrKind !== 'color') {
    return (
      <input value={value} onChange={e => onChange(e.target.value)} placeholder={label}
        className="rounded-lg outline-none" style={{ ...MINI, width: 120 }} />
    )
  }

  const isHex = /^#[0-9a-f]{6}$/i.test(String(value || '').trim())

  return (
    <span className="flex items-center gap-1.5">
      <input
        type="color"
        value={isHex ? value : '#7c3aed'}
        onChange={e => onChange(e.target.value)}
        aria-label="Pick a colour"
        title={isHex ? value : 'Pick a colour'}
        style={{
          width: 30, height: 30, padding: 0, borderRadius: 8, cursor: 'pointer',
          border: '1px solid var(--border)', background: 'var(--bg-input)',
        }}
      />
      <input value={value} onChange={e => onChange(e.target.value)} placeholder="#c0392b or a name"
        className="rounded-lg outline-none" style={{ ...MINI, width: 130 }} />
    </span>
  )
}

/* ── Bulk add ──────────────────────────────────────────────────── */

/**
 * Add a list at once — pasted, or read out of a CSV in the browser.
 *
 * Master data arrives in lists: a page of commodity types off a supplier's
 * catalogue, forty colours off a swatch card. Entering them through a
 * single-line form is the complaint this answers.
 *
 * The file is parsed HERE rather than uploaded, because the parsing is one
 * split on commas and the alternative is a multipart endpoint, a temp file and
 * a format nobody can see before it commits. What goes to the server is the
 * same list of rows the textarea shows, so what you can read is what you get.
 *
 * One request for the whole list — see settings.bulk.
 */
function BulkAddPanel({ tab, onDone, onClose }) {
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [result, setResult] = useState(null)

  const extraName = { short_name: 'short name', rate: 'rate', value: 'hex or colour name' }[tab.extra]

  // "Name" or "Name, extra" per line. Blank lines and a leading header row are
  // dropped, because a pasted spreadsheet column almost always has one.
  const parse = (raw) => raw.split(/\r?\n/)
    .map(l => l.trim())
    .filter(Boolean)
    .filter((l, i) => !(i === 0 && /^name\s*(,|$)/i.test(l)))
    .map(line => {
      const [name, ...rest] = line.split(',')
      const row = { name: (name || '').trim() }
      const extra = rest.join(',').trim()
      if (tab.attrKind) row.kind = tab.attrKind
      // Sub-groups all belong to the group chosen above the panel.
      if (tab.groupId) row.group_id = tab.groupId
      if (extra) {
        if (tab.extra === 'short_name') row.short_name = extra
        if (tab.extra === 'rate') row.rate = Number(extra) || 0
        if (tab.extra === 'value') row.value = extra
      }
      return row
    })
    .filter(r => r.name)

  const rows = parse(text)

  const readFile = (file) => {
    if (!file) return
    const reader = new FileReader()
    reader.onload = () => setText(String(reader.result || ''))
    reader.readAsText(file)
  }

  const submit = async () => {
    if (!rows.length || busy) return
    setBusy(true); setErr(''); setResult(null)
    try {
      const r = await inventoryApi.settings.bulk(tab.kind, rows)
      setResult(r)
      setText('')
      // Left open on purpose: the count is the only record of what happened,
      // and closing over it would answer "did that work?" by vanishing.
      onDone?.()
    } catch (e) {
      setErr(e?.message || 'Could not import that list.')
    } finally { setBusy(false) }
  }

  return (
    <div className="rounded-xl p-3 mb-3" style={{ background: 'var(--bg-input)', border: '1px dashed var(--border)' }}>
      <div className="flex items-center justify-between mb-2">
        <p className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>
          One per line{tab.extra ? ` — “Name, ${extraName}” to set both` : ''}
        </p>
        <button type="button" onClick={onClose} aria-label="Close bulk add" className="hover:opacity-70">
          <X size={13} style={{ color: 'var(--text-muted)' }} />
        </button>
      </div>

      <textarea
        value={text}
        onChange={e => setText(e.target.value)}
        rows={5}
        aria-label={`Bulk add ${tab.label}`}
        placeholder={tab.extra === 'value' ? 'Brick red, #c0392b\nSlate, #475569' : 'Galvanised sheet\nMild steel bar'}
        className="w-full rounded-lg outline-none"
        style={{ ...MINI, resize: 'vertical', minHeight: 90, fontFamily: 'ui-monospace, monospace' }}
      />

      <div className="flex flex-wrap items-center gap-2 mt-2">
        <label className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold cursor-pointer"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-body)' }}>
          <Upload size={11} /> Choose a CSV
          <input type="file" accept=".csv,.txt,text/csv,text/plain" className="hidden"
            onChange={e => { readFile(e.target.files?.[0]); e.target.value = '' }} />
        </label>

        <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          {rows.length ? `${rows.length} ready` : 'Nothing to add yet'}
        </span>

        <button type="button" onClick={submit} disabled={!rows.length || busy}
          className="ml-auto px-3 py-1.5 rounded-lg text-[11px] font-bold disabled:opacity-40"
          style={{ background: INV_ACCENT, color: '#fff' }}>
          {busy ? 'Adding…' : `Add ${rows.length || ''}`.trim()}
        </button>
      </div>

      {err && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
      {result && (
        <p className="text-[11px] mt-2" style={{ color: 'var(--text-body)' }}>
          <strong style={{ color: INV_ACCENT }}>{result.created} added</strong>
          {result.skipped ? ` · ${result.skipped} were already there` : ''}
        </p>
      )}
    </div>
  )
}

/* ── Sub-groups (dependent on a group) ────────────────────────── */

function SubgroupPanel({ groups, isAdmin, onChange }) {
  const qc = useQueryClient()
  const [groupId, setGroupId] = useState(groups[0]?.id ?? '')
  const [name, setName] = useState('')
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [bulkOpen, setBulkOpen] = useState(false)

  const { data: subs = [] } = useQuery({
    queryKey: ['inv-subgroups', groupId], queryFn: () => inventoryApi.settings.subgroups(groupId), enabled: !!groupId,
  })
  const bust = () => {
    qc.invalidateQueries({ queryKey: ['inv-subgroups', groupId] })
    qc.invalidateQueries({ queryKey: ['inv-setting', 'groups'] })
    onChange?.()
  }

  const add = useMutation({
    mutationFn: () => inventoryApi.settings.create('subgroups', { name: name.trim(), group_id: Number(groupId) }),
    onSuccess: () => { setName(''); setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not add that sub-group.'),
  })
  const del = useMutation({
    mutationFn: (id) => inventoryApi.settings.remove('subgroups', id),
    onSuccess: () => { setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not delete that sub-group.'),
  })

  if (!groups.length) return null

  const term = q.trim().toLowerCase()
  const shownSubs = term ? subs.filter(s => String(s.name ?? '').toLowerCase().includes(term)) : subs

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3" style={{ color: 'var(--text-h)' }}>
        Sub groups <span className="font-normal" style={{ color: 'var(--text-muted)' }}>
          — belong to one group{term ? ` · ${shownSubs.length} of ${subs.length}` : ''}
        </span>
      </h2>

      <div className="flex flex-wrap items-center gap-2 mb-3">
        <div style={{ maxWidth: 240, flex: '1 1 180px' }}>
          <Select size="sm" value={groupId} onChange={setGroupId} options={groups.map(g => ({ value: g.id, label: g.name }))} />
        </div>
        {subs.length > 5 && (
          <div className="relative">
            <Search size={12} style={{ position: 'absolute', left: 8, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
            <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search sub-groups…"
              aria-label="Search sub-groups" className="rounded-lg outline-none" style={{ ...MINI, paddingLeft: 24, width: 170 }} />
          </div>
        )}
        {isAdmin && groupId && (
          <button type="button" onClick={() => setBulkOpen(o => !o)}
            className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold"
            style={{ background: bulkOpen ? INV_ACCENT : 'var(--bg-input)', color: bulkOpen ? '#fff' : 'var(--text-body)', border: '1px solid var(--border)' }}>
            <Upload size={11} /> Bulk add
          </button>
        )}
      </div>

      {err && <p className="text-xs mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {/* Sub-groups belong to the group chosen above, so the import carries it
          on every row — there is no sensible "which group?" per line. */}
      {bulkOpen && isAdmin && groupId && (
        <BulkAddPanel
          tab={{ kind: 'subgroups', label: 'Sub-groups', groupId: Number(groupId) }}
          onDone={() => { setBulkOpen(false); bust() }}
          onClose={() => setBulkOpen(false)}
        />
      )}

      <ul className="space-y-1.5 mb-3">
        {shownSubs.map(s => (
          <li key={s.id} className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
            <span className="flex-1 text-xs" style={{ color: 'var(--text-h)' }}>{s.name}</span>
            {isAdmin && (
              <button onClick={() => del.mutate(s.id)} aria-label={`Delete ${s.name}`} className="hover:opacity-70">
                <Trash2 size={11} style={{ color: 'var(--color-danger-500)' }} />
              </button>
            )}
          </li>
        ))}
        {shownSubs.length === 0 && (
          <li className="text-xs py-2" style={{ color: 'var(--text-muted)' }}>
            {term ? `Nothing matches “${q}”.` : 'No sub-groups in this group.'}
          </li>
        )}
      </ul>

      {isAdmin && (
        <form onSubmit={e => { e.preventDefault(); if (name.trim() && groupId) add.mutate() }} className="flex gap-2">
          <input value={name} onChange={e => setName(e.target.value)} placeholder="New sub-group"
            className="flex-1 rounded-lg outline-none" style={MINI} />
          <button type="submit" disabled={!name.trim() || add.isPending}
            className="px-3 rounded-lg disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }} aria-label="Add sub-group">
            <Plus size={13} />
          </button>
        </form>
      )}
    </section>
  )
}

const MINI = { padding: '7px 10px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
