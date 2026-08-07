import { useState } from 'react'
import { createPortal } from 'react-dom'
import { X, Send, Paperclip, FileText, Link2 } from 'lucide-react'
import { proposalApi } from '@/services/proposalApi'
import { useToast } from '@/hooks/useToast'
import RichTextEditor from '@/components/ui/RichTextEditor'

const MAX_FILE = 5 * 1024 * 1024   // 5 MB per file
const MAX_FILES = 5
const humanSize = (b) => b < 1024 * 1024 ? `${Math.round(b / 1024)} KB` : `${(b / 1024 / 1024).toFixed(1)} MB`

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
  const [attachments, setAttachments] = useState([])   // [{name, mime, data, size}]
  const [sending, setSending] = useState(false)

  const onFiles = (e) => {
    const files = Array.from(e.target.files || [])
    e.target.value = ''   // allow re-selecting the same file
    for (const f of files) {
      if (attachments.length >= MAX_FILES) return toast.error(`Up to ${MAX_FILES} attachments`)
      if (f.size > MAX_FILE) { toast.error(`${f.name} is larger than 5 MB`); continue }
      const reader = new FileReader()
      reader.onload = () => setAttachments(prev =>
        prev.length >= MAX_FILES ? prev : [...prev, { name: f.name, mime: f.type, data: reader.result, size: f.size }])
      reader.readAsDataURL(f)
    }
  }
  const removeAttachment = (i) => setAttachments(prev => prev.filter((_, idx) => idx !== i))

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
      await proposalApi.submit(proposal.id, {
        subject, body, cc,
        attachments: attachments.map(({ name, mime, data }) => ({ name, mime, data })),
      })
      onSent()
    } catch (e) { toast.error(e.message) } finally { setSending(false) }
  }

  return createPortal(
    <>
      <div className="drawer-backdrop" />
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

          {/* Attachments */}
          <div>
            <label className="label">Attachments</label>
            {/* Always-included items */}
            <div className="flex flex-wrap gap-2 mb-2">
              <span className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-semibold"
                style={{ background: 'rgba(16,185,129,0.1)', color: '#059669', border: '1px solid rgba(16,185,129,0.2)' }}>
                <FileText size={12} /> Proposal PDF · attached automatically
              </span>
              <span className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-semibold"
                style={{ background: 'rgba(124,58,237,0.08)', color: 'var(--accent)', border: '1px solid rgba(124,58,237,0.18)' }}>
                <Link2 size={12} /> Secure online link · included in email
              </span>
            </div>
            {/* User-added files */}
            {attachments.length > 0 && (
              <div className="space-y-1.5 mb-2">
                {attachments.map((a, i) => (
                  <div key={i} className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <Paperclip size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                    <span className="flex-1 truncate" style={{ color: 'var(--text-h)' }}>{a.name}</span>
                    <span style={{ color: 'var(--text-muted)' }}>{humanSize(a.size)}</span>
                    <button onClick={() => removeAttachment(i)}><X size={13} style={{ color: 'var(--text-muted)' }} /></button>
                  </div>
                ))}
              </div>
            )}
            <label className="inline-flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold cursor-pointer"
              style={{ background: 'var(--bg-input)', color: 'var(--accent)', border: '1px dashed var(--border)' }}>
              <Paperclip size={13} /> Add attachment
              <input type="file" multiple className="hidden" onChange={onFiles} />
            </label>
            <p className="text-[11px] mt-1.5" style={{ color: 'var(--text-muted)' }}>Up to {MAX_FILES} files, 5 MB each.</p>
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
