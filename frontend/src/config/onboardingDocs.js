// Onboarding document requirements — single source of truth shared by the
// candidate portal and the HR verification panel. The `req` field drives the
// 🔴 Mandatory / 🟢 Optional badges and the approval gate.
//
//   mandatory    → required for every candidate
//   conditional  → required only when the candidate has prior experience (> 0 yrs)
//   optional     → nice-to-have, never blocks approval
export const ONBOARDING_DOCS = [
  { key: 'aadhaar', label: 'Aadhaar Card', req: 'mandatory' },
  { key: 'pan', label: 'PAN Card', req: 'mandatory' },
  { key: 'resume', label: 'Resume', req: 'mandatory' },
  { key: 'photo', label: 'Passport Photo', req: 'mandatory' },
  { key: 'address_proof', label: 'Address Proof', req: 'mandatory' },
  { key: 'educational_certificate', label: 'Educational Certificates', req: 'mandatory' },
  { key: 'experience_document', label: 'Previous Employment Documents', req: 'conditional' },
  { key: 'medical', label: 'Medical Documents', req: 'optional' },
  { key: 'vaccination', label: 'Vaccination Certificate', req: 'optional' },
  { key: 'cancelled_cheque', label: 'Cancelled Cheque', req: 'optional' },
  { key: 'company_document', label: 'Company Documents', req: 'optional' },
  { key: 'other', label: 'Other Documents', req: 'optional' },
]

export const DOC_LABEL = Object.fromEntries(ONBOARDING_DOCS.map(d => [d.key, d.label]))

// Prior work experience (> 0 years) makes Previous Employment Documents mandatory.
export const hasExperience = (experienceYears) => Number(experienceYears || 0) > 0

// Is a given document type mandatory for this specific candidate?
export const isDocMandatory = (key, experienceYears) => {
  const d = ONBOARDING_DOCS.find(x => x.key === key)
  if (!d) return false
  if (d.req === 'mandatory') return true
  if (d.req === 'conditional') return hasExperience(experienceYears)
  return false
}

// Ordered list of the mandatory document keys for this candidate.
export const mandatoryDocKeys = (experienceYears) =>
  ONBOARDING_DOCS
    .filter(d => d.req === 'mandatory' || (d.req === 'conditional' && hasExperience(experienceYears)))
    .map(d => d.key)
