import { useState, useEffect, useRef } from 'react'
import { Clock } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { authApi } from '@/services/authApi'

/**
 * Idle-timeout warning. Tracks client-side inactivity and warns the user before
 * the server-side idle timeout ends their session; "Stay Signed In" sends a
 * heartbeat. Mirrors the backend default (30 min idle, 2 min warning). A
 * throttled heartbeat on activity keeps the server session fresh during use.
 */
const IDLE_MINUTES = 30
const WARN_SECONDS = 120

export default function IdleTimeoutWarning() {
  const { logout } = useAuth()
  const [warn, setWarn] = useState(false)
  const [count, setCount] = useState(WARN_SECONDS)
  const lastActivity = useRef(Date.now())
  const lastBeat = useRef(Date.now())

  useEffect(() => {
    const onActivity = () => {
      lastActivity.current = Date.now()
      if (Date.now() - lastBeat.current > 60000) {
        lastBeat.current = Date.now()
        authApi.heartbeat().catch(() => {})
      }
    }
    const events = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart']
    events.forEach((e) => window.addEventListener(e, onActivity, { passive: true }))
    return () => events.forEach((e) => window.removeEventListener(e, onActivity))
  }, [])

  useEffect(() => {
    const id = setInterval(() => {
      const idle = (Date.now() - lastActivity.current) / 1000
      const warnAt = IDLE_MINUTES * 60 - WARN_SECONDS
      if (idle >= IDLE_MINUTES * 60) {
        clearInterval(id)
        logout?.().finally(() => { window.location.href = '/auth/login' })
      } else if (idle >= warnAt) {
        setWarn(true)
        setCount(Math.max(0, Math.ceil(IDLE_MINUTES * 60 - idle)))
      } else if (warn) {
        setWarn(false)
      }
    }, 1000)
    return () => clearInterval(id)
  }, [warn, logout])

  const stay = () => {
    lastActivity.current = Date.now()
    lastBeat.current = Date.now()
    setWarn(false)
    authApi.heartbeat().catch(() => {})
  }

  if (!warn) return null

  const mm = String(Math.floor(count / 60)).padStart(2, '0')
  const ss = String(count % 60).padStart(2, '0')

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(4px)' }}>
      <div style={{ width: 380, maxWidth: '92vw', borderRadius: 16, padding: 24, background: 'var(--bg-card)', border: '1px solid var(--border)', textAlign: 'center' }}>
        <div style={{ width: 54, height: 54, borderRadius: '50%', margin: '0 auto 14px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(245,158,11,0.14)' }}>
          <Clock size={24} style={{ color: '#f59e0b' }} />
        </div>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Still there?</h2>
        <p style={{ margin: '6px 0 4px', fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.5 }}>
          You'll be signed out due to inactivity in
        </p>
        <div style={{ fontSize: 28, fontWeight: 900, color: '#f59e0b', margin: '2px 0 16px', fontVariantNumeric: 'tabular-nums' }}>{mm}:{ss}</div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={() => { logout?.().finally(() => { window.location.href = '/auth/login' }) }}
            style={{ flex: 1, padding: '10px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Log out</button>
          <button onClick={stay}
            style={{ flex: 1, padding: '10px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer' }}>Stay Signed In</button>
        </div>
      </div>
    </div>
  )
}
