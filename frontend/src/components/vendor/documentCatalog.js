/**
 * What each engine calls its statutory documents.
 *
 * Both engines use the SAME type keys. `Vendor\VendorDocument::TYPE_LABELS` and
 * `Purchase\PurchaseDocument::TYPE_LABELS` are the same twelve strings, so the
 * two catalogs below differ only in wording, never in keys.
 *
 * This file used to claim otherwise — that TPV said `company_pan`, `pf_no`,
 * `esic_no`, `bocw_registration`, `udyam_certificate` — and the TPV catalog was
 * built on those five invented keys plus `other` and `subcontractor_decl`. No
 * such type has ever existed in the TPV backend: `VendorDocumentService::
 * allowedTypes()` rejects all seven with "Unknown document type". The effect on
 * a standard TPV vendor's Documents tab was seven rows that could never be
 * filled (upload threw), while the five real requirements — pan, pf, esic,
 * bocw, udyam — arrived as types the catalog had never heard of and were
 * appended, unlabelled, under "Other Documents". Sixteen rows for eleven
 * documents, seven of them dead.
 *
 * The catalog is what turns a flat checklist into the grouped, filterable panel
 * the vendor actually uses: a label, a category to file it under, and whether
 * it is mandatory. `VendorDocumentsPanel` renders whichever catalog it is given
 * and knows nothing else about either engine.
 */

const COMPANY    = 'Company Documents'
const COMPLIANCE = 'Compliance Documents'
const FINANCIAL  = 'Financial Documents'
const OTHER      = 'Other Documents'

export const DOC_CATEGORY_ORDER = [COMPANY, COMPLIANCE, FINANCIAL, OTHER]

/** TPV — mirrors Vendor\VendorDocument::TYPE_LABELS, key for key. */
export const TPV_DOC_CATALOG = [
  { type: 'company_registration', label: 'Company Registration Certificate', required: true,  category: COMPANY },
  { type: 'pan',                  label: 'Company PAN Card',                 required: true,  category: COMPANY },
  { type: 'gst',                  label: 'GST Certificate',                  required: true,  category: COMPANY },
  { type: 'udyam',                label: 'Udyam Certificate',                required: true,  category: COMPANY },
  { type: 'insurance_wcp',        label: 'Insurance [WCP]',                  required: true,  category: COMPLIANCE },
  { type: 'pf',                   label: 'PF Registration',                  required: true,  category: COMPLIANCE },
  { type: 'esic',                 label: 'ESIC Registration',                required: true,  category: COMPLIANCE },
  { type: 'bocw',                 label: 'BOCW Registration',                required: true,  category: COMPLIANCE },
  { type: 'clr',                  label: 'CLR [Contract Labour Registration]', required: true, category: COMPLIANCE },
  { type: 'mlwf',                 label: 'MLWF [Maharashtra Labour Welfare]', required: true, category: COMPLIANCE },
  { type: 'mscb',                 label: 'MSCB Certificate',                 required: true,  category: COMPLIANCE },
  { type: 'loi_wo_po',            label: 'LOI / WO / PO',                    required: false, category: FINANCIAL },
]

/**
 * Purchase — mirrors Purchase\PurchaseDocument::TYPE_LABELS.
 *
 * `loi_wo_po` belongs to the TEMPORARY set only, so it is not marked required:
 * whether it is actually demanded of this vendor comes from the server's
 * checklist, which the panel treats as the authority. The catalog only supplies
 * the label, the grouping, and the default.
 */
export const PURCHASE_DOC_CATALOG = [
  { type: 'company_registration', label: 'Company Registration Certificate', required: true,  category: COMPANY },
  { type: 'pan',                  label: 'Company PAN Card',                 required: true,  category: COMPANY },
  { type: 'gst',                  label: 'GST Certificate',                  required: true,  category: COMPANY },
  { type: 'udyam',                label: 'Udyam Certificate',                required: true,  category: COMPANY },
  { type: 'insurance_wcp',        label: 'Insurance [WCP]',                  required: true,  category: COMPLIANCE },
  { type: 'pf',                   label: 'PF Registration',                  required: true,  category: COMPLIANCE },
  { type: 'esic',                 label: 'ESIC Registration',                required: true,  category: COMPLIANCE },
  { type: 'bocw',                 label: 'BOCW Registration',                required: true,  category: COMPLIANCE },
  { type: 'clr',                  label: 'CLRA / Labour Licence',            required: true,  category: COMPLIANCE },
  { type: 'mlwf',                 label: 'MLWF [Maharashtra Labour Welfare]', required: true, category: COMPLIANCE },
  { type: 'mscb',                 label: 'MSCB Certificate',                 required: true,  category: COMPLIANCE },
  { type: 'loi_wo_po',            label: 'LOI / WO / PO',                    required: false, category: FINANCIAL },
]

/**
 * Partners a vendor can be handed off to when it does not hold a document yet.
 *
 * A missing registration is the single most common reason onboarding stalls,
 * and "you are missing this" is not, on its own, something the vendor can act
 * on. Same list for both engines — it is about the paperwork, not the module.
 */
export const COMPLIANCE_PROVIDERS = [
  {
    id: 'business_badhega',
    name: 'BusinessBadhega.com',
    desc: 'Registration & compliance experts',
    badge: 'Recommended',
    badgeTone: 'orange',
    logoBg: 'linear-gradient(135deg, #f97316, #ea580c)',
    logoText: 'BB',
  },
  {
    id: 'legaldesk',
    name: 'LegalDesk',
    desc: 'Legal documentation & CA services',
    badge: 'New',
    badgeTone: 'green',
    logoBg: 'linear-gradient(135deg, #10b981, #059669)',
    logoText: 'LD',
  },
  {
    id: 'vakilsearch',
    name: 'VakilSearch',
    desc: 'CA & CS assisted registrations',
    badge: 'Partner',
    badgeTone: 'purple',
    logoBg: 'linear-gradient(135deg, #7C3AED, #5b21b6)',
    logoText: 'VS',
  },
]

/** The category a type files under, for a row the catalog has never seen. */
export const categoryOf = (catalog, type) =>
  catalog.find(d => d.type === type)?.category || OTHER
