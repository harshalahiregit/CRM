import { useState, useEffect, useCallback, useRef } from 'react'
import { useParams } from 'react-router-dom'
import {
  FileText, Download, CheckCircle2, XCircle, Clock, HelpCircle, ShieldCheck, Upload, Check,
} from 'lucide-react'
import { offerPortalApi } from '@/services/offerPortalApi'

const accent = '#7C3AED'
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'
const fmtCTC = (v) => v == null ? '—' : `₹${Number(v).toLocaleString('en-IN')}`
const STATUS = (s) => ({
  Generated: { c: '#64748b', bg: '#f1f5f9' }, Sent: { c: '#2563eb', bg: '#eff6ff' }, Viewed: { c: '#7c3aed', bg: '#f5f3ff' },
  Accepted: { c: '#059669', bg: '#ecfdf5' }, Declined: { c: '#dc2626', bg: '#fef2f2' }, Expired: { c: '#b45309', bg: '#fffbeb' },
  Withdrawn: { c: '#4b5563', bg: '#f3f4f6' },
}[s] || { c: '#64748b', bg: '#f1f5f9' })

/**
 * Also embedded inside the candidate Onboarding Portal's "Offer Letter" tab.
 * Pass `token` to render it in place; without it the token comes from the route,
 * so the standalone /offer/{token} page is unchanged.
 */
