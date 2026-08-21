// Field schemas for the simple per-customer record tabs (Contracts, Expenses,
// Subscriptions, Pre-Alerts, Packages, Shipments) — driven into the generic
// RecordTab. `columns` = list table; `fields` = add/edit form. Field types:
// text | number | money | date | textarea | checkbox | select(options).
// Column types: text | money | date | bool.

const STATUS = (opts) => ({ key: 'status', label: 'Status', type: 'select', options: opts })

export const CONTRACTS = {
  apiKey: 'contracts', title: 'Contracts', addLabel: 'New Contract',
  columns: [
    { key: 'subject', label: 'Subject', bold: true },
    { key: 'contract_type', label: 'Type' },
    { key: 'value', label: 'Value', type: 'money' },
    { key: 'start_date', label: 'Start', type: 'date' },
    { key: 'end_date', label: 'End', type: 'date' },
    { key: 'status', label: 'Status' },
  ],
  fields: [
    { key: 'subject', label: 'Subject', type: 'text', required: true },
    { key: 'contract_type', label: 'Contract Type', type: 'text' },
    { key: 'value', label: 'Value', type: 'money' },
    { key: 'start_date', label: 'Start Date', type: 'date' },
    { key: 'end_date', label: 'End Date', type: 'date' },
    STATUS(['Draft', 'Active', 'Expired']),
    { key: 'description', label: 'Description', type: 'textarea' },
  ],
}

export const EXPENSES = {
  apiKey: 'expenses', title: 'Expenses', addLabel: 'New Expense',
  columns: [
    { key: 'name', label: 'Name', bold: true },
    { key: 'category', label: 'Category' },
    { key: 'amount', label: 'Amount', type: 'money' },
    { key: 'date', label: 'Date', type: 'date' },
    { key: 'billable', label: 'Billable', type: 'bool' },
  ],
  fields: [
    { key: 'name', label: 'Name', type: 'text', required: true },
    { key: 'category', label: 'Category', type: 'select', optionsKey: 'expenseCategories', placeholder: 'Select category…' },
    { key: 'amount', label: 'Amount', type: 'money' },
    { key: 'date', label: 'Date', type: 'date' },
    { key: 'payment_mode', label: 'Payment Mode', type: 'text' },
    { key: 'billable', label: 'Billable', type: 'checkbox' },
    { key: 'project_id', label: 'Project', type: 'select', optionsKey: 'projects', showIf: { key: 'billable', value: true }, placeholder: 'No project', helpText: 'Projects module coming soon — the link activates when it ships.' },
    { key: 'note', label: 'Note', type: 'richtext', placeholder: 'Details, approvals, receipts reference…' },
  ],
}

export const SUBSCRIPTIONS = {
  apiKey: 'subscriptions', title: 'Subscriptions', addLabel: 'New Subscription',
  columns: [
    { key: 'name', label: 'Name', bold: true },
    { key: 'amount', label: 'Amount', type: 'money' },
    { key: 'quantity', label: 'Qty' },
    { key: 'interval', label: 'Interval' },
    { key: 'status', label: 'Status' },
    { key: 'next_billing_date', label: 'Next Billing', type: 'date' },
  ],
  fields: [
    { key: 'name', label: 'Name', type: 'text', required: true },
    { key: 'amount', label: 'Amount', type: 'money' },
    { key: 'quantity', label: 'Quantity', type: 'number' },
    { key: 'interval', label: 'Interval', type: 'select', options: ['Monthly', 'Quarterly', 'Yearly'] },
    STATUS(['Active', 'Cancelled']),
    { key: 'next_billing_date', label: 'Next Billing Date', type: 'date' },
    { key: 'description', label: 'Description', type: 'textarea' },
  ],
}

export const PRE_ALERTS = {
  apiKey: 'preAlerts', title: 'Pre-Alerts', addLabel: 'New Pre-Alert',
  columns: [
    { key: 'tracking_number', label: 'Tracking #', bold: true },
    { key: 'courier_company', label: 'Courier' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'purchase_price', label: 'Price', type: 'money' },
    { key: 'delivery_date', label: 'Delivery', type: 'date' },
    { key: 'status', label: 'Status' },
  ],
  fields: [
    { key: 'tracking_number', label: 'Tracking Number', type: 'text', required: true },
    { key: 'courier_company', label: 'Courier Company', type: 'text' },
    { key: 'supplier', label: 'Supplier', type: 'text' },
    { key: 'purchase_price', label: 'Purchase Price', type: 'money' },
    { key: 'delivery_date', label: 'Delivery Date', type: 'date' },
    { key: 'status', label: 'Status', type: 'text' },
    { key: 'description', label: 'Description', type: 'textarea' },
  ],
}

