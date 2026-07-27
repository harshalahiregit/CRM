// Purchase Vendor Master — form option lists. Purchase-OWNED and self-contained:
// no import from the shared Vendor / Customer / TPV modules (there is no shared
// static category or country list in the repo to reuse — these are the Purchase
// module's own canonical lists). Keep values in sync with the backend validation
// in StorePurchaseVendorRequest / UpdatePurchaseVendorRequest.

// Vendor categories — the shared Vendor master stores `category` as free text with
// no enum, so the Purchase module defines its own canonical procurement categories.
export const PV_CATEGORIES = [
  'Raw Materials',
  'Finished Goods',
  'Services',
  'Logistics & Transport',
  'IT & Software',
  'Machinery & Equipment',
  'Office Supplies',
  'Maintenance & Repairs',
  'Consultancy',
  'Utilities',
  'Other',
]

// Currency — §6: INR, USD, EUR only.
export const PV_CURRENCIES = ['INR', 'USD', 'EUR']

// Default language — §7: System Default, English.
export const PV_LANGUAGES = ['System Default', 'English']

// Country — no shared/generic country constant exists in the repo; this is the
// Purchase module's own generic country list (superset of the register-page lists).
export const PV_COUNTRIES = [
  'India',
  'United States',
  'United Kingdom',
  'UAE',
  'Singapore',
  'Australia',
  'Germany',
  'France',
]

// Sensible defaults for a fresh vendor form.
export const PV_DEFAULTS = {
  currency: 'INR',
  language: 'System Default',
  vendor_type: 'standard',
}