export default function OfferPortal({ token: tokenProp, embedded = false }) {
  const { token: routeToken } = useParams()
  const token = tokenProp || routeToken
  const [offer, setOffer] = useState(null)
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [agreed, setAgreed] = useState(false)
  const [fullName, setFullName] = useState('')
  const [signature, setSignature] = useState('')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')

  const load = useCallback(async () => {
    try { setOffer(await offerPortalApi.get(token)) } catch { setNotFound(true) } finally { setLoading(false) }
  }, [token])
  useEffect(() => { load() }, [load])

  const act = async (fn, key) => {
    setBusy(key); setMsg('')
    try { const r = await fn(); if (r?.offer) setOffer(r.offer) }
    catch (e) { setMsg(e?.response?.data?.message || 'Something went wrong. Please try again.') }
    finally { setBusy('') }
  }

  const accept = () => act(() => offerPortalApi.accept(token, { full_name: fullName.trim(), signature: signature || undefined }), 'accept')
  const decline = () => { const reason = window.prompt('Optionally, tell us why you are declining:'); if (reason === null) return; act(() => offerPortalApi.decline(token, reason || undefined), 'decline') }
  const clarify = () => { const m = window.prompt('What would you like clarified about this offer?'); if (!m) return; act(() => offerPortalApi.clarify(token, m), 'clarify') }

  if (loading) return <Center>Loading your offer…</Center>
  if (notFound || !offer) return <Center><h1 style={{ fontSize: 22, fontWeight: 800, color: '#0f172a' }}>This offer link is invalid or has expired.</h1></Center>

  const st = STATUS(offer.status)

  return (
    <div style={{ minHeight: '100vh', background: '#f8fafc', color: '#0f172a', fontFamily: 'system-ui,-apple-system,sans-serif' }}>
      <style>{`@media print { .no-print { display:none !important } body { background:#fff } }`}</style>
      <header className="no-print" style={{ background: '#fff', borderBottom: '1px solid #e2e8f0' }}>
        <div style={{ maxWidth: 760, margin: '0 auto', padding: '14px 24px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}><ShieldCheck size={20} style={{ color: accent }} /><strong style={{ fontSize: 15 }}>Offer Letter</strong></div>
          <span style={{ fontSize: 12, fontWeight: 700, padding: '4px 12px', borderRadius: 999, background: st.bg, color: st.c }}>{offer.status}</span>
        </div>
      </header>

      <div style={{ maxWidth: 760, margin: '0 auto', padding: '28px 24px 60px' }}>
        {/* Offer letter */}
        <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 32 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 10 }}>
            <div>
              <div style={{ fontSize: 11, color: '#94a3b8', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Offer of Employment · {offer.reference}</div>
              <h1 style={{ fontSize: 26, fontWeight: 900, margin: '4px 0 0' }}>{offer.position}</h1>
            </div>
            <FileText size={30} style={{ color: accent }} />
          </div>
          <p style={{ color: '#334155', marginTop: 16, fontSize: 14.5, lineHeight: 1.6 }}>Dear <strong>{offer.candidate_name}</strong>, we are delighted to offer you the position of <strong>{offer.position}</strong>{offer.department ? ` in the ${offer.department} department` : ''}. Please review the details below.</p>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 12, marginTop: 20 }}>
            <Detail k="Annual CTC" v={fmtCTC(offer.offered_ctc)} />
            <Detail k="Joining Date" v={fmtDate(offer.joining_date)} />
            <Detail k="Probation" v={offer.probation_period || '—'} />
            <Detail k="Notice Period" v={offer.notice_period || '—'} />
            <Detail k="Valid Until" v={fmtDate(offer.valid_until)} highlight={offer.status !== 'Accepted'} />
          </div>

          <div className="no-print" style={{ marginTop: 20 }}>
            <button onClick={() => window.print()} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 10, background: '#fff', border: '1px solid #e2e8f0', color: '#334155', cursor: 'pointer', fontWeight: 700, fontSize: 13.5 }}><Download size={15} /> Download / Print PDF</button>
          </div>
        </div>

        {/* Status-specific */}
        {offer.status === 'Expired' && <Banner icon={<Clock size={16} />} color="#b45309" bg="#fffbeb">This offer has expired. Please contact HR for a fresh offer.</Banner>}
        {offer.status === 'Declined' && <Banner icon={<XCircle size={16} />} color="#dc2626" bg="#fef2f2">You have declined this offer.</Banner>}
        {offer.status === 'Withdrawn' && <Banner icon={<XCircle size={16} />} color="#4b5563" bg="#f3f4f6">This offer has been withdrawn by HR.{offer.withdraw_reason ? ` Reason: ${offer.withdraw_reason}` : ''} Please contact HR for details.</Banner>}
        {offer.clarification && offer.status !== 'Accepted' && <Banner icon={<HelpCircle size={16} />} color="#2563eb" bg="#eff6ff">Clarification requested — HR will get back to you.</Banner>}

        {/* Actions */}
        {msg && <div className="no-print" style={{ background: '#fef2f2', color: '#b91c1c', border: '1px solid #fecaca', borderRadius: 10, padding: '10px 14px', fontSize: 13, marginTop: 16 }}>{msg}</div>}

        {offer.can_respond && (
          <div className="no-print" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 22, marginTop: 16 }}>
            <h3 style={{ fontSize: 15, fontWeight: 800, margin: '0 0 4px' }}>Accept &amp; Sign</h3>
            <p style={{ fontSize: 12.5, color: '#64748b', margin: '0 0 16px' }}>To accept, type your full legal name and, optionally, draw your signature below.</p>

            {/* Typed full name (required) */}
            <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, color: '#475569', marginBottom: 6 }}>Full legal name <span style={{ color: '#dc2626' }}>*</span></label>
            <input value={fullName} onChange={e => setFullName(e.target.value)} placeholder="e.g. Harshal Ahire"
              style={{ width: '100%', padding: '10px 12px', border: '1px solid #e2e8f0', borderRadius: 9, fontSize: 14, outline: 'none', boxSizing: 'border-box', marginBottom: 16 }} />

            {/* Optional drawn signature */}
            <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, color: '#475569', marginBottom: 6 }}>Signature <span style={{ color: '#94a3b8', fontWeight: 500 }}>(optional — draw below)</span></label>
            <SignaturePad onChange={setSignature} />

            {/* Acceptance checkbox */}
            <label style={{ display: 'flex', alignItems: 'flex-start', gap: 10, cursor: 'pointer', fontSize: 14, marginTop: 16 }}>
              <input type="checkbox" checked={agreed} onChange={e => setAgreed(e.target.checked)} style={{ marginTop: 3 }} />
              <span>I, <strong>{fullName.trim() || '…'}</strong>, have read and understood this offer and accept it. I understand this constitutes my digital signature.</span>
            </label>

            <div style={{ display: 'flex', gap: 10, marginTop: 16, flexWrap: 'wrap' }}>
              <button onClick={accept} disabled={!agreed || !fullName.trim() || busy} title={!fullName.trim() ? 'Enter your full name' : !agreed ? 'Tick the acceptance box' : ''} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '12px 26px', borderRadius: 10, background: (!agreed || !fullName.trim() || busy) ? '#94a3b8' : '#059669', color: '#fff', border: 'none', cursor: (!agreed || !fullName.trim() || busy) ? 'not-allowed' : 'pointer', fontWeight: 700, fontSize: 14.5 }}><CheckCircle2 size={16} /> {busy === 'accept' ? 'Submitting…' : 'Accept & Sign Offer'}</button>
              <button onClick={decline} disabled={busy} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '12px 22px', borderRadius: 10, background: '#fff', border: '1px solid #fecaca', color: '#b91c1c', cursor: 'pointer', fontWeight: 700, fontSize: 14 }}><XCircle size={15} /> Decline</button>
              <button onClick={clarify} disabled={busy} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '12px 22px', borderRadius: 10, background: '#fff', border: '1px solid #e2e8f0', color: '#475569', cursor: 'pointer', fontWeight: 700, fontSize: 14 }}><HelpCircle size={15} /> Request Clarification</button>
            </div>
          </div>
        )}

        {/* Accepted → signature record + pre-joining checklist */}
        {offer.status === 'Accepted' && (
          <>
            <Banner icon={<CheckCircle2 size={16} />} color="#059669" bg="#ecfdf5">🎉 You accepted this offer on {fmtDate(offer.accepted_at)}. Complete your pre-joining checklist below.</Banner>
            {(offer.accepted_name || offer.accepted_signature) && (
              <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 22, marginTop: 16 }}>
                <h2 style={{ fontSize: 15, fontWeight: 800, margin: '0 0 10px', display: 'flex', alignItems: 'center', gap: 8 }}><ShieldCheck size={16} style={{ color: accent }} /> Signed Acceptance</h2>
                {offer.accepted_signature && <img src={offer.accepted_signature} alt="Signature" style={{ maxWidth: 260, height: 90, objectFit: 'contain', border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff', marginBottom: 12 }} />}
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 10 }}>
                  <Detail k="Signed by" v={offer.accepted_name || '—'} />
                  <Detail k="Signed on" v={offer.accepted_at ? new Date(offer.accepted_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'} />
                  <Detail k="IP address" v={offer.accepted_ip || '—'} />
                  <Detail k="Device / Browser" v={[offer.accepted_device, offer.accepted_browser].filter(Boolean).join(' · ') || '—'} />
                </div>
              </div>
            )}
            {offer.pre_joining && <PreJoining token={token} pj={offer.pre_joining} onChanged={setOffer} />}
          </>
        )}
      </div>
    </div>
  )
}

