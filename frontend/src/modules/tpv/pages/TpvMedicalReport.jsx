import MedicalReport from '@/components/medical/MedicalReport'

/**
 * The Medical report, TPV side. Shared with Purchase for the same reason the
 * register is: the two are one screen over two databases.
 */
export default function TpvMedicalReport() {
  return <MedicalReport module="tpv" accent="#a78bfa" />
}
