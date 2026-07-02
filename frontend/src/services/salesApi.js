// Sales & Revenue — Mock API Service
// All functions return mock data via Promise.resolve()
// Replace with real axios calls when backend is ready

const delay = (ms = 300) => new Promise(r => setTimeout(r, ms))

// ── Mock Data ─────────────────────────────────────────────

const CLIENTS = [
  'Mahindra Logistics', 'Tata Consultancy', 'Reliance Industries',
  'Infosys Ltd', 'Wipro Technologies', 'Bharti Airtel', 'HCL Tech',
  'Adani Enterprises', 'Larsen & Toubro', 'Tech Mahindra'
]

const ITEMS_CATALOG = [
  { id: 1, name: 'Web Development', description: 'Full-stack web application development', long_description: 'End-to-end web application development including frontend, backend, and database design', rate: 85000, unit: 'project', tax_rate: 18, category: 'Development' },
  { id: 2, name: 'UI/UX Design', description: 'User interface and experience design', long_description: 'Complete UI/UX design including wireframes, mockups, and prototypes', rate: 45000, unit: 'project', tax_rate: 18, category: 'Design' },
  { id: 3, name: 'Cloud Hosting', description: 'AWS/Azure cloud hosting setup', long_description: 'Cloud infrastructure setup, deployment, and management', rate: 12000, unit: 'monthly', tax_rate: 18, category: 'Infrastructure' },
  { id: 4, name: 'SEO Services', description: 'Search engine optimization', long_description: 'On-page and off-page SEO with monthly reporting', rate: 25000, unit: 'monthly', tax_rate: 18, category: 'Marketing' },
  { id: 5, name: 'Mobile App Dev', description: 'Cross-platform mobile application', long_description: 'React Native / Flutter mobile app development', rate: 150000, unit: 'project', tax_rate: 18, category: 'Development' },
  { id: 6, name: 'Consulting', description: 'IT strategy consulting', long_description: 'Technical consulting and architecture review', rate: 8000, unit: 'hours', tax_rate: 18, category: 'Consulting' },
  { id: 7, name: 'Data Analytics', description: 'Business intelligence & analytics', long_description: 'Dashboard setup, data pipeline, and analytics reporting', rate: 60000, unit: 'project', tax_rate: 18, category: 'Analytics' },
  { id: 8, name: 'Maintenance', description: 'Monthly application maintenance', long_description: 'Bug fixes, security patches, and performance monitoring', rate: 20000, unit: 'monthly', tax_rate: 18, category: 'Support' },
]

const d = (daysAgo) => {
  const dt = new Date()
  dt.setDate(dt.getDate() - daysAgo)
  return dt.toISOString().split('T')[0]
}

const futureD = (daysAhead) => {
  const dt = new Date()
  dt.setDate(dt.getDate() + daysAhead)
  return dt.toISOString().split('T')[0]
}

