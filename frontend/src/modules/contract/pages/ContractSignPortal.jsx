import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { FileText, CheckCircle2, ShieldCheck, MessageSquare, PenLine } from 'lucide-react'
import { publicContractApi as api } from '@/services/contractModuleApi'
import SignaturePad from '../components/SignaturePad'
import RichText from '@/components/ui/RichText'
import { inputStyle, PRIMARY_GRADIENT } from '@/components/ui/kit3d'

/**
 * The counterparty's page — no login, reached by the signing link.
 *
 * Whoever opens this is a customer or a vendor, not a colleague, so it carries
 * none of the application shell and shows only what the brief says they may
 * see: the agreement, the two signature slots, and the thread they can
 * negotiate in. Merely opening it records the view, which is half of the audit
 * trail the signed PDF later prints.
 */
export default function ContractSignPortal() {
  const { token } = useParams()
  const [c, setC] = useState(null)
  const [err, setErr] = useState(null)
  const [pad, setPad] = useState(false)
  const [saving, setSaving] = useState(false)
  const [comment, setComment] = useState('')

  const load = useCallback(() => {
    api.get(token)
      .then(setC)
      .catch(() => setErr('This link is not valid. Please ask for a new one.'))
  }, [token])

  useEffect(() => { load() }, [load])

  const sign = async (sig) => {
    setSaving(true); setErr(null)
    try {
      const updated = await api.sign(token, sig)
      setC(updated)
      setPad(false)
    } catch (e) {
      setErr(e?.response?.data?.message || 'The signature could not be recorded.')
    } finally { setSaving(false) }
  }

  const post = async () => {
    if (!comment.trim()) return
    try {
      const updated = await api.comment(token, comment.trim(), c?.party_name)
      setC(updated)
      setComment('')
    } catch { setErr('The comment could not be sent.') }
  }

  if (err && !c) {
    return (
      <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', background: 'var(--bg)', padding: 24 }}>
        <p style={{ color: '#ef4444', fontSize: 14 }}>{err}</p>
      </div>
    )
  }
  if (!c) {
    return <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', color: 'var(--text-muted)' }}>Loading…</div>
  }

  const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 20 }
  const mySig = c.signatures?.find(s => s.party === 'party')

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg)', padding: '28px 18px' }}>
      <div style={{ maxWidth: 780, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 16 }}>

        <div style={{ textAlign: 'center' }}>
          <h1 style={{ fontSize: 21, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{c.title}</h1>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '5px 0 0' }}>
            {c.reference_no}{c.category ? ` · ${c.category}` : ''}
          </p>
        </div>

        {c.is_fully_signed && (
          <div style={{ ...card, padding: 14, display: 'flex', alignItems: 'center', gap: 10, border: '1px solid rgba(16,185,129,.4)', background: 'rgba(16,185,129,.06)' }}>
            <ShieldCheck size={17} style={{ color: '#10b981' }} />
            <span style={{ fontSize: 13, color: 'var(--text-h)' }}>
              This contract has been signed by both parties. Keep a copy for your records.
            </span>
          </div>
        )}

        {/* ── Summary ───────────────────────────────────────── */}
        <div style={card}>
          <table style={{ width: '100%', fontSize: 13 }}>
            <tbody>
              {[
                ['Between', c.party_name || '—'],
                ['Value', c.value ? `${c.currency} ${Number(c.value).toLocaleString('en-IN')}` : '—'],
                ['Term', `${c.start_date || '—'} to ${c.end_date || 'open-ended'}`],
              ].map(([k, v]) => (
                <tr key={k}>
                  <td style={{ padding: '5px 0', color: 'var(--text-muted)', width: 130 }}>{k}</td>
                  <td style={{ padding: '5px 0', color: 'var(--text-h)', fontWeight: 600 }}>{v}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {c.description && (
            <div style={{ fontSize: 13, color: 'var(--text-b)', lineHeight: 1.7, margin: '12px 0 0' }}>
              <RichText html={c.description} text={c.description} />
            </div>
          )}
          <a href={api.pdfUrl(token)} target="_blank" rel="noreferrer"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 14, padding: '8px 13px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, textDecoration: 'none' }}>
            <FileText size={14} /> Download PDF
          </a>
        </div>

        {/* ── The terms themselves ──────────────────────────── */}
        {c.pages?.map((p, i) => (
          <div key={i} style={card}>
            <h2 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 10px' }}>
              {i + 1}. {p.title || 'Terms and Conditions'}
            </h2>
            <div style={{ fontSize: 13, color: 'var(--text-b)', lineHeight: 1.75 }}>
              {/* Rendered as rich text, not stripped: the terms are written in the
                    editor now, and stripping tags turned a formatted clause list
                    into one unreadable paragraph. The server sanitises on save. */}
                <RichText html={p.content} text={p.content} />
            </div>
          </div>
        ))}

        {/* ── Signing ───────────────────────────────────────── */}
        <div style={card}>
          <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 12px', textTransform: 'uppercase', letterSpacing: '.04em' }}>
            Signatures
          </h2>

          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            {c.signatures?.map(s => (
              <div key={s.party} style={{
                flex: 1, minWidth: 210, padding: 12, borderRadius: 10,
                border: `1px solid ${s.signed_at ? 'rgba(16,185,129,.4)' : 'var(--border)'}`,
                background: s.signed_at ? 'rgba(16,185,129,.05)' : 'var(--bg-input)',
              }}>
                <div style={{ fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 7 }}>
                  {s.party_label}
                </div>
                {s.signed_at ? (
                  <>
                    {s.method === 'type'
                      ? <div style={{ fontStyle: 'italic', fontSize: 20, color: 'var(--text-h)' }}>{s.signer_name}</div>
                      : s.image ? <img src={s.image} alt="" style={{ maxHeight: 46, background: '#fff', borderRadius: 5, padding: 4 }} /> : null}
                    <div style={{ display: 'flex', alignItems: 'center', gap: 5, marginTop: 7, fontSize: 12, color: '#10b981', fontWeight: 700 }}>
                      <CheckCircle2 size={13} /> {s.signer_name}
                    </div>
                    {s.certificate_no && (
                      <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 4 }}>
                        Certificate {s.certificate_no}
                      </div>
                    )}
                  </>
                ) : (
                  <div style={{ fontSize: 12, color: '#f59e0b', fontWeight: 700, paddingTop: 14 }}>Awaiting signature</div>
                )}
              </div>
            ))}
          </div>

          {c.awaiting_me ? (
            <button onClick={() => setPad(true)}
              style={{ marginTop: 14, display: 'flex', alignItems: 'center', gap: 7, padding: '11px 20px', borderRadius: 10, border: 'none', background: PRIMARY_GRADIENT, color: '#fff', fontSize: 13.5, fontWeight: 700, cursor: 'pointer' }}>
              <PenLine size={15} /> Review and sign
            </button>
          ) : (
            <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '14px 0 0' }}>
              {mySig?.signed_at
                ? 'You have signed this contract. Thank you.'
                : 'Waiting for the other party to sign.'}
            </p>
          )}

          {err && <div style={{ color: '#ef4444', fontSize: 12.5, marginTop: 10 }}>{err}</div>}
        </div>

        {/* ── Negotiation ───────────────────────────────────── */}
        <div style={card}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 11 }}>
            <MessageSquare size={15} style={{ color: '#7C3AED' }} />
            <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.04em' }}>
              Questions or changes
            </h2>
          </div>

          <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
            <input value={comment} onChange={e => setComment(e.target.value)} style={inputStyle}
              placeholder="Ask a question or request a change…"
              onKeyDown={e => { if (e.key === 'Enter') post() }} />
            <button onClick={post}
              style={{ padding: '9px 15px', borderRadius: 9, border: 'none', background: PRIMARY_GRADIENT, color: '#fff', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>
              Send
            </button>
          </div>

          {c.discussions?.length ? c.discussions.map((d, i) => (
            <div key={i} style={{ padding: 10, borderRadius: 9, background: 'var(--bg-input)', marginBottom: 8 }}>
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h)' }}>{d.author}</div>
              <div style={{ fontSize: 12.5, color: 'var(--text-b)', whiteSpace: 'pre-wrap', marginTop: 2 }}>{d.body}</div>
            </div>
          )) : (
            <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>No messages yet.</p>
          )}
        </div>
      </div>

      <SignaturePad
        open={pad}
        onClose={() => setPad(false)}
        onSign={sign}
        saving={saving}
        partyLabel={c.party_name || 'Customer / Vendor'}
      />
    </div>
  )
}
