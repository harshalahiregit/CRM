import { useState, useEffect, useCallback, useRef } from 'react'
import {
  FileCheck2, Loader2, Upload, CheckCircle2, XCircle, AlertTriangle, ShieldCheck,
} from 'lucide-react'
import { useToast } from '@/components/ui/Toast'
import { transportPodApi } from '@/services/transportApi'
import { Chip } from './MasterFormFields'
import { fmtDateTime, fmtBytes, tripDocStatusCfg, DOC_TYPE_LABEL } from '../constants'

/**
 * Trip documents and POD panel — SNG-TRN-014, API-008.
 *
 * ── THE BILLING VERDICT LEADS, NOT THE FILE LIST ────────────────────────
 * The rule is "POD required before billable state unless approved exception".
 * The second arm is INVISIBLE from the document rows — a waived exception lives
 * on the trip, not on any file here — so a screen inferring billability from the
 * list would get that case wrong every time. The verdict and its reason come
 * from the server and are shown first.
 *
 * ── SUBMITTING AND VERIFYING ARE SEPARATE GRANTS ────────────────────────
 * PERM-010 lets a Driver submit their own POD and a Supplier submit against
 * trips assigned to them. Neither may then certify it: STT-008's effect is
 * "Unlock billing", which is a financial act. The buttons follow the grants, so
 * the segregation is visible rather than discovered by a 403.
 *
 * ── A DECIDED DOCUMENT SHOWS NO BUTTONS ─────────────────────────────────
 * CTR-012 is "immutable after verification" and a rejection is terminal — the
 * next attempt is a NEW row, with the rejected one kept as evidence. So a
 * decided document offers nothing to click, and the rejection reason is shown
 * rather than hidden, because it is the only explanation anybody will get.
 */
