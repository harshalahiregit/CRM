// Inventory OS — /api/inventory/* (owner: Shivam)
// Backend wraps responses in { status, message, data }; we unwrap to `data`.
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.message || err?.response?.data?.error || 'Something went wrong'
  throw new Error(msg)
}
const unwrap = (r) => r.data?.data ?? r.data

export const inventoryApi = {
  summary:  () => api.get('/inventory/summary').then(unwrap).catch(handleErr),
  lowStock: () => api.get('/inventory/low-stock').then(unwrap).catch(handleErr),

  products: {
    list:   (params = {}) => api.get('/inventory/products', { params }).then(unwrap).catch(handleErr),
    get:    (id) => api.get(`/inventory/products/${id}`).then(unwrap).catch(handleErr),
    create: (data) => api.post('/inventory/products', data).then(unwrap).catch(handleErr),
    update: (id, data) => api.put(`/inventory/products/${id}`, data).then(unwrap).catch(handleErr),
    remove: (id) => api.delete(`/inventory/products/${id}`).then(unwrap).catch(handleErr),
    // Scanner / search-by-code — resolves a barcode OR a SKU.
    lookup: (code) => api.get('/inventory/products/lookup', { params: { code } }).then(unwrap).catch(handleErr),
    levels: (id) => api.get(`/inventory/products/${id}/levels`).then(unwrap).catch(handleErr),
    history: (id, limit = 100) => api.get(`/inventory/products/${id}/history`, { params: { limit } }).then(unwrap).catch(handleErr),
  },

  categories: {
    list:   () => api.get('/inventory/categories').then(unwrap).catch(handleErr),
    create: (data) => api.post('/inventory/categories', data).then(unwrap).catch(handleErr),
    update: (id, data) => api.put(`/inventory/categories/${id}`, data).then(unwrap).catch(handleErr),
    remove: (id) => api.delete(`/inventory/categories/${id}`).then(unwrap).catch(handleErr),
  },

  warehouses: {
    list:   () => api.get('/inventory/warehouses').then(unwrap).catch(handleErr),
    get:    (id) => api.get(`/inventory/warehouses/${id}`).then(unwrap).catch(handleErr),
    create: (data) => api.post('/inventory/warehouses', data).then(unwrap).catch(handleErr),
    update: (id, data) => api.put(`/inventory/warehouses/${id}`, data).then(unwrap).catch(handleErr),
    remove: (id) => api.delete(`/inventory/warehouses/${id}`).then(unwrap).catch(handleErr),
    locations:      (id) => api.get(`/inventory/warehouses/${id}/locations`).then(unwrap).catch(handleErr),
    createLocation: (id, data) => api.post(`/inventory/warehouses/${id}/locations`, data).then(unwrap).catch(handleErr),
    deleteLocation: (id, locId) => api.delete(`/inventory/warehouses/${id}/locations/${locId}`).then(unwrap).catch(handleErr),
  },

  stock: {
    // Any movement: receive / issue / damage / transfer / …
    move:   (data) => api.post('/inventory/stock/move', data).then(unwrap).catch(handleErr),
    // Set an exact counted figure; backend records the delta as an adjustment.
    adjust: (data) => api.post('/inventory/stock/adjust', data).then(unwrap).catch(handleErr),
  },

  // Voucher documents (§3–§6). type = receipt | delivery | internal | loss_adjustment.
  // A voucher moves nothing until it's posted.
  vouchers: {
    list:   (type, params = {}) => api.get(`/inventory/vouchers/${type}`, { params }).then(unwrap).catch(handleErr),
    get:    (type, id) => api.get(`/inventory/vouchers/${type}/${id}`).then(unwrap).catch(handleErr),
    create: (type, data) => api.post(`/inventory/vouchers/${type}`, data).then(unwrap).catch(handleErr),
    update: (type, id, data) => api.put(`/inventory/vouchers/${type}/${id}`, data).then(unwrap).catch(handleErr),
    remove: (type, id) => api.delete(`/inventory/vouchers/${type}/${id}`).then(unwrap).catch(handleErr),
    post:   (type, id) => api.post(`/inventory/vouchers/${type}/${id}/post`).then(unwrap).catch(handleErr),
    cancel: (type, id) => api.post(`/inventory/vouchers/${type}/${id}/cancel`).then(unwrap).catch(handleErr),
  },

  // Settings master data (blueprint §10). `all()` is one request behind every
  // dropdown on the Item form; the {kind} calls drive the Settings screen.
  settings: {
    all:    () => api.get('/inventory/settings').then(unwrap).catch(handleErr),
    list:   (kind, params = {}) => api.get(`/inventory/settings/${kind}`, { params }).then(unwrap).catch(handleErr),
    create: (kind, data) => api.post(`/inventory/settings/${kind}`, data).then(unwrap).catch(handleErr),
    update: (kind, id, data) => api.put(`/inventory/settings/${kind}/${id}`, data).then(unwrap).catch(handleErr),
    remove: (kind, id) => api.delete(`/inventory/settings/${kind}/${id}`).then(unwrap).catch(handleErr),
    subgroups: (groupId) => api.get(`/inventory/settings/groups/${groupId}/subgroups`).then(unwrap).catch(handleErr),
  },
}