function PreJoining({ token, pj, onChanged }) {
  const [busyKey, setBusyKey] = useState('')
  const [values, setValues] = useState(Object.fromEntries(pj.items.map(i => [i.key, i.value || ''])))
  const refs = useRef({})

  const submit = async (key, value, file) => {
    setBusyKey(key)
    try { const r = await offerPortalApi.task(token, key, value, file); if (r?.offer) onChanged(r.offer) }
    catch { /* ignore */ } finally { setBusyKey('') }
  }

  return (
    <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 22, marginTop: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
        <h2 style={{ fontSize: 16, fontWeight: 800, margin: 0 }}>Pre-Joining Checklist</h2>
        <span style={{ fontSize: 13, fontWeight: 800, color: accent }}>{pj.percent}%</span>
      </div>
      <div style={{ height: 9, borderRadius: 999, background: '#f1f5f9', overflow: 'hidden', marginBottom: 16 }}>
        <div style={{ width: `${pj.percent}%`, height: '100%', background: `linear-gradient(90deg,#a78bfa,${accent})`, transition: 'width .3s' }} />
      </div>
      <div style={{ display: 'grid', gap: 10 }}>
        {pj.items.map(t => (
          <div key={t.key} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '11px 14px', borderRadius: 10, background: t.done ? '#ecfdf5' : '#f8fafc', border: '1px solid #e2e8f0', flexWrap: 'wrap' }}>
            {t.done ? <CheckCircle2 size={17} style={{ color: '#059669', flexShrink: 0 }} /> : <Clock size={17} style={{ color: '#d97706', flexShrink: 0 }} />}
            <span style={{ fontSize: 13.5, flex: 1, minWidth: 140 }}>{t.label}</span>
            {!t.done && t.type === 'ack' && <button onClick={() => submit(t.key, null, null)} disabled={busyKey === t.key} style={btn}>Mark done</button>}
            {!t.done && t.type === 'data' && (
              <div style={{ display: 'flex', gap: 6 }}>
                <input value={values[t.key]} onChange={e => setValues(v => ({ ...v, [t.key]: e.target.value }))} placeholder="Enter details" style={{ padding: '7px 10px', border: '1px solid #e2e8f0', borderRadius: 8, fontSize: 12.5 }} />
                <button onClick={() => submit(t.key, values[t.key], null)} disabled={busyKey === t.key || !values[t.key]} style={btn}>Save</button>
              </div>
            )}
            {!t.done && t.type === 'file' && (
              <>
                <button onClick={() => refs.current[t.key]?.click()} disabled={busyKey === t.key} style={{ ...btn, display: 'inline-flex', alignItems: 'center', gap: 5 }}><Upload size={12} /> Upload</button>
                <input ref={el => refs.current[t.key] = el} type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style={{ display: 'none' }} onChange={e => e.target.files?.[0] && submit(t.key, null, e.target.files[0])} />
              </>
            )}
            {t.done && <span style={{ fontSize: 11.5, color: '#059669', fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 4 }}><Check size={12} /> Done</span>}
          </div>
        ))}
      </div>
    </div>
  )
}

