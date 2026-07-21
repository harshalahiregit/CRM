import { useState, useEffect, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import {
  CheckCircle, AlertTriangle, XCircle, LogIn, LogOut, RefreshCw,
  ShieldAlert, Clock, Droplet, Building2, Loader,
} from 'lucide-react'
import { gateScanApi } from '@/services/gateScanApi'
import { GATE_DECISION, gateDecisionCfg, STRIKE_LIMIT, fmtTime } from '@/modules/tpv/constants'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

/**
 * PUBLIC site-gate screen — opened by scanning a worker's badge QR.
 *
 * Built thumb-first for a guard holding a phone outdoors: one column, oversized
 * verdict, 56px action buttons, and nothing that needs a login. The verdict
 * colour is backed by an icon and words, never colour alone.
 */
export default function GateScan() {
  const { token } = useParams()
  const [card, setCard]     = useState(null)
  const [error, setError]   = useState(null)
  const [loading, setLoad]  = useState(true)
  const [acting, setActing] = useState(null)   // 'in' | 'out'
  const [flash, setFlash]   = useState(null)   // transient confirmation

  const load = useCallback(async () => {
    setLoad(true); setError(null)
    try { setCard(await gateScanApi.scan(token)) }
    catch (e) { setError(readError(e)) }
    finally { setLoad(false) }
  }, [token])
  useEffect(() => { load() }, [load])

  const act = async (which) => {
    setActing(which); setFlash(null)
    try {
      const res = which === 'in' ? await gateScanApi.checkIn(token) : await gateScanApi.checkOut(token)
      setCard(res)
      setFlash({ ok: true, msg: which === 'in' ? 'Checked in' : 'Checked out' })
    } catch (e) {
      setFlash({ ok: false, msg: readError(e) })
      // The refusal reason may have changed the card — reload the truth.
      try { setCard(await gateScanApi.scan(token)) } catch { /* keep */ }
    } finally { setActing(null) }
  }

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-global)', padding: '16px 14px 40px' }}>
      <style>{KIT3D_STYLE}</style>
      <div style={{ maxWidth: 460, margin: '0 auto' }}>
        <Header />
        {loading   ? <Skeleton /> :
         error     ? <FatalCard message={error} onRetry={load} /> :
         card      ? <Pass card={card} acting={acting} flash={flash} onAct={act} onRefresh={load} /> : null}
      </div>
    </div>
  )
}

// Surface the API's message; fall back to something a guard can act on.
function readError(e) {
  if (e?.response?.status === 429) return 'Too many scans — wait a moment and try again.'
  return e?.response?.data?.message || 'Could not reach the gate service. Check the connection.'
}

