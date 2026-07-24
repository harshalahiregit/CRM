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
    // The site as a building: bin occupancy, over-capacity, unlocated stock.
    // `measure` = units | volume | weight — what "full" counts.
    layout:  (id, measure = 'units') => api.get(`/inventory/warehouses/${id}/layout`, { params: { measure } }).then(unwrap).catch(handleErr),
    putaway: (id, data) => api.post(`/inventory/warehouses/${id}/putaway`, data).then(unwrap).catch(handleErr),
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

    // The approval gate. Only reachable where Settings > Approval switched a
    // rule on; submit belongs to the raiser, approve/reject to someone else.
    // Goods-in inspection (§13). Recorded BEFORE posting, because posting a
    // receipt moves the ACCEPTED quantity, not whatever turned up on the lorry.
    inspect: (type, id, lines) => api.post(`/inventory/vouchers/${type}/${id}/inspect`, { lines }).then(unwrap).catch(handleErr),
    vendorReturn: (type, id) => api.post(`/inventory/vouchers/${type}/${id}/vendor-return`).then(unwrap).catch(handleErr),

    // Paperwork on the document: challan, test certificate, QC photos, POD.
    // Files live on a private disk, so downloads are fetched as an authenticated
    // blob rather than linked to directly.
    files: (type, id) => api.get(`/inventory/vouchers/${type}/${id}/files`).then(unwrap).catch(handleErr),
    uploadFiles: (type, id, fileList, kind = 'document') => {
      const fd = new FormData()
      Array.from(fileList).forEach(f => fd.append('files[]', f))
      fd.append('kind', kind)
      // Overriding Content-Type lets axios set the multipart boundary itself —
      // the instance default (application/json) would break the upload.
      return api.post(`/inventory/vouchers/${type}/${id}/files`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then(unwrap).catch(handleErr)
    },
    deleteFile: (type, id, fileId) => api.delete(`/inventory/vouchers/${type}/${id}/files/${fileId}`).then(unwrap).catch(handleErr),
    downloadFile: async (type, id, fileId, name) => {
      const res = await api.get(`/inventory/vouchers/${type}/${id}/files/${fileId}/download`, { responseType: 'blob' }).catch(handleErr)
      const url = URL.createObjectURL(res.data)
      const a = document.createElement('a')
      a.href = url; a.download = name || 'attachment'
      a.click()
      URL.revokeObjectURL(url)
    },

    submit:  (type, id) => api.post(`/inventory/vouchers/${type}/${id}/submit`).then(unwrap).catch(handleErr),
    approve: (type, id) => api.post(`/inventory/vouchers/${type}/${id}/approve`).then(unwrap).catch(handleErr),
    reject:  (type, id, reason) => api.post(`/inventory/vouchers/${type}/${id}/reject`, { reason }).then(unwrap).catch(handleErr),
  },

  /** Documents waiting on the current user's decision, across all four types. */
  pendingApprovals: () => api.get('/inventory/approvals/pending').then(unwrap).catch(handleErr),

  /**
   * One call for the scanner: give it any code and the server says what it is —
   * product, serial, batch, bin, warehouse or document. A miss comes back as
   * found:false with a 200, because an unlabelled box is a normal thing to scan,
   * not a client error worth an exception.
   */
  scan: (code) => api.post('/inventory/scan', { code }).then(unwrap).catch(handleErr),

  /**
   * Pick, pack, ship. Nothing here moves stock — shipping posts the delivery
   * voucher, and that is what writes the movement.
   */
  fulfilment: {
    list:    (params = {}) => api.get('/inventory/fulfilment', { params }).then(unwrap).catch(handleErr),
    get:     (id) => api.get(`/inventory/fulfilment/${id}`).then(unwrap).catch(handleErr),
    create:  (data) => api.post('/inventory/fulfilment', data).then(unwrap).catch(handleErr),
    assign:  (id, assigned_to) => api.patch(`/inventory/fulfilment/${id}/assign`, { assigned_to }).then(unwrap).catch(handleErr),
    pick:    (id, lines) => api.post(`/inventory/fulfilment/${id}/pick`, { lines }).then(unwrap).catch(handleErr),
    picked:  (id) => api.post(`/inventory/fulfilment/${id}/picked`).then(unwrap).catch(handleErr),
    pack:    (id, lines) => api.post(`/inventory/fulfilment/${id}/pack`, { lines }).then(unwrap).catch(handleErr),
    ship:    (id, data) => api.post(`/inventory/fulfilment/${id}/ship`, data).then(unwrap).catch(handleErr),
    deliver: (id, data) => api.post(`/inventory/fulfilment/${id}/deliver`, data).then(unwrap).catch(handleErr),
    cancel:  (id) => api.post(`/inventory/fulfilment/${id}/cancel`).then(unwrap).catch(handleErr),
    // Which couriers exist and which are actually connected — the UI says so
    // rather than leaving people wondering why a number must be typed by hand.
    carriers: () => api.get('/inventory/fulfilment/carriers').then(unwrap).catch(handleErr),
  },

  /**
   * Physical counts. Counting writes to the count sheet only — APPROVAL is what
   * posts the corrections to the ledger, and never by whoever did the walking.
   */
  counts: {
    list:    (params = {}) => api.get('/inventory/counts', { params }).then(unwrap).catch(handleErr),
    get:     (id) => api.get(`/inventory/counts/${id}`).then(unwrap).catch(handleErr),
    create:  (data) => api.post('/inventory/counts', data).then(unwrap).catch(handleErr),
    assign:  (id, assigned_to) => api.patch(`/inventory/counts/${id}/assign`, { assigned_to }).then(unwrap).catch(handleErr),
    // A counter found something the sheet doesn't list.
    addLine: (id, data) => api.post(`/inventory/counts/${id}/lines`, data).then(unwrap).catch(handleErr),
    count:   (id, lines) => api.post(`/inventory/counts/${id}/count`, { lines }).then(unwrap).catch(handleErr),
    recount: (id, lines) => api.post(`/inventory/counts/${id}/recount`, { lines }).then(unwrap).catch(handleErr),
    submit:  (id) => api.post(`/inventory/counts/${id}/submit`).then(unwrap).catch(handleErr),
    approve: (id) => api.post(`/inventory/counts/${id}/approve`).then(unwrap).catch(handleErr),
    reject:  (id, reason) => api.post(`/inventory/counts/${id}/reject`, { reason }).then(unwrap).catch(handleErr),
    cancel:  (id) => api.post(`/inventory/counts/${id}/cancel`).then(unwrap).catch(handleErr),
    photo:   (id, lineId, file) => {
      const fd = new FormData()
      fd.append('photo', file)
      return api.post(`/inventory/counts/${id}/lines/${lineId}/photo`, fd,
        { headers: { 'Content-Type': 'multipart/form-data' } }).then(unwrap).catch(handleErr)
    },
    photoUrl: (id, lineId) => `/api/inventory/counts/${id}/lines/${lineId}/photo`,
  },

  /**
   * Consignments. Dispatch moves stock into the transit warehouse; receiving
   * moves what actually arrived out of it. Anything left over stays in transit
   * until it is received late or deliberately written off.
   */
  transfers: {
    list:     (params = {}) => api.get('/inventory/transfers', { params }).then(unwrap).catch(handleErr),
    get:      (id) => api.get(`/inventory/transfers/${id}`).then(unwrap).catch(handleErr),
    create:   (data) => api.post('/inventory/transfers', data).then(unwrap).catch(handleErr),
    dispatch: (id, data) => api.post(`/inventory/transfers/${id}/dispatch`, data).then(unwrap).catch(handleErr),
    receive:  (id, lines) => api.post(`/inventory/transfers/${id}/receive`, { lines }).then(unwrap).catch(handleErr),
    writeOff: (id, lines, reason) => api.post(`/inventory/transfers/${id}/write-off`, { lines, reason }).then(unwrap).catch(handleErr),
    cancel:   (id) => api.post(`/inventory/transfers/${id}/cancel`).then(unwrap).catch(handleErr),
    // Does the transit balance match what the open consignments claim? Admin-only.
    reconcile: () => api.get('/inventory/transfers/reconcile').then(unwrap).catch(handleErr),
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
  draft:            { label: 'Draft',      color: 'var(--text-muted)' },
  // Only appear where Settings > Approval switched a rule on. Everywhere else a
  // draft still goes straight to posted, exactly as it always did.
  pending_approval: { label: 'Awaiting approval', color: '#f59e0b' },
  approved:         { label: 'Approved',   color: '#3b82f6' },
  posted:           { label: 'Posted',     color: '#10B981' },
  cancelled:        { label: 'Cancelled',  color: 'var(--color-danger-500)' },
}

