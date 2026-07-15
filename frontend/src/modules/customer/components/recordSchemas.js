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
    { key: 'category', label: 'Category', type: 'text' },
    { key: 'amount', label: 'Amount', type: 'money' },
    { key: 'date', label: 'Date', type: 'date' },
    { key: 'payment_mode', label: 'Payment Mode', type: 'text' },
    { key: 'billable', label: 'Billable', type: 'checkbox' },
    { key: 'note', label: 'Note', type: 'textarea' },
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
