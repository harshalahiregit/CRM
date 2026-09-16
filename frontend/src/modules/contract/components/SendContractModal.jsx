import { useState } from 'react'
import { Send, Paperclip, X, Plus } from 'lucide-react'
import { Overlay, ModalFooter, labelStyle, inputStyle, InfoBox } from '@/components/ui/kit3d'

/**
 * Send the contract to the party who has to sign it.
 *
 * The recipient is pre-filled from the contract's own party e-mail, because
 * that is who the agreement names — typing it again from memory is how a
 * contract goes to the wrong address.
 *
 * ── Templates ───────────────────────────────────────────────────────────
 * Three, kept in the component rather than in a settings table. They are
 * starting points a person edits before sending, not policy: a template that
 * needs a settings screen to change is one nobody changes, and the message that
 * accompanies a contract is exactly the kind of thing people want to reword for
 * each recipient.
 *
 * Closing follows the house rule — ✕ or Cancel only, never a backdrop click,
 * so a mis-click cannot discard a message somebody has typed.
 */

const TEMPLATES = {
  standard: {
    label: 'Standard',
    subject: (c) => `Contract for signature: ${c.title}`,
    body: (c) =>
      `<p>Dear ${c.party_name || 'Sir/Madam'},</p>`
      + `<p>Please find attached the contract <strong>${c.title}</strong> for your review and signature.</p>`
      + `<p>You can review the full terms and sign online using the button below. No account is needed.</p>`,
  },
  reminder: {
    label: 'Reminder',
    subject: (c) => `Reminder: ${c.title} is awaiting your signature`,
    body: (c) =>
      `<p>Dear ${c.party_name || 'Sir/Madam'},</p>`
      + `<p>This is a gentle reminder that the contract <strong>${c.title}</strong> is still awaiting your signature.</p>`
      + `<p>If anything in it needs discussing, reply to this e-mail or leave a comment on the contract page.</p>`,
  },
  renewal: {
    label: 'Renewal',
    subject: (c) => `Renewal of ${c.title}`,
    body: (c) =>
      `<p>Dear ${c.party_name || 'Sir/Madam'},</p>`
      + `<p>Our agreement is due for renewal. Attached is <strong>${c.title}</strong> covering the new term.</p>`
      + `<p>Please review and sign at your convenience.</p>`,
  },
}

/** Strip the tags the compose box shows as plain text, then put them back. */
const toPlain = (html) => String(html)
  .replace(/<\/p>\s*<p>/gi, '\n\n')
  .replace(/<br\s*\/?>/gi, '\n')
  .replace(/<[^>]*>/g, '')
  .trim()

const toHtml = (text) => String(text).trim()
  .split(/\n{2,}/)
  .map(p => `<p>${p.replace(/\n/g, '<br>')}</p>`)
  .join('')

