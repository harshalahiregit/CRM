import MedicalRegister from '@/components/medical/MedicalRegister'

/**
 * Sangoe TPV §3/§16 — Medical Fitness register, now the Medical module's TPV
 * face: the examinations, the quality check, the timeline with the vendor and
 * the external intake.
 *
 * The screen itself is shared with the Purchase side (components/medical/
 * MedicalRegister) because the two registers are the same screen over two
 * databases — the previous arrangement, two hand-maintained copies of one
 * table, is exactly how parity quietly stops being true.
 */
export default function TpvMedicalFitness() {
  return <MedicalRegister module="tpv" accent="#a78bfa" />
}
