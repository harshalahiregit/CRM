import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeft, FileText, PenLine, Link2, Send, MessageSquare, CheckCircle2,
  Clock, Copy, RefreshCw, Pencil, ShieldCheck,
} from 'lucide-react'
import { contractModuleApi as api } from '@/services/contractModuleApi'
import SignaturePad from '../components/SignaturePad'
import SendContractModal from '../components/SendContractModal'
import RichText from '@/components/ui/RichText'
import { inputStyle, PRIMARY_GRADIENT } from '@/components/ui/kit3d'

/**
 * One contract: its terms, both signatures, the audit trail and the thread.
 *
 * The signature panel is the centre of this screen. It shows BOTH parties side
 * by side, each with when they opened the document, when they signed, from what
 * address and where — because that is the evidence the contract exists to carry,
 * and a single "signed ✓" would hide which half is still outstanding.
 */

const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 18 }

const fmt = (d, withTime = false) => d
  ? new Date(d).toLocaleString('en-IN', {
      day: '2-digit', month: 'short', year: 'numeric',
      ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    })
  : null

/** One party's signature block, with everything recorded about it. */
function SignatureBlock({ party, label, sig, canSign, onSign }) {
  const signed = Boolean(sig?.signed_at)

  return (
    <div style={{
      flex: 1, minWidth: 240, border: `1px solid ${signed ? 'rgba(16,185,129,.4)' : 'var(--border)'}`,
      borderRadius: 12, padding: 14, background: signed ? 'rgba(16,185,129,.04)' : 'var(--bg-input)',
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 10 }}>
        {signed ? <CheckCircle2 size={15} style={{ color: '#10b981' }} /> : <Clock size={15} style={{ color: '#f59e0b' }} />}
        <span style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '.05em', color: 'var(--text-muted)' }}>
          {label}
        </span>
      </div>

      {signed ? (
        <>
          {sig.method === 'type' ? (
            <div style={{ fontStyle: 'italic', fontSize: 22, color: 'var(--text-h)', padding: '4px 0' }}>{sig.signer_name}</div>
          ) : sig.method === 'stamp' ? (
            <div style={{ border: '2px solid #1f4ed8', color: '#1f4ed8', borderRadius: 6, padding: '8px 10px', textAlign: 'center', fontWeight: 800, fontSize: 10 }}>
              AUTHORISED SIGNATORY
            </div>
          ) : sig.image ? (
            <img src={sig.image} alt="" style={{ maxHeight: 54, background: '#fff', borderRadius: 6, padding: 4 }} />
          ) : null}

          <div style={{ fontWeight: 700, color: 'var(--text-h)', marginTop: 8 }}>{sig.signer_name}</div>

          {/* The audit trail, in the order a person reads it: what happened,
              then when, then from where. */}
          <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 8, lineHeight: 1.7 }}>
            {sig.viewed_at && <>Opened {fmt(sig.viewed_at, true)}<br /></>}
            Signed {fmt(sig.signed_at, true)}<br />
            IP {sig.signed_ip || 'not recorded'}<br />
            Location {sig.place || 'not provided'}
            {sig.certificate_no && <><br />Certificate <strong style={{ color: 'var(--text-h)' }}>{sig.certificate_no}</strong></>}
          </div>
        </>
      ) : (
        <>
          <div style={{ height: 34, borderBottom: '1px solid var(--border)', marginBottom: 8 }} />
          <div style={{ fontSize: 12, color: '#f59e0b', fontWeight: 700 }}>Awaiting signature</div>
          {sig?.viewed_at && (
            <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 6 }}>
              Opened the document {fmt(sig.viewed_at, true)}
            </div>
          )}
          {canSign && (
            <button onClick={onSign}
              style={{ marginTop: 12, display: 'flex', alignItems: 'center', gap: 6, padding: '7px 13px', borderRadius: 8, border: 'none', background: PRIMARY_GRADIENT, color: '#fff', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
              <PenLine size={13} /> Sign now
            </button>
          )}
        </>
      )}
    </div>
  )
}

