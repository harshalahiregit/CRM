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

  // §7 — the whole-module audit ledger, filterable and paged.
  history: (params = {}) => api.get('/inventory/history', { params }).then(unwrap).catch(handleErr),

  // §8 — kind = summary | valuation | analysis, all sharing one filter shape.
  report: (kind, params = {}) => api.get(`/inventory/reports/${kind}`, { params }).then(unwrap).catch(handleErr),

  // Internal people, for the "Staff" filter on reports/history.
  staff: () => api.get('/inventory/staff').then(unwrap).catch(handleErr),

  // Analytics — ABC/XYZ, turnover, dead stock, accuracy. The backend scopes the
  // figures to the viewer (tenant-wide for an admin, own activity otherwise) and
  // tells us which via `scope`.
  analytics: (params = {}) => api.get('/inventory/analytics', { params }).then(unwrap).catch(handleErr),

  // Traceability — batches, serial numbers, reservations, expiry.
  batches: {
    list:   (params = {}) => api.get('/inventory/batches', { params }).then(unwrap).catch(handleErr),
    create: (data) => api.post('/inventory/batches', data).then(unwrap).catch(handleErr),
    update: (id, data) => api.put(`/inventory/batches/${id}`, data).then(unwrap).catch(handleErr),
    remove: (id) => api.delete(`/inventory/batches/${id}`).then(unwrap).catch(handleErr),
    fefo:   (params) => api.get('/inventory/batches-fefo', { params }).then(unwrap).catch(handleErr),
  },

  expiry: (days) => api.get('/inventory/expiry', { params: days ? { days } : {} }).then(unwrap).catch(handleErr),

  serials: {
    list:   (params = {}) => api.get('/inventory/serials', { params }).then(unwrap).catch(handleErr),
    create: (data) => api.post('/inventory/serials', data).then(unwrap).catch(handleErr),
    update: (id, data) => api.put(`/inventory/serials/${id}`, data).then(unwrap).catch(handleErr),
    remove: (id) => api.delete(`/inventory/serials/${id}`).then(unwrap).catch(handleErr),
  },

  reservations: {
    list:    (params = {}) => api.get('/inventory/reservations', { params }).then(unwrap).catch(handleErr),
    reserve: (data) => api.post('/inventory/reservations', data).then(unwrap).catch(handleErr),
    close:   (id, as = 'released') => api.post(`/inventory/reservations/${id}/close`, { as }).then(unwrap).catch(handleErr),
  },

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

    // §1 — tag vocabulary, bulk actions, imports and item images.
    tags: () => api.get('/inventory/products/tags').then(unwrap).catch(handleErr),
    bulk: (action, ids, value = null) =>
      api.post('/inventory/products/bulk', { action, ids, value }).then(unwrap).catch(handleErr),
    importTemplate: (kind) => api.get(`/inventory/products/import/${kind}/template`).then(unwrap).catch(handleErr),
    import: (kind, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return api.post(`/inventory/products/import/${kind}`, fd).then(unwrap).catch(handleErr)
    },
    uploadImage: (id, file) => {
      const fd = new FormData()
      fd.append('image', file)
      return api.post(`/inventory/products/${id}/image`, fd).then(unwrap).catch(handleErr)
    },
    deleteImage: (id) => api.delete(`/inventory/products/${id}/image`).then(unwrap).catch(handleErr),
    // Images are private — this is the authenticated URL the <img> can't use
    // directly, so the caller fetches it as a blob.
    imageBlob: (id) => api.get(`/inventory/products/${id}/image`, { responseType: 'blob' })
      .then(r => URL.createObjectURL(r.data)).catch(() => null),
  },

  // §9 — configuration tabs, custom field definitions, and the reset tool.
  config: {
    get:  () => api.get('/inventory/config').then(unwrap).catch(handleErr),
    save: (settings) => api.put('/inventory/config', { settings }).then(unwrap).catch(handleErr),
    reset: (scopes, confirm) => api.post('/inventory/reset', { scopes, confirm }).then(unwrap).catch(handleErr),
  },

  customFields: {
    list:   (entity) => api.get('/inventory/custom-fields', { params: entity ? { entity } : {} }).then(unwrap).catch(handleErr),
    create: (data) => api.post('/inventory/custom-fields', data).then(unwrap).catch(handleErr),
    update: (id, data) => api.put(`/inventory/custom-fields/${id}`, data).then(unwrap).catch(handleErr),
    remove: (id) => api.delete(`/inventory/custom-fields/${id}`).then(unwrap).catch(handleErr),
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
    // §2 "send received note" — email the document to a supplier/customer.
    send:   (type, id, data) => api.post(`/inventory/vouchers/${type}/${id}/send`, data).then(unwrap).catch(handleErr),
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

/** The Items page's Alert dropdown (blueprint §1). */
export const ALERT_OPTIONS = [
  { value: 'min_stock',    label: 'At/below minimum stock' },
  { value: 'max_stock',    label: 'At/above maximum stock' },
  { value: 'out_of_stock', label: 'Out of stock' },
  { value: 'expiring',     label: 'Expiring soon' },
]

/** Bulk actions the Items table offers on a checkbox selection. */
export const BULK_ACTIONS = [
  { value: 'status',      label: 'Set status' },
  { value: 'category',    label: 'Set category' },
  { value: 'add_tags',    label: 'Add tags' },
  { value: 'remove_tags', label: 'Remove tags' },
  { value: 'delete',      label: 'Delete', danger: true },
]

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
