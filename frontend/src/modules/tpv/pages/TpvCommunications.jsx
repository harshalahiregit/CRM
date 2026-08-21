import { useState, useEffect, useCallback } from 'react'
import { Megaphone, RefreshCw, Send, X, ExternalLink, CheckCircle2, XCircle } from 'lucide-react'
import { Link } from 'react-router-dom'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import { canApproveTpv } from '../constants'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §31 — Communications Centre. A derived alerts feed (what the current
// governance state calls for) plus send-to-vendor over email/WhatsApp/SMS, logged.
const SEV_TONE = { high: '#ef4444', medium: '#f59e0b', low: '#0ea5e9' }
const KIND_LABEL = {
  document_expiry: 'Document', ncr_overdue: 'NCR', capa_overdue: 'CAPA',
  violation_open: 'Violation', renewal_due: 'Renewal',
}
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function TpvCommunications() {
  const { user } = useAuth()
  const admin = canApproveTpv(user)
  const [data, setData] = useState(null)
  const [compose, setCompose] = useState(null)

  const load = useCallback(() => {
    tpvApi.communications.get().then(setData).catch(() => setData({ alerts: [], log: [], channels: [] }))
  }, [])
  useEffect(() => { load() }, [load])

  if (!data) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading communications…</div>
  const { alerts = [], log = [], channels = [] } = data

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>INTELLIGENCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Communications</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>What each vendor needs to hear — derived from live governance state — and what you have sent.</p>
        </div>
        <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1.4fr) minmax(0,1fr)', gap: 16, alignItems: 'start' }}>
        {/* Alerts feed */}
        <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
          <div style={{ padding: '14px 16px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
            <Megaphone size={16} style={{ color: '#a78bfa' }} /> Action feed <span style={{ fontSize: 12, fontWeight: 500, color: 'var(--text-muted)' }}>· {alerts.length}</span>
          </div>
          {alerts.length === 0 ? <div style={{ padding: 18, color: 'var(--text-muted)', fontSize: 13 }}>Nothing needs communicating — all clear.</div>
            : alerts.map((a, i) => (
              <div key={i} style={{ padding: '12px 16px', borderTop: '1px solid var(--border)', display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: SEV_TONE[a.severity], marginTop: 6, flexShrink: 0 }} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{a.title}</span>
                    <span style={{ fontSize: 10, fontWeight: 700, color: SEV_TONE[a.severity], background: `${SEV_TONE[a.severity]}1f`, padding: '2px 7px', borderRadius: 999 }}>{KIND_LABEL[a.kind] || a.kind}</span>
                  </div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', margin: '3px 0' }}>{a.vendor} · {a.vendor_code}{a.due ? ` · due ${date(a.due)}` : ''}</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{a.message}</div>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0 }}>
                  {admin && <button onClick={() => setCompose({ vendor_id: a.vendor_id, vendor: a.vendor, subject: a.title, body: a.message })} style={miniBtn}><Send size={12} /> Notify</button>}
                  {a.link && <Link to={a.link} style={{ ...miniBtn, color: 'var(--text-muted)', textDecoration: 'none' }}><ExternalLink size={12} /> Open</Link>}
                </div>
              </div>
            ))}
        </div>

        {/* Sent log */}
        <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
          <div style={{ padding: '14px 16px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Sent</div>
          {log.length === 0 ? <div style={{ padding: 18, color: 'var(--text-muted)', fontSize: 13 }}>No messages sent yet.</div>
            : log.map(l => (
              <div key={l.id} style={{ padding: '11px 16px', borderTop: '1px solid var(--border)', display: 'flex', gap: 10, alignItems: 'flex-start' }}>
                {l.status === 'sent' ? <CheckCircle2 size={15} style={{ color: '#22c55e', marginTop: 2, flexShrink: 0 }} /> : <XCircle size={15} style={{ color: '#ef4444', marginTop: 2, flexShrink: 0 }} />}
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)' }}>{l.subject}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{l.vendor?.company_name || '—'} · {l.channel} · {l.sent_at ? new Date(l.sent_at).toLocaleString() : ''}</div>
                </div>
              </div>
            ))}
        </div>
      </div>

      {compose && <ComposeModal seed={compose} channels={channels} onClose={() => setCompose(null)} onSent={() => { setCompose(null); load() }} />}
    </div>
  )
}

function ComposeModal({ seed, channels, onClose, onSent }) {
  const [form, setForm] = useState({ channel: 'email', subject: seed.subject || '', body: seed.body || '' })
  const [sending, setSending] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const send = async () => {
    setSending(true); setErr(null)
    try {
      await tpvApi.communications.send({ vendor_id: seed.vendor_id, ...form })
      onSent()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not send.') } finally { setSending(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Notify {seed.vendor}</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <label style={lbl}>Channel
          <select value={form.channel} onChange={set('channel')} style={inp}>
            {(channels.length ? channels : ['email']).map(c => <option key={c} value={c}>{c}</option>)}
          </select>
        </label>
        <label style={{ ...lbl, marginTop: 10 }}>Subject
          <input value={form.subject} onChange={set('subject')} style={inp} />
        </label>
        <label style={{ ...lbl, marginTop: 10 }}>Message
          <textarea value={form.body} onChange={set('body')} rows={5} style={{ ...inp, resize: 'vertical' }} />
        </label>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={send} disabled={sending || !form.subject || !form.body} style={{ ...btnPrimary, opacity: (sending || !form.subject || !form.body) ? 0.6 : 1 }}><Send size={14} /> {sending ? 'Sending…' : 'Send'}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#a78bfa', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 520, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '8px 10px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
