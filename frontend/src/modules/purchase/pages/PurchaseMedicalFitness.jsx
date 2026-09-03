import MedicalRegister from '@/components/medical/MedicalRegister'

/**
 * Purchase Medical Fitness register — the Purchase face of the Medical module,
 * and the mirror of the TPV one.
 *
 * It reads /purchase/medical (tenant-wide, like TPV) rather than the older
 * vendor-scoped /purchase/workforce/medicals: a quality reviewer works a queue
 * across vendors, and having to pick a vendor before seeing what is waiting made
 * that queue invisible.
 */
export default function PurchaseMedicalFitness() {
  return <MedicalRegister module="purchase" accent="#38bdf8" />
}
