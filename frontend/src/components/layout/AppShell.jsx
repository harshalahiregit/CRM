import { useState, useEffect } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import Sidebar from './Sidebar'
import { useSidebarSection, sectionForPath } from './sidebarSection'
import Header from './Header'
import MobileBottomNav from './MobileBottomNav'
import CommandPalette from '@/components/CommandPalette'
import IdleTimeoutWarning from '@/components/common/IdleTimeoutWarning'
import AppNotificationToaster from '@/components/notifications/AppNotificationToaster'
import ReportIssueRoot from '@/components/sire/ReportIssueRoot'
import ErrorBoundary from '@/components/ErrorBoundary'
import PageErrorFallback from '@/components/PageErrorFallback'
import { canUseSire } from '@/lib/sire/access'
import { useAuth } from '@/context/AuthContext'
import clsx from 'clsx'
import { useTheme } from '@/context/ThemeContext'

export default function AppShell() {
  const { user } = useAuth()
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
        <Sidebar inDrawer collapsed={false} onToggle={() => {}} openSection={openSection} toggleSection={toggleSection} isGroupOpen={isGroupOpen} toggleGroup={toggleGroup} />
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
        className="app-shifted transition-all duration-300 pt-16 pb-20 md:pb-6 min-h-screen"
        // The offset itself is a variable; the breakpoint lives in CSS, because
        // an inline style cannot have one. See .app-shifted in index.css.
        style={{ '--sidebar-w': `${sidebarW}px` }}
      >
        <div className={clsx('p-4 md:p-6', !fullBleed && 'max-w-[1440px] mx-auto')}>
          {/* A crashing PAGE must not take the application with it.
              The only boundary used to be at the top of App.jsx, above the
              providers and above this shell, so any page that threw replaced
              everything -- sidebar, header, and the Report Issue button, which
              lives a few lines below. Report Issue is meant to be on every
              screen, and the screen it was missing from was the one that had
              just broken in front of the user.
              resetKey, because a boundary latches: without it, one crashed
              page would follow the user to every route they tried next, and
              only a manual reload would clear it. */}
          <ErrorBoundary
            resetKey={pathname}
            fallback={(error, retry) => <PageErrorFallback error={error} onRetry={retry} />}
          >
            <Outlet />
          </ErrorBoundary>
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
      {/* SIRE Report Issue -- one click from any screen. It captures the module,
          screen, app version, browser and recent failed requests itself, so the
          form only ever asks for a title and what happened. */}
      {canUseSire(user) && <ReportIssueRoot />}
    </div>
  )
}
