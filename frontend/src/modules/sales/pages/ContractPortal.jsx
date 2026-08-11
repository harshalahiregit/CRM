import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { XCircle, PenLine, ShieldCheck, Download, MessageSquare } from 'lucide-react'
import { publicContractApi } from '@/services/publicProposalApi'
import SignatureModal from '../components/SignatureModal'
import { richHtml } from '@/lib/richText'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const d10 = s => (s ? String(s).slice(0, 10) : '—')

/** Public contract view + client signing — /portal/contracts/:token (QR target). */
export default function ContractPortal() {
  const { token } = useParams()
  const [doc, setDoc] = useState(null)
  const [state, setState] = useState('loading')
  const [signOpen, setSignOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    publicContractApi.get(token).then(d => { setDoc(d); setState('ready') }).catch(() => setState('notfound'))
  }, [token])

  const doSign = async (payload) => {
    setBusy(true); setError(null)
    try { const d = await publicContractApi.sign(token, payload); setDoc(d); setSignOpen(false) }
    catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  return (
    <div className="min-h-screen py-8 px-4" style={{ background: 'var(--bg-body, #f1f5f9)' }}>
      {state === 'loading' && <div className="mx-auto max-w-xl mt-24 skeleton h-40 rounded-2xl" style={{ background: 'var(--border, #e2e8f0)' }} />}

      {state === 'notfound' && (
        <div className="mx-auto max-w-md mt-24 card-3d text-center" style={{ padding: '40px' }}>
          <XCircle size={28} className="mx-auto mb-3" style={{ color: '#ef4444' }} />
          <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Contract not found</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>This link is invalid or has been withdrawn.</p>
        </div>
      )}

      {state === 'ready' && doc && (
        <div className="mx-auto space-y-5" style={{ maxWidth: 820 }}>
          <div className="card-3d" style={{ padding: 'clamp(24px, 5vw, 48px)' }}>
            <div className="pb-5 mb-6" style={{ borderBottom: '2px solid var(--accent)' }}>
              <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,3vw,1.8rem)', color: 'var(--text-h)' }}>{doc.subject}</h1>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                {doc.reference_no} {doc.type ? `· ${doc.type}` : ''} · {doc.client}
              </p>
            </div>

            <div className="grid sm:grid-cols-3 gap-3 mb-6 text-xs">
              <div className="p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <p style={{ color: 'var(--text-muted)' }}>Value</p><p className="font-black text-sm" style={{ color: 'var(--text-h)' }}>{fmt(doc.value)}</p>
              </div>
              <div className="p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <p style={{ color: 'var(--text-muted)' }}>Start</p><p className="font-black text-sm" style={{ color: 'var(--text-h)' }}>{d10(doc.start_date)}</p>
              </div>
              <div className="p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <p style={{ color: 'var(--text-muted)' }}>End</p><p className="font-black text-sm" style={{ color: 'var(--text-h)' }}>{d10(doc.end_date)}</p>
              </div>
            </div>

            {doc.description && <div className="rich-content text-sm mb-6" style={{ color: 'var(--text-h)' }} dangerouslySetInnerHTML={richHtml(doc.description)} />}

            {(doc.pages || []).map((pg, i) => (
              <div key={i} className="mb-8">
                <h2 className="font-black text-base mb-3 pb-2" style={{ color: 'var(--text-h)', borderBottom: '1px solid var(--border)' }}>{pg.title}</h2>
                <div className="rich-content text-sm" style={{ color: 'var(--text-h)' }} dangerouslySetInnerHTML={{ __html: pg.content }} />
              </div>
            ))}

            {/* Download */}
            <div className="flex justify-end mb-2">
              <a href={publicContractApi.pdfUrl(token)} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', textDecoration: 'none' }}>
                <Download size={13} /> Download PDF
              </a>
            </div>

            {/* Signature block */}
            <div className="pt-5 mt-6" style={{ borderTop: '1px solid var(--border)' }}>
              {doc.signed_at ? (
                <div>
                  <p className="flex items-center gap-1.5 text-sm font-bold" style={{ color: '#10b981' }}><ShieldCheck size={15} /> Signed by {doc.signed_by}</p>
                  {doc.signature_image && <img src={doc.signature_image} alt="signature" className="mt-2 rounded-xl" style={{ maxHeight: 80, background: '#fff', padding: 8, border: '1px solid var(--border)' }} />}
                  <p className="text-[11px] mt-1.5" style={{ color: 'var(--text-muted)' }}>Signed on {String(doc.signed_at).slice(0, 10)} — this record serves as the acceptance certificate.</p>
                </div>
              ) : (
                <div className="text-center py-4">
                  <p className="text-sm mb-3" style={{ color: 'var(--text-muted)' }}>This contract awaits your signature.</p>
                  <button onClick={() => setSignOpen(true)} className="px-8 py-3 rounded-2xl text-sm font-bold text-white inline-flex items-center gap-2" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
                    <PenLine size={15} /> Review & Sign
                  </button>
                  {error && <p className="text-xs mt-2" style={{ color: '#ef4444' }}>{error}</p>}
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {state === 'ready' && doc && (
        <div className="mx-auto mt-5" style={{ maxWidth: 820 }}>
          <Discussion token={token} doc={doc} onUpdated={setDoc} />
        </div>
      )}

      {signOpen && <SignatureModal title="Sign this contract" onSign={doSign} onClose={() => setSignOpen(false)} busy={busy} />}
    </div>
  )
}


/** Client-side discussion thread (negotiation happens on the contract itself). */
function Discussion({ token, doc, onUpdated }) {
  const [name, setName] = useState('')
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const comments = doc.comments || []

  const post = async () => {
    if (!body.trim()) return
    setBusy(true); setError(null)
    try {
      const updated = await publicContractApi.comment(token, { name: name.trim() || undefined, body })
      onUpdated(updated); setBody('')
    } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <p className="flex items-center gap-1.5 font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>
        <MessageSquare size={14} /> Discussion
      </p>
      {comments.map(c => (
        <div key={c.id} className="py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
          <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-h)' }}>{c.body}</p>
          <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{c.author}{c.is_staff ? ' (staff)' : ''} · {String(c.at || '').slice(0, 16)}</p>
        </div>
      ))}
      {!comments.length && <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>No comments yet — questions or change requests about the terms go here.</p>}
      <div className="mt-3 space-y-2">
        <input className="input-3d text-sm" placeholder="Your name (optional)" value={name} onChange={e => setName(e.target.value)} />
        <textarea rows={2} className="input-3d text-sm resize-none" placeholder="Write a comment…" value={body} onChange={e => setBody(e.target.value)} />
        <div className="flex items-center justify-end gap-3">
          {error && <p className="text-xs" style={{ color: '#ef4444' }}>{error}</p>}
          <button onClick={post} disabled={busy} className="px-4 py-2 rounded-xl text-xs font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            {busy ? 'Posting…' : 'Post Comment'}
          </button>
        </div>
      </div>
    </div>
  )
}