const Header = () => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16, justifyContent: 'center' }}>
    <span style={{ width: 32, height: 32, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 16px -4px rgba(124,58,237,.6)' }}>
      <ShieldAlert size={16} color="#fff" />
    </span>
    <span style={{ fontSize: 13, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>Site Gate</span>
  </div>
)

const Skeleton = () => (
  <div className="pr-glass" style={{ padding: 28, textAlign: 'center', color: 'var(--text-muted)' }}>
    <Loader size={22} style={{ marginBottom: 8 }} />
    <div style={{ fontSize: 14 }}>Reading badge…</div>
  </div>
)

/** Unknown/expired token, or the service is unreachable. */
const FatalCard = ({ message, onRetry }) => (
  <div className="pr-glass" style={{ padding: 28, textAlign: 'center', border: '1.5px solid rgba(239,68,68,0.5)' }}>
    <div style={{ width: 72, height: 72, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#f87171,#ef4444)', boxShadow: '0 12px 30px -8px rgba(239,68,68,.7)' }}>
      <XCircle size={38} color="#fff" />
    </div>
    <div style={{ fontSize: 26, fontWeight: 900, color: '#ef4444', letterSpacing: '-0.02em' }}>STOP</div>
    <p style={{ fontSize: 14.5, color: 'var(--text-h)', margin: '10px 0 20px', lineHeight: 1.5 }}>{message}</p>
    <button onClick={onRetry} style={{ ...bigBtn, background: 'var(--bg-input)', color: 'var(--text-h)', border: '1px solid var(--border)' }}>
      <RefreshCw size={16} /> Scan again
    </button>
  </div>
)

const bigBtn = {
  width: '100%', minHeight: 56, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 9,
  borderRadius: 14, border: 'none', fontSize: 15.5, fontWeight: 800, cursor: 'pointer',
}

// ── The pass ─────────────────────────────────────────────────────────────────
function Pass({ card, acting, flash, onAct, onRefresh }) {
  const cfg = gateDecisionCfg(card.decision)
  const w = card.worker || {}
  const att = card.attendance
  const onSite = !!att?.on_site
  const Icon = card.decision === GATE_DECISION.ADMIT ? CheckCircle
    : card.decision === GATE_DECISION.WARN ? AlertTriangle : XCircle

  return (
    <>
      {/* Verdict — the thing the guard reads from arm's length */}
      <div className="pr-glass" style={{ padding: '26px 20px', textAlign: 'center', border: `1.5px solid ${cfg.color}88`, boxShadow: `0 18px 44px -14px ${cfg.color}66`, marginBottom: 14 }}>
        <div style={{ width: 84, height: 84, borderRadius: '50%', margin: '0 auto 14px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(145deg, ${cfg.color}, ${cfg.color}bb)`, boxShadow: `0 14px 34px -8px ${cfg.color}aa, inset 0 2px 0 rgba(255,255,255,.35)` }}>
          <Icon size={44} color="#fff" strokeWidth={2.4} />
        </div>
        <div style={{ fontSize: 34, fontWeight: 900, color: cfg.color, letterSpacing: '-0.02em', lineHeight: 1 }}>{cfg.short}</div>
        {/* A clean admit's label is just "Admit" again — only show the qualifier
            when it actually says something more (e.g. "Admit with Warning"). */}
        {cfg.label.toUpperCase() !== cfg.short.toUpperCase() && (
          <div style={{ fontSize: 12.5, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'var(--text-muted)', marginTop: 6 }}>{cfg.label}</div>
        )}

        <ul style={{ listStyle: 'none', margin: '14px 0 0', padding: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
          {(card.reasons || []).map((r, i) => (
            <li key={i} style={{ fontSize: 13.5, lineHeight: 1.45, color: card.decision === GATE_DECISION.ADMIT ? 'var(--text-muted)' : cfg.color, fontWeight: card.decision === GATE_DECISION.ADMIT ? 500 : 600 }}>{r}</li>
          ))}
        </ul>
      </div>

      {/* Identity */}
      <div className="pr-glass" style={{ padding: 18, marginBottom: 14 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 13 }}>
          <div style={{ width: 54, height: 54, borderRadius: 16, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18, fontWeight: 900, color: '#fff', background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 8px 20px -5px rgba(124,58,237,.6)' }}>
            {(w.name || '?').split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()}
          </div>
          <div style={{ minWidth: 0, flex: 1 }}>
            <div style={{ fontSize: 19, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{w.name}</div>
            <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>{w.designation} · {w.worker_code}</div>
          </div>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 14 }}>
          <Fact icon={Building2} label="Vendor" value={w.vendor} />
          <Fact icon={Droplet} label="Blood group" value={w.blood_group} accent="#ef4444" />
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--border)' }}>
          <Mini label="Badge" value={w.badge_number} />
          <Mini label="Valid until" value={w.valid_until ? new Date(w.valid_until).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'} right />
        </div>
      </div>

      {/* Strikes */}
      <StrikeMeter active={card.strikes?.active || 0} limit={card.strikes?.limit || STRIKE_LIMIT} />

      {/* Attendance + actions */}
      <div className="pr-glass" style={{ padding: 18 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
          <Clock size={15} style={{ color: onSite ? '#10b981' : 'var(--text-muted)' }} />
          <span style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)' }}>
            {onSite ? `On site since ${fmtTime(att.check_in_at)}` :
             att?.check_out_at ? `Left at ${fmtTime(att.check_out_at)}` : 'Not on site today'}
          </span>
          {att?.duration && <span style={{ marginLeft: 'auto', fontSize: 12.5, fontWeight: 700, color: 'var(--text-muted)' }}>{att.duration}</span>}
        </div>

        {flash && (
          <div style={{ padding: '10px 13px', borderRadius: 10, marginBottom: 12, fontSize: 13.5, fontWeight: 600,
            background: flash.ok ? 'rgba(16,185,129,0.14)' : 'rgba(239,68,68,0.12)',
            color: flash.ok ? '#10b981' : '#ef4444',
            border: `1px solid ${flash.ok ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)'}` }}>
            {flash.msg}
          </div>
        )}

        {/* Entry is offered only when the gate permits it; exit is always
            available, because someone whose access lapsed still has to leave. */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {!onSite && card.permits_entry && !att?.check_out_at && (
            <button onClick={() => onAct('in')} disabled={!!acting}
              style={{ ...bigBtn, background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff', boxShadow: '0 12px 26px -8px rgba(16,185,129,.7)', opacity: acting ? 0.7 : 1 }}>
              {acting === 'in' ? <Loader size={17} /> : <LogIn size={17} />} {acting === 'in' ? 'Checking in…' : 'Check In'}
            </button>
          )}
          {!onSite && !card.permits_entry && (
            <div style={{ ...bigBtn, background: 'rgba(239,68,68,0.1)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.4)', cursor: 'not-allowed' }}>
              <XCircle size={17} /> Entry refused
            </div>
          )}
          {onSite && (
            <button onClick={() => onAct('out')} disabled={!!acting}
              style={{ ...bigBtn, background: 'linear-gradient(135deg,#f59e0b,#d97706)', color: '#fff', boxShadow: '0 12px 26px -8px rgba(245,158,11,.7)', opacity: acting ? 0.7 : 1 }}>
              {acting === 'out' ? <Loader size={17} /> : <LogOut size={17} />} {acting === 'out' ? 'Checking out…' : 'Check Out'}
            </button>
          )}
          {!onSite && att?.check_out_at && (
            <div style={{ ...bigBtn, background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)', cursor: 'default', fontSize: 14 }}>
              Shift complete for today
            </div>
          )}
          <button onClick={onRefresh} disabled={!!acting}
            style={{ ...bigBtn, minHeight: 46, background: 'transparent', color: 'var(--text-muted)', border: '1px solid var(--border)', fontSize: 14 }}>
            <RefreshCw size={15} /> Refresh
          </button>
        </div>
      </div>
    </>
  )
}

const Fact = ({ icon: Icon, label, value, accent }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 11px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
    <Icon size={14} style={{ color: accent || 'var(--text-muted)', flexShrink: 0 }} />
    <div style={{ minWidth: 0 }}>
      <div style={{ fontSize: 9.5, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)', fontWeight: 800 }}>{label}</div>
      <div style={{ fontSize: 13, fontWeight: 700, color: accent || 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{value || '—'}</div>
    </div>
  </div>
)

const Mini = ({ label, value, right }) => (
  <div style={{ textAlign: right ? 'right' : 'left' }}>
    <div style={{ fontSize: 9.5, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)', fontWeight: 800 }}>{label}</div>
    <div style={{ fontSize: 13, fontWeight: 800, color: '#a78bfa' }}>{value || '—'}</div>
  </div>
)

/** Strike meter — dots plus a count, so it reads without relying on colour. */
function StrikeMeter({ active, limit }) {
  const atLimit = active >= limit
  const warn = active >= limit - 1
  const color = atLimit ? '#ef4444' : warn ? '#f59e0b' : '#10b981'
  return (
    <div className="pr-glass" style={{ padding: '13px 18px', marginBottom: 14, display: 'flex', alignItems: 'center', gap: 12 }}>
      <ShieldAlert size={15} style={{ color, flexShrink: 0 }} />
      <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>Safety strikes</span>
      <div style={{ display: 'flex', gap: 5, marginLeft: 'auto' }}>
        {Array.from({ length: limit }).map((_, i) => (
          <span key={i} style={{
            width: 13, height: 13, borderRadius: '50%',
            background: i < active ? color : 'var(--bg-input)',
            border: `1.5px solid ${i < active ? color : 'var(--border)'}`,
            boxShadow: i < active ? `0 0 8px -1px ${color}` : 'none',
          }} />
        ))}
      </div>
      <span style={{ fontSize: 13.5, fontWeight: 800, color, minWidth: 28, textAlign: 'right' }}>{active}/{limit}</span>
    </div>
  )
}