// Lightweight drawn-signature pad (canvas + pointer events, no external deps).
// Emits a base64 PNG data URL via onChange, or '' when cleared/empty.
function SignaturePad({ onChange }) {
  const canvasRef = useRef(null)
  const drawing = useRef(false)
  const dirty = useRef(false)

  const pos = (e) => {
    const c = canvasRef.current
    const r = c.getBoundingClientRect()
    return { x: (e.clientX - r.left) * (c.width / r.width), y: (e.clientY - r.top) * (c.height / r.height) }
  }
  const start = (e) => {
    e.preventDefault()
    drawing.current = true
    const ctx = canvasRef.current.getContext('2d')
    const { x, y } = pos(e)
    ctx.beginPath(); ctx.moveTo(x, y)
  }
  const move = (e) => {
    if (!drawing.current) return
    e.preventDefault()
    const ctx = canvasRef.current.getContext('2d')
    ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0f172a'
    const { x, y } = pos(e)
    ctx.lineTo(x, y); ctx.stroke()
    dirty.current = true
  }
  const end = () => {
    if (!drawing.current) return
    drawing.current = false
    if (dirty.current) onChange?.(canvasRef.current.toDataURL('image/png'))
  }
  const clear = () => {
    const c = canvasRef.current
    c.getContext('2d').clearRect(0, 0, c.width, c.height)
    dirty.current = false
    onChange?.('')
  }

  return (
    <div>
      <canvas
        ref={canvasRef} width={500} height={140}
        onPointerDown={start} onPointerMove={move} onPointerUp={end} onPointerLeave={end}
        style={{ width: '100%', height: 140, border: '1px dashed #cbd5e1', borderRadius: 10, background: '#f8fafc', touchAction: 'none', cursor: 'crosshair', display: 'block' }}
      />
      <button type="button" onClick={clear} style={{ marginTop: 8, padding: '6px 12px', borderRadius: 8, background: '#fff', border: '1px solid #e2e8f0', color: '#475569', cursor: 'pointer', fontWeight: 700, fontSize: 12 }}>Clear signature</button>
    </div>
  )
}

const btn = { padding: '7px 14px', borderRadius: 8, background: accent, color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 700, fontSize: 12.5 }
const Center = ({ children }) => <div style={{ minHeight: '100vh', background: '#f8fafc', display: 'flex', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: 24, color: '#64748b', fontFamily: 'system-ui,sans-serif' }}>{children}</div>
const Detail = ({ k, v, highlight }) => (
  <div style={{ background: highlight ? `${accent}0d` : '#f8fafc', border: `1px solid ${highlight ? accent + '30' : '#e2e8f0'}`, borderRadius: 12, padding: '12px 14px' }}>
    <div style={{ fontSize: 10.5, color: '#94a3b8', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>{k}</div>
    <div style={{ fontSize: 15, fontWeight: 700, marginTop: 3, color: highlight ? accent : '#0f172a' }}>{v}</div>
  </div>
)
const Banner = ({ icon, color, bg, children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 16, padding: '12px 16px', borderRadius: 12, background: bg, color, fontWeight: 600, fontSize: 13.5 }}>{icon}{children}</div>
)