export default function SendContractModal({ open, contract, onClose, onSent }) {
  const [template, setTemplate] = useState('standard')
  const [to, setTo] = useState(contract?.party_email || '')
  const [cc, setCc] = useState([])
  const [ccDraft, setCcDraft] = useState('')
  const [subject, setSubject] = useState(TEMPLATES.standard.subject(contract || {}))
  const [body, setBody] = useState(toPlain(TEMPLATES.standard.body(contract || {})))
  const [sending, setSending] = useState(false)
  const [err, setErr] = useState(null)

  if (!open) return null

  const pickTemplate = (key) => {
    setTemplate(key)
    setSubject(TEMPLATES[key].subject(contract))
    setBody(toPlain(TEMPLATES[key].body(contract)))
  }

  /**
   * Turn whatever is in the draft box into chips.
   *
   * Returns the resulting list so submit() can use it in the same tick — React
   * state does not update synchronously, so reading `cc` straight after calling
   * this would still see the old array.
   *
   * A pasted CC line is usually several addresses at once, so commas, spaces
   * and semicolons all split.
   */
  const flushCc = (raw = ccDraft) => {
    const parts = String(raw).split(/[,;\s]+/).map(s => s.trim()).filter(Boolean)
    if (!parts.length) return cc

    // A rough check only. The server validates properly, and a client-side rule
    // strict enough to be useful also rejects addresses that are valid.
    const bad = parts.filter(p => !p.includes('@'))
    if (bad.length) {
      // Silently emptying the box on a typo is what made this look broken:
      // the address vanished and nothing said why.
      setErr(`${bad.join(', ')} — that does not look like an email address.`)
      return cc
    }

    const next = [...cc]
    for (const p of parts) if (! next.includes(p)) next.push(p)

    setCc(next)
    setCcDraft('')
    setErr(null)

    return next
  }

  const submit = async () => {
    setErr(null)
    if (!to.trim()) { setErr('Enter the address to send this to.'); return }

    // An address typed but not yet turned into a chip is still an address the
    // person meant to copy in. Dropping it on Send is the likeliest way to
    // conclude that CC "does not work" — nothing is shown, nothing is warned,
    // and the mail simply goes out without them.
    const ccList = ccDraft.trim() ? flushCc() : cc
    if (ccDraft.trim() && ccList === cc) return   // flushCc set an error

    setSending(true)
    try {
      const res = await onSent({
        to: to.trim(),
        cc: ccList.length ? ccList : undefined,
        subject: subject.trim() || undefined,
        body: toHtml(body),
      })
      onClose(res)
    } catch (e) {
      // The tenant's SMTP is what actually failed here — a bad password or an
      // unreachable host. Show what the server said rather than "failed to
      // send", which leaves somebody guessing whether the vendor got it.
      setErr(e?.response?.data?.message || 'The e-mail could not be sent. Check the mail settings.')
    } finally { setSending(false) }
  }

  return (
    <Overlay onClose={() => onClose(null)} width={560}>
      <div style={{ padding: '18px 20px', borderBottom: '1px solid var(--border)' }}>
        <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>
          <Send size={16} style={{ color: '#7C3AED' }} /> Send contract
        </h2>
        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '4px 0 0' }}>
          {contract?.reference_no} · the PDF and the signing link go together.
        </p>
      </div>

      <div style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 13, maxHeight: '62vh', overflowY: 'auto' }}>

        <div>
          <label style={labelStyle}>Template</label>
          <div style={{ display: 'flex', gap: 6 }}>
            {Object.entries(TEMPLATES).map(([key, t]) => (
              <button key={key} type="button" onClick={() => pickTemplate(key)}
                style={{
                  padding: '6px 12px', borderRadius: 8, fontSize: 12, fontWeight: 600, cursor: 'pointer',
                  border: `1px solid ${template === key ? '#7C3AED' : 'var(--border)'}`,
                  background: template === key ? 'rgba(124,58,237,.10)' : 'var(--bg-input)',
                  color: template === key ? '#7C3AED' : 'var(--text-muted)',
                }}>
                {t.label}
              </button>
            ))}
          </div>
        </div>

        <div>
          <label style={labelStyle}>To <span style={{ color: '#ef4444' }}>*</span></label>
          <input value={to} onChange={e => setTo(e.target.value)} style={inputStyle}
            placeholder="accounts@vendor.com" />
          {!contract?.party_email && (
            <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '5px 0 0' }}>
              This contract has no e-mail on file for {contract?.party_name || 'the other party'} —
              the address you use here will be saved with it.
            </p>
          )}
        </div>

        <div>
          <label style={labelStyle}>CC</label>
          <div style={{ display: 'flex', gap: 6 }}>
            {/* Comma and Tab commit too, and the field is flushed on blur and
                again on Send — a CC field that only responds to Enter loses the
                address of anyone who typed it and clicked the button. */}
            <input value={ccDraft} onChange={e => setCcDraft(e.target.value)} style={inputStyle}
              placeholder="Add an address and press Enter"
              onBlur={() => { if (ccDraft.trim()) flushCc() }}
              onKeyDown={e => {
                if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') {
                  if (!ccDraft.trim()) return
                  e.preventDefault()
                  flushCc()
                }
              }} />
            <button type="button" onClick={() => flushCc()}
              style={{ padding: '0 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: '#7C3AED', cursor: 'pointer' }}>
              <Plus size={15} />
            </button>
          </div>
          {cc.length > 0 && (
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
              {cc.map(a => (
                <span key={a} style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '3px 9px', borderRadius: 20, background: 'var(--bg-input)', border: '1px solid var(--border)', fontSize: 11.5, color: 'var(--text-h)' }}>
                  {a}
                  <button type="button" onClick={() => setCc(l => l.filter(x => x !== a))}
                    style={{ background: 'transparent', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex', padding: 0 }}>
                    <X size={11} />
                  </button>
                </span>
              ))}
            </div>
          )}
        </div>

        <div>
          <label style={labelStyle}>Subject</label>
          <input value={subject} onChange={e => setSubject(e.target.value)} style={inputStyle} />
        </div>

        <div>
          <label style={labelStyle}>Message</label>
          <textarea value={body} onChange={e => setBody(e.target.value)} rows={7}
            style={{ ...inputStyle, resize: 'vertical', lineHeight: 1.6 }} />
        </div>

        <InfoBox tone="info">
          <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <Paperclip size={13} />
            The contract PDF is attached automatically, and the e-mail carries the signing
            link — they do not need an account to sign.
          </span>
        </InfoBox>

        {err && <div style={{ color: '#ef4444', fontSize: 12.5 }}>{err}</div>}
      </div>

      <ModalFooter onClose={() => onClose(null)} onConfirm={submit} loading={sending}
        confirmLabel={sending ? 'Sending…' : 'Send now'} />
    </Overlay>
  )
}