const PROPOSALS = [
  { id: 1, subject: 'Enterprise Web Portal Development', client: 'Mahindra Logistics', amount: 425000, created_at: d(2), expiry_date: futureD(28), status: 'Sent', currency: 'INR', items: [{ item_id: 1, name: 'Web Development', qty: 1, rate: 85000, tax: 18, amount: 85000 }, { item_id: 2, name: 'UI/UX Design', qty: 1, rate: 45000, tax: 18, amount: 45000 }, { item_id: 5, name: 'Mobile App Dev', qty: 1, rate: 150000, tax: 18, amount: 150000 }], notes: 'Includes 3 months post-launch support' },
  { id: 2, subject: 'Cloud Migration & DevOps Setup', client: 'Tata Consultancy', amount: 280000, created_at: d(5), expiry_date: futureD(25), status: 'Open', currency: 'INR', items: [{ item_id: 3, name: 'Cloud Hosting', qty: 12, rate: 12000, tax: 18, amount: 144000 }], notes: '' },
  { id: 3, subject: 'Digital Marketing Suite', client: 'Infosys Ltd', amount: 175000, created_at: d(8), expiry_date: futureD(22), status: 'Accepted', currency: 'INR', items: [{ item_id: 4, name: 'SEO Services', qty: 6, rate: 25000, tax: 18, amount: 150000 }], notes: 'Q3-Q4 campaign' },
  { id: 4, subject: 'ERP System Consulting', client: 'Wipro Technologies', amount: 96000, created_at: d(12), expiry_date: futureD(18), status: 'Declined', currency: 'INR', items: [{ item_id: 6, name: 'Consulting', qty: 12, rate: 8000, tax: 18, amount: 96000 }], notes: '' },
  { id: 5, subject: 'Data Analytics Platform', client: 'Bharti Airtel', amount: 320000, created_at: d(1), expiry_date: futureD(30), status: 'Sent', currency: 'INR', items: [{ item_id: 7, name: 'Data Analytics', qty: 2, rate: 60000, tax: 18, amount: 120000 }, { item_id: 1, name: 'Web Development', qty: 1, rate: 85000, tax: 18, amount: 85000 }], notes: 'Phase 1 delivery in 45 days' },
  { id: 6, subject: 'Annual Maintenance Contract', client: 'HCL Tech', amount: 240000, created_at: d(15), expiry_date: d(1), status: 'Expired', currency: 'INR', items: [{ item_id: 8, name: 'Maintenance', qty: 12, rate: 20000, tax: 18, amount: 240000 }], notes: '' },
  { id: 7, subject: 'Mobile Commerce App', client: 'Adani Enterprises', amount: 195000, created_at: d(3), expiry_date: futureD(27), status: 'Open', currency: 'INR', items: [{ item_id: 5, name: 'Mobile App Dev', qty: 1, rate: 150000, tax: 18, amount: 150000 }, { item_id: 2, name: 'UI/UX Design', qty: 1, rate: 45000, tax: 18, amount: 45000 }], notes: '' },
  { id: 8, subject: 'IT Infrastructure Audit', client: 'Reliance Industries', amount: 64000, created_at: d(6), expiry_date: futureD(24), status: 'Sent', currency: 'INR', items: [{ item_id: 6, name: 'Consulting', qty: 8, rate: 8000, tax: 18, amount: 64000 }], notes: 'On-site audit required' },
]

const ESTIMATES = [
  { id: 1, subject: 'CRM Implementation', client: 'Larsen & Toubro', amount: 350000, created_at: d(3), valid_until: futureD(15), status: 'Sent', reference: 'EST-2026-001', currency: 'INR' },
  { id: 2, subject: 'Website Redesign', client: 'Tech Mahindra', amount: 125000, created_at: d(7), valid_until: futureD(8), status: 'Accepted', reference: 'EST-2026-002', currency: 'INR' },
  { id: 3, subject: 'API Gateway Setup', client: 'Infosys Ltd', amount: 98000, created_at: d(10), valid_until: futureD(5), status: 'Draft', reference: 'EST-2026-003', currency: 'INR' },
  { id: 4, subject: 'Security Audit', client: 'Bharti Airtel', amount: 72000, created_at: d(14), valid_until: d(2), status: 'Expired', reference: 'EST-2026-004', currency: 'INR' },
  { id: 5, subject: 'Cloud Migration', client: 'Wipro Technologies', amount: 210000, created_at: d(5), valid_until: futureD(20), status: 'Declined', reference: 'EST-2026-005', currency: 'INR' },
  { id: 6, subject: 'E-Commerce Platform', client: 'Adani Enterprises', amount: 480000, created_at: d(1), valid_until: futureD(30), status: 'Sent', reference: 'EST-2026-006', currency: 'INR' },
]

