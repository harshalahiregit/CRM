import { useState } from 'react'
import { Send, Paperclip, MessageSquare, CheckCircle2, XCircle, PauseCircle, Stethoscope, Upload } from 'lucide-react'
import { S, humanise } from './MedicalBits'

/**
 * The back-and-forth between the quality team and the vendor, in order.
 *
 * This is the screen the requirement calls "log and map the complete
 * communication history". It shows every submission, verdict and reply as one
 * thread, says which round each belongs to, and — importantly — says how many
 * rounds are left, because the exchange is capped and someone arguing their
 * ninth revision deserves to know that.
 */

const ICONS = {
  submitted: Stethoscope,
  resubmitted: Upload,
  reexamined: Stethoscope,
  approved: CheckCircle2,
  rejected: XCircle,
  hold: PauseCircle,
  comment: MessageSquare,
}

const TONES = {
  submitted: '#6366f1',
  resubmitted: '#6366f1',
  reexamined: '#8b5cf6',
  approved: '#10b981',
  rejected: '#ef4444',
  hold: '#f59e0b',
  comment: '#64748b',
}

const SIDE_LABEL = {
  doctor: 'Doctor',
  vendor: 'Vendor',
  quality: 'Quality team',
  admin: 'Admin',
  system: 'System',
}

export default function MedicalTimeline({ timeline, onComment, busy }) {
  const [body, setBody] = useState('')
  const [file, setFile] = useState(null)

  if (!timeline) return null

  const { messages = [], iteration_count = 0, max_iterations = 10, exchanges_left } = timeline

  const send = async () => {
    if (!body.trim()) return
    await onComment({ body: body.trim(), attachment: file })
    setBody('')
    setFile(null)
  }

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
        <h3 style={{ margin: 0, fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Communication history</h3>
        <span style={{ fontSize: 11.5, color: exchanges_left === 0 ? '#ef4444' : 'var(--text-muted)', fontWeight: 700 }}>
          Round {iteration_count + 1} of {max_iterations}
          {exchanges_left === 0 && ' · limit reached'}
        </span>
      </div>

      {exchanges_left === 0 && (
        <div style={{
          padding: '8px 12px', borderRadius: 10, marginBottom: 10,
          background: '#ef444418', color: '#ef4444', fontSize: 12, fontWeight: 600,
        }}>
          This certificate has used all {max_iterations} exchanges. It can no longer be sent back — it has to be approved or rejected as it stands.
        </div>
      )}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
        {messages.length === 0 && (
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>Nothing recorded yet.</p>
        )}

        {messages.map((m, i) => {
          const Icon = ICONS[m.action] || MessageSquare
          const tone = TONES[m.action] || '#64748b'
          const last = i === messages.length - 1

          return (
            <div key={m.id} style={{ display: 'flex', gap: 10 }}>
              {/* Rail — the thread's spine, so a long exchange still reads as one conversation. */}
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                <div style={{
                  width: 26, height: 26, borderRadius: 999, background: tone + '22',
                  color: tone, display: 'grid', placeItems: 'center', flexShrink: 0,
                }}>
                  <Icon size={14} />
                </div>
                {!last && <div style={{ width: 2, flex: 1, background: 'var(--border)', minHeight: 14 }} />}
              </div>

              <div style={{ paddingBottom: last ? 0 : 14, flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                  <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{m.action_label || humanise(m.action)}</strong>
                  <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                    {SIDE_LABEL[m.author_side] || m.author_side}
                    {m.author_name ? ` · ${m.author_name}` : ''}
                  </span>
                  <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 'auto' }}>
                    {m.created_at ? new Date(m.created_at).toLocaleString() : ''}
                  </span>
                </div>

                {m.reason_code && (
                  <div style={{ fontSize: 12, color: tone, fontWeight: 700, marginTop: 2 }}>
                    Reason: {humanise(m.reason_code)}
                  </div>
                )}
                {m.body && (
                  <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)', whiteSpace: 'pre-wrap' }}>{m.body}</p>
                )}
                {m.attachment_name && (
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3, display: 'flex', alignItems: 'center', gap: 4 }}>
                    <Paperclip size={12} /> {m.attachment_name}
                  </div>
                )}
              </div>
            </div>
          )
        })}
      </div>

      {onComment && (
        <div style={{ marginTop: 14, borderTop: '1px solid var(--border)', paddingTop: 12 }}>
          <textarea
            value={body}
            onChange={e => setBody(e.target.value)}
            rows={2}
            placeholder="Reply on this certificate…"
            style={{ ...S.input, resize: 'vertical' }}
          />
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 8, flexWrap: 'wrap' }}>
            <label style={{ ...S.btn, fontSize: 12 }}>
              <Paperclip size={13} /> {file ? file.name : 'Attach'}
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                onChange={e => setFile(e.target.files?.[0] ?? null)}
                style={{ display: 'none' }}
              />
            </label>
            <button
              type="button"
              onClick={send}
              disabled={busy || !body.trim()}
              style={{ ...S.btnPrimary, opacity: busy || !body.trim() ? 0.5 : 1, marginLeft: 'auto' }}
            >
              <Send size={13} /> Send reply
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
