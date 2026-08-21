import QualificationQueue from '../components/QualificationQueue'

// §7 Risk & Due Diligence — module-level queue over the existing per-vendor risk
// assessment (VendorRiskPanel / "Risk Score" tab on the vendor workspace).
export default function TpvRiskDueDiligence() {
  return <QualificationQueue mode="risk" />
}