export const PACKAGES = {
  apiKey: 'packages', title: 'Packages', addLabel: 'New Package',
  columns: [
    { key: 'package_number', label: 'Package #', bold: true },
    { key: 'courier_company', label: 'Courier' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'value', label: 'Value', type: 'money' },
    { key: 'weight', label: 'Weight' },
    { key: 'status', label: 'Status' },
    { key: 'date', label: 'Date', type: 'date' },
  ],
  fields: [
    { key: 'package_number', label: 'Package Number', type: 'text', required: true },
    { key: 'description', label: 'Description', type: 'text' },
    { key: 'courier_company', label: 'Courier Company', type: 'text' },
    { key: 'supplier', label: 'Supplier', type: 'text' },
    { key: 'value', label: 'Value', type: 'money' },
    { key: 'weight', label: 'Weight', type: 'text' },
    { key: 'status', label: 'Status', type: 'text' },
    { key: 'date', label: 'Date', type: 'date' },
  ],
}

export const SHIPMENTS = {
  apiKey: 'shipments', title: 'Shipments', addLabel: 'New Shipment',
  columns: [
    { key: 'shipment_number', label: 'Shipment #', bold: true },
    { key: 'origin', label: 'Origin' },
    { key: 'destination', label: 'Destination' },
    { key: 'courier_company', label: 'Courier' },
    { key: 'status', label: 'Status' },
    { key: 'date', label: 'Date', type: 'date' },
  ],
  fields: [
    { key: 'shipment_number', label: 'Shipment Number', type: 'text', required: true },
    { key: 'origin', label: 'Origin', type: 'text' },
    { key: 'destination', label: 'Destination', type: 'text' },
    { key: 'courier_company', label: 'Courier Company', type: 'text' },
    { key: 'weight', label: 'Weight', type: 'text' },
    { key: 'value', label: 'Value', type: 'money' },
    { key: 'status', label: 'Status', type: 'text' },
    { key: 'date', label: 'Date', type: 'date' },
  ],
}


// ── Customer 360 ─────────────────────────────────────────────────────────────

/** §4 — every touch that is not a full meeting. */
export const ACTIVITIES = {
  apiKey: 'activities', title: 'Activities', addLabel: 'Log Activity',
  columns: [
    { key: 'occurred_at', label: 'When', type: 'datetime' },
    { key: 'type', label: 'Type', bold: true },
    { key: 'subject', label: 'Subject' },
    { key: 'direction', label: 'Direction' },
    { key: 'outcome', label: 'Outcome' },
    { key: 'follow_up_on', label: 'Follow-up', type: 'date' },
  ],
  fields: [
    { key: 'type', label: 'Type', type: 'select', required: true,
      options: ['Call', 'Email', 'WhatsApp', 'Visit', 'Meeting', 'Follow-up', 'Note', 'Escalation'] },
    { key: 'direction', label: 'Direction', type: 'select', options: ['Inbound', 'Outbound'] },
    { key: 'subject', label: 'Subject', type: 'text', required: true },
    { key: 'occurred_at', label: 'When', type: 'datetime', required: true, defaultNow: true },
    { key: 'client_contact_id', label: 'Contact', type: 'select', optionsKey: 'contacts', placeholder: 'Whole company' },
    { key: 'outcome', label: 'Outcome', type: 'select',
      options: ['Connected', 'No answer', 'Left message', 'Rescheduled', 'Information shared', 'Resolved', 'Needs follow-up'] },
    { key: 'duration_minutes', label: 'Duration (minutes)', type: 'number' },
    { key: 'follow_up_on', label: 'Follow up on', type: 'date',
      helpText: 'Leave blank if nothing is owed. Overdue follow-ups count against Customer Health.' },
    { key: 'follow_up_done', label: 'Follow-up done', type: 'checkbox' },
    { key: 'summary', label: 'Summary', type: 'richtext' },
  ],
}