/**
 * The fulfilment stages, in order. A parcel only ever moves forward, so the
 * order here IS the workflow — the UI reads the next action off it.
 */
export const PICK_STATUS = {
  open:      { label: 'To pick',   color: 'var(--text-muted)', next: 'Start picking' },
  picking:   { label: 'Picking',   color: '#3b82f6',           next: 'Finish picking' },
  picked:    { label: 'To pack',   color: '#8b5cf6',           next: 'Pack it' },
  packed:    { label: 'To ship',   color: '#f59e0b',           next: 'Ship it' },
  shipped:   { label: 'Shipped',   color: '#0ea5e9',           next: 'Mark delivered' },
  delivered: { label: 'Delivered', color: '#10B981',           next: null },
  cancelled: { label: 'Cancelled', color: 'var(--color-danger-500)', next: null },
}

/**
 * The count stages. `counting` is where a rejected sheet lands too, so the
 * label has to make sense both the first time and the second.
 */
export const COUNT_STATUS = {
  draft:            { label: 'Not started',       color: 'var(--text-muted)' },
  counting:         { label: 'Being counted',     color: '#3b82f6' },
  pending_approval: { label: 'Awaiting approval', color: '#f59e0b' },
  approved:         { label: 'Approved',          color: '#10B981' },
  cancelled:        { label: 'Cancelled',         color: 'var(--color-danger-500)' },
}

