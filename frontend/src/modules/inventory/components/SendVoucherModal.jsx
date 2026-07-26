import { useState, useEffect } from 'react'
import { useMutation } from '@tanstack/react-query'
import { X, Mail, Plus, CheckCircle2 } from 'lucide-react'
import { inventoryApi, VOUCHER_TYPES } from '@/services/inventoryApi'

/**
 * Email a stock document to a supplier or customer (blueprint §2's
 * "send received note").
 *
 * The recipient list is built as chips rather than one comma-separated string,
 * so a typo in the fifth address doesn't silently take the other four down with
 * it — each one is validated as it's added. Sending is queued server-side.
 */
export default function SendVoucherModal({ type, voucher, onClose }) {
  const cfg = VOUCHER_TYPES[type] || {}
  const [to, setTo] = useState([])
  const [draft, setDraft] = useState('')
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [err, setErr] = useState('')
  const [sent, setSent] = useState(0)

  useEffect(() => {
    if (!voucher) return
    setTo([]); setDraft(''); setErr(''); setSent(0)
    setSubject(`${cfg.label || 'Document'} ${voucher.code}`)
    // Pre-fill the covering note with who it's for, so the common case is one click.
    const who = voucher.supplier_name || voucher.customer_name || ''
    setBody(who ? `Hi ${who},\n\nPlease find ${voucher.code} attached below.` : '')
  }, [voucher]) // eslint-disable-line react-hooks/exhaustive-deps

  const addEmail = (raw) => {
    const parts = String(raw || '').split(/[,\s]+/).map(x => x.trim()).filter(Boolean)
    const bad = parts.find(p => !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(p))
    if (bad) { setErr(`“${bad}” isn’t a valid email address.`); return }
    if (parts.length) { setTo(t => [...new Set([...t, ...parts])].slice(0, 10)); setDraft(''); setErr('') }
  }

  const send = useMutation({
    mutationFn: () => inventoryApi.vouchers.send(type, voucher.id, { to, subject, body }),
    onSuccess: (r) => { setSent(r?.sent || to.length); setErr('') },
    onError: (e) => setErr(e?.message || 'Could not send that document.'),
  })

  if (!voucher) return null

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 overflow-y-auto" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={onClose}>
      <div className="w-full max-w-[480px] rounded-2xl mt-[8vh] p-5" onClick={e => e.stopPropagation()}
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>

        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-base flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <Mail size={17} style={{ color: cfg.accent }} /> Send {voucher.code}
          </h2>
          <button onClick={onClose} aria-label="Close" className="hover:opacity-70"><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        {sent > 0 ? (
          <div className="text-center py-6">
            <CheckCircle2 size={32} style={{ color: 'var(--color-success-500)', margin: '0 auto 10px' }} />
            <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Sent to {sent} recipient{sent === 1 ? '' : 's'}</p>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Delivery is queued — it goes out in the background.</p>
            <button onClick={onClose} className="mt-5 px-4 py-2 rounded-xl text-xs font-bold" style={{ background: cfg.accent, color: '#fff' }}>Done</button>
          </div>
        ) : (
          <div className="space-y-3.5">
            <div>
              <label style={LBL}>Send to</label>
              <div className="flex flex-wrap gap-1.5 mb-1.5">
                {to.map(e => (
                  <span key={e} className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg"
                    style={{ background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
                    {e}
                    <button onClick={() => setTo(t => t.filter(x => x !== e))} aria-label={`Remove ${e}`} className="hover:opacity-60"><X size={10} /></button>
                  </span>
                ))}
              </div>
              <div className="flex gap-1.5">
                <input value={draft} onChange={e => setDraft(e.target.value)}
                  onKeyDown={e => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addEmail(draft) } }}
                  onBlur={() => draft && addEmail(draft)}
                  placeholder="name@supplier.com" style={INP} />
                <button onClick={() => addEmail(draft)} aria-label="Add recipient"
                  className="px-3 rounded-xl" style={{ border: '1px solid var(--border)', color: cfg.accent }}>
                  <Plus size={14} />
                </button>
              </div>
            </div>

            <div>
              <label style={LBL}>Subject</label>
              <input value={subject} onChange={e => setSubject(e.target.value)} style={INP} />
            </div>

            <div>
              <label style={LBL}>Message</label>
              <textarea value={body} onChange={e => setBody(e.target.value)} rows={4}
                style={{ ...INP, resize: 'vertical' }} placeholder="Optional covering note…" />
            </div>

            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              The document's line items and totals are included in the email automatically.
            </p>

            {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

            <div className="flex justify-end gap-2 pt-1">
              <button onClick={onClose} className="px-4 py-2 rounded-xl text-xs font-bold"
                style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
              <button disabled={!to.length || send.isPending} onClick={() => send.mutate()}
                className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold disabled:opacity-40"
                style={{ background: cfg.accent, color: '#fff' }}>
                <Mail size={13} /> {send.isPending ? 'Sending…' : 'Send'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

const INP = {
  width: '100%', padding: '9px 12px', borderRadius: 10, fontSize: 13,
  background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none',
}
const LBL = {
  display: 'block', fontSize: 10, fontWeight: 700, letterSpacing: '0.06em',
  textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 5,
}
