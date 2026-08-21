import QualificationQueue from '../components/QualificationQueue'

// §6 Prequalification — module-level queue over the existing per-vendor scored
// questionnaire (VendorPrequalificationPanel on the vendor workspace).
export default function TpvPrequalification() {
  return <QualificationQueue mode="prequalification" />
}
