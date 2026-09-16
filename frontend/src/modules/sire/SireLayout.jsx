/**
 * SIRE — module shell.
 *
 * The nav frame the SIRE screens hang off, written to this repo's module
 * convention (see HelpdeskLayout / PurchaseLayout) rather than shipped by the
 * package, because a package cannot know our chrome.
 *
 * SIRE is the ENGINEERING defect track: something in the product broke, and the
 * case is closed only when a fix ships in a verified release. It is not the
 * Helpdesk, which answers "please do something for me" and closes when the
 * requester is satisfied. Different objects, different clocks -- keep them apart.
 */

import { useEffect } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import { SireHost } from '@/lib/sire/host'
import {
  LayoutDashboard, ListChecks, Rocket, FileText, ShieldCheck, LineChart, ArrowLeft, Bug,
} from 'lucide-react'

const SIRE_NAV = [
  { label: 'Dashboard',     path: '/app/sire/dashboard',     icon: LayoutDashboard },
  { label: 'My Work',       path: '/app/sire/my-work',       icon: ListChecks },
  { label: 'Releases',      path: '/app/sire/releases',      icon: Rocket },
  // No 'Release Notes' tab: ReleaseNotesPage is the review screen for ONE note
  // (/app/sire/release-notes/:noteId) and the package ships no list behind it, so
  // a top-level tab here would only ever land on a blank page.
  { label: 'Quality',       path: '/app/sire/quality',       icon: ShieldCheck },
  { label: 'Insights',      path: '/app/sire/insights',      icon: LineChart },
]

export default function SireLayout() {
  const { isDark } = useTheme()
  const navigate = useNavigate()

  // Hand SIRE the router. Without this its bridge falls back to a full page
  // load, which works but throws away app state on every internal link.
  useEffect(() => {
    SireHost.configure({ navigate: (path) => navigate(path) })
  }, [navigate])

  return (
    <div style={{ margin: '-1rem -1.5rem', minHeight: '100vh' }}>

      {/* ── Module header ─────────────────────────────────────── */}
      <div
        className="relative overflow-hidden"
        style={{
          background: isDark
            ? 'linear-gradient(135deg, rgba(244,63,94,0.18) 0%, rgba(159,18,57,0.08) 50%, transparent 100%)'
            : 'linear-gradient(135deg, rgba(244,63,94,0.12) 0%, rgba(251,113,133,0.05) 50%, transparent 100%)',
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

          <div
            className="p-3 rounded-xl"
            style={{ background: 'linear-gradient(135deg,#f43f5e,#9f1239)' }}
          >
            <Bug size={22} color="#fff" />
          </div>

          <div>
            <h1 className="text-xl font-bold">Issues &amp; Quality</h1>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
              Report to verified fix — SIRE
            </p>
          </div>
        </div>

        {/* ── Tabs ────────────────────────────────────────────── */}
        <div className="px-6 flex gap-1 overflow-x-auto">
          {SIRE_NAV.map(({ label, path, icon: Icon }) => (
            <NavLink
              key={path}
              to={path}
              className="flex items-center gap-2 px-4 py-2.5 text-sm whitespace-nowrap"
              style={({ isActive }) => ({
                borderBottom: isActive ? '2px solid #f43f5e' : '2px solid transparent',
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
