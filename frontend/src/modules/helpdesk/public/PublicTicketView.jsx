import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { LifeBuoy, Paperclip } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/**
 * Public, no-login view of a single ticket. The requester follows a link
 * carrying {id}-{token}; the unforgeable token is the credential, so only that
 * one ticket is exposed. Read-only — shows the ticket + its reply thread.
 */
export default function PublicTicketView() {
  const { ref } = useParams()
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    helpdeskApi.tickets.publicView(ref)
      .then(setData)
      .catch(() => setError('This ticket link is invalid or has expired.'))
  }, [ref])

  if (error) {
    return (
      <Shell>
        <div style={{ textAlign: 'center', padding: '48px 16px', color: 'var(--text-muted)' }}>
          <LifeBuoy size={30} style={{ opacity: 0.4, marginBottom: 10 }} />
          <p style={{ fontSize: 14 }}>{error}</p>
        </div>
      </Shell>
    )
  }
  if (!data) {
    return <Shell><div className="skeleton" style={{ height: 200, borderRadius: 16, background: 'var(--border)' }} /></Shell>
  }

  const { ticket, replies } = data

  return (
    <Shell>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
        <span style={{ fontFamily: 'monospace', fontWeight: 800, color: '#0e7490' }}>#{ticket.id}</span>
        <span style={{ padding: '3px 10px', borderRadius: 999, background: '#cffafe', color: '#0e7490', fontSize: 11, fontWeight: 800, textTransform: 'capitalize' }}>
          {String(ticket.status || '').replace(/-/g, ' ')}
        </span>
      </div>
      <h1 style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)', margin: '0 0 6px' }}>{ticket.subject}</h1>
      <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 20px' }}>
        Priority: <span style={{ textTransform: 'capitalize' }}>{ticket.priority || '—'}</span>
        {ticket.requester_name ? ` · Raised by ${ticket.requester_name}` : ''}
      </p>

      {ticket.description && (
        <div style={{ padding: 16, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 20 }}>
          <div style={{ fontSize: 14, color: 'var(--text-body)', lineHeight: 1.7 }} dangerouslySetInnerHTML={{ __html: ticket.description }} />
        </div>
      )}

      <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 12px' }}>Conversation</h2>
      {replies.length === 0 ? (
        <p style={{ fontSize: 13, color: 'var(--text-muted)' }}>No replies yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {replies.map(r => {
            const staff = r.sender_type !== 'client'
            return (
              <div key={r.id} style={{ padding: 14, borderRadius: 12, border: '1px solid var(--border)', background: staff ? '#f0fdff' : 'var(--bg-card)' }}>
                <div style={{ fontSize: 11, fontWeight: 700, color: staff ? '#0e7490' : 'var(--text-muted)', marginBottom: 6 }}>
                  {staff ? 'Support' : (ticket.requester_name || 'You')}
                </div>
                <div style={{ fontSize: 14, color: 'var(--text-body)', lineHeight: 1.7 }} dangerouslySetInnerHTML={{ __html: r.message }} />
                {(r.attachments || []).length > 0 && (
                  <div style={{ marginTop: 8, display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                    {r.attachments.map((a, i) => (
                      <span key={i} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, color: 'var(--text-muted)' }}>
                        <Paperclip size={11} /> {a.file_name}
                      </span>
                    ))}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}
      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 24, textAlign: 'center' }}>
        To respond, simply reply to the email you received for this ticket.
      </p>
    </Shell>
  )
}

function Shell({ children }) {
  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-global, #f1f5f9)', padding: '32px 16px' }}>
      <div style={{ maxWidth: 640, margin: '0 auto' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 20, color: '#0e7490' }}>
          <LifeBuoy size={20} />
          <span style={{ fontWeight: 800, fontSize: 15 }}>Helpdesk &amp; Support</span>
        </div>
        <div style={{ background: 'var(--bg-card, #fff)', border: '1px solid var(--border,#e2e8f0)', borderRadius: 16, padding: 24, boxShadow: '0 6px 24px rgba(15,23,42,0.06)' }}>
          {children}
        </div>
      </div>
    </div>
  )
}
