import { useState } from 'react'
import { createPortal } from 'react-dom'
import { X, Send } from 'lucide-react'
import { proposalApi } from '@/services/proposalApi'
import { useToast } from '@/hooks/useToast'
import RichTextEditor from '@/components/ui/RichTextEditor'

const defaultBody = (proposal, contactName) => `
<p>Dear ${contactName || 'Sir/Madam'},</p>
<p>Please find our proposal <b>${proposal.subject}</b> attached as a PDF. You can also review and respond to it online using the button below.</p>
<p>We look forward to working with you.</p>
<p>Best regards</p>`.trim()

/**
 * B-4 submit popup: pre-drafted editable email, To locked to the recipient
 * contact, editable CC chips. Also used from ProposalDetail for resends.
 */
export default function ProposalSubmitModal({ proposal, contact, onClose, onSent, resend = false }) {
  const toast = useToast()
  const to = contact?.email || proposal.contact?.email || proposal.email
  const [subject, setSubject] = useState(proposal.email_subject || `Proposal: ${proposal.subject}`)
  const [body, setBody] = useState(proposal.email_body || defaultBody(proposal, contact?.name || proposal.contact?.name))
  const [cc, setCc] = useState(proposal.email_cc || [])
  const [ccInput, setCcInput] = useState('')
  const [sending, setSending] = useState(false)

  const addCc = () => {
    const v = ccInput.trim().toLowerCase()
    if (!v) return
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) return toast.error('Invalid CC email')
    if (cc.length >= 10) return toast.error('Max 10 CC addresses')
    if (!cc.includes(v)) setCc([...cc, v])
    setCcInput('')
  }

  const send = async () => {
    setSending(true)
    try {
      await proposalApi.submit(proposal.id, { subject, body, cc })
      onSent()
    } catch (e) { toast.error(e.message) } finally { setSending(false) }
  }

  return createPortal(
    <>
      <div className="drawer-backdrop" onClick={onClose} />
      <div className="drawer-panel" style={{ width: 'min(680px, 96vw)' }}>
        <div className="drawer-header">
          <div>
            <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{resend ? 'Resend Proposal' : 'Send Proposal'}</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Email with PDF attachment + secure online link</p>
          </div>
          <button onClick={onClose} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)]" style={{ border: '1px solid var(--border)' }}>
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="drawer-body space-y-4">
          <div>
            <label className="label">To</label>
            <input className="input-3d text-sm" value={to || ''} readOnly style={{ opacity: 0.7 }} />
            {!to && <p className="text-[11px] mt-1" style={{ color: '#ef4444' }}>No recipient email — assign a contact with an email address first.</p>}
          </div>
          <div>
            <label className="label">CC</label>
            <div className="flex flex-wrap gap-1.5 mb-1.5">
              {cc.map(e => (
                <span key={e} className="flex items-center gap-1 px-2 py-1 rounded-lg text-[11px] font-bold" style={{ background: 'rgba(124,58,237,0.08)', color: 'var(--accent)' }}>
                  {e}
                  <button onClick={() => setCc(cc.filter(x => x !== e))}><X size={10} /></button>
                </span>
              ))}
            </div>
            <input className="input-3d text-sm" placeholder="Add CC email and press Enter" value={ccInput}
              onChange={e => setCcInput(e.target.value)}
              onKeyDown={e => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addCc() } }}
              onBlur={addCc} />
          </div>
          <div><label className="label">Subject</label><input className="input-3d text-sm" value={subject} onChange={e => setSubject(e.target.value)} /></div>
          <div>
            <label className="label">Message</label>
            <RichTextEditor value={body} onChange={setBody} minHeight={180} />
          </div>
        </div>

        <div className="drawer-footer">
          <button onClick={onClose} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={send} disabled={sending || !to} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white flex items-center justify-center gap-2 disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 6px 20px rgba(16,185,129,0.35)' }}>
            <Send size={14} /> {sending ? 'Sending…' : resend ? 'Resend Email' : 'Send to Client'}
          </button>
        </div>
      </div>
    </>,
    document.body,
  )
}
