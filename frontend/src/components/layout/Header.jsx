import { Search, Bell, Menu, X, User, LogOut, Settings, Moon, Sun } from 'lucide-react'
import { useState, useRef, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { useTheme } from '@/context/ThemeContext'
import clsx from 'clsx'

export default function Header({ sidebarCollapsed, mobileMenuOpen, onMobileMenuToggle }) {
  const { user, logout } = useAuth()
  const { isDark, toggleTheme } = useTheme()
  const navigate = useNavigate()
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const menuRef = useRef(null)

  // Close menu on outside click
  useEffect(() => {
    const handler = (e) => {
      if (menuRef.current && !menuRef.current.contains(e.target)) {
        setUserMenuOpen(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  // Keyboard shortcut Cmd/Ctrl+K
  useEffect(() => {
    const handler = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault()
        setSearchOpen(true)
      }
      if (e.key === 'Escape') setSearchOpen(false)
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [])

  const handleLogout = async () => {
    await logout()
    navigate('/auth/login')
  }

  const initials = user?.name?.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) || 'U'

  return (
    <>
      <header
        className={clsx(
          'header',
          sidebarCollapsed
            ? '[--sidebar-width:72px]'
            : '[--sidebar-width:260px]'
        )}
      >
        {/* Mobile hamburger */}
        <button
          onClick={onMobileMenuToggle}
          className="btn-icon text-gray-400 hover:text-white md:hidden"
          aria-label="Menu"
        >
          {mobileMenuOpen ? <X size={20} /> : <Menu size={20} />}
        </button>

        {/* Search bar (desktop) */}
        <button
          onClick={() => setSearchOpen(true)}
          className="hidden md:flex items-center gap-2 px-3 py-2 rounded-lg
                     bg-white/5 border border-white/10 text-gray-500 text-sm
                     hover:bg-white/10 hover:text-gray-300 transition-all
                     min-w-[200px]"
        >
          <Search size={15} />
          <span>Search...</span>
          <span className="ml-auto text-xs bg-white/10 px-1.5 py-0.5 rounded">⌘K</span>
        </button>

        {/* Search icon (mobile) */}
        <button
          onClick={() => setSearchOpen(true)}
          className="btn-icon text-gray-400 hover:text-white md:hidden"
          aria-label="Search"
        >
          <Search size={20} />
        </button>

        {/* Spacer */}
        <div className="flex-1" />

        {/* Theme toggle */}
        <button
          onClick={toggleTheme}
          className="btn-icon text-gray-400 hover:text-white hidden md:flex"
          aria-label="Toggle theme"
        >
          {isDark ? <Sun size={18} /> : <Moon size={18} />}
        </button>

        {/* Notifications */}
        <button className="btn-icon text-gray-400 hover:text-white relative" aria-label="Notifications">
          <Bell size={20} />
          <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-primary-500 rounded-full" />
        </button>

        {/* User menu */}
        <div className="relative" ref={menuRef}>
          <button
            onClick={() => setUserMenuOpen(o => !o)}
            className="flex items-center gap-2 rounded-lg px-2 py-1.5
                       hover:bg-white/10 transition-all duration-150"
            aria-label="User menu"
          >
            <div className="w-8 h-8 rounded-full bg-primary-600 flex items-center justify-center
                            text-xs font-bold text-white">
              {initials}
            </div>
            <div className="hidden md:block text-left">
              <p className="text-xs font-medium text-white leading-none">{user?.name}</p>
              <p className="text-xs text-gray-500 capitalize leading-none mt-0.5">{user?.role}</p>
            </div>
          </button>

          {/* Dropdown */}
          {userMenuOpen && (
            <div className="absolute right-0 top-12 w-52 bg-gray-800 border border-white/10
                            rounded-xl shadow-xl py-1.5 animate-scale-in z-50">
              <div className="px-3 py-2 border-b border-white/10 mb-1">
                <p className="text-sm font-medium text-white">{user?.name}</p>
                <p className="text-xs text-gray-500">{user?.email}</p>
              </div>
              <button
                onClick={() => { navigate('/app/settings/profile'); setUserMenuOpen(false) }}
                className="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-gray-300
                           hover:bg-white/10 hover:text-white transition-colors"
              >
                <User size={16} /> My Profile
              </button>
              <button
                onClick={() => { navigate('/app/settings'); setUserMenuOpen(false) }}
                className="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-gray-300
                           hover:bg-white/10 hover:text-white transition-colors"
              >
                <Settings size={16} /> Settings
              </button>
              <div className="border-t border-white/10 mt-1 pt-1">
                <button
                  onClick={handleLogout}
                  className="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-danger
                             hover:bg-danger/10 transition-colors"
                >
                  <LogOut size={16} /> Logout
                </button>
              </div>
            </div>
          )}
        </div>
      </header>

      {/* Global Search Modal */}
      {searchOpen && (
        <div className="modal-backdrop" onClick={() => setSearchOpen(false)}>
          <div
            className="bg-gray-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-lg mx-4
                       overflow-hidden animate-scale-in"
            onClick={e => e.stopPropagation()}
          >
            <div className="flex items-center gap-3 px-4 py-3 border-b border-white/10">
              <Search size={18} className="text-gray-400 flex-shrink-0" />
              <input
                autoFocus
                value={searchQuery}
                onChange={e => setSearchQuery(e.target.value)}
                placeholder="Search contacts, deals, tasks..."
                className="flex-1 bg-transparent text-white placeholder-gray-500 outline-none text-sm"
              />
              <kbd className="text-xs text-gray-500 bg-white/5 px-1.5 py-0.5 rounded">Esc</kbd>
            </div>
            <div className="px-4 py-8 text-center text-gray-500 text-sm">
              {searchQuery ? `Searching for "${searchQuery}"...` : 'Start typing to search across all modules'}
            </div>
          </div>
        </div>
      )}
    </>
  )
}
