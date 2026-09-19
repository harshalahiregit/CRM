import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { FileText, Upload, Check, X, ShieldCheck, Clock, AlertTriangle } from 'lucide-react'
import { stosApi, STOS_ACCENT, fmtWhen } from '@/services/stosApi'
import Select from '@/components/ui/Select'

/**
 * A vehicle's statutory paperwork, and the rule that an upload is not a
 * clearance (T-57).
 *
 * The thing this panel has to make obvious — because it is counter-intuitive
 * and people will otherwise assume the opposite — is that attaching a
 * certificate does NOT put the truck back on the road. Somebody has to look at
 * it first. So the gate row stays red after an upload and says "waiting to be
 * verified" rather than going quiet.
 *
 * Fleet does not own the documents. They live in the shared document store;
 * what Fleet owns is the consequence, which is that five of them gate dispatch.
 */
export default function VehicleDocumentsPanel({ vehicle, onChanged }) {
  const qc = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [err, setErr] = useState('')
  const [rejecting, setRejecting] = useState(null)
  const [reason, setReason] = useState('')

  const { data, isLoading, refetch } = useQuery({
    queryKey: ['stos-vehicle-documents', vehicle?.id],
    queryFn: () => stosApi.documents.forVehicle(vehicle.id),
    enabled: Boolean(vehicle?.id),
  })

  const done = () => { refetch(); qc.invalidateQueries({ queryKey: ['stos-vehicle'] }); onChanged?.() }

  const verify = useMutation({
    mutationFn: ({ id, verdict, why }) => stosApi.documents.verify(id, verdict, why),
    onSuccess: () => { setRejecting(null); setReason(''); setErr(''); done() },
    onError: (e) => setErr(e?.message || 'Could not record that verdict.'),
  })

  if (!vehicle) return null

  const gating = data?.gating ?? {}
  const documents = data?.documents ?? []

  return (
    <div className="space-y-3">
      {/* ── The five that gate dispatch ───────────────────────── */}
      <div className="space-y-1.5">
        {Object.entries(gating).map(([type, g]) => (
          <div key={type} className="flex items-center gap-2 rounded-xl px-3 py-2"
            style={{
              background: 'var(--bg-input)',
              border: `1px solid ${g.verified ? 'var(--border)' : 'var(--color-warning-500, #f59e0b)'}`,
            }}>
            {g.verified
              ? <ShieldCheck size={13} style={{ color: 'var(--color-success-500, #10b981)' }} />
              : <AlertTriangle size={13} style={{ color: 'var(--color-warning-500, #f59e0b)' }} />}

            <div className="min-w-0 flex-1">
              <p className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>{g.label}</p>
              <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                {g.verified
                  ? `Verified · valid to ${g.valid_until ?? '—'}`
                  : g.awaiting
                    ? 'Filed — waiting to be verified. The vehicle stays blocked until then.'
                    : 'Nothing on file.'}
              </p>
            </div>

            {/* The date the vehicle is actually gated on, which may still be a
                hand-entered one from before documents became the master. */}
            {g.vehicle_date && (
              <span className="text-[10px] shrink-0" style={{ color: 'var(--text-muted)' }}>
                gate: {g.vehicle_date}
              </span>
            )}
          </div>
        ))}
      </div>

      {/* ── Everything on file ────────────────────────────────── */}
      {documents.length > 0 && (
        <div className="space-y-1">
          <p className="text-[11px] font-bold" style={{ color: 'var(--text-muted)' }}>On file</p>

          {documents.map((d) => (
            <div key={d.id} className="flex items-center gap-2 rounded-lg px-3 py-1.5"
              style={{ background: 'var(--bg-input)' }}>
              <FileText size={11} style={{ color: STOS_ACCENT }} />

              <div className="min-w-0 flex-1">
                <p className="text-[10px] font-semibold truncate" style={{ color: 'var(--text-h)' }}>
                  {d.document_type} {d.document_number ? `· ${d.document_number}` : ''}
                  {d.version > 1 ? ` · v${d.version}` : ''}
                </p>
                <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                  {d.valid_until ? `valid to ${d.valid_until}` : 'no expiry recorded'}
                  {d.status === 'superseded' ? ' · superseded' : ''}
                  {d.rejection_reason ? ` · rejected: ${d.rejection_reason}` : ''}
                </p>
              </div>

              <VerificationChip state={d.verification_status} />

              {d.verification_status !== 'VERIFIED' && d.status !== 'superseded' && (
                <div className="flex items-center gap-1 shrink-0">
                  <button type="button" onClick={() => verify.mutate({ id: d.id, verdict: 'VERIFIED' })}
                    disabled={verify.isPending}
                    className="text-[10px] font-bold px-2 py-1 rounded-lg disabled:opacity-60"
                    style={{ background: 'var(--color-success-500, #10b981)', color: '#fff' }}>
                    Verify
                  </button>
                  <button type="button" onClick={() => { setRejecting(d.id); setReason('') }}
                    className="text-[10px] font-semibold px-2 py-1 rounded-lg"
                    style={{ color: 'var(--color-danger-500)', border: '1px solid var(--border)' }}>
                    Reject
                  </button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {/* A rejection has to say why — whoever uploaded it needs to know what to fix. */}
      {rejecting && (
        <div className="rounded-xl p-3 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--color-danger-500)' }}>
          <input value={reason} onChange={(e) => setReason(e.target.value)} autoFocus
            placeholder="Why is it being rejected? e.g. photograph is unreadable"
            className="w-full text-xs rounded-lg px-3 py-2"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text)' }} />
          <div className="flex items-center justify-end gap-2">
            <button type="button" onClick={() => setRejecting(null)}
              className="text-[11px] font-semibold px-3 py-1.5 rounded-lg"
              style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button type="button" disabled={reason.trim() === '' || verify.isPending}
              onClick={() => verify.mutate({ id: rejecting, verdict: 'REJECTED', why: reason.trim() })}
              className="text-[11px] font-bold px-3 py-1.5 rounded-lg disabled:opacity-60"
              style={{ background: 'var(--color-danger-500)', color: '#fff' }}>Reject</button>
          </div>
        </div>
      )}

      {adding
        ? <UploadForm vehicle={vehicle} types={data?.types ?? []}
            onCancel={() => setAdding(false)}
            onDone={() => { setAdding(false); done() }} />
        : (
          <button type="button" onClick={() => { setAdding(true); setErr('') }}
            className="flex items-center gap-1 text-[11px] font-semibold" style={{ color: STOS_ACCENT }}>
            <Upload size={11} /> File a document
          </button>
        )}

      {isLoading && <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Loading…</p>}

      {err && (
        <p className="text-[11px] px-3 py-2 rounded-lg"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
          {err}
        </p>
      )}
    </div>
  )
}

function VerificationChip({ state }) {
  const map = {
    VERIFIED: ['Verified', 'var(--color-success-500, #10b981)'],
    REJECTED: ['Rejected', 'var(--color-danger-500)'],
    UNDER_VERIFICATION: ['Checking', 'var(--color-warning-500, #f59e0b)'],
    UPLOADED: ['Not checked', 'var(--text-muted)'],
  }
  const [label, colour] = map[state] ?? [state, 'var(--text-muted)']

  return (
    <span className="inline-flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.5 rounded shrink-0"
      style={{ background: `color-mix(in srgb, ${colour} 14%, transparent)`, color: colour }}>
      {state === 'VERIFIED' ? <Check size={9} /> : <Clock size={9} />} {label}
    </span>
  )
}

function UploadForm({ vehicle, types, onCancel, onDone }) {
  const [type, setType] = useState(types[0]?.value ?? 'insurance')
  const [number, setNumber] = useState('')
  const [validUntil, setValidUntil] = useState('')
  const [file, setFile] = useState(null)
  const [err, setErr] = useState('')

  const save = useMutation({
    mutationFn: () => stosApi.documents.file(vehicle.id, {
      document_type: type, document_number: number, valid_until: validUntil, file,
    }),
    onSuccess: () => onDone(),
    onError: (e) => setErr(e?.message || 'Could not file that document.'),
  })

  const gates = types.find((t) => t.value === type)?.gates_dispatch

  return (
    <div className="rounded-xl p-3 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <Select size="sm" value={type} onChange={setType} options={types} ariaLabel="Document type" />

      <div className="grid grid-cols-2 gap-2">
        <input value={number} onChange={(e) => setNumber(e.target.value)} placeholder="Document number"
          className={cell} style={inputStyle} />
        <input type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)}
          className={cell} style={inputStyle} />
      </div>

      <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        className="w-full text-[10px]" style={{ color: 'var(--text-muted)' }} />

      {/* Said before they press save, not after. */}
      {gates && (
        <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
          This document gates dispatch. Filing it does not clear the vehicle — it stays blocked
          until somebody verifies the certificate.
        </p>
      )}

      {err && <p className="text-[10px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <div className="flex items-center justify-end gap-2">
        <button type="button" onClick={onCancel}
          className="text-[11px] font-semibold px-3 py-1.5 rounded-lg"
          style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
        <button type="button" onClick={() => save.mutate()} disabled={save.isPending}
          className="flex items-center gap-1 text-[11px] font-bold px-3 py-1.5 rounded-lg disabled:opacity-60"
          style={{ background: STOS_ACCENT, color: '#fff' }}>
          <Check size={11} /> {save.isPending ? 'Filing…' : 'File'}
        </button>
      </div>
    </div>
  )
}

const cell = 'w-full text-[11px] rounded-lg px-2 py-1.5'
const inputStyle = { background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text)' }
