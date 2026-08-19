import { useState, useEffect, useCallback } from 'react'
import { HardHat, Stethoscope, GraduationCap, DoorOpen, ShieldAlert } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { SectionTable, Pill } from './VendorSectionTable'
import VendorAddModal from './VendorAddModal'

/**
 * The five vendor-detail sections backed by data the TPV module already holds:
 * Workforce, Medical, Training, Gate Log and Strikes.
 *
 * Every one of them reads an EXISTING admin endpoint, scoped to this vendor —
 * no TPV-vendor-specific API, no second copy of the data:
 *
 *   Workforce / Medical / Training  GET /tpv/workers?vendor_id=
 *   Gate Log                        GET /tpv/gate-log?vendor_id=
 *   Strikes                         GET /tpv/strikes?vendor_id=
 *
 * Medical and Training are projections of the SAME worker roster rather than
 * separate fetches: tpv_workers already carries medical_status / medical_result
 * and induction_status, so a vendor-level view is a different set of columns
 * over the same rows, not a different query.
 *
 * The table shell and status pill live in VendorSectionTable so a section is a
 * column list, not another copy of the markup.
 */

/** The worker roster — shared by Workforce, Medical and Training. */
function useVendorWorkers(vendorId) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const load = useCallback(() => {
    setLoading(true); setError(null)
    tpvApi.workers.list({ vendor_id: vendorId })
      .then(d => setRows(d?.data ?? d ?? []))
      .catch(e => setError(e?.response?.data?.message || 'Could not load workers.'))
      .finally(() => setLoading(false))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  return { rows, loading, error, reload: load }
}

/** Worker picker options — Medical, Training and Strikes all record AGAINST a worker. */
const workerOptions = rows => rows.map(w => ({
  value: String(w.id),
  label: w.worker_code ? `${w.name} (${w.worker_code})` : w.name,
}))

export function VendorWorkforce({ vendorId, manage }) {
  const { rows, loading, error, reload } = useVendorWorkers(vendorId)
  const [adding, setAdding] = useState(false)

  return (
    <>
    {adding && (
      <VendorAddModal
        title="Add Worker" submitLabel="Add worker"
        onClose={() => setAdding(false)} onSaved={reload}
        // vendor_id is fixed from the screen, never typed — a worker added here
        // belongs to THIS vendor by construction.
        onSubmit={form => tpvApi.workers.create({ ...form, vendor_id: vendorId })}
        fields={[
          { name: 'name', label: 'Name', required: true },
          { name: 'designation', label: 'Designation' },
          { name: 'skill_category', label: 'Skill category' },
          { name: 'mobile', label: 'Mobile' },
          { name: 'dob', label: 'Date of birth', type: 'date' },
          { name: 'blood_group', label: 'Blood group' },
        ]}
      />
    )}
    <SectionTable
      icon={HardHat} title="Workforce" loading={loading} error={error} rows={rows}
      hint="Workers registered by this vendor."
      onAdd={manage ? () => setAdding(true) : undefined} addLabel="Add Worker"
      searchFields={['name', 'worker_code', 'designation', 'skill_category', 'status']}
      columns={[
        { key: 'worker_code', label: 'Code' },
        { key: 'name', label: 'Name' },
        { key: 'designation', label: 'Designation' },
        { key: 'skill_category', label: 'Skill' },
        { key: 'current_step', label: 'Step', render: r => `${r.current_step ?? 1}/5` },
        { key: 'status', label: 'Status', render: r => <Pill value={r.status} good={['active']} bad={['blocked', 'rejected', 'inactive']} /> },
      ]}
    />
    </>
  )
}

export function VendorMedical({ vendorId, manage }) {
  const { rows, loading, error, reload } = useVendorWorkers(vendorId)
  const [adding, setAdding] = useState(false)

  return (
    <>
    {adding && (
      <VendorAddModal
        title="Record Medical" submitLabel="Save medical"
        blockedReason={rows.length ? null : "This vendor has no workers yet. A medical is recorded against a worker, so add one on the Workforce tab first."}
        onClose={() => setAdding(false)} onSaved={reload}
        // A medical belongs to a WORKER, so the worker is chosen from this
        // vendor's own roster — the list can never offer another vendor's worker.
        onSubmit={({ worker_id, ...data }) => tpvApi.workers.saveMedical(worker_id, data)}
        fields={[
          { name: 'worker_id', label: 'Worker', type: 'select', required: true, options: workerOptions(rows) },
          // fitness_status is the one required field — it is the outcome the rest
          // of the workforce flow gates on, so the form cannot omit it.
          { name: 'fitness_status', label: 'Fitness', type: 'select', required: true, options: [
            { value: 'Fit', label: 'Fit' },
            { value: 'Fit_With_Restrictions', label: 'Fit with restrictions' },
            { value: 'Unfit', label: 'Unfit' },
          ] },
          { name: 'exam_type', label: 'Exam type', type: 'select', options: [
            { value: 'internal', label: 'Internal' }, { value: 'external', label: 'External' },
          ] },
          { name: 'exam_date', label: 'Exam date', type: 'date' },
          // Certificate currency window. Left blank, the server defaults it to
          // exam date + 1 year; once past, activation is blocked and the gate denies.
          { name: 'valid_until', label: 'Valid until', type: 'date', help: 'Defaults to exam date + 1 year if left blank' },
          { name: 'examiner_name', label: 'Examiner' },
          { name: 'clinic_name', label: 'Clinic' },
          { name: 'restrictions', label: 'Restrictions', type: 'textarea' },
        ]}
      />
    )}
    <SectionTable
      icon={Stethoscope} title="Medical" loading={loading} error={error} rows={rows}
      hint="Medical fitness across this vendor's workers. Records are captured per worker during Workforce step 2."
      onAdd={manage ? () => setAdding(true) : undefined} addLabel="Record Medical"
      searchFields={['name', 'worker_code', 'medical_status', 'medical_result']}
      columns={[
        { key: 'worker_code', label: 'Code' },
        { key: 'name', label: 'Worker' },
        { key: 'medical_status', label: 'Status', render: r => <Pill value={r.medical_status} good={['fit', 'complete', 'done']} bad={['unfit', 'fail']} /> },
        { key: 'medical_result', label: 'Result', render: r => <Pill value={r.medical_result} good={['fit', 'pass']} bad={['unfit', 'fail']} /> },
        { key: 'medical_type', label: 'Type' },
      ]}
    />
    </>
  )
}

export function VendorTraining({ vendorId, manage }) {
  const { rows, loading, error, reload } = useVendorWorkers(vendorId)
  const [adding, setAdding] = useState(false)

  return (
    <>
    {adding && (
      <VendorAddModal
        title="Record Induction" submitLabel="Save induction"
        blockedReason={rows.length ? null : "This vendor has no workers yet. An induction is recorded against a worker, so add one on the Workforce tab first."}
        onClose={() => setAdding(false)} onSaved={reload}
        onSubmit={({ worker_id, ...data }) => tpvApi.workers.saveInduction(worker_id, data)}
        fields={[
          { name: 'worker_id', label: 'Worker', type: 'select', required: true, options: workerOptions(rows) },
          { name: 'induction_type', label: 'Induction type' },
          { name: 'trainer', label: 'Trainer' },
          { name: 'location', label: 'Location' },
        ]}
      />
    )}
    <SectionTable
      icon={GraduationCap} title="Training & Induction" loading={loading} error={error} rows={rows}
      hint="Induction status across this vendor's workers, captured during Workforce step 3."
      onAdd={manage ? () => setAdding(true) : undefined} addLabel="Record Induction"
      searchFields={['name', 'worker_code', 'induction_status', 'trainer']}
      columns={[
        { key: 'worker_code', label: 'Code' },
        { key: 'name', label: 'Worker' },
        { key: 'induction_status', label: 'Induction', render: r => <Pill value={r.induction_status} good={['complete', 'done', 'pass']} bad={['fail', 'pending']} /> },
        { key: 'induction_type', label: 'Type' },
        { key: 'trainer', label: 'Trainer' },
      ]}
    />
    </>
  )
}

export function VendorGateLog({ vendorId }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    setLoading(true); setError(null)
    tpvApi.gate.log({ vendor_id: vendorId })
      .then(d => setRows(d?.data ?? d ?? []))
      .catch(e => setError(e?.response?.data?.message || 'Could not load the gate log.'))
      .finally(() => setLoading(false))
  }, [vendorId])

  return (
    <SectionTable
      icon={DoorOpen} title="Gate Log" loading={loading} error={error} rows={rows}
      // No Add: a scan is produced by the gate (POST /scan/{token}/check-in), so
      // a hand-typed entry would be a false attendance record.
      hint="Site entry scans for this vendor's workers, newest first. Entries are created by gate scans, not added by hand."
      searchFields={['decision', 'reason']}
      columns={[
        { key: 'scanned_at', label: 'Scanned', render: r => r.scanned_at ? new Date(r.scanned_at).toLocaleString() : '—' },
        { key: 'worker', label: 'Worker', render: r => r.worker?.name || '—' },
        { key: 'code', label: 'Code', render: r => r.worker?.worker_code || '—' },
        { key: 'decision', label: 'Decision', render: r => <Pill value={r.decision} good={['admit', 'allow']} bad={['deny', 'reject']} /> },
        { key: 'reason', label: 'Reason' },
      ]}
    />
  )
}