/** How a sheet's lines were chosen. */
export const COUNT_SCOPES = [
  { value: 'random',   label: 'Random sample',    hint: 'Nobody can predict which shelf gets checked — the cheapest honest count there is.' },
  { value: 'abc',      label: 'By ABC class',     hint: 'Count the items that carry the value most often.' },
  { value: 'location', label: 'Chosen bins',      hint: 'Verify particular racks or bins.' },
  { value: 'category', label: 'Chosen categories', hint: 'Everything in one part of the catalogue.' },
  { value: 'product',  label: 'Chosen items',     hint: 'A short list you already suspect.' },
  { value: 'full',     label: 'Whole warehouse',  hint: 'A wall-to-wall stocktake. Thorough, and rarely finished.' },
]

/**
 * The consignment stages. `in_transit` is the one that matters: goods that have
 * left one shelf and not yet reached another are on nobody's, and saying so is
 * the entire point of the feature.
 */
export const TRANSFER_STATUS = {
  draft:      { label: 'Not sent yet', color: 'var(--text-muted)', next: 'Dispatch' },
  in_transit: { label: 'On the road',  color: '#0ea5e9',           next: 'Receive' },
  received:   { label: 'Received',     color: '#8b5cf6',           next: null },
  closed:     { label: 'Complete',     color: '#10B981',           next: null },
  cancelled:  { label: 'Turned round', color: 'var(--color-danger-500)', next: null },
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

/** What a bin's capacity number counts. A number with no unit is not a limit. */
export const CAPACITY_UOMS = [
  { value: 'units',  label: 'Units',  hint: 'A plain count. Honest only where a bin holds one kind of thing.' },
  { value: 'weight', label: 'Weight', hint: 'Sum of quantity × the product weight. The limit that matters for a rack beam.' },
  { value: 'volume', label: 'Volume', hint: 'Sum of quantity × the product volume.' },
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
