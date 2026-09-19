import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { purchaseApi } from '@/services/purchaseApi'
import { fmtDate } from '@/modules/purchase/constants'
import VendorAddModal from '@/modules/tpv/components/VendorAddModal'
import { useVendorWorkspace } from './vendorWorkspaceContext'
import { VendorScopedList, linkBtn } from './vendorDetailShared'
import { useDoctorOptions, doctorSelectOptions } from '@/components/medical/InternalDoctorSelect'

/**
 * The Workforce group on a Purchase vendor: roster, medical, training, gate log
 * and safety strikes.
 *
 * Grouped the way TPV groups them. Medical and Training used to sit under
 * General as read-only tables with no way to add a record — so an admin who had
 * just examined a worker had to leave the vendor and go hunting for the worker
 * wizard, while the equivalent TPV tab simply had a button. Workforce, Gate Log
 * and Strikes had no entry at all.
 *
 * Every form here records against a worker chosen from THIS vendor's own
 * roster, which is what makes it impossible to file a medical against another
 * vendor's worker by mistake.
 *
 * The add forms are the shared VendorAddModal — the same component the TPV
 * workspace uses — pointed at Purchase's existing endpoints. A second copy of
 * the form would be a second copy of the rules.
 */

/** This vendor's workers, for the pickers below. */
function useVendorWorkers(vendorId) {
  const [workers, setWorkers] = useState([])

  const load = useCallback(() => {
    purchaseApi.workforce.workers({ vendor_id: vendorId })
      .then(r => setWorkers(r?.data ?? r ?? []))
      .catch(() => setWorkers([]))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  return workers
}

const workerOptions = (workers) => workers.map(w => ({
  value: w.id,
  label: `${w.full_name || w.name}${w.worker_code ? ` · ${w.worker_code}` : ''}`,
}))

/** Said once, because all three forms depend on the roster existing. */
const noWorkers = (workers, thing) => (workers.length
  ? null
  : `This vendor has no workers yet. A ${thing} is recorded against a worker, so add one on the Workforce tab first.`)

/* ── The roster ──────────────────────────────────────────────────────────── */

export function WorkforceTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()

  return (
    <VendorScopedList
      key={`wf-${vendor.id}`}
      title="Workforce"
      fetcher={(vid) => purchaseApi.workforce.workers({ vendor_id: vid })}
      emptyText="This vendor has no workers registered yet"
      statusCfg={(st) => ({
        label: st || '—',
        color: st === 'Active' ? '#10b981' : st === 'Terminated' ? '#ef4444' : '#f59e0b',
        bg: st === 'Active' ? 'rgba(16,185,129,0.15)'
          : st === 'Terminated' ? 'rgba(239,68,68,0.15)' : 'rgba(245,158,11,0.15)',
      })}
      onRowClick={(r) => navigate(`/app/purchase/workers/${r.id}`)}
      // The worker wizard is the real create screen — five steps with the
      // evidence each one needs. A reduced modal here would be a second,
      // weaker way to register somebody.
      addLabel="Add Worker"
      onAdd={() => navigate(`/app/purchase/workers/new?vendor=${vendor.id}`)}
      columns={[
        { header: 'Worker', strong: true, cell: (r) => r.full_name || r.name || '—' },
        { header: 'Code', cell: (r) => r.worker_code || '—' },
        { header: 'Trade', cell: (r) => r.designation || r.trade || '—' },
        { header: 'Phone', cell: (r) => r.phone || '—' },
      ]}
    />
  )
}

/* ── Medical ─────────────────────────────────────────────────────────────── */

/**
 * Purchase keeps medicals NORMALISED — one row per record, not one per worker —
 * so this lists the history rather than projecting a latest value.
 */
export function MedicalTab() {
  const { vendor } = useVendorWorkspace()
  const workers = useVendorWorkers(vendor.id)
  const doctors = useDoctorOptions('purchase')

  return (
    <VendorScopedList
      key={`med-${vendor.id}`}
      title="Medical Records"
      fetcher={(vid) => purchaseApi.workforce.medicals(vid)}
      statusCfg={(s) => ({ label: s || '—', color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' })}
      addLabel="Record Medical"
      AddModal={(props) => (
        <VendorAddModal
          {...props}
          title="Record Medical"
          submitLabel="Save medical"
          blockedReason={noWorkers(workers, 'medical')}
          onSubmit={({ worker_id, ...data }) => purchaseApi.workforce.saveMedical(worker_id, data)}
          fields={[
            { name: 'worker_id', label: 'Worker', type: 'select', required: true, options: workerOptions(workers) },
            // The outcome the rest of the workforce flow gates on, so the form
            // cannot omit it.
            { name: 'fitness_status', label: 'Fitness', type: 'select', required: true, options: [
              { value: 'Fit', label: 'Fit' },
              { value: 'Fit_With_Restrictions', label: 'Fit with restrictions' },
              { value: 'Unfit', label: 'Unfit' },
            ] },
            { name: 'exam_type', label: 'Exam type', type: 'select', options: [
              { value: 'internal', label: 'Internal' },
              { value: 'external', label: 'External' },
            ] },
            { name: 'exam_date', label: 'Exam date', type: 'date' },
            { name: 'valid_until', label: 'Valid until', type: 'date', help: 'Defaults to exam date + 1 year if left blank' },
            // Pick one of our own doctors, or leave it and type any name in
            // Examiner below. Choosing one makes the server copy their licence
            // and clinic from the directory — see DoctorOptions on the server.
            { name: 'doctor_user_id', label: 'Internal doctor', type: 'select',
              options: doctorSelectOptions(doctors),
              help: 'Optional. Leave blank and type the name in Examiner instead.' },
            { name: 'examiner_name', label: 'Examiner' },
            { name: 'clinic_name', label: 'Clinic' },
            { name: 'restrictions', label: 'Restrictions', type: 'textarea' },
            // A fitness verdict with no document behind it is an assertion.
            { name: 'certificate_file', label: 'Certificate', type: 'file', accept: '.pdf,.jpg,.jpeg,.png',
              help: 'The document the examination produced' },
          ]}
        />
      )}
      columns={[
        { header: 'Worker', strong: true, cell: (r) => r.worker?.full_name || '—' },
        { header: 'Code', cell: (r) => r.worker?.worker_code || '—' },
        { header: 'Exam Date', cell: (r) => fmtDate(r.exam_date) },
        { header: 'Expires', cell: (r) => fmtDate(r.expiry_date) },
        { header: 'Fitness', cell: (r) => r.fitness_status || '—' },
      ]}
    />
  )
}

/* ── Training ────────────────────────────────────────────────────────────── */

/** Kept in step with PurchaseWorkerTraining::TYPES. */
const TRAINING_TYPES = [
  'Site_Induction', 'HSE_Induction', 'Toolbox', 'Fire', 'Work_At_Height',
  'Electrical', 'Confined_Space', 'Lifting', 'Equipment', 'Emergency_Response',
  'Job_Specific', 'Other',
]

export function TrainingTab() {
  const { vendor } = useVendorWorkspace()
  const workers = useVendorWorkers(vendor.id)

  return (
    <VendorScopedList
      key={`trn-${vendor.id}`}
      title="Training Records"
      fetcher={(vid) => purchaseApi.workforce.trainings(vid)}
      statusCfg={(s) => ({ label: s || '—', color: '#8b5cf6', bg: 'rgba(139,92,246,0.15)' })}
      addLabel="Record Training"
      AddModal={(props) => (
        <VendorAddModal
          {...props}
          title="Record Training"
          submitLabel="Save training"
          blockedReason={noWorkers(workers, 'training')}
          onSubmit={({ worker_id, ...data }) => purchaseApi.workforce.saveTraining(worker_id, data)}
          fields={[
            { name: 'worker_id', label: 'Worker', type: 'select', required: true, options: workerOptions(workers) },
            { name: 'title', label: 'Training', required: true, placeholder: 'Work at Height — refresher' },
            { name: 'training_type', label: 'Type', type: 'select',
              options: TRAINING_TYPES.map(t => ({ value: t, label: t.replace(/_/g, ' ') })) },
            { name: 'status', label: 'Status', type: 'select', required: true, options: [
              { value: 'Completed', label: 'Completed' },
              { value: 'Pending', label: 'Pending' },
              { value: 'Expired', label: 'Expired' },
              { value: 'Failed', label: 'Failed' },
            ] },
            { name: 'provider', label: 'Provider' },
            { name: 'training_date', label: 'Completed on', type: 'date' },
            { name: 'valid_until', label: 'Valid until', type: 'date' },
            { name: 'score', label: 'Score', type: 'number' },
            { name: 'remarks', label: 'Remarks', type: 'textarea' },
            { name: 'certificate_file', label: 'Certificate', type: 'file', accept: '.pdf,.jpg,.jpeg,.png' },
          ]}
        />
      )}
      columns={[
        { header: 'Worker', strong: true, cell: (r) => r.worker?.full_name || '—' },
        { header: 'Code', cell: (r) => r.worker?.worker_code || '—' },
        { header: 'Training', cell: (r) => r.title || r.name || '—' },
        { header: 'Completed', cell: (r) => fmtDate(r.completed_at || r.training_date) },
        { header: 'Expires', cell: (r) => fmtDate(r.expiry_date) },
      ]}
    />
  )
}

/* ── Gate log ────────────────────────────────────────────────────────────── */

/**
 * Gate crossings for this vendor's workers.
 *
 * Deliberately no Add: a crossing is produced by a scan at the gate, and a
 * hand-typed one would be a false attendance record.
 */
export function GateLogTab() {
  const { vendor } = useVendorWorkspace()

  return (
    <VendorScopedList
      key={`gate-${vendor.id}`}
      title="Gate Log"
      fetcher={(vid) => purchaseApi.gate.log({ vendor_id: vid })}
      emptyText="No gate crossings recorded for this vendor's workers"
      columns={[
        { header: 'Worker', strong: true, cell: (r) => r.worker?.full_name || r.worker_name || '—' },
        { header: 'Direction', cell: (r) => r.direction || '—' },
        { header: 'When', cell: (r) => fmtDate(r.scanned_at || r.created_at) },
        { header: 'Gate', cell: (r) => r.gate || r.location || '—' },
        { header: 'Result', cell: (r) => (r.admitted === false ? 'Refused' : 'Admitted') },
      ]}
    />
  )
}

/* ── Safety strikes ──────────────────────────────────────────────────────── */

/**
 * Safety strikes against this vendor's workers.
 *
 * Purchase had no strikes engine at all before this, so a repeat offender on a
 * Purchase crew could be sent home and nothing anywhere recorded it — the next
 * site had no way to know. Three active strikes, or one Critical, terminates
 * site access, which is why the form says so before it is submitted.
 *
 * Voiding is an appeal upheld: the row stays on the ledger and stops counting.
 * It never reinstates a terminated worker — that is a separate decision.
 */
export function StrikesTab() {
  const { vendor } = useVendorWorkspace()
  const workers = useVendorWorkers(vendor.id)
  const [voiding, setVoiding] = useState(null)
  const [nonce, setNonce] = useState(0)

  return (
    <>
      {voiding && (
        <VendorAddModal
          title={`Void the strike on ${voiding.worker?.full_name || 'this worker'}`}
          submitLabel="Void strike"
          onClose={() => setVoiding(null)}
          onSaved={() => setNonce(n => n + 1)}
          onSubmit={({ reason }) => purchaseApi.strikes.void(voiding.id, reason)}
          fields={[{
            name: 'reason', label: 'Why it is being voided', type: 'textarea', required: true,
            help: 'The strike stays on the ledger and stops counting. Voiding does not reinstate a terminated worker — that is a separate decision.',
          }]}
        />
      )}

      <VendorScopedList
        key={`strk-${vendor.id}-${nonce}`}
        title="Safety Strikes"
        fetcher={(vid) => purchaseApi.strikes.list({ vendor_id: vid })}
        emptyText="No safety strikes against this vendor's workers"
        addLabel="Issue Strike"
        AddModal={(props) => (
          <VendorAddModal
            {...props}
            title="Issue Safety Strike"
            submitLabel="Issue strike"
            blockedReason={noWorkers(workers, 'strike')}
            onSubmit={({ worker_id, ...data }) => purchaseApi.strikes.issue(worker_id, data)}
            fields={[
              { name: 'worker_id', label: 'Worker', type: 'select', required: true, options: workerOptions(workers) },
              { name: 'severity', label: 'Severity', type: 'select', required: true, options: [
                { value: 'Minor', label: 'Minor' },
                { value: 'Major', label: 'Major' },
                { value: 'Critical', label: 'Critical — terminates site access on its own' },
              ] },
              { name: 'reason', label: 'Reason', required: true, placeholder: 'No harness at height' },
              { name: 'occurred_at', label: 'When', type: 'date' },
              { name: 'location', label: 'Where' },
              { name: 'notes', label: 'Notes', type: 'textarea',
                help: 'Three active strikes terminate site access. A strike can be voided on appeal but never deleted.' },
            ]}
          />
        )}
        columns={[
          { header: 'Worker', strong: true, cell: (r) => r.worker?.full_name || '—' },
          { header: 'Severity', cell: (r) => r.severity_label || r.severity || '—' },
          { header: 'Reason', cell: (r) => r.reason || '—' },
          { header: 'When', cell: (r) => fmtDate(r.occurred_at) },
          { header: 'Issued by', cell: (r) => r.issuer?.name || '—' },
          {
            header: 'State',
            cell: (r) => (r.voided_at
              ? <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Voided</span>
              : <button style={linkBtn} onClick={(e) => { e.stopPropagation(); setVoiding(r) }}>Void</button>),
          },
        ]}
      />
    </>
  )
}
