import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar from './Sidebar'
import Header from './Header'
import MobileBottomNav from './MobileBottomNav'
import clsx from 'clsx'
import { useTheme } from '@/context/ThemeContext'

export default function AppShell() {
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)
  const { isDark } = useTheme()

  const sidebarW = sidebarCollapsed ? 72 : 260

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
        sidebarW={sidebarW}
      />

      {/* Main content */}
      <main
        className="transition-all duration-300 pt-16 pb-20 md:pb-6 min-h-screen"
        style={{ paddingLeft: `${sidebarW}px` }}
      >
        {/* Page content with 3D tilt-in animation */}
        <div
          className="p-4 md:p-6 max-w-[1440px] mx-auto animate-[tiltIn_0.35s_ease_forwards]"
        >
          <Outlet />
        </div>
      </main>

      {/* Mobile bottom nav */}
      <MobileBottomNav />
    </div>
  )
}