export default function ContractDetail() {
  const { id } = useParams()
  const navigate = useNavigate()

  const [c, setC] = useState(null)
  const [err, setErr] = useState(null)
  const [pad, setPad] = useState(false)
  const [saving, setSaving] = useState(false)
  const [comment, setComment] = useState('')
  const [link, setLink] = useState(null)
  const [sendOpen, setSendOpen] = useState(false)
  const [sentNote, setSentNote] = useState(null)
  const [copied, setCopied] = useState(false)

  const load = useCallback(() => {
    api.get(id).then(setC).catch(() => setErr('That contract could not be loaded.'))
  }, [id])

  useEffect(() => { load() }, [load])

  const sign = async (sig) => {
    setSaving(true); setErr(null)
    try {
      await api.sign(id, sig)
      setPad(false)
      load()
    } catch (e) {
      setErr(e?.response?.data?.message || 'The signature could not be recorded.')
    } finally { setSaving(false) }
  }

  const openPdf = async () => {
    setErr(null)
    try { await api.openPdf(id) } catch { setErr('The PDF could not be opened.') }
  }

  const getLink = async () => {
    try {
      const d = await api.signingLink(id)
      setLink(d.url)
    } catch { setErr('The signing link could not be fetched.') }
  }

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(link)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch { /* clipboard blocked — the link is on screen to copy by hand */ }
  }

  const addComment = async () => {
    if (!comment.trim()) return
    try {
      await api.comment(id, comment.trim())
      setComment('')
      load()
    } catch { setErr('The comment could not be saved.') }
  }

  if (err && !c) return <div style={{ padding: 24, color: '#ef4444' }}>{err}</div>
  if (!c) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading…</div>

  const sigOf = (p) => c.signatures?.find(s => s.signer_party === p)
  const companySig = sigOf('company')
  const partySig = sigOf('party')

  return (
    <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 1020 }}>

      <button onClick={() => navigate('/app/contracts')}
        style={{ display: 'flex', alignItems: 'center', gap: 6, background: 'transparent', border: 'none', color: 'var(--text-muted)', fontSize: 13, cursor: 'pointer', padding: 0, alignSelf: 'flex-start' }}>
        <ArrowLeft size={15} /> Contracts
      </button>

      {/* ── Header ────────────────────────────────────────────── */}
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 14, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{c.title}</h1>
          <div style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 3 }}>
            {c.reference_no}{c.category?.name ? ` · ${c.category.name}` : ''}
            {c.party_name ? ` · with ${c.party_name}` : ''}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {/* A button, not a link: the PDF route is behind Sanctum and a plain
              href navigates without the token, so the tab opened blank. */}
          <button onClick={openPdf}
            style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 13px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>
            <FileText size={14} /> View PDF
          </button>
          {/* The main way to get a contract to the other side. The signing
              link beside it stays for the case where somebody wants to send it
              through their own mail client or a chat. */}
          <button onClick={() => setSendOpen(true)}
            style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, border: 'none', background: PRIMARY_GRADIENT, color: '#fff', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>
            <Send size={14} /> Send by email
          </button>
          <button onClick={getLink}
            style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 13px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>
            <Link2 size={14} /> Signing link
          </button>
          {!c.is_fully_signed && (
            <button onClick={() => navigate(`/app/contracts/${c.id}/edit`)}
              style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 13px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>
              <Pencil size={14} /> Edit
            </button>
          )}
        </div>
      </div>

      {sentNote && (
        <div style={{ ...card, padding: 12, display: 'flex', alignItems: 'center', gap: 9, border: '1px solid rgba(16,185,129,.4)', background: 'rgba(16,185,129,.06)' }}>
          <CheckCircle2 size={15} style={{ color: '#10b981' }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{sentNote}</span>
        </div>
      )}

      {link && (
        <div style={{ ...card, padding: 13, display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <Send size={15} style={{ color: '#7C3AED' }} />
          <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Send this to the other party:</span>
          <input readOnly value={link} onFocus={e => e.target.select()}
            style={{ ...inputStyle, flex: 1, minWidth: 220, fontSize: 12, fontFamily: 'monospace' }} />
          <button onClick={copy}
            style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '8px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: copied ? '#10b981' : 'var(--text-h)', fontSize: 12, cursor: 'pointer' }}>
            <Copy size={13} /> {copied ? 'Copied' : 'Copy'}
          </button>
        </div>
      )}

      {err && <div style={{ color: '#ef4444', fontSize: 13 }}>{err}</div>}

      {/* ── Signatures ────────────────────────────────────────── */}
      <div style={card}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
          <ShieldCheck size={15} style={{ color: c.is_fully_signed ? '#10b981' : 'var(--text-muted)' }} />
          <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.04em' }}>
            Signatures
          </h2>
        </div>
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 14px' }}>
          {c.is_fully_signed
            ? `Executed by both parties on ${fmt(c.fully_signed_at, true)}.`
            : 'This contract is in force only once both parties have signed.'}
        </p>

        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          <SignatureBlock party="party" label="Customer / Vendor" sig={partySig}
            canSign={false} onSign={() => {}} />
          <SignatureBlock party="company" label="Company representative" sig={companySig}
            canSign onSign={() => setPad(true)} />
        </div>

        {!partySig?.signed_at && (
          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '12px 0 0' }}>
            The other party signs through the signing link above — they do not need an account.
          </p>
        )}
      </div>

      {/* ── Terms ─────────────────────────────────────────────── */}
      {c.pages?.length > 0 && (
        <div style={card}>
          <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 12px', textTransform: 'uppercase', letterSpacing: '.04em' }}>
            Terms &amp; Conditions
          </h2>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
            {c.pages.map((p, i) => (
              <div key={p.id ?? i}>
                <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', marginBottom: 5 }}>
                  {i + 1}. {p.title || 'Terms'}
                </div>
                <div style={{ fontSize: 13, color: 'var(--text-b)', lineHeight: 1.7 }}>
                  {/* Rendered as rich text, not stripped: the terms are written in the
                    editor now, and stripping tags turned a formatted clause list
                    into one unreadable paragraph. The server sanitises on save. */}
                <RichText html={p.content} text={p.content} />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ── Discussion ────────────────────────────────────────── */}
      <div style={card}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 12 }}>
          <MessageSquare size={15} style={{ color: '#7C3AED' }} />
          <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.04em' }}>
            Discussion
          </h2>
        </div>

        <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
          <input value={comment} onChange={e => setComment(e.target.value)} style={inputStyle}
            placeholder="Add a comment or negotiate a change…"
            onKeyDown={e => { if (e.key === 'Enter') addComment() }} />
          <button onClick={addComment}
            style={{ padding: '9px 15px', borderRadius: 9, border: 'none', background: PRIMARY_GRADIENT, color: '#fff', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>
            Post
          </button>
        </div>

        {c.discussions?.length ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {c.discussions.map(d => (
              <div key={d.id} style={{
                padding: 11, borderRadius: 10, background: 'var(--bg-input)',
                // The counterparty's messages are marked, so nobody mistakes an
                // outside comment for an internal one.
                borderLeft: `3px solid ${d.is_external ? '#f59e0b' : '#7C3AED'}`,
              }}>
                <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', marginBottom: 3 }}>
                  <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{d.author_name}</strong>
                  {d.is_external && <span style={{ fontSize: 10, color: '#f59e0b', fontWeight: 700 }}>EXTERNAL</span>}
                  <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>{fmt(d.created_at, true)}</span>
                </div>
                <div style={{ fontSize: 13, color: 'var(--text-b)', whiteSpace: 'pre-wrap' }}>{d.body}</div>
              </div>
            ))}
          </div>
        ) : (
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>No comments yet.</p>
        )}
      </div>

      <SendContractModal
        open={sendOpen}
        contract={c}
        onSent={(payload) => api.send(c.id, payload)}
        onClose={(res) => {
          setSendOpen(false)
          if (res) {
            setSentNote('Contract sent. It is now marked Sent, and the other party can open and sign it.')
            load()
          }
        }} />

      <SignaturePad
        open={pad}
        onClose={() => setPad(false)}
        onSign={sign}
        saving={saving}
        partyLabel="Company representative"
      />
    </div>
  )
}
