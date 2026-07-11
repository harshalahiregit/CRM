import { Search, Bell, Menu, X, User, LogOut, Settings, Moon, Sun, Sparkles, Command } from 'lucide-react'
import { useState, useRef, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { useTheme } from '@/context/ThemeContext'
import clsx from 'clsx'

export default function Header({ sidebarCollapsed, mobileMenuOpen, onMobileMenuToggle, sidebarW = 260 }) {
  const { user, logout } = useAuth()
  const { isDark, toggleTheme } = useTheme()
  const navigate = useNavigate()
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const [searchOpen,   setSearchOpen]   = useState(false)
  const [searchQuery,  setSearchQuery]  = useState('')
  const menuRef = useRef(null)

  useEffect(() => {
    const h = (e) => { if (menuRef.current && !menuRef.current.contains(e.target)) setUserMenuOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [])

  // ⌘K is owned by the global CommandPalette (Phase 7d). The header search box
  // just opens it via a custom event, so there is a single working palette.
  const openPalette = () => window.dispatchEvent(new CustomEvent('open-command-palette'))

  const handleLogout = async () => { await logout(); navigate('/auth/login') }
  const initials = user?.name?.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) || 'U'

  const menuBg   = isDark ? '#1c1c2e' : '#ffffff'
  const menuBdr  = isDark ? 'rgba(124,58,237,0.25)' : 'rgba(124,58,237,0.15)'
  const itemHover= isDark ? 'rgba(124,58,237,0.08)' : 'rgba(124,58,237,0.06)'

  return (
    <>
      {/* ── Header bar ──────────────────────────────────────── */}
      <header
        className="header-3d"
        style={{ left: sidebarW, transition: 'left 0.3s ease' }}
      >
        {/* Mobile hamburger */}
        <button onClick={onMobileMenuToggle} className="btn-icon md:hidden" aria-label="Menu">
          {mobileMenuOpen ? <X size={20} /> : <Menu size={20} />}
        </button>

        {/* Mobile brand */}
        <div className="flex items-center gap-2 md:hidden">
          <div
            className="w-7 h-7 rounded-xl flex items-center justify-center"
            style={{ background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 3px 10px rgba(124,58,237,0.4)' }}
          >
            <Sparkles size={13} className="text-white" />
          </div>
          <span className="text-sm font-black" style={{ color: 'var(--text-h)' }}>Sangoe</span>
        </div>

        {/* Search bar (desktop) — 3D inset look */}
        <button
          onClick={openPalette}
          className="hidden md:flex items-center gap-2.5 px-4 py-2.5 rounded-xl text-sm min-w-[220px] transition-all duration-200"
          style={{
            background: 'var(--bg-input)',
            border: '1px solid var(--border-input)',
            color: 'var(--text-muted)',
            boxShadow: isDark
              ? 'inset 0 2px 4px rgba(0,0,0,0.15)'
              : 'inset 0 2px 6px rgba(124,58,237,0.06), 0 1px 0 rgba(255,255,255,1)',
          }}
          onMouseEnter={e => { e.currentTarget.style.borderColor = 'rgba(124,58,237,0.4)'; e.currentTarget.style.background = 'rgba(124,58,237,0.06)' }}
          onMouseLeave={e => { e.currentTarget.style.borderColor = 'var(--border-input)'; e.currentTarget.style.background = 'var(--bg-input)' }}
        >
          <Search size={14} />
          <span>Search...</span>
          <div className="ml-auto flex items-center gap-1">
            <kbd
              className="flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded-md font-semibold"
              style={{ background: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(124,58,237,0.08)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
            >
              <Command size={9} /> K
            </kbd>
          </div>
        </button>

        {/* Mobile search */}
        <button onClick={openPalette} className="btn-icon md:hidden" aria-label="Search">
          <Search size={20} />
        </button>

        <div className="flex-1" />

        {/* Theme toggle — 3D pill */}
        <button
          onClick={toggleTheme}
          className="hidden md:flex w-9 h-9 rounded-xl items-center justify-center transition-all duration-200"
          style={{
            background: isDark ? 'linear-gradient(135deg,rgba(251,191,36,0.15),rgba(245,158,11,0.08))' : 'linear-gradient(135deg,rgba(99,102,241,0.15),rgba(79,70,229,0.08))',
            border: `1px solid ${isDark ? 'rgba(251,191,36,0.25)' : 'rgba(99,102,241,0.25)'}`,
            color: isDark ? '#fbbf24' : '#6366f1',
            boxShadow: isDark ? '0 2px 8px rgba(251,191,36,0.2)' : '0 2px 8px rgba(99,102,241,0.2)',
          }}
          aria-label="Toggle theme"
          onMouseEnter={e => e.currentTarget.style.transform = 'scale(1.1) rotate(15deg)'}
          onMouseLeave={e => e.currentTarget.style.transform = 'scale(1) rotate(0deg)'}
        >
          {isDark ? <Sun size={16} /> : <Moon size={16} />}
        </button>

        {/* Notifications — 3D bell */}
        <button
          className="relative w-9 h-9 rounded-xl flex items-center justify-center transition-all duration-200"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}
          aria-label="Notifications"
          onMouseEnter={e => { e.currentTarget.style.borderColor = 'rgba(124,58,237,0.3)'; e.currentTarget.style.color = 'var(--text-h)'; e.currentTarget.style.transform = 'scale(1.05)' }}
          onMouseLeave={e => { e.currentTarget.style.borderColor = 'var(--border)'; e.currentTarget.style.color = 'var(--text-muted)'; e.currentTarget.style.transform = 'scale(1)' }}
        >
          <Bell size={17} />
          {/* 3D notification badge */}
          <span
            className="absolute top-1 right-1 w-2 h-2 rounded-full border-2"
            style={{
              background: '#7C3AED',
              borderColor: isDark ? '#0b0b16' : '#f0f0f8',
              boxShadow: '0 0 6px rgba(124,58,237,0.7)',
            }}
          />
        </button>

        {/* User avatar menu */}
        <div className="relative" ref={menuRef}>
          <button
            onClick={() => setUserMenuOpen(o => !o)}
            className="flex items-center gap-2.5 px-2.5 py-1.5 rounded-xl transition-all duration-200"
            style={{
              border: `1px solid ${userMenuOpen ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`,
              background: userMenuOpen ? 'rgba(124,58,237,0.08)' : 'transparent',
            }}
            onMouseEnter={e => { e.currentTarget.style.background = 'rgba(124,58,237,0.06)'; e.currentTarget.style.borderColor = 'rgba(124,58,237,0.25)' }}
            onMouseLeave={e => {
              if (!userMenuOpen) {
                e.currentTarget.style.background = 'transparent'
                e.currentTarget.style.borderColor = 'var(--border)'
              }
            }}
            aria-label="User menu"
          >
            {/* 3D Avatar */}
            <div
              className="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black text-white"
              style={{
                background: 'linear-gradient(145deg,#9f67ff,#7C3AED,#5b21b6)',
                boxShadow: '0 4px 12px rgba(124,58,237,0.45), inset 0 1px 0 rgba(255,255,255,0.25)',
              }}
            >
              {initials}
            </div>
            <div className="hidden md:block text-left">
              <p className="text-xs font-semibold leading-none" style={{ color: 'var(--text-h)' }}>{user?.name}</p>
              <p className="text-[10px] capitalize leading-none mt-0.5" style={{ color: 'var(--text-muted)' }}>
                {user?.role?.replace(/_/g,' ')}
              </p>
            </div>
          </button>

          {/* 3D Dropdown */}
          {userMenuOpen && (
            <div
              className="absolute right-0 top-12 w-60 rounded-2xl py-2 animate-scale-in z-50"
              style={{
                background: menuBg,
                border: `1px solid ${menuBdr}`,
                boxShadow: '0 20px 60px rgba(0,0,0,0.3), 0 4px 16px rgba(124,58,237,0.15), inset 0 1px 0 var(--card-shine)',
              }}
            >
              {/* User info */}
              <div className="px-4 pt-3 pb-3 mb-1" style={{ borderBottom: `1px solid var(--border)` }}>
                <div className="flex items-center gap-3">
                  <div
                    className="w-10 h-10 rounded-2xl flex items-center justify-center text-sm font-black text-white"
                    style={{ background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 4px 12px rgba(124,58,237,0.4)' }}
                  >
                    {initials}
                  </div>
                  <div>
                    <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{user?.name}</p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{user?.email}</p>
                  </div>
                </div>
                {/* Role badge */}
                <div
                  className="mt-2.5 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold"
                  style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.2)' }}
                >
                  <div className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                  {user?.role?.replace(/_/g,' ')?.charAt(0).toUpperCase() + user?.role?.replace(/_/g,' ')?.slice(1)}
                </div>
              </div>

              {[
                { icon: User,     label: 'My Profile', action: () => { navigate('/app/settings/profile'); setUserMenuOpen(false) } },
                { icon: Settings, label: 'Settings',   action: () => { navigate('/app/settings'); setUserMenuOpen(false) } },
              ].map(({ icon: Icon, label, action }) => (
                <button key={label} onClick={action}
                  className="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium transition-all duration-150"
                  style={{ color: 'var(--text-muted)' }}
                  onMouseEnter={e => { e.currentTarget.style.background = itemHover; e.currentTarget.style.color = 'var(--text-h)' }}
                  onMouseLeave={e => { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = 'var(--text-muted)' }}
                >
                  <div className="w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
                    <Icon size={14} style={{ color: '#a78bfa' }} />
                  </div>
                  {label}
                </button>
              ))}

              <div className="mt-1 pt-1" style={{ borderTop: `1px solid var(--border)` }}>
                <button onClick={handleLogout}
                  className="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium transition-all duration-150"
                  style={{ color: '#f87171' }}
                  onMouseEnter={e => e.currentTarget.style.background = 'rgba(239,68,68,0.06)'}
                  onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                >
                  <div className="w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(239,68,68,0.1)' }}>
                    <LogOut size={14} style={{ color: '#f87171' }} />
                  </div>
                  Logout
                </button>
              </div>
            </div>
          )}
        </div>
      </header>

      {/* ── Global Search Modal ─────────────────────────────── */}
      {searchOpen && (
        <div className="modal-backdrop" onClick={() => setSearchOpen(false)}>
          <div
            className="rounded-2xl w-full max-w-lg mx-4 overflow-hidden animate-scale-in"
            style={{
              background: isDark ? '#1c1c2e' : '#ffffff',
              border: `1px solid ${isDark ? 'rgba(124,58,237,0.25)' : 'rgba(124,58,237,0.15)'}`,
              boxShadow: '0 30px 80px rgba(0,0,0,0.4), 0 0 40px rgba(124,58,237,0.15), inset 0 1px 0 var(--card-shine)',
            }}
            onClick={e => e.stopPropagation()}
          >
            {/* Search input */}
            <div className="flex items-center gap-3 px-5 py-4" style={{ borderBottom: `1px solid var(--border)` }}>
              <div
                className="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
                style={{ background: 'rgba(124,58,237,0.12)' }}
              >
                <Search size={15} style={{ color: '#a78bfa' }} />
              </div>
              <input
                autoFocus
                value={searchQuery}
                onChange={e => setSearchQuery(e.target.value)}
                placeholder="Search contacts, deals, tasks..."
                className="flex-1 bg-transparent outline-none text-sm"
                style={{ color: 'var(--text-h)' }}
              />
              <kbd
                className="text-[10px] font-semibold px-2 py-1 rounded-lg"
                style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.2)' }}
              >
                Esc
              </kbd>
            </div>

            {/* Empty state */}
            <div className="px-5 py-10 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
              {searchQuery
                ? <span>Searching for <strong style={{ color: 'var(--text-h)' }}>"{searchQuery}"</strong>...</span>
                : <span>Start typing to search across all modules</span>}
            </div>
          </div>
        </div>
      )}
    </>
  )
}