export default function TripDocumentsPanel({ trip, canSubmit, canVerify, onChanged }) {
  const toast = useToast()
  const fileRef = useRef(null)
  const [state, setState] = useState({ documents: [], billing: null })
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(null)
  const [type, setType] = useState('pod')
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setState(await transportPodApi.list(trip.id))
    } catch {
      toast.error('Could not load trip documents')
    } finally {
      setLoading(false)
    }
  }, [trip.id, toast])

  useEffect(() => { load() }, [load])

  const upload = async (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    setError('')
    setBusy('upload')
    try {
      const res = await transportPodApi.submit(trip.id, file, { document_type: type })
      if (!res.ok) { setError(res.message || 'That file was refused.'); return }
      toast.success('Document filed')
      await load(); onChanged?.()
    } catch {
      toast.error('Could not upload the document')
    } finally {
      setBusy(null)
      if (fileRef.current) fileRef.current.value = ''   // let the same file be retried
    }
  }

  const decide = async (doc, approve) => {
    let reason = ''
    if (!approve) {
      reason = window.prompt('Why is this document being rejected?') || ''
      if (!reason.trim()) return
    }
    setError('')
    setBusy(doc.id)
    try {
      const res = approve
        ? await transportPodApi.verify(trip.id, doc.id)
        : await transportPodApi.reject(trip.id, doc.id, reason)
      if (!res.ok) { setError(res.message || 'That decision was refused.'); return }
      toast.success(approve ? 'Document verified' : 'Document rejected')
      await load(); onChanged?.()
    } catch {
      toast.error('Could not record that decision')
    } finally { setBusy(null) }
  }

  if (loading) {
    return <p style={muted}><Loader2 size={13} className="spin" /> Loading documents…</p>
  }

  const billing = state.billing

  return (
    <div style={{ marginTop: 12 }}>
      {billing && (
        <div style={{
          ...bar,
          borderLeft: `3px solid ${billing.billable ? 'var(--success)' : 'var(--warning, #fbbf24)'}`,
        }}>
          {billing.billable
            ? <ShieldCheck size={16} style={{ color: 'var(--success)', flexShrink: 0 }} />
            : <AlertTriangle size={16} style={{ color: '#fbbf24', flexShrink: 0 }} />}
          <div>
            <div style={{ fontSize: 13, fontWeight: 600 }}>
              {billing.billable ? 'Ready to bill' : 'Not ready to bill'}
            </div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{billing.reason}</div>
          </div>
        </div>
      )}

      {error && <p style={{ ...muted, color: 'var(--danger)' }}><AlertTriangle size={13} /> {error}</p>}

      {state.documents.length === 0 ? (
        <p style={muted}>No paperwork has been filed against this trip.</p>
      ) : (
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, marginTop: 8 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11 }}>
              <th style={th}>Document</th><th style={th}>Type</th>
              <th style={th}>Status</th><th style={th} />
            </tr>
          </thead>
          <tbody>
            {state.documents.map((d) => (
              <tr key={d.id} style={{ borderTop: '1px solid var(--border)' }}>
                <td style={td}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <FileCheck2 size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                    <span style={{ wordBreak: 'break-all' }}>{d.file_name}</span>
                  </div>
                  <div style={{ fontSize: 10, color: 'var(--text-muted)', marginTop: 2 }}>
                    {fmtBytes(d.file_size)} · filed {fmtDateTime(d.created_at)}
                  </div>
                </td>
                <td style={td}>{DOC_TYPE_LABEL[d.document_type] ?? d.document_type}</td>
                <td style={td}>
                  <Chip cfg={tripDocStatusCfg(d.status)} />
                  {d.status === 'rejected' && d.rejection_reason && (
                    <div style={{ fontSize: 10, color: 'var(--danger)', marginTop: 3, maxWidth: 220 }}>
                      {d.rejection_reason}
                    </div>
                  )}
                  {d.status === 'verified' && d.verified_at && (
                    <div style={{ fontSize: 10, color: 'var(--text-muted)', marginTop: 2 }}>
                      {fmtDateTime(d.verified_at)}
                    </div>
                  )}
                </td>
                <td style={{ ...td, textAlign: 'right' }}>
                  {/* Only an undecided document can be decided — CTR-012. */}
                  {canVerify && d.status === 'received' && (
                    <>
                      <button type="button" style={btnGhost} disabled={busy === d.id}
                        onClick={() => decide(d, true)}>
                        <CheckCircle2 size={13} /> Verify
                      </button>
                      <button type="button" style={{ ...btnGhost, color: 'var(--danger)' }}
                        disabled={busy === d.id} onClick={() => decide(d, false)}>
                        <XCircle size={13} /> Reject
                      </button>
                    </>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {canSubmit && (
        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', marginTop: 12, flexWrap: 'wrap' }}>
          <label style={field}>
            <span style={lbl}>Document type</span>
            <select style={input} value={type} onChange={(e) => setType(e.target.value)}>
              {['pod', 'lr', 'ewaybill', 'delivery_order', 'invoice', 'other'].map((t) => (
                <option key={t} value={t}>{DOC_TYPE_LABEL[t] ?? t}</option>
              ))}
            </select>
          </label>
          <label style={{ ...btn, cursor: busy === 'upload' ? 'wait' : 'pointer' }}>
            {busy === 'upload' ? <Loader2 size={14} className="spin" /> : <Upload size={14} />}
            {busy === 'upload' ? 'Uploading…' : 'Upload'}
            <input ref={fileRef} type="file" hidden disabled={busy === 'upload'}
              accept="application/pdf,image/*" onChange={upload} />
          </label>
          <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>PDF or image, up to 10 MB.</span>
        </div>
      )}
    </div>
  )
}

const muted = { fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0', display: 'flex', alignItems: 'center', gap: 6 }
const bar = { display: 'flex', gap: 10, alignItems: 'center', padding: '10px 12px', background: 'var(--surface-2)', borderRadius: 8 }
const th = { padding: '6px 8px', fontWeight: 500 }
const td = { padding: '8px', verticalAlign: 'top' }
const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', fontSize: 13, borderRadius: 7, border: '1px solid var(--border)', background: 'var(--surface)' }
const btnGhost = { ...btn, marginLeft: 6, background: 'transparent', cursor: 'pointer' }
const field = { display: 'flex', flexDirection: 'column', gap: 4, minWidth: 150 }
const lbl = { fontSize: 11, color: 'var(--text-muted)' }
const input = { padding: '6px 8px', fontSize: 13, borderRadius: 6, border: '1px solid var(--border)', background: 'var(--surface)', color: 'inherit' }
