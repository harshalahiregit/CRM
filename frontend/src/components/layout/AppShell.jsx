import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar from './Sidebar'
import Header from './Header'
import MobileBottomNav from './MobileBottomNav'
import clsx from 'clsx'

export default function AppShell() {
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)

  return (
    <div className="min-h-screen min-h-dvh bg-gray-950 dark:bg-gray-950">
      {/* Mobile sidebar overlay */}
      {mobileMenuOpen && (
        <div
          className="fixed inset-0 z-30 bg-black/60 backdrop-blur-sm md:hidden"
          onClick={() => setMobileMenuOpen(false)}
        />
      )}

      {/* Mobile sidebar drawer */}
      <div
        className={clsx(
          'fixed left-0 top-0 h-full w-72 z-40 md:hidden transition-transform duration-300',
          mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'
        )}
      >
        <Sidebar collapsed={false} onToggle={() => {}} />
      </div>

      {/* Desktop sidebar */}
      <Sidebar
        collapsed={sidebarCollapsed}
        onToggle={() => setSidebarCollapsed(c => !c)}
      />

      {/* Header */}
      <Header
        sidebarCollapsed={sidebarCollapsed}
        mobileMenuOpen={mobileMenuOpen}
        onMobileMenuToggle={() => setMobileMenuOpen(o => !o)}
      />

      {/* Main content */}
      <main
        className={clsx(
          'transition-all duration-300 pt-16 pb-20 md:pb-6 min-h-screen',
          'md:pl-[260px]',
          sidebarCollapsed && 'md:pl-[72px]'
        )}
      >
        <div className="p-4 md:p-6 max-w-[1440px] mx-auto">
          <Outlet />
        </div>
      </main>

      {/* Mobile bottom nav */}
      <MobileBottomNav />
    </div>
  )
}
