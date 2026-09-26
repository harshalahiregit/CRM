import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { FileText, Upload, Check, ShieldCheck, Clock, AlertTriangle, Eye } from 'lucide-react'
import { stosApi, STOS_ACCENT } from '@/services/stosApi'
import { useToast } from '@/components/ui/Toast'
import Select from '@/components/ui/Select'

/**
 * A driver's paperwork, and the rule that an upload is not a clearance (T-43).
 *
 * The twin of `VehicleDocumentsPanel`, and the thing it has to make obvious is
 * the same one — because people assume the opposite — with more at stake: a
 * driving licence is the single document that stops a PERSON being dispatched.
 * Photographing it does not clear them. So the gate row stays amber after an
 * upload and says "waiting to be verified" rather than going quiet.
 *
 * Fleet does not own the documents. They live in the shared store; what Fleet
 * owns is the consequence, which is that a verified licence sets the expiry the
 * allocation engine blocks on.
 */
export default function DriverDocumentsPanel({ driver, onChanged }) {
  const qc = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [err, setErr] = useState('')
  const [rejecting, setRejecting] = useState(null)
  const [reason, setReason] = useState('')

  const source = driver?.source
  const personId = driver?.source_id

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['stos-driver-documents', source, personId],
    queryFn: () => stosApi.driverDocuments.forDriver(source, personId),
    enabled: Boolean(source && personId),
    // A person with no profile yet is a 404 with a sentence telling you what to
    // do first. Retrying it just delays that sentence.
    retry: false,
  })

  const toast = useToast()

  const done = () => { refetch(); qc.invalidateQueries({ queryKey: ['stos-drivers'] }); onChanged?.() }

  // View the actual file. The private disk needs the bearer token, so the file
  // is fetched with it and opened as a blob rather than a bare URL a new tab
  // could not authenticate.
  const viewDoc = async (id) => {
    try {
      const blob = await stosApi.driverDocuments.fileBlob(id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch {
      toast.error('Could not open that document')
    }
  }

  const verify = useMutation({
    mutationFn: ({ id, verdict, why }) => stosApi.driverDocuments.verify(id, verdict, why),
    onSuccess: () => { setRejecting(null); setReason(''); setErr(''); done() },
    onError: (e) => setErr(e?.message || 'Could not record that verdict.'),
  })

  if (!driver) return null

  // No profile yet is the common case for a directory person who has never been
  // saved as a driver: forDriver() answers 404 with the sentence saying what to
  // do first. Show THAT, not an empty "File a document" form whose type list is
  // blank because the request that fills it failed — which reads as a broken
  // dropdown when the real message is "save this driver first".
  if (isError) {
    return (
      <div className="flex items-start gap-2 rounded-xl px-3 py-2 text-[11px]"
        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
        <AlertTriangle size={13} style={{ marginTop: 1, flexShrink: 0 }} />
        <span>{error?.message
          || 'This person has no driver profile yet. Save their licence details above first, then you can file documents against them.'}</span>
      </div>
    )
  }

  const gating = data?.gating ?? {}
  const documents = data?.documents ?? []

  return (
    <div className="space-y-3">
      {/* ── The one that gates dispatch ───────────────────────── */}
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
                    ? 'Filed — waiting to be verified. The driver stays blocked until then.'
                    : 'Nothing on file.'}
              </p>
            </div>

            {/* The date the driver is actually gated on, which may still be a
                hand-typed one from before documents became the master. */}
            {g.driver_date && (
              <span className="text-[10px] shrink-0" style={{ color: 'var(--text-muted)' }}>
                gate: {g.driver_date}
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

              {d.file_name && (
                <button type="button" onClick={() => viewDoc(d.id)}
                  className="flex items-center gap-1 text-[10px] font-semibold px-2 py-1 rounded-lg shrink-0"
                  style={{ color: STOS_ACCENT, border: '1px solid var(--border)' }}>
                  <Eye size={11} /> View
                </button>
              )}

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
        ? <UploadForm driver={driver} types={data?.types ?? []}
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

function UploadForm({ driver, types, onCancel, onDone }) {
  const [type, setType] = useState(types[0]?.value ?? 'driving_license')
  const [number, setNumber] = useState('')
  const [validUntil, setValidUntil] = useState('')
  const [files, setFiles] = useState([])
  const [err, setErr] = useState('')

  // Several files at once — e.g. both sides of a licence, or a multi-page
  // permit. Each becomes its own document of the chosen type, filed one after
  // the other so a failure part-way names which file it was.
  const save = useMutation({
    mutationFn: async () => {
      for (const f of files) {
        try {
          await stosApi.driverDocuments.file(driver.source, driver.source_id, {
            document_type: type, document_number: number, valid_until: validUntil, file: f,
          })
        } catch (e) {
          throw new Error(`${f.name}: ${e?.message || 'could not be filed'}`)
        }
      }
    },
    onSuccess: () => onDone(),
    onError: (e) => setErr(e?.message || 'Could not file those documents.'),
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

      <input type="file" accept=".pdf,.jpg,.jpeg,.png" multiple
        onChange={(e) => setFiles([...(e.target.files ?? [])])}
        className="w-full text-[10px]" style={{ color: 'var(--text-muted)' }} />
      {files.length > 1 && (
        <p className="text-[10px]" style={{ color: STOS_ACCENT }}>{files.length} files selected — each is filed separately.</p>
      )}

      {/* Said before they press save, not after. */}
      {gates && (
        <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
          A licence gates dispatch. Filing it does not clear the driver — they stay blocked until
          somebody verifies it.
        </p>
      )}

      {err && <p className="text-[10px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <div className="flex items-center justify-end gap-2">
        <button type="button" onClick={onCancel}
          className="text-[11px] font-semibold px-3 py-1.5 rounded-lg"
          style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
        <button type="button" onClick={() => save.mutate()} disabled={save.isPending || files.length === 0}
          className="flex items-center gap-1 text-[11px] font-bold px-3 py-1.5 rounded-lg disabled:opacity-60"
          style={{ background: STOS_ACCENT, color: '#fff' }}>
          <Check size={11} /> {save.isPending ? 'Filing…' : (files.length > 1 ? `File ${files.length}` : 'File')}
        </button>
      </div>
    </div>
  )
}

const cell = 'w-full text-[11px] rounded-lg px-2 py-1.5'
const inputStyle = { background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text)' }
