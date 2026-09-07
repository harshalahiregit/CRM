import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Users, UserCheck, HeartPulse, GraduationCap, HardHat, QrCode, RefreshCw, Plus,
} from 'lucide-react'
import { useVendorModule } from '@/modules/tpv/useVendorModule'
import { KIT3D_STYLE, StatusBadge as StatusPill } from '@/components/ui/kit3d'
import { fmtDate } from '../constants'
// The canonical Purchase worker vocabulary lives beside the register that owns
// it — the same way PurchaseWorkforceAttendance borrows its roster helpers from
// PurchaseGateLog, rather than a third copy drifting from the other two.
import { WORKER_STATUS, workerStatusCfg } from './PurchaseWorkers'

/**
 * Vendor-scoped Purchase workforce dashboard — the counters TPV's has, from
 * Purchase's own data.
 *
 * TPV's portal has opened on a workforce dashboard since it existed; Purchase's
 * portal had no such landing, so a vendor arrived at a bare list with no sense of
 * what still needed doing. `PurchaseWorkforce.jsx` is not this screen — it is the
 * admin's ALL-VENDOR view, which answers a different question.
 *
 * Every card is derived from the worker list already being fetched, exactly as
 * TPV derives its own: one request, no second source of truth to disagree with
 * the rows underneath it.
 *
 * ── Why the pending counts read `current_step` ──────────────────────────────
 * TPV hangs `medical` and `induction` objects off each worker and tests their
 * contents. Purchase persists `current_step` — the highest step CLEARED by
 * PurchaseWorkforceService — so progress is read straight from it rather than
 * recomputed from nested records that may not be loaded. The steps are fixed:
 * 1 profile · 2 medical · 3 training/induction · 4 PPE · 5 badge.
 */
const STEP = { PROFILE: 1, MEDICAL: 2, INDUCTION: 3, PPE: 4, BADGE: 5 }

/** Days until a date, or null when there isn't one. Negative once past. */
function daysUntil(date) {
  if (!date) return null
  const d = new Date(date)
  if (Number.isNaN(d.getTime())) return null

  return Math.ceil((d - new Date()) / 86400000)
}

export default function PurchaseWorkforceDashboard() {
  const navigate = useNavigate()
  const { api, portal: isPortal } = useVendorModule()

  const [vendor, setVendor] = useState(null)
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)

  const base = isPortal ? '/purchase-portal/workforce' : '/app/purchase'

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.workforce.workers({})
      setRows(Array.isArray(res?.data ?? res) ? (res.data ?? res) : [])
    } catch {
      setRows([])
    } finally {
      setLoading(false)
    }
  }, [api])
  useEffect(() => { load() }, [load])

  // The company header, for the portal only — an admin reaching this screen is
  // already inside a vendor's workspace and does not need telling whose it is.
  useEffect(() => {
    if (!isPortal || !api.me) return
    let alive = true
    api.me()
      .then(d => { if (alive) setVendor(d?.vendor ?? d ?? null) })
      .catch(() => {})

    return () => { alive = false }
  }, [isPortal, api])

  // Terminated people are off the books: counting them among "pending medical"
  // would ask the vendor to chase someone who no longer works for them.
  const live = rows.filter(r => r.status !== WORKER_STATUS.TERMINATED)
  const step = (r) => Number(r.current_step || 0)

  const stats = {
    total: rows.length,
    active: rows.filter(r => r.status === WORKER_STATUS.ACTIVE).length,
    pendingMedical: live.filter(r => step(r) < STEP.MEDICAL).length,
    pendingInduction: live.filter(r => step(r) < STEP.INDUCTION).length,
    ppePending: live.filter(r => step(r) < STEP.PPE).length,
    // A badge that has already lapsed is not "expiring" — it has expired, and
    // the worker's status carries that. This is the window worth acting in.
    expiring: rows.filter(r => {
      if (r.status !== WORKER_STATUS.ACTIVE) return false
      const d = daysUntil(r.badge_valid_until)

      return d !== null && d >= 0 && d <= 30
    }).length,
  }

  const cards = [
    { key: 'total', label: 'Total Workers', value: stats.total, color: '#7C3AED', icon: Users, href: `${base}/workers` },
    { key: 'active', label: 'Active', value: stats.active, color: '#10b981', icon: UserCheck, href: `${base}/workers?status=${WORKER_STATUS.ACTIVE}` },
    { key: 'medical', label: 'Pending Medical', value: stats.pendingMedical, color: '#ec4899', icon: HeartPulse, href: `${base}/workers` },
    { key: 'induction', label: 'Pending Induction', value: stats.pendingInduction, color: '#8b5cf6', icon: GraduationCap, href: `${base}/workers` },
    { key: 'ppe', label: 'PPE Pending', value: stats.ppePending, color: '#f59e0b', icon: HardHat, href: `${base}/workers` },
    { key: 'expiring', label: 'Expiring Badges', value: stats.expiring, color: '#f97316', icon: QrCode, href: `${base}/workers?status=${WORKER_STATUS.ACTIVE}` },
  ]

  return (
    <div style={{ padding: 24 }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 20, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            {isPortal ? 'My Workforce' : 'Workforce Dashboard'}
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
            {vendor?.company_name ? `${vendor.company_name} · ` : ''}
            5-step statutory onboarding · medical, induction, PPE and entry badge
          </p>
        </div>
        <button onClick={load} disabled={loading}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }}>
          <RefreshCw size={14} /> Refresh
        </button>
        <button onClick={() => navigate(`${base}/workers`)}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700 }}>
          <Plus size={15} /> Register Worker
        </button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12, marginBottom: 22 }}>
        {cards.map(c => (
          <button key={c.key} onClick={() => navigate(c.href)} className="card-3d"
            style={{ padding: 16, textAlign: 'left', cursor: 'pointer', border: '1px solid var(--border)', background: 'var(--bg-card)', borderRadius: 14 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
              <c.icon size={16} style={{ color: c.color }} />
              <span style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{c.label}</span>
            </div>
            <div style={{ fontSize: 28, fontWeight: 900, color: c.color, fontVariantNumeric: 'tabular-nums' }}>
              {loading ? '—' : c.value}
            </div>
          </button>
        ))}
      </div>

      <div className="card-3d" style={{ padding: 18, borderRadius: 14, border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
        <h2 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 3px' }}>Recently registered</h2>
        <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 14px' }}>
          Open a worker to continue their medical, induction, PPE and entry-badge steps.
        </p>

        {loading ? (
          <div style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading…</div>
        ) : rows.length === 0 ? (
          <div style={{ padding: '26px 0', textAlign: 'center' }}>
            <h3 style={{ color: 'var(--text-h)', fontSize: 15, fontWeight: 800, margin: '0 0 6px' }}>No workers registered yet</h3>
            <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>
              Register your first worker to begin the 5-step onboarding.
            </p>
          </div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {rows.slice(0, 8).map(r => (
              <button key={r.id} onClick={() => navigate(`${base}/workers/${r.id}`)}
                style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', cursor: 'pointer', textAlign: 'left' }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{r.full_name}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                    {r.worker_code || '—'}{r.designation ? ` · ${r.designation}` : ''}
                    {r.badge_valid_until ? ` · badge to ${fmtDate(r.badge_valid_until)}` : ''}
                  </div>
                </div>
                <span style={{ fontSize: 11, color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>
                  Step {Math.min(Math.max(step(r), 1), 5)}/5
                </span>
                <StatusPill cfg={workerStatusCfg(r.status)} />
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
