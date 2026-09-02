import { useState, useEffect } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import Sidebar from './Sidebar'
import { useSidebarSection, sectionForPath } from './sidebarSection'
import Header from './Header'
import MobileBottomNav from './MobileBottomNav'
import CommandPalette from '@/components/CommandPalette'
import IdleTimeoutWarning from '@/components/common/IdleTimeoutWarning'
import AppNotificationToaster from '@/components/notifications/AppNotificationToaster'
import clsx from 'clsx'
import { useTheme } from '@/context/ThemeContext'

export default function AppShell() {
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  // Owned here, not inside Sidebar: two Sidebars are mounted (the off-canvas
  // mobile drawer and the desktop one) and they must not disagree about which
  // accordion section is open. See sidebarSection.js.
  const { openSection, setOpenSection, toggleSection, isGroupOpen, toggleGroup } = useSidebarSection()
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)
  const { isDark } = useTheme()
  const { pathname } = useLocation()

  // Being IN a module opens that module's section and (via Sidebar's scroll
  // effect) pulls it to the top of the sidebar — so navigating to Inventory
  // shows the Inventory menu at the top, instead of leaving it closed and
  // buried. Only fires on a real route change, so a section you close by hand
  // while staying on the page stays closed. Pages in no section leave the
  // sidebar as-is.
  useEffect(() => {
    const section = sectionForPath(pathname)
    if (section) setOpenSection(section)
  }, [pathname, setOpenSection])

  const sidebarW = sidebarCollapsed ? 72 : 260

  // HR runs edge to edge, from the sidebar to the right of the window.
  //
  // Every other module sits in a 1440px column so long text stays readable. HR
  // opens with a full-bleed header band — the numbered pipeline rail and the
  // business-phase strip — and inside that column the band could only ever reach
  // 1440, leaving a strip of dead page on each side of it. The wider the monitor
  // the worse it looked, which is why it seemed to differ from page to page when
  // it was really differing from screen to screen.
  //
  // Capping the column and then asking one child to escape it takes fragile
  // margin arithmetic that has to know the sidebar width. Not capping HR at all
  // is one line and needs to know nothing.
  const fullBleed = pathname.startsWith('/app/hr')

  return (
    <div
      className="min-h-screen min-h-dvh transition-colors duration-300"
      style={{ backgroundColor: 'var(--bg-global)' }}
    >
      {/* Mobile sidebar overlay */}
      {mobileMenuOpen && (
        <div
          className="fixed inset-0 z-30 md:hidden"
          style={{ background: 'rgba(0,0,0,0.6)', backdropFilter: 'blur(6px)' }}
          onClick={() => setMobileMenuOpen(false)}
        />
      )}

      {/* Mobile sidebar drawer */}
      <div
        className={clsx(
          'fixed left-0 top-0 h-full w-72 z-40 md:hidden transition-transform duration-300',
          mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'
        )}
        style={{ filter: isDark ? 'none' : 'drop-shadow(4px 0 24px rgba(124,58,237,0.12))' }}
      >
        <Sidebar collapsed={false} onToggle={() => {}} openSection={openSection} toggleSection={toggleSection} isGroupOpen={isGroupOpen} toggleGroup={toggleGroup} />
      </div>

      {/* Desktop sidebar */}
      <Sidebar
        collapsed={sidebarCollapsed}
        onToggle={() => setSidebarCollapsed(c => !c)}
        openSection={openSection}
        toggleSection={toggleSection}
        isGroupOpen={isGroupOpen}
        toggleGroup={toggleGroup}
      />

      {/* Header */}
      <Header
        sidebarCollapsed={sidebarCollapsed}
        mobileMenuOpen={mobileMenuOpen}
        onMobileMenuToggle={() => setMobileMenuOpen(o => !o)}
        sidebarW={sidebarW}
      />

      {/* Main content */}
      <main
        className="transition-all duration-300 pt-16 pb-20 md:pb-6 min-h-screen"
        style={{ paddingLeft: `${sidebarW}px` }}
      >
        <div className={clsx('p-4 md:p-6', !fullBleed && 'max-w-[1440px] mx-auto')}>
          <Outlet />
        </div>
      </main>

      {/* Mobile bottom nav */}
      <MobileBottomNav />

      {/* Global Ctrl/Cmd+K command palette (Phase 7d) */}
      <CommandPalette />
      {/* Idle-timeout warning (session management) */}
      <IdleTimeoutWarning />
      {/* On-screen notification pop-ups (persistent until the user reacts) */}
      <AppNotificationToaster />
    </div>
  )
}