const INVOICES = [
  { id: 1, number: 'INV-2026-001', client: 'Larsen & Toubro', issue_date: d(20), due_date: d(0), amount: 350000, paid: 350000, balance: 0, status: 'Paid', currency: 'INR', recurring: false },
  { id: 2, number: 'INV-2026-002', client: 'Mahindra Logistics', issue_date: d(15), due_date: futureD(15), amount: 425000, paid: 200000, balance: 225000, status: 'Partially Paid', currency: 'INR', recurring: false },
  { id: 3, number: 'INV-2026-003', client: 'Tata Consultancy', issue_date: d(30), due_date: d(5), amount: 280000, paid: 0, balance: 280000, status: 'Overdue', currency: 'INR', recurring: false },
  { id: 4, number: 'INV-2026-004', client: 'Infosys Ltd', issue_date: d(5), due_date: futureD(25), amount: 175000, paid: 0, balance: 175000, status: 'Unpaid', currency: 'INR', recurring: false },
  { id: 5, number: 'INV-2026-005', client: 'Tech Mahindra', issue_date: d(2), due_date: futureD(28), amount: 125000, paid: 0, balance: 125000, status: 'Draft', currency: 'INR', recurring: false },
  { id: 6, number: 'INV-2026-006', client: 'HCL Tech', issue_date: d(10), due_date: futureD(20), amount: 240000, paid: 0, balance: 240000, status: 'Unpaid', currency: 'INR', recurring: true },
  { id: 7, number: 'INV-2026-007', client: 'Reliance Industries', issue_date: d(25), due_date: d(3), amount: 64000, paid: 64000, balance: 0, status: 'Paid', currency: 'INR', recurring: false },
  { id: 8, number: 'INV-2026-008', client: 'Bharti Airtel', issue_date: d(18), due_date: d(8), amount: 320000, paid: 0, balance: 320000, status: 'Overdue', currency: 'INR', recurring: false },
  { id: 9, number: 'INV-2026-009', client: 'Wipro Technologies', issue_date: d(1), due_date: futureD(29), amount: 96000, paid: 0, balance: 96000, status: 'Unpaid', currency: 'INR', recurring: false },
  { id: 10, number: 'INV-2026-010', client: 'Adani Enterprises', issue_date: d(12), due_date: futureD(18), amount: 195000, paid: 0, balance: 195000, status: 'Cancelled', currency: 'INR', recurring: false },
]

const DELIVERY_NOTES = [
  { id: 1, number: 'DN-001', invoice_number: 'INV-2026-001', client: 'Larsen & Toubro', items_count: 3, delivery_date: d(18), status: 'Delivered' },
  { id: 2, number: 'DN-002', invoice_number: 'INV-2026-002', client: 'Mahindra Logistics', items_count: 2, delivery_date: futureD(3), status: 'Sent' },
  { id: 3, number: 'DN-003', invoice_number: 'INV-2026-006', client: 'HCL Tech', items_count: 1, delivery_date: futureD(10), status: 'Draft' },
  { id: 4, number: 'DN-004', invoice_number: 'INV-2026-007', client: 'Reliance Industries', items_count: 1, delivery_date: d(22), status: 'Delivered' },
  { id: 5, number: 'DN-005', invoice_number: 'INV-2026-010', client: 'Adani Enterprises', items_count: 2, delivery_date: d(10), status: 'Cancelled' },
]

const PAYMENTS = [
  { id: 1, invoice_number: 'INV-2026-001', client: 'Larsen & Toubro', date: d(18), amount: 175000, mode: 'Bank Transfer', reference: 'NEFT-78234', gateway: null },
  { id: 2, invoice_number: 'INV-2026-001', client: 'Larsen & Toubro', date: d(10), amount: 175000, mode: 'Bank Transfer', reference: 'NEFT-78901', gateway: null },
  { id: 3, invoice_number: 'INV-2026-002', client: 'Mahindra Logistics', date: d(8), amount: 200000, mode: 'Razorpay', reference: 'pay_LmN9xQ', gateway: 'Razorpay' },
  { id: 4, invoice_number: 'INV-2026-007', client: 'Reliance Industries', date: d(20), amount: 64000, mode: 'Cheque', reference: 'CHQ-445512', gateway: null },
  { id: 5, invoice_number: 'INV-2026-003', client: 'Tata Consultancy', date: d(25), amount: 100000, mode: 'Stripe', reference: 'ch_3Mq', gateway: 'Stripe' },
]

