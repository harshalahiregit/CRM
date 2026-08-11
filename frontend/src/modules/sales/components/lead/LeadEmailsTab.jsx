import { useState, useEffect, useCallback } from 'react'
import { Mail, Send, ArrowUpRight, ArrowDownLeft, AlertCircle, Loader2, ChevronDown } from 'lucide-react'
import { leadEngagementApi } from '@/services/leadEngagementApi'
import EmptyState from '@/components/ui/EmptyState'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { richHtml } from '@/lib/richText'
import { useToast } from '@/hooks/useToast'

const fmtDT = s => s
  ? new Date(s).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  : '—'

/**
 * Email activity for a lead: compose and send, plus the log of what was sent.
 *
 * The old CRM's version was read-only and showed only INBOUND mail captured by an
 * IMAP cron. We have no inbound plumbing, so this is the honest inverse — it logs
 * what the CRM actually sent, and a failed send stays in the list marked failed
 * rather than vanishing and implying the mail went out.
 */
export default function LeadEmailsTab({ lead }) {
  const toast = useToast()
  const [rows, setRows] = useState(null)
  const [composing, setComposing] = useState(false)
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [to, setTo] = useState(lead.email || '')
  const [sending, setSending] = useState(false)
  const [open, setOpen] = useState({})   // expanded bodies, keyed by id

  const load = useCallback(() => {
    leadEngagementApi.emails.list(lead.id)
      .then(d => setRows(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => { toast.error(e.message); setRows([]) })
  }, [lead.id])
  useEffect(() => { load() }, [load])

  const send = async () => {
    if (!to.trim()) return toast.error('No recipient — add an email to this lead first')
    if (!subject.trim()) return toast.error('Add a subject')
    if (!body.replace(/<[^>]*>/g, '').trim()) return toast.error('Write a message')
    setSending(true)
    try {
      await leadEngagementApi.emails.send(lead.id, { to_email: to.trim(), subject: subject.trim(), body })
      toast.success('Email sent')
      setSubject(''); setBody(''); setComposing(false)
    } catch (e) {
      // The attempt is logged either way, so refresh to show the failed row.
      toast.error(e.message)
    } finally { setSending(false); load() }
  }

  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <div className="flex items-center justify-between mb-4">
        <h3 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <Mail size={14} style={{ color: 'var(--accent)' }} /> Email activity
          {!!rows?.length && <span className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>{rows.length}</span>}
        </h3>
        <button onClick={() => setComposing(c => !c)}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold"
          style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)', border: '1px solid var(--border)' }}>
          <Send size={12} /> Compose
        </button>
      </div>

      {!lead.email && !composing && (
        <p className="text-xs mb-3 px-3 py-2 rounded-lg"
          style={{ background: 'rgba(245,158,11,0.08)', color: '#f59e0b' }}>
          This lead has no email address — add one on the Profile tab before sending.
        </p>
      )}

      {composing && (
        <div className="rounded-xl p-3 mb-4 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <div>
            <label className="label">To</label>
            <input className="input-3d text-sm" placeholder="name@company.com" value={to} onChange={e => setTo(e.target.value)} />
          </div>
          <div>
            <label className="label">Subject</label>
            <input className="input-3d text-sm" placeholder="Following up on your enquiry"
              value={subject} onChange={e => setSubject(e.target.value)} />
          </div>
          <div>
            <label className="label">Message</label>
            <RichTextEditor value={body} onChange={setBody} placeholder="Write your message…" minHeight={140} />
          </div>
          <div className="flex justify-end gap-2">
            <button onClick={() => setComposing(false)} className="px-3 py-1.5 rounded-lg text-xs font-semibold"
              style={{ background: 'var(--bg-card)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button onClick={send} disabled={sending}
              className="px-4 py-1.5 rounded-lg text-xs font-bold text-white inline-flex items-center gap-1.5 disabled:opacity-60"
              style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
              {sending ? <Loader2 size={11} className="animate-spin" /> : <Send size={11} />} Send
            </button>
          </div>
        </div>
      )}

      {rows === null ? (
        <div className="skeleton h-20 rounded-xl" style={{ background: 'var(--border)' }} />
      ) : !rows.length ? (
        <EmptyState icon={Mail} title="No emails yet" description="Mail sent to this lead from the CRM is recorded here." />
      ) : (
        <div className="space-y-2">
          {rows.map(e => {
            const failed = e.status === 'failed'
            const Dir = e.direction === 'inbound' ? ArrowDownLeft : ArrowUpRight
            const expanded = !!open[e.id]
            return (
              <div key={e.id} className="rounded-xl px-3 py-2.5"
                style={{
                  border: `1px solid ${failed ? 'rgba(239,68,68,0.3)' : 'var(--border)'}`,
                  background: failed ? 'rgba(239,68,68,0.04)' : 'var(--bg-card)',
                }}>
                <button onClick={() => setOpen(o => ({ ...o, [e.id]: !o[e.id] }))}
                  className="w-full flex items-start gap-2.5 text-left">
                  <div className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
                    style={{ background: failed ? 'rgba(239,68,68,0.12)' : 'rgba(124,58,237,0.12)' }}>
                    {failed ? <AlertCircle size={13} style={{ color: '#f87171' }} />
                            : <Dir size={13} style={{ color: '#a78bfa' }} />}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-xs font-bold truncate" style={{ color: 'var(--text-h)' }}>{e.subject}</p>
                    <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                      {e.direction === 'inbound' ? `from ${e.from_email || '—'}` : `to ${e.to_email || '—'}`}
                      {' · '}{fmtDT(e.sent_at || e.created_at)}
                      {e.sender?.name ? ` · ${e.sender.name}` : ''}
                      {failed ? ' · not delivered' : ''}
                    </p>
                  </div>
                  <ChevronDown size={13} className="flex-shrink-0 transition-transform"
                    style={{ color: 'var(--text-muted)', transform: expanded ? 'rotate(180deg)' : 'none' }} />
                </button>

                {expanded && (
                  <div className="mt-2 pt-2" style={{ borderTop: '1px solid var(--border)' }}>
                    {failed && e.error && (
                      <p className="text-[11px] mb-2 px-2 py-1.5 rounded-lg"
                        style={{ background: 'rgba(239,68,68,0.08)', color: '#f87171' }}>
                        Delivery failed: {e.error}
                      </p>
                    )}
                    <div className="rich-content text-xs" style={{ color: 'var(--text-h)' }}
                      dangerouslySetInnerHTML={richHtml(e.body)} />
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
