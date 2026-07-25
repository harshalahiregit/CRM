import {
  LayoutDashboard, Users, Briefcase, CheckSquare, MoreHorizontal,
  FolderOpen, Receipt, Truck, LifeBuoy, BarChart2, Settings, Landmark
} from 'lucide-react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useState } from 'react'
import clsx from 'clsx'

const PRIMARY_TABS = [
  { label: 'Dashboard', icon: LayoutDashboard, path: '/app/dashboard' },
  { label: 'Contacts',  icon: Users,            path: '/app/contacts'  },
  { label: 'Deals',     icon: Briefcase,        path: '/app/deals'     },
  { label: 'Tasks',     icon: CheckSquare,      path: '/app/tasks'     },
  { label: 'More',      icon: MoreHorizontal,   path: null             },
]

const MORE_ITEMS = [
  { label: 'Accounts',  icon: Landmark,   path: '/app/accounts'  },
  { label: 'Projects',  icon: FolderOpen, path: '/app/projects'  },
  { label: 'Invoices',  icon: Receipt,    path: '/app/invoices'  },
  { label: 'Vendors',   icon: Truck,      path: '/app/vendors'   },
  { label: 'Tickets',   icon: LifeBuoy,   path: '/app/tickets'   },
  { label: 'Reports',   icon: BarChart2,  path: '/app/reports'   },
  { label: 'Settings',  icon: Settings,   path: '/app/settings'  },
]

export default function MobileBottomNav() {
  const [moreOpen, setMoreOpen] = useState(false)
  const navigate = useNavigate()

  return (
    <>
      {/* More Drawer */}
      {moreOpen && (
        <>
          <div
            className="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm md:hidden"
            onClick={() => setMoreOpen(false)}
          />
          <div className="fixed bottom-16 left-0 right-0 z-50 bg-gray-900 border-t border-white/10
                          rounded-t-2xl px-4 py-4 grid grid-cols-3 gap-3 animate-slide-up md:hidden">
            <p className="col-span-3 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
              More
            </p>
            {MORE_ITEMS.map(({ label, icon: Icon, path }) => (
              <button
                key={path}
                onClick={() => { navigate(path); setMoreOpen(false) }}
                className="flex flex-col items-center gap-1.5 p-3 rounded-xl
                           text-gray-400 hover:text-white hover:bg-white/10
                           transition-all duration-150 touch-target"
              >
                <Icon size={22} />
                <span className="text-xs">{label}</span>
              </button>
            ))}
          </div>
        </>
      )}

      {/* Bottom Tab Bar */}
      <nav className="bottom-nav md:hidden">
        {PRIMARY_TABS.map(({ label, icon: Icon, path }) => {
          if (!path) {
            // "More" button
            return (
              <button
                key="more"
                onClick={() => setMoreOpen(o => !o)}
                className={clsx(
                  'bottom-nav-item',
                  moreOpen && 'bottom-nav-item-active'
                )}
              >
                <Icon size={22} />
                <span className="text-[10px] font-medium">{label}</span>
              </button>
            )
          }
          return (
            <NavLink key={path} to={path} onClick={() => setMoreOpen(false)}>
              {({ isActive }) => (
                <div className={clsx(isActive ? 'bottom-nav-item-active' : 'bottom-nav-item')}>
                  <Icon size={22} />
                  <span className="text-[10px] font-medium">{label}</span>
                </div>
              )}
            </NavLink>
          )
        })}
      </nav>
    </>
  )
}