const CREDIT_NOTES = [
  { id: 1, number: 'CN-001', client: 'Larsen & Toubro', invoice_number: 'INV-2026-001', amount: 15000, date: d(5), status: 'Open', reason: 'Service scope reduction' },
  { id: 2, number: 'CN-002', client: 'Reliance Industries', invoice_number: 'INV-2026-007', amount: 8000, date: d(12), status: 'Open', reason: 'Billing error adjustment' },
  { id: 3, number: 'CN-003', client: 'Tata Consultancy', invoice_number: 'INV-2026-003', amount: 25000, date: d(20), status: 'Void', reason: 'Cancelled refund request' },
]

// ── Dashboard Stats ───────────────────────────────────────

const DASHBOARD = {
  kpis: {
    total_revenue: 789000,
    open_invoices: 5,
    pending_proposals: 4,
    overdue_payments: 2,
    accepted_estimates: 2,
    credit_notes_issued: 2,
    conversion_rate: 68,
    monthly_target: 1200000,
  },
  revenue_by_month: [
    { month: 'Jan', amount: 320000 },
    { month: 'Feb', amount: 480000 },
    { month: 'Mar', amount: 560000 },
    { month: 'Apr', amount: 420000 },
    { month: 'May', amount: 710000 },
    { month: 'Jun', amount: 789000 },
  ],
  pipeline: [
    { stage: 'Proposals', count: 8, value: 1795000 },
    { stage: 'Estimates', count: 6, value: 1335000 },
    { stage: 'Invoiced', count: 10, value: 2270000 },
    { stage: 'Paid', count: 3, value: 589000 },
  ],
  recent_invoices: INVOICES.slice(0, 5),
  top_clients: [
    { name: 'Mahindra Logistics', revenue: 425000 },
    { name: 'Larsen & Toubro', revenue: 350000 },
    { name: 'Bharti Airtel', revenue: 320000 },
    { name: 'Tata Consultancy', revenue: 280000 },
    { name: 'HCL Tech', revenue: 240000 },
  ],
}

// ── Export API (mock) ─────────────────────────────────────