export function VendorStrikes({ vendorId, manage }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [adding, setAdding] = useState(false)
  const { rows: workers } = useVendorWorkers(vendorId)

  const load = useCallback(() => {
    setLoading(true); setError(null)
    tpvApi.strikes.list({ vendor_id: vendorId })
      .then(d => setRows(d?.data ?? d ?? []))
      .catch(e => setError(e?.response?.data?.message || 'Could not load strikes.'))
      .finally(() => setLoading(false))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  return (
    <>
    {adding && (
      <VendorAddModal
        title="Issue Safety Strike" submitLabel="Issue strike"
        blockedReason={workers.length ? null : "This vendor has no workers yet. A strike is issued against a worker, so add one on the Workforce tab first."}
        onClose={() => setAdding(false)} onSaved={load}
        // Issuing runs the real engine: three active strikes (or one Critical)
        // terminate site access. That rule lives in SafetyStrikeService and is
        // not restated here.
        onSubmit={({ worker_id, ...data }) => tpvApi.strikes.issue(worker_id, data)}
        fields={[
          { name: 'worker_id', label: 'Worker', type: 'select', required: true, options: workerOptions(workers) },
          { name: 'severity', label: 'Severity', type: 'select', required: true, options: [
            { value: 'Minor', label: 'Minor' }, { value: 'Major', label: 'Major' }, { value: 'Critical', label: 'Critical' },
          ], help: 'A Critical strike terminates site access immediately.' },
          { name: 'reason', label: 'Reason', required: true },
          { name: 'location', label: 'Location' },
          { name: 'notes', label: 'Notes', type: 'textarea' },
        ]}
      />
    )}
    <SectionTable
      icon={ShieldAlert} title="Safety Strikes" loading={loading} error={error} rows={rows}
      hint="Safety strikes raised against this vendor's workers."
      onAdd={manage ? () => setAdding(true) : undefined} addLabel="Issue Strike"
      searchFields={['severity', 'reason', 'category']}
      columns={[
        { key: 'occurred_at', label: 'Occurred', render: r => r.occurred_at ? new Date(r.occurred_at).toLocaleDateString() : '—' },
        { key: 'worker', label: 'Worker', render: r => r.worker?.name || '—' },
        { key: 'severity', label: 'Severity', render: r => <Pill value={r.severity} bad={['major', 'critical', 'high']} /> },
        { key: 'reason', label: 'Reason' },
        { key: 'issuer', label: 'Issued by', render: r => r.issuer?.name || '—' },
        { key: 'voided_at', label: 'State', render: r => r.voided_at ? 'Voided' : 'Active' },
      ]}
    />
    </>
  )
}
