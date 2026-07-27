import { useState, useEffect } from 'react'
import { Monitor, Smartphone, Tablet, LogOut, Trash2, RefreshCw, ShieldCheck } from 'lucide-react'
import { authApi } from '@/services/authApi'

const deviceIcon = (d) => (/mobile/i.test(d) ? Smartphone : /tablet/i.test(d) ? Tablet : Monitor)
const fmt = (iso) => (iso ? new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

export default function ActiveSessions() {
  const [rows, setRows] = useState([])
  const [loading, setLoad] = useState(true)
  const [busy, setBusy] = useState(null)

  const load = () => {
    setLoad(true)
    authApi.sessions().then((r) => { setRows(r?.data?.sessions ?? r?.sessions ?? []); setLoad(false) }).catch(() => setLoad(false))
  }
  useEffect(() => { load() }, [])

  const revoke = async (id) => {
    if (!confirm('Sign out this device?')) return
    setBusy(id)
    try { await authApi.revokeSession(id); load() } catch { alert('Could not revoke session.') } finally { setBusy(null) }
  }
  const logoutOthers = async () => {
    if (!confirm('Sign out all other devices?')) return
    setBusy('others')
    try { const r = await authApi.logoutOthers(); alert(`${r?.data?.revoked ?? 0} session(s) signed out.`); load() }
    catch { alert('Could not sign out other sessions.') } finally { setBusy(null) }
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>SECURITY</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0' }}>Active Sessions</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Devices currently signed in to your account.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={logoutOthers} disabled={busy === 'others'} style={dangerBtn}><LogOut size={14} /> Sign out other devices</button>
        </div>
      </div>

      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>{[1, 2].map(i => <div key={i} style={{ height: 64, borderRadius: 12, background: 'var(--border)' }} />)}</div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {rows.map((s) => {
            const Icon = deviceIcon(s.device)
            return (
              <div key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '14px 16px', borderRadius: 14, background: 'var(--bg-card)', border: `1px solid ${s.current ? 'rgba(16,185,129,0.4)' : 'var(--border)'}` }}>
                <div style={{ width: 42, height: 42, borderRadius: 12, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)' }}>
                  <Icon size={20} style={{ color: '#a78bfa' }} />
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
                    {s.browser || 'Unknown'} · {s.device || 'Device'}
                    {s.current && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 800, color: '#10b981' }}><ShieldCheck size={12} /> This device</span>}
                    {s.remember_me && <span style={{ fontSize: 10, color: 'var(--text-muted)' }}>remembered</span>}
                  </div>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{s.ip || '—'} · last active {fmt(s.last_activity_at)}</div>
                </div>
                {!s.current && (
                  <button onClick={() => revoke(s.id)} disabled={busy === s.id} title="Sign out"
                    style={{ ...ghostBtn, color: '#ef4444', padding: '8px 10px' }}><Trash2 size={15} /></button>
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }
const dangerBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#f87171,#ef4444)' }
