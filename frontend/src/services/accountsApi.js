// Accounts module — /api/accounts/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.message || err?.response?.data?.error || 'Something went wrong'
  throw new Error(msg)
}

export const accountsApi = {
  // First-run setup
  setupStatus: () => api.get('/accounts/setup').then(r => r.data).catch(handleErr),
  runSetup:    () => api.post('/accounts/setup').then(r => r.data).catch(handleErr),

  dashboard: (params = {}) => api.get('/accounts/dashboard', { params }).then(r => r.data).catch(handleErr),

  // Registers — per-ledger passbook (read-only)
  registers: {
    list:      (params = {}) => api.get('/accounts/registers', { params }).then(r => r.data).catch(handleErr),
    statement: (ledgerId, params = {}) => api.get(`/accounts/registers/${ledgerId}`, { params }).then(r => r.data).catch(handleErr),
  },


  // Vendor Bills
  bills: {
    list:    (params = {}) => api.get('/accounts/bills', { params }).then(r => r.data).catch(handleErr),
    create:  (data)       => api.post('/accounts/bills', data).then(r => r.data).catch(handleErr),
    get:     (id)         => api.get(`/accounts/bills/${id}`).then(r => r.data).catch(handleErr),
    pay:     (id, data)   => api.post(`/accounts/bills/${id}/pay`, data).then(r => r.data).catch(handleErr),
    approve: (id)         => api.post(`/accounts/bills/${id}/approve`).then(r => r.data).catch(handleErr),
    recur:   (id)         => api.post(`/accounts/bills/${id}/recur`).then(r => r.data).catch(handleErr),
    remove:  (id)         => api.delete(`/accounts/bills/${id}`).then(r => r.data).catch(handleErr),
  },


  // Chart of accounts — groups
  groups: {
    tree: () => api.get('/accounts/account-groups').then(r => r.data).catch(handleErr),
    flat: () => api.get('/accounts/account-groups', { params: { flat: 1 } }).then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/account-groups', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/account-groups/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/accounts/account-groups/${id}`).then(r => r.data).catch(handleErr),
  },

  // Chart of accounts — ledgers
  ledgers: {
    list: (params = {}) => api.get('/accounts/ledgers', { params }).then(r => r.data).catch(handleErr),
    options: () => api.get('/accounts/ledgers/options').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/ledgers', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/ledgers/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/accounts/ledgers/${id}`).then(r => r.data).catch(handleErr),
  },

  // Vouchers (the transaction engine)
  vouchers: {
    list: (params = {}) => api.get('/accounts/vouchers', { params }).then(r => r.data).catch(handleErr),
    get: (id) => api.get(`/accounts/vouchers/${id}`).then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/vouchers', data).then(r => r.data).catch(handleErr),
    cancel: (id, reason) => api.post(`/accounts/vouchers/${id}/cancel`, { reason }).then(r => r.data).catch(handleErr),
    transfer: (data) => api.post('/accounts/vouchers/transfer', data).then(r => r.data).catch(handleErr),
  },

  // Transfer Funds — grouped account picker, history, categories
  transfers: {
    accounts: () => api.get('/accounts/transfers/accounts').then(r => r.data).catch(handleErr),
    history:  (params = {}) => api.get('/accounts/transfers', { params }).then(r => r.data).catch(handleErr),
    cancel:   (id, reason) => api.post(`/accounts/transfers/${id}/cancel`, { reason }).then(r => r.data).catch(handleErr),
  },
  transferCategories: {
    list:   () => api.get('/accounts/transfer-categories').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/transfer-categories', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/transfer-categories/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/accounts/transfer-categories/${id}`).then(r => r.data).catch(handleErr),
  },

  // Reports (all derived from the ledger)
  reports: {
    trialBalance:    (params = {}) => api.get('/accounts/reports/trial-balance', { params }).then(r => r.data).catch(handleErr),
    profitLoss:      (params = {}) => api.get('/accounts/reports/profit-loss', { params }).then(r => r.data).catch(handleErr),
    balanceSheet:    (params = {}) => api.get('/accounts/reports/balance-sheet', { params }).then(r => r.data).catch(handleErr),
    ledgerStatement: (params = {}) => api.get('/accounts/reports/ledger-statement', { params }).then(r => r.data).catch(handleErr),
    generalLedger:   (params = {}) => api.get('/accounts/reports/general-ledger', { params }).then(r => r.data).catch(handleErr),
    dayBook:         (params = {}) => api.get('/accounts/reports/day-book', { params }).then(r => r.data).catch(handleErr),
    cashFlow:        (params = {}) => api.get('/accounts/reports/cash-flow', { params }).then(r => r.data).catch(handleErr),
    ageing:          (params = {}) => api.get('/accounts/reports/ageing', { params }).then(r => r.data).catch(handleErr),
    gstr1:           (params = {}) => api.get('/accounts/reports/gstr-1', { params }).then(r => r.data).catch(handleErr),
    gstr3b:          (params = {}) => api.get('/accounts/reports/gstr-3b', { params }).then(r => r.data).catch(handleErr),
    tds:             (params = {}) => api.get('/accounts/reports/tds', { params }).then(r => r.data).catch(handleErr),
  },

  // Tax masters + GST invoicing
  gstRates: {
    list:   () => api.get('/accounts/tax/gst-rates').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/tax/gst-rates', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/tax/gst-rates/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/accounts/tax/gst-rates/${id}`).then(r => r.data).catch(handleErr),
  },
  hsnSac: {
    list:   (search = '') => api.get('/accounts/tax/hsn-sac', { params: { search } }).then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/tax/hsn-sac', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/tax/hsn-sac/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/accounts/tax/hsn-sac/${id}`).then(r => r.data).catch(handleErr),
  },
  tdsSections: {
    list:   () => api.get('/accounts/tax/tds-sections').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/tax/tds-sections', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/tax/tds-sections/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/accounts/tax/tds-sections/${id}`).then(r => r.data).catch(handleErr),
  },
  taxInvoice: (data) => api.post('/accounts/tax-invoices', data).then(r => r.data).catch(handleErr),

  // Budgets
  budgets: {
    list:      () => api.get('/accounts/budgets').then(r => r.data).catch(handleErr),
    create:    (data) => api.post('/accounts/budgets', data).then(r => r.data).catch(handleErr),
    get:       (id) => api.get(`/accounts/budgets/${id}`).then(r => r.data).catch(handleErr),
    saveLines: (id, lines) => api.put(`/accounts/budgets/${id}/lines`, { lines }).then(r => r.data).catch(handleErr),
    vsActual:  (id) => api.get(`/accounts/budgets/${id}/vs-actual`).then(r => r.data).catch(handleErr),
    remove:    (id) => api.delete(`/accounts/budgets/${id}`).then(r => r.data).catch(handleErr),
  },

  // ── Phase 2: Banking ──────────────────────────────────────
  bankAccounts: {
    list:   () => api.get('/accounts/bank-accounts').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/bank-accounts', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/bank-accounts/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/accounts/bank-accounts/${id}`).then(r => r.data).catch(handleErr),
  },
  statements: {
    imports: (bankId) => api.get(`/accounts/bank-accounts/${bankId}/imports`).then(r => r.data).catch(handleErr),
    lines:   (bankId, params = {}) => api.get(`/accounts/bank-accounts/${bankId}/statement-lines`, { params }).then(r => r.data).catch(handleErr),
    import:  (bankId, file, simulate = false) => {
      const fd = new FormData()
      fd.append('file', file)
      if (simulate) fd.append('simulate', '1')
      return api.post(`/accounts/bank-accounts/${bankId}/imports`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data).catch(handleErr)
    },
    revert:  (importId) => api.delete(`/accounts/statement-imports/${importId}`).then(r => r.data).catch(handleErr),
  },
  reconciliations: {
    history:     (bankId) => api.get(`/accounts/bank-accounts/${bankId}/reconciliations`).then(r => r.data).catch(handleErr),
    start:       (data) => api.post('/accounts/reconciliations', data).then(r => r.data).catch(handleErr),
    get:         (id) => api.get(`/accounts/reconciliations/${id}`).then(r => r.data).catch(handleErr),
    toggle:      (id, data) => api.post(`/accounts/reconciliations/${id}/toggle`, data).then(r => r.data).catch(handleErr),
    autoSuggest: (id) => api.post(`/accounts/reconciliations/${id}/auto-suggest`).then(r => r.data).catch(handleErr),
    ignoreLine:  (statementLineId) => api.post('/accounts/reconciliations/ignore-line', { statement_line_id: statementLineId }).then(r => r.data).catch(handleErr),
    complete:    (id) => api.post(`/accounts/reconciliations/${id}/complete`).then(r => r.data).catch(handleErr),
    cancel:      (id) => api.delete(`/accounts/reconciliations/${id}`).then(r => r.data).catch(handleErr),
  },
  cheques: {
    list:    (params = {}) => api.get('/accounts/cheques', { params }).then(r => r.data).catch(handleErr),
    summary: () => api.get('/accounts/cheques/summary').then(r => r.data).catch(handleErr),
    due:     () => api.get('/accounts/cheques/due').then(r => r.data).catch(handleErr),
    create:  (data) => api.post('/accounts/cheques', data).then(r => r.data).catch(handleErr),
    update:  (id, data) => api.put(`/accounts/cheques/${id}`, data).then(r => r.data).catch(handleErr),
    changeStatus: (id, status, clearedDate) => api.patch(`/accounts/cheques/${id}/status`, { status, cleared_date: clearedDate }).then(r => r.data).catch(handleErr),
    remove:  (id) => api.delete(`/accounts/cheques/${id}`).then(r => r.data).catch(handleErr),
  },

  // Settings
  companyProfile: {
    get:  () => api.get('/accounts/company-profile').then(r => r.data).catch(handleErr),
    save: (data) => api.post('/accounts/company-profile', data).then(r => r.data).catch(handleErr),
  },
  financialYears: {
    list:   () => api.get('/accounts/financial-years').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/financial-years', data).then(r => r.data).catch(handleErr),
    lock:   (id, isClosed) => api.patch(`/accounts/financial-years/${id}/lock`, { is_closed: isClosed }).then(r => r.data).catch(handleErr),
  },
  billCategories: {
    list:   () => api.get('/accounts/bill-categories').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/bill-categories', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/bill-categories/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/accounts/bill-categories/${id}`).then(r => r.data).catch(handleErr),
  },

  voucherTypes: {
    list:   () => api.get('/accounts/voucher-types').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/accounts/voucher-types', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/voucher-types/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/accounts/voucher-types/${id}`).then(r => r.data).catch(handleErr),
  },
  numberingSeries: {
    list:   () => api.get('/accounts/numbering-series').then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/accounts/numbering-series/${id}`, data).then(r => r.data).catch(handleErr),
  },
  auditLogs: (params = {}) => api.get('/accounts/audit-logs', { params }).then(r => r.data).catch(handleErr),
}

export default accountsApi