/** §17 SERVICE — complaints and the escalations they become. */
export const COMPLAINTS = {
  apiKey: 'complaints', title: 'Complaints & Escalations', addLabel: 'Log Complaint',
  columns: [
    { key: 'raised_at', label: 'Raised', type: 'datetime' },
    { key: 'kind', label: 'Kind', bold: true },
    { key: 'subject', label: 'Subject' },
    { key: 'category', label: 'Category' },
    { key: 'severity', label: 'Severity' },
    { key: 'status', label: 'Status' },
    { key: 'resolved_at', label: 'Resolved', type: 'datetime' },
  ],
  fields: [
    { key: 'kind', label: 'Kind', type: 'select', required: true, options: ['Complaint', 'Escalation'],
      helpText: 'Raising an existing complaint to an escalation edits it — do not create a second record.' },
    { key: 'subject', label: 'Subject', type: 'text', required: true },
    { key: 'raised_at', label: 'Raised at', type: 'datetime', required: true, defaultNow: true },
    { key: 'category', label: 'Category', type: 'select',
      options: ['Service', 'Delivery', 'Billing', 'Quality', 'Conduct', 'Compliance', 'Other'] },
    { key: 'severity', label: 'Severity', type: 'select', options: ['Low', 'Medium', 'High', 'Critical'] },
    { key: 'status', label: 'Status', type: 'select', options: ['Open', 'Investigating', 'Resolved', 'Closed'] },
    { key: 'owner_id', label: 'Owner', type: 'select', optionsKey: 'staff', placeholder: 'Unassigned' },
    { key: 'resolved_at', label: 'Resolved at', type: 'datetime' },
    { key: 'reference', label: 'Reference', type: 'text' },
    { key: 'description', label: 'Description', type: 'richtext' },
    { key: 'resolution', label: 'Resolution', type: 'richtext' },
  ],
}

/** Domain Manager — the screen exists for the expiry dates. */
export const DOMAINS = {
  apiKey: 'domains', title: 'Domains', addLabel: 'Add Domain',
  columns: [
    { key: 'domain', label: 'Domain', bold: true },
    { key: 'registrar', label: 'Registrar' },
    { key: 'expires_on', label: 'Expires', type: 'date' },
    { key: 'ssl_expires_on', label: 'SSL Expires', type: 'date' },
    { key: 'auto_renew', label: 'Auto-renew', type: 'bool' },
    { key: 'status', label: 'Status' },
  ],
  fields: [
    { key: 'domain', label: 'Domain', type: 'text', required: true },
    { key: 'registrar', label: 'Registrar', type: 'text' },
    { key: 'registered_on', label: 'Registered on', type: 'date' },
    { key: 'expires_on', label: 'Expires on', type: 'date',
      helpText: 'Warned about 30 days ahead, auto-renew or not — auto-renew fails often enough to matter.' },
    { key: 'auto_renew', label: 'Auto-renew', type: 'checkbox' },
    { key: 'dns_provider', label: 'DNS provider', type: 'text' },
    { key: 'hosting_provider', label: 'Hosting provider', type: 'text' },
    { key: 'ssl_expires_on', label: 'SSL expires on', type: 'date' },
    { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Expiring', 'Expired', 'Transferred', 'Cancelled'] },
    { key: 'notes', label: 'Notes', type: 'textarea' },
  ],
}

/**
 * §17 COMMERCIAL — the PO the CUSTOMER issued to us.
 *
 * Not the Purchase module's orders to vendors. `consumed` is deliberately not a
 * form field: what has been billed is Sales' fact, not a number to assert.
 */
export const CUSTOMER_PURCHASE_ORDERS = {
  apiKey: 'purchaseOrders', title: 'Purchase Orders', addLabel: 'Add Purchase Order',
  columns: [
    { key: 'po_number', label: 'PO Number', bold: true },
    { key: 'po_date', label: 'Date', type: 'date' },
    { key: 'valid_until', label: 'Valid Until', type: 'date' },
    { key: 'value', label: 'Value', type: 'money' },
    { key: 'consumed', label: 'Billed', type: 'money' },
    { key: 'status', label: 'Status' },
  ],
  fields: [
    { key: 'po_number', label: 'PO Number', type: 'text', required: true },
    { key: 'po_date', label: 'PO Date', type: 'date' },
    { key: 'valid_until', label: 'Valid Until', type: 'date',
      helpText: 'An expired PO with value left raises an alert — work may be happening with no authority to bill.' },
    { key: 'value', label: 'Value', type: 'money', required: true },
    { key: 'currency', label: 'Currency', type: 'text' },
    { key: 'status', label: 'Status', type: 'select',
      options: ['Open', 'Partially Billed', 'Exhausted', 'Closed', 'Cancelled'] },
    { key: 'scope', label: 'Scope', type: 'richtext' },
    { key: 'notes', label: 'Notes', type: 'textarea' },
  ],
}
