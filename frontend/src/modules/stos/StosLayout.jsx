/**
 * STOS — module shell.
 *
 * Sangoé Transport OS. This shell carries the FLEET & ASSET control tower
 * (Developer 2's domain): asset availability, telemetry health, workshop and
 * running costs.
 *
 * The other three towers in the design — CEO Control Room, Operations Tower and
 * the Driver app — are deliberately NOT tabs here. They read trip, order and
 * invoice data owned by Developers 1 and 3, and stubbing them as empty tabs
 * would advertise screens that cannot work yet.
 */

import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { Truck, ArrowLeft, LayoutGrid, Wrench, UserRound } from 'lucide-react'
import { useTheme } from '@/context/ThemeContext'
import { STOS_ACCENT, STOS_GRADIENT } from '@/services/stosApi'

const STOS_NAV = [
  { label: 'Fleet',    path: '/app/stos/fleet',    icon: LayoutGrid, end: true },
  { label: 'Drivers',  path: '/app/stos/drivers',  icon: UserRound,  end: false },
  { label: 'Workshop', path: '/app/stos/workshop', icon: Wrench,     end: false },
]

export default function StosLayout() {
  const { isDark } = useTheme()
  const navigate = useNavigate()

  return (
    <div style={{ margin: '-1rem -1.5rem', minHeight: '100vh' }}>

      {/* ── Module header ─────────────────────────────────────── */}
      <div
        className="relative overflow-hidden"
        style={{
          background: isDark
            ? 'linear-gradient(135deg, rgba(6,182,212,0.18) 0%, rgba(14,116,144,0.08) 50%, transparent 100%)'
            : 'linear-gradient(135deg, rgba(6,182,212,0.12) 0%, rgba(34,211,238,0.05) 50%, transparent 100%)',
          borderBottom: '1px solid var(--border)',
        }}
      >
        <div className="px-6 py-5 flex items-center gap-4">
          <button
            type="button"
            onClick={() => navigate('/app/dashboard')}
            className="p-2 rounded-lg hover:opacity-70"
            aria-label="Back to dashboard"
            style={{ border: '1px solid var(--border)' }}
          >
            <ArrowLeft size={18} />
          </button>

          <div className="p-3 rounded-xl" style={{ background: STOS_GRADIENT }}>
            <Truck size={22} color="#fff" />
          </div>

          <div>
            <h1 className="text-xl font-bold">Transport (STOS)</h1>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
              Fleet, telemetry, workshop and running costs
            </p>
          </div>
        </div>

        {/* ── Tabs ────────────────────────────────────────────── */}
        <div className="px-6 flex gap-1 overflow-x-auto">
          {STOS_NAV.map(({ label, path, icon: Icon, end }) => (
            <NavLink
              key={path}
              to={path}
              end={end}
              className="flex items-center gap-2 px-4 py-2.5 text-sm whitespace-nowrap"
              style={({ isActive }) => ({
                borderBottom: isActive ? `2px solid ${STOS_ACCENT}` : '2px solid transparent',
                color: isActive ? 'var(--text)' : 'var(--text-muted)',
                fontWeight: isActive ? 600 : 500,
              })}
            >
              <Icon size={16} />
              {label}
            </NavLink>
          ))}
        </div>
      </div>

      <div className="p-6">
        <Outlet />
      </div>
    </div>
  )
}
