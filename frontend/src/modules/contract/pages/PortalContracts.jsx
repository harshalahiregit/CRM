import { useCallback, useState } from 'react'
import { FileSignature, ArrowLeft, PenLine, CheckCircle2, MessageSquare } from 'lucide-react'
import PartyContractList from '../components/PartyContractList'
import RichText from '@/components/ui/RichText'
import { inputStyle, PRIMARY_GRADIENT } from '@/components/ui/kit3d'

/**
 * A customer's or vendor's own contracts, inside their portal.
 *
 * One page for all three portals — the caller passes the api client for the
 * portal it is mounted in. The party is resolved from the session server-side,
 * so this page never names an id and cannot ask for somebody else's agreements.
 *
 * Signing is NOT offered here. The signature has to be tied to a token that was
 * sent to a named person; a portal login is a company account that several
 * people may share, and "AlphaCo signed" is not a signature. The page points
 * them at the e-mailed link instead, which is the one addressed to them.
 */
export default function PortalContracts({ api, title = 'Contracts' }) {
  const [open, setOpen] = useState(null)
  const [comment, setComment] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const fetcher = useCallback(() => api.list(), [api])

  // The list already carries the terms, signatures and thread, so opening one
  // is local state rather than a second request — there is no detail endpoint
  // to call (see partyContractApi).
  const openOne = (row) => { setErr(null); setOpen(row) }

  const reload = async () => {
    const rows = await api.list()
    const fresh = (Array.isArray(rows) ? rows : []).find(r => r.id === open?.id)
    if (fresh) setOpen(fresh)
  }

  const post = async () => {
    if (!comment.trim() || !open) return
    setBusy(true); setErr(null)
    try {
      await api.comment(open.id, comment.trim())
      setComment('')
      await reload()
    } catch (e) {
      // The onboarding gate blocks writes for a vendor still awaiting approval —
      // say so plainly rather than showing a generic failure.
      setErr(e?.response?.data?.message || 'The comment could not be sent.')
    } finally { setBusy(false) }
  }

  const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 18 }

  if (open) {
    return (
      <div style={{ padding: 22, display: 'flex', flexDirection: 'column', gap: 14, maxWidth: 860 }}>
        <button onClick={() => { setOpen(null); setErr(null) }}
          style={{ display: 'flex', alignItems: 'center', gap: 6, background: 'transparent', border: 'none', color: 'var(--text-muted)', fontSize: 13, cursor: 'pointer', padding: 0, alignSelf: 'flex-start' }}>
          <ArrowLeft size={15} /> All contracts
        </button>

        <div>
          <h1 style={{ fontSize: 19, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{open.title}</h1>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '3px 0 0' }}>
            {open.reference_no}{open.category ? ` · ${open.category}` : ''}
          </p>
        </div>

        <div style={card}>
          <table style={{ width: '100%', fontSize: 13 }}>
            <tbody>
              {[
                ['Value', open.value ? `${open.currency} ${Number(open.value).toLocaleString('en-IN')}` : '—'],
                ['Term', `${open.start_date || '—'} to ${open.end_date || 'open-ended'}`],
                ['Status', open.is_fully_signed ? 'Signed by both parties' : 'Awaiting signature'],
              ].map(([k, v]) => (
                <tr key={k}>
                  <td style={{ padding: '4px 0', color: 'var(--text-muted)', width: 130 }}>{k}</td>
                  <td style={{ padding: '4px 0', color: 'var(--text-h)', fontWeight: 600 }}>{v}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <a href={open.pdf_url} target="_blank" rel="noreferrer"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 12, padding: '8px 13px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, textDecoration: 'none' }}>
            <FileSignature size={14} /> Download PDF
          </a>
        </div>

        {/* Signatures */}
        <div style={card}>
          <h2 style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 12px', textTransform: 'uppercase', letterSpacing: '.04em' }}>
            Signatures
          </h2>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            {(open.signatures || []).map(s => (
              <div key={s.party} style={{
                flex: 1, minWidth: 200, padding: 12, borderRadius: 10,
                border: `1px solid ${s.signed_at ? 'rgba(16,185,129,.4)' : 'var(--border)'}`,
                background: s.signed_at ? 'rgba(16,185,129,.05)' : 'var(--bg-input)',
              }}>
                <div style={{ fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 6 }}>
                  {s.party_label}
                </div>
                {s.signed_at ? (
                  <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 12.5, color: '#10b981', fontWeight: 700 }}>
                    <CheckCircle2 size={13} /> {s.signer_name}
                  </div>
                ) : (
                  <div style={{ fontSize: 12, color: '#f59e0b', fontWeight: 700 }}>Awaiting signature</div>
                )}
              </div>
            ))}
          </div>

          {open.awaiting_me && (
            <div style={{ marginTop: 12, padding: 11, borderRadius: 9, background: 'rgba(245,158,11,.08)', border: '1px solid rgba(245,158,11,.3)', display: 'flex', gap: 8 }}>
              <PenLine size={15} style={{ color: '#f59e0b', flexShrink: 0, marginTop: 1 }} />
              <span style={{ fontSize: 12.5, color: 'var(--text-b)', lineHeight: 1.55 }}>
                This contract is waiting for your signature. Please use the signing link that
                was e-mailed to you — it is addressed to the person authorised to sign.
              </span>
            </div>
          )}
        </div>

        {/* Terms */}
        {(open.pages || []).map((p, i) => (
          <div key={i} style={card}>
            <h2 style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 9px' }}>
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

        {/* Discussion */}
        <div style={card}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 11 }}>
            <MessageSquare size={15} style={{ color: '#7C3AED' }} />
            <h2 style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.04em' }}>
              Questions
            </h2>
          </div>

          <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
            <input value={comment} onChange={e => setComment(e.target.value)} style={inputStyle}
              placeholder="Ask a question about this contract…"
              onKeyDown={e => { if (e.key === 'Enter') post() }} />
            <button onClick={post} disabled={busy}
              style={{ padding: '9px 15px', borderRadius: 9, border: 'none', background: PRIMARY_GRADIENT, color: '#fff', fontSize: 12.5, fontWeight: 700, cursor: 'pointer', opacity: busy ? .7 : 1 }}>
              Send
            </button>
          </div>

          {err && <div style={{ color: '#ef4444', fontSize: 12.5, marginBottom: 8 }}>{err}</div>}

          {(open.discussions || []).length ? open.discussions.map((d, i) => (
            <div key={i} style={{ padding: 10, borderRadius: 9, background: 'var(--bg-input)', marginBottom: 8 }}>
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h)' }}>{d.author}</div>
              <div style={{ fontSize: 12.5, color: 'var(--text-b)', whiteSpace: 'pre-wrap', marginTop: 2 }}>{d.body}</div>
            </div>
          )) : (
            <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>No messages yet.</p>
          )}
        </div>
      </div>
    )
  }

  return (
    <div style={{ padding: 22, display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div>
        <h1 style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 19, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>
          <FileSignature size={19} style={{ color: '#7C3AED' }} /> {title}
        </h1>
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '3px 0 0' }}>
          Agreements between you and us. Drafts are not shown until they are sent to you.
        </p>
      </div>

      {err && <div style={{ color: '#ef4444', fontSize: 12.5 }}>{err}</div>}

      <PartyContractList
        fetcher={fetcher}
        onOpen={openOne}
        compact
        emptyText="No contracts have been shared with you yet."
      />
    </div>
  )
}
