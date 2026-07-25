import { Fragment } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import { ChevronRight, ArrowLeft } from 'lucide-react'

// Shared module shell: breadcrumb back to /app/modules, a module badge, the
// neumorphic workflow rail, and the nested page outlet. Extracted from
// HRLayout so Purchase/TPV don't duplicate ~100 lines of nav chrome each.
//
// Props:
//   label — module display name, e.g. "Purchase"
//   badge — emoji shown in the module chip
//   items — [{ label, path, icon }], ordered to follow the business workflow
export default function ModuleShell({ label, badge, items }) {
  const { isDark } = useTheme()
  const navigate = useNavigate()

  return (
    <div className="space-y-0 -m-4 md:-m-6">
      <div
        className="relative overflow-hidden px-4 md:px-6 pt-5 pb-0"
        style={{
          background: isDark
            ? 'linear-gradient(135deg,rgba(124,58,237,0.2),rgba(91,33,182,0.1),transparent)'
            : 'linear-gradient(135deg,rgba(124,58,237,0.1),rgba(167,139,250,0.06),transparent)',
          borderBottom: '1px solid var(--border)',
        }}
      >
        <div className="flex items-center gap-3 mb-4">
          <button
            onClick={() => navigate('/app/modules')}
            className="flex items-center gap-1.5 text-xs font-semibold transition-all duration-150"
            style={{ color: 'var(--text-muted)' }}
            onMouseEnter={e => e.currentTarget.style.color = 'var(--text-h)'}
            onMouseLeave={e => e.currentTarget.style.color = 'var(--text-muted)'}
          >
            <ArrowLeft size={12} /> Modules
          </button>
          <ChevronRight size={12} style={{ color: 'var(--text-muted)' }} />
          <div className="flex items-center gap-2">
            <div
              className="w-6 h-6 rounded-lg flex items-center justify-center text-xs"
              style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 3px 8px rgba(124,58,237,0.4)' }}
            >
              {badge}
            </div>
            <span className="text-xs font-bold" style={{ color: '#a78bfa' }}>{label}</span>
          </div>
        </div>

        <style>{`
          .modnav-plate {
            margin-bottom: 14px; padding: 9px; border-radius: 9999px;
            background: ${isDark ? '#242229' : '#e9e4da'};
            box-shadow: ${isDark
              ? '6px 6px 16px rgba(0,0,0,0.55), -6px -6px 16px rgba(255,255,255,0.035)'
              : '6px 6px 15px rgba(183,175,160,0.55), -6px -6px 15px rgba(255,255,255,0.9)'};
          }
          .modnav-rail {
            display: flex; align-items: stretch; gap: 0;
            overflow-x: auto; padding: 5px; border-radius: 9999px;
            background: ${isDark ? '#1f1d24' : '#ece7de'};
            box-shadow: ${isDark
              ? 'inset 2px 2px 6px rgba(0,0,0,0.6), inset -2px -2px 6px rgba(255,255,255,0.04), inset 0 0 16px rgba(150,120,255,0.08)'
              : 'inset 2px 2px 5px rgba(183,175,160,0.6), inset -2px -2px 5px rgba(255,255,255,0.85), inset 0 0 14px rgba(255,255,255,0.55)'};
          }
          .modnav-rail::-webkit-scrollbar { display: none; }
          .modnav-tab {
            position: relative; display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 9999px;
            font-size: 12px; font-weight: 600; white-space: nowrap; cursor: pointer;
            color: ${isDark ? 'var(--text-muted)' : '#9a9182'}; text-decoration: none;
            transition: color .3s ease, background .35s ease, box-shadow .4s ease, transform .18s ease;
          }
          .modnav-tab:hover { color: ${isDark ? 'var(--text-h)' : '#5f5849'}; }
          .modnav-tab:active { transform: scale(0.96); }
          .modnav-tab.on {
            color: ${isDark ? '#c4b5fd' : '#7C3AED'};
            background: ${isDark ? 'linear-gradient(145deg,#3a3450,#2c2840)' : '#ffffff'};
            box-shadow: ${isDark
              ? '0 0 16px rgba(167,139,250,0.45), 3px 3px 8px rgba(0,0,0,0.5), -2px -2px 6px rgba(255,255,255,0.05)'
              : '0 0 16px rgba(255,255,255,0.9), 3px 3px 8px rgba(183,175,160,0.55), -3px -3px 8px rgba(255,255,255,0.95), 0 0 0 1px rgba(255,255,255,0.7)'};
          }
          .modnav-ico { opacity: .8; transition: transform .3s cubic-bezier(.34,1.56,.64,1), opacity .3s ease; }
          .modnav-tab:hover .modnav-ico { opacity: 1; }
          .modnav-tab.on .modnav-ico { transform: scale(1.12); opacity: 1; }
          .modnav-tick {
            flex: 0 0 auto; align-self: center; width: 2px; height: 15px; margin: 0 1px;
            border-radius: 2px;
            background: ${isDark ? 'rgba(255,255,255,0.12)' : 'rgba(160,150,134,0.5)'};
          }
        `}</style>
        <nav className="modnav-plate">
          <div className="modnav-rail">
            {items.map(({ label: tabLabel, path, icon: Icon }, i) => (
              <Fragment key={path}>
                {i > 0 && <span className="modnav-tick" aria-hidden="true" />}
                <NavLink to={path} className={({ isActive }) => `modnav-tab${isActive ? ' on' : ''}`}>
                  <Icon size={13} className="modnav-ico" />
                  {tabLabel}
                </NavLink>
              </Fragment>
            ))}
          </div>
        </nav>
      </div>

      <div className="p-4 md:p-6">
        <Outlet />
      </div>
    </div>
  )
}