export const salesApi = {
  dashboard: {
    get: async () => { await delay(); return DASHBOARD },
  },
  proposals: {
    list: async (params = {}) => {
      await delay()
      let data = [...PROPOSALS]
      if (params.status && params.status !== 'All') data = data.filter(p => p.status === params.status)
      if (params.search) data = data.filter(p => p.subject.toLowerCase().includes(params.search.toLowerCase()) || p.client.toLowerCase().includes(params.search.toLowerCase()))
      return data
    },
    get: async (id) => { await delay(); return PROPOSALS.find(p => p.id === Number(id)) },
    create: async (data) => { await delay(); return { id: PROPOSALS.length + 1, ...data, created_at: d(0), status: 'Open' } },
    update: async (id, data) => { await delay(); return { ...PROPOSALS.find(p => p.id === Number(id)), ...data } },
    delete: async (id) => { await delay(); return { success: true } },
    send: async (id) => { await delay(); return { ...PROPOSALS.find(p => p.id === Number(id)), status: 'Sent' } },
    updateStatus: async (id, status) => { await delay(); return { ...PROPOSALS.find(p => p.id === Number(id)), status } },
  },
  estimates: {
    list: async (params = {}) => {
      await delay()
      let data = [...ESTIMATES]
      if (params.status && params.status !== 'All') data = data.filter(e => e.status === params.status)
      return data
    },
    get: async (id) => { await delay(); return ESTIMATES.find(e => e.id === Number(id)) },
    create: async (data) => { await delay(); return { id: ESTIMATES.length + 1, ...data, created_at: d(0), status: 'Draft' } },
    update: async (id, data) => { await delay(); return { ...ESTIMATES.find(e => e.id === Number(id)), ...data } },
    delete: async (id) => { await delay(); return { success: true } },
    send: async (id) => { await delay(); return { ...ESTIMATES.find(e => e.id === Number(id)), status: 'Sent' } },
    convertToInvoice: async (id) => { await delay(); return { invoice_id: INVOICES.length + 1 } },
  },
  invoices: {
    list: async (params = {}) => {
      await delay()
      let data = [...INVOICES]
      if (params.status && params.status !== 'All') data = data.filter(i => i.status === params.status)
      return data
    },
    get: async (id) => { await delay(); return INVOICES.find(i => i.id === Number(id)) },
    create: async (data) => { await delay(); return { id: INVOICES.length + 1, ...data, status: 'Draft' } },
    update: async (id, data) => { await delay(); return { ...INVOICES.find(i => i.id === Number(id)), ...data } },
    delete: async (id) => { await delay(); return { success: true } },
    send: async (id) => { await delay(); return { ...INVOICES.find(i => i.id === Number(id)), status: 'Unpaid' } },
    recordPayment: async (id, paymentData) => { await delay(); return { success: true, payment_id: PAYMENTS.length + 1 } },
  },
  deliveryNotes: {
    list: async (params = {}) => {
      await delay()
      let data = [...DELIVERY_NOTES]
      if (params.status && params.status !== 'All') data = data.filter(dn => dn.status === params.status)
      return data
    },
    get: async (id) => { await delay(); return DELIVERY_NOTES.find(dn => dn.id === Number(id)) },
    create: async (data) => { await delay(); return { id: DELIVERY_NOTES.length + 1, ...data, status: 'Draft' } },
    markDelivered: async (id) => { await delay(); return { ...DELIVERY_NOTES.find(dn => dn.id === Number(id)), status: 'Delivered' } },
    delete: async (id) => { await delay(); return { success: true } },
  },
  payments: {
    list: async (params = {}) => {
      await delay()
      let data = [...PAYMENTS]
      if (params.mode) data = data.filter(p => p.mode === params.mode)
      return data
    },
    record: async (data) => { await delay(); return { id: PAYMENTS.length + 1, ...data, date: d(0) } },
    delete: async (id) => { await delay(); return { success: true } },
  },
  creditNotes: {
    list: async (params = {}) => {
      await delay()
      let data = [...CREDIT_NOTES]
      if (params.status && params.status !== 'All') data = data.filter(cn => cn.status === params.status)
      return data
    },
    get: async (id) => { await delay(); return CREDIT_NOTES.find(cn => cn.id === Number(id)) },
    create: async (data) => { await delay(); return { id: CREDIT_NOTES.length + 1, ...data, status: 'Open' } },
    applyToInvoice: async (id, invoiceId) => { await delay(); return { success: true } },
    void: async (id) => { await delay(); return { ...CREDIT_NOTES.find(cn => cn.id === Number(id)), status: 'Void' } },
  },
  items: {
    list: async (params = {}) => {
      await delay()
      let data = [...ITEMS_CATALOG]
      if (params.category) data = data.filter(i => i.category === params.category)
      if (params.search) data = data.filter(i => i.name.toLowerCase().includes(params.search.toLowerCase()))
      return data
    },
    get: async (id) => { await delay(); return ITEMS_CATALOG.find(i => i.id === Number(id)) },
    create: async (data) => { await delay(); return { id: ITEMS_CATALOG.length + 1, ...data } },
    update: async (id, data) => { await delay(); return { ...ITEMS_CATALOG.find(i => i.id === Number(id)), ...data } },
    delete: async (id) => { await delay(); return { success: true } },
  },
  clients: CLIENTS,
}
