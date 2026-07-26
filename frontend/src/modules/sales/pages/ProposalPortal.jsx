import { useState, useEffect, useRef } from 'react'
import { useParams } from 'react-router-dom'
import { Lock, Mail, CheckCircle2, XCircle, ShieldCheck } from 'lucide-react'
import { publicProposalApi } from '@/services/publicProposalApi'
import ProposalDocument from '../components/ProposalDocument'

/**
 * Public proposal view — /portal/proposals/:token (no auth). Handles the
 * OTP-locked state, document render and Accept / Decline.
 */
export default function ProposalPortal() {
  const { token } = useParams()
  const [state, setState] = useState('loading')   // loading | locked | ready | notfound
  const [teaser, setTeaser] = useState(null)
  const [doc, setDoc] = useState(null)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState(null)

  const accessKey = `portal_access_${token}`
  const access = () => sessionStorage.getItem(accessKey)

  const load = async () => {
    try {
      const res = await publicProposalApi.get(token, access())
      setDoc(res.proposal)
      setState('ready')
    } catch (e) {
      if (e.status === 401) { setTeaser(e.data?.proposal); setState('locked'); sessionStorage.removeItem(accessKey) }
      else setState('notfound')
    }
  }
  useEffect(() => { load() }, [token])

  const respond = async (action) => {
    setBusy(true)
    try {
      const res = await publicProposalApi[action](token, access())
      setDoc(d => ({ ...d, status: res.status }))
      setMessage(action === 'accept' ? 'Thank you — the proposal has been accepted.' : 'The proposal has been declined.')
    } catch (e) { setMessage(e.message) } finally { setBusy(false) }
  }

  return (
    <div className="min-h-screen py-8 px-4" style={{ background: 'var(--bg-body, #f1f5f9)' }}>
      {state === 'loading' && <div className="mx-auto max-w-xl mt-24 skeleton h-40 rounded-2xl" style={{ background: 'var(--border, #e2e8f0)' }} />}

      {state === 'notfound' && (
        <div className="mx-auto max-w-md mt-24 card-3d text-center" style={{ padding: '40px' }}>
          <XCircle size={28} className="mx-auto mb-3" style={{ color: '#ef4444' }} />
          <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Proposal not found</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>This link is invalid or has been withdrawn.</p>
        </div>
      )}

      {state === 'locked' && <OtpGate token={token} teaser={teaser} onUnlocked={(t) => { sessionStorage.setItem(accessKey, t); load() }} />}

      {state === 'ready' && doc && (
        <div className="space-y-5">
          <ProposalDocument proposal={doc} />
          <div className="mx-auto" style={{ maxWidth: 820 }}>
            {message && (
              <div className="card-3d text-center mb-4" style={{ padding: '16px' }}>
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{message}</p>
              </div>
            )}
            {doc.is_expired && !message && (
              <div className="card-3d text-center mb-4" style={{ padding: '16px', border: '1px solid rgba(245,158,11,0.35)' }}>
                <p className="text-sm font-bold" style={{ color: '#f59e0b' }}>This proposal expired on {String(doc.open_till).slice(0, 10)}.</p>
                <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Contact us if you'd like it re-opened.</p>
              </div>
            )}
            {doc.status === 'Sent' && !doc.is_expired && !message && (
              <div className="card-3d flex flex-col sm:flex-row gap-3 items-center justify-center" style={{ padding: '20px' }}>
                <button onClick={() => respond('accept')} disabled={busy} className="w-full sm:w-auto px-8 py-3 rounded-2xl text-sm font-bold text-white flex items-center justify-center gap-2 disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
                  <CheckCircle2 size={15} /> Accept Proposal
                </button>
                <button onClick={() => respond('decline')} disabled={busy} className="w-full sm:w-auto px-8 py-3 rounded-2xl text-sm font-bold flex items-center justify-center gap-2 disabled:opacity-60" style={{ background: 'rgba(239,68,68,0.08)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.3)' }}>
                  <XCircle size={15} /> Decline
                </button>
              </div>
            )}
            {['Accepted', 'Declined'].includes(doc.status) && !message && (
              <div className="card-3d text-center" style={{ padding: '16px' }}>
                <p className="text-sm font-bold" style={{ color: doc.status === 'Accepted' ? '#10b981' : '#ef4444' }}>
                  This proposal was {doc.status.toLowerCase()}.
                </p>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function OtpGate({ token, teaser, onUnlocked }) {
  const [phase, setPhase] = useState('idle')      // idle | sent
  const [maskedEmail, setMaskedEmail] = useState(null)
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [cooldown, setCooldown] = useState(0)
  const timer = useRef(null)

  useEffect(() => () => clearInterval(timer.current), [])

  const startCooldown = () => {
    setCooldown(60)
    clearInterval(timer.current)
    timer.current = setInterval(() => setCooldown(c => { if (c <= 1) { clearInterval(timer.current); return 0 } return c - 1 }), 1000)
  }

  const request = async () => {
    setBusy(true); setError(null)
    try {
      const res = await publicProposalApi.requestOtp(token)
      setMaskedEmail(res.email)
      setPhase('sent')
      startCooldown()
    } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  const verify = async () => {
    if (code.length !== 6) return setError('Enter the 6-digit code')
    setBusy(true); setError(null)
    try {
      const res = await publicProposalApi.verifyOtp(token, code)
      onUnlocked(res.access_token)
    } catch (e) { setError(e.message); setCode('') } finally { setBusy(false) }
  }

  return (
    <div className="mx-auto max-w-md mt-24 card-3d text-center" style={{ padding: '40px 32px' }}>
      <div className="w-14 h-14 rounded-2xl mx-auto mb-4 flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
        <Lock size={22} style={{ color: 'var(--accent)' }} />
      </div>
      <h1 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>{teaser?.subject || 'Protected proposal'}</h1>
      {teaser?.reference_no && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{teaser.reference_no}</p>}
      <p className="text-xs mt-3 mb-5" style={{ color: 'var(--text-muted)' }}>
        This proposal is protected. To confirm it's you, we'll email a one-time code to the address it was sent to.
      </p>

      {phase === 'idle' ? (
        <button onClick={request} disabled={busy} className="w-full py-3 rounded-2xl text-sm font-bold text-white flex items-center justify-center gap-2 disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)' }}>
          <Mail size={14} /> {busy ? 'Sending…' : 'Email me a code'}
        </button>
      ) : (
        <div className="space-y-3">
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Code sent to <b style={{ color: 'var(--text-h)' }}>{maskedEmail}</b>. Check your inbox.</p>
          <input
            className="input-3d text-center font-black tracking-[0.5em] text-xl"
            style={{ letterSpacing: '0.5em' }}
            inputMode="numeric" maxLength={6} placeholder="••••••"
            value={code}
            onChange={e => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
            onKeyDown={e => e.key === 'Enter' && verify()}
            autoFocus
          />
          <button onClick={verify} disabled={busy} className="w-full py-3 rounded-2xl text-sm font-bold text-white flex items-center justify-center gap-2 disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
            <ShieldCheck size={14} /> {busy ? 'Verifying…' : 'Verify & View'}
          </button>
          <button onClick={request} disabled={busy || cooldown > 0} className="text-xs font-bold disabled:opacity-40" style={{ color: 'var(--accent)' }}>
            {cooldown > 0 ? `Resend code in ${cooldown}s` : 'Resend code'}
          </button>
        </div>
      )}
      {error && <p className="text-xs mt-3" style={{ color: '#ef4444' }}>{error}</p>}
    </div>
  )
}