/**
 * The four stock documents. `pricing` = the line grid shows unit price/tax
 * (money documents); `lots` = lines carry lot number + expiry.
 */
export const VOUCHER_TYPES = {
  receipt: {
    label: 'Inventory receiving voucher', short: 'Receiving', accent: '#10B981',
    verb: 'Receive into', pricing: true, lots: true, needsWarehouse: true,
    blurb: 'Stock coming IN — purchases and goods receipts.',
  },
  delivery: {
    label: 'Inventory delivery voucher', short: 'Delivery', accent: '#f59e0b',
    verb: 'Deliver from', pricing: true, lots: false, needsWarehouse: true,
    blurb: 'Stock going OUT — to customers and sales.',
  },
  internal: {
    label: 'Internal delivery note', short: 'Internal transfer', accent: '#3b82f6',
    verb: 'Transfer', pricing: true, lots: false, needsWarehouse: false, perLineTransfer: true,
    blurb: 'Move stock between two warehouses.',
  },
  loss_adjustment: {
    label: 'Loss & adjustment', short: 'Loss & adjustment', accent: '#8b5cf6',
    verb: 'At', pricing: false, lots: true, needsWarehouse: true,
    blurb: 'Write-offs and stock corrections.',
  },
}

export const VOUCHER_STATUS = {
  draft:     { label: 'Draft',     color: 'var(--text-muted)' },
  posted:    { label: 'Posted',    color: '#10B981' },
  cancelled: { label: 'Cancelled', color: 'var(--color-danger-500)' },
}

/** Settings screen tabs → API kind + the shape each row has. */
export const SETTING_TABS = [
  { key: 'units',  label: 'Units',          kind: 'units',      extra: 'short_name' },
  { key: 'types',  label: 'Commodity Type', kind: 'types' },
  { key: 'groups', label: 'Groups',         kind: 'groups' },
  { key: 'taxes',  label: 'Taxes',          kind: 'taxes',      extra: 'rate' },
  { key: 'color',  label: 'Colors',         kind: 'attributes', attrKind: 'color', extra: 'value' },
  { key: 'model',  label: 'Models',         kind: 'attributes', attrKind: 'model' },
  { key: 'size',   label: 'Sizes',          kind: 'attributes', attrKind: 'size' },
  { key: 'style',  label: 'Styles',         kind: 'attributes', attrKind: 'style' },
]

/** Sale price from cost + profit %, and the reverse — mirrors the backend. */
export const calcSalePrice = (cost, ratio) => {
  const c = Number(cost), r = Number(ratio)
  if (!(c > 0) || !Number.isFinite(r)) return ''
  return String(Math.round(c * (1 + r / 100) * 100) / 100)
}
export const calcProfitRatio = (cost, sale) => {
  const c = Number(cost), s = Number(sale)
  if (!(c > 0) || !Number.isFinite(s)) return ''
  return String(Math.round(((s - c) / c) * 100 * 100) / 100)
}

/** Module accent — Inventory is green, matching its card in the module registry. */
export const INV_ACCENT = '#10B981'

export const WAREHOUSE_TYPES = [
  { value: 'godown',       label: 'Godown' },
  { value: 'store_room',   label: 'Store Room' },
  { value: 'cold_storage', label: 'Cold Storage' },
  { value: 'open_yard',    label: 'Open Yard' },
  { value: 'transit',      label: 'Transit' },
  { value: 'virtual',      label: 'Virtual' },
]

export const LOCATION_TYPES = [
  { value: 'zone', label: 'Zone' }, { value: 'rack', label: 'Rack' },
  { value: 'shelf', label: 'Shelf' }, { value: 'bin', label: 'Bin' },
  { value: 'position', label: 'Position' },
]

/** Movement types the "record movement" form offers, with how they read. */
export const MOVEMENT_TYPES = [
  { value: 'receive',  label: 'Receive',   dir: 'in',  color: '#10B981' },
  { value: 'issue',    label: 'Issue',     dir: 'out', color: '#f59e0b' },
  { value: 'transfer', label: 'Transfer',  dir: 'transfer', color: '#3b82f6' },
  { value: 'return',   label: 'Return',    dir: 'in',  color: '#10B981' },
  { value: 'damage',   label: 'Damage',    dir: 'out', color: '#ef4444' },
  { value: 'expired',  label: 'Expired',   dir: 'out', color: '#ef4444' },
  { value: 'lost',     label: 'Lost',      dir: 'out', color: '#ef4444' },
  { value: 'scrap',    label: 'Scrap',     dir: 'out', color: '#ef4444' },
]

export const MOVEMENT_META = Object.fromEntries(
  [...MOVEMENT_TYPES, { value: 'opening', label: 'Opening', dir: 'in', color: '#64748b' },
    { value: 'adjustment', label: 'Adjustment', dir: 'in', color: '#8b5cf6' }].map(m => [m.value, m])
)

/** Trim trailing zeros so 40.000 reads as 40 but 1.5 stays 1.5. */
export const fmtQty = (q) => {
  const n = Number(q ?? 0)
  return Number.isFinite(n) ? String(parseFloat(n.toFixed(3))) : '0'
}

export const money = (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN')

export default inventoryApi
