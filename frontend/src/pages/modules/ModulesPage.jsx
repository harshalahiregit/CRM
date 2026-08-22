import { useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import { ALL_MODULES, getInstalledModules, installModule, uninstallModule } from '@/modules/registry'
import {
  Package, CheckCircle, Download, Trash2, ExternalLink,
  Search, Filter, Star, Zap, Shield, ChevronRight
} from 'lucide-react'

const CATEGORIES = ['All', 'Human Resources', 'Operations', 'Finance', 'Intelligence', 'Communication']

export default function ModulesPage() {
  const { isDark } = useTheme()
  const navigate = useNavigate()
  const [installed, setInstalled] = useState(() => getInstalledModules())
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('All')
  const [installing, setInstalling] = useState(null)
  const [confirmUninstall, setConfirmUninstall] = useState(null)

  const handleInstall = useCallback(async (moduleId) => {
    setInstalling(moduleId)
    await new Promise(r => setTimeout(r, 1200)) // simulate install
    installModule(moduleId)
    setInstalled(getInstalledModules())
    setInstalling(null)
  }, [])

  const handleUninstall = useCallback((moduleId) => {
    uninstallModule(moduleId)
    setInstalled(getInstalledModules())
    setConfirmUninstall(null)
  }, [])

  const filtered = ALL_MODULES.filter(m => {
    const matchSearch = m.name.toLowerCase().includes(search.toLowerCase()) ||
                        m.description.toLowerCase().includes(search.toLowerCase())
    const matchCat = category === 'All' || m.category === category
    return matchSearch && matchCat
  })
  // Installed (selected) modules float to the FRONT of the grid instead of
  // sitting in fixed catalog order — the one you picked jumps to the top. Array
  // sort is stable, so within each group the original catalog order is kept.
  .sort((a, b) => (installed.includes(a.id) ? 0 : 1) - (installed.includes(b.id) ? 0 : 1))

  const installedModules = ALL_MODULES.filter(m => installed.includes(m.id))

  return (
    <div className="space-y-8 animate-[tiltIn_0.35s_ease_forwards]">

      {/* ── Page Header ─────────────────────────────────── */}
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="label-caps mb-1">Marketplace</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.5rem,3vw,2rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            Module <span className="text-gradient">Manager</span>
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
            Install, manage and launch your CRM modules.
          </p>
        </div>
        <div
          className="hidden md:flex flex-col items-center justify-center px-4 py-3 rounded-2xl text-center"
          style={{ background: isDark ? 'rgba(124,58,237,0.12)' : 'rgba(124,58,237,0.08)', border: '1px solid rgba(124,58,237,0.2)' }}
        >
          <p className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>{installed.length}</p>
          <p className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>Installed</p>
        </div>
      </div>

      {/* ── Installed Modules strip ──────────────────────── */}
      {installedModules.length > 0 && (
        <div
          className="card-3d p-4"
          style={{ borderColor: 'rgba(124,58,237,0.2)', background: isDark ? 'rgba(124,58,237,0.06)' : 'rgba(124,58,237,0.04)' }}
        >
          <div className="flex items-center gap-2 mb-3">
            <CheckCircle size={15} style={{ color: '#a78bfa' }} />
            <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Installed Modules</span>
          </div>
          <div className="flex flex-wrap gap-2">
            {installedModules.map(m => (
              <button
                key={m.id}
                onClick={() => m.launchPath && navigate(m.launchPath)}
                disabled={!m.launchPath}
                className="flex items-center gap-2 px-3 py-1.5 rounded-xl text-sm font-semibold transition-all duration-200 hover-lift"
                style={{
                  background: m.color,
                  color: '#fff',
                  boxShadow: `0 4px 14px ${m.shadowColor}40`,
                }}
              >
                <span>{m.icon}</span> {m.name}
                <ExternalLink size={11} />
              </button>
            ))}
          </div>
        </div>
      )}

      {/* ── Search + Filter ──────────────────────────────── */}
      <div className="flex flex-col sm:flex-row gap-3">
        <div className="relative flex-1">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            className="input-3d pl-9"
            placeholder="Search modules..."
            value={search}
            onChange={e => setSearch(e.target.value)}
          />
        </div>
        <div className="flex gap-2 flex-wrap">
          {CATEGORIES.map(cat => (
            <button key={cat} onClick={() => setCategory(cat)}
              className="px-3 py-2 rounded-xl text-xs font-bold transition-all duration-150"
              style={{
                background: category === cat ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)',
                color: category === cat ? '#fff' : 'var(--text-muted)',
                border: `1px solid ${category === cat ? 'transparent' : 'var(--border)'}`,
                boxShadow: category === cat ? '0 4px 14px rgba(124,58,237,0.35)' : 'none',
              }}
            >
              {cat}
            </button>
          ))}
        </div>
      </div>

      {/* ── Module Grid ─────────────────────────────────── */}
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        {filtered.map(module => {
          const isInstalled = installed.includes(module.id)
          const isLoading  = installing === module.id

          return (
            <div
              key={module.id}
              className="card-3d relative overflow-hidden flex flex-col"
              style={{ padding: '24px' }}
            >
              {/* Category badge */}
              <div className="absolute top-4 right-4">
                <span
                  className="text-[10px] font-bold px-2 py-0.5 rounded-md"
                  style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
                >
                  {module.category}
                </span>
              </div>

              {/* Icon + Name */}
              <div className="flex items-center gap-3 mb-4">
                <div
                  className="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl flex-shrink-0"
                  style={{
                    background: module.color,
                    boxShadow: `0 8px 24px ${module.shadowColor}40, inset 0 1px 0 rgba(255,255,255,0.2)`,
                  }}
                >
                  {module.icon}
                </div>
                <div>
                  <h3 className="font-bold" style={{ color: 'var(--text-h)' }}>{module.name}</h3>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>v{module.version} · {module.author}</p>
                </div>
              </div>

              {/* Description */}
              <p className="text-sm mb-4 flex-1" style={{ color: 'var(--text-muted)', lineHeight: 1.6 }}>
                {module.description}
              </p>

              {/* Features */}
              <div className="flex flex-wrap gap-1.5 mb-5">
                {module.features.slice(0, 4).map(f => (
                  <span
                    key={f}
                    className="text-[10px] font-semibold px-2 py-0.5 rounded-lg"
                    style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.15)' }}
                  >
                    {f}
                  </span>
                ))}
                {module.features.length > 4 && (
                  <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>+{module.features.length - 4} more</span>
                )}
              </div>

              {/* Action Buttons */}
              <div className="flex gap-2 mt-auto">
                {isInstalled ? (
                  <>
                    {/* Launch only where something exists to launch. basePath + '/dashboard'
                        was assumed for every module and 404'd for five of them. */}
                    {module.launchPath && (
                      <button
                        onClick={() => module.launchPath && navigate(module.launchPath)}
                        disabled={!module.launchPath}
                        className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white transition-all duration-200"
                        style={{
                          background: module.color,
                          boxShadow: `0 4px 16px ${module.shadowColor}40`,
                        }}
                        onMouseEnter={e => e.currentTarget.style.transform = 'translateY(-2px)'}
                        onMouseLeave={e => e.currentTarget.style.transform = 'translateY(0)'}
                      >
                        <ExternalLink size={14} /> Launch
                      </button>
                    )}
                    <button
                      onClick={() => setConfirmUninstall(module.id)}
                      className="flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-semibold transition-all duration-150"
                      style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171', border: '1px solid rgba(239,68,68,0.2)' }}
                      onMouseEnter={e => e.currentTarget.style.background = 'rgba(239,68,68,0.15)'}
                      onMouseLeave={e => e.currentTarget.style.background = 'rgba(239,68,68,0.1)'}
                    >
                      <Trash2 size={13} />
                    </button>
                  </>
                ) : (
                  <button
                    onClick={() => handleInstall(module.id)}
                    disabled={isLoading}
                    className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold transition-all duration-200"
                    style={{
                      background: isLoading ? 'var(--bg-input)' : 'var(--bg-card)',
                      color: isLoading ? 'var(--text-muted)' : 'var(--text-h)',
                      border: '1px solid var(--border-purple)',
                    }}
                    onMouseEnter={e => { if (!isLoading) { e.currentTarget.style.background = 'rgba(124,58,237,0.1)' }}}
                    onMouseLeave={e => { e.currentTarget.style.background = isLoading ? 'var(--bg-input)' : 'var(--bg-card)' }}
                  >
                    {isLoading ? (
                      <><div className="w-4 h-4 border-2 border-purple-400 border-t-transparent rounded-full animate-spin" /> Installing...</>
                    ) : (
                      <><Download size={14} style={{ color: '#a78bfa' }} /> Install</>
                    )}
                  </button>
                )}
              </div>

              {/* Installed ribbon */}
              {isInstalled && (
                <div
                  className="absolute top-0 left-0 flex items-center gap-1 text-[10px] font-black text-white px-3 py-1 rounded-br-xl"
                  style={{ background: module.color }}
                >
                  <CheckCircle size={9} /> INSTALLED
                </div>
              )}
            </div>
          )
        })}
      </div>

      {/* Uninstall Confirm Modal */}
      {confirmUninstall && (
        <div className="modal-backdrop" onClick={() => setConfirmUninstall(null)}>
          <div className="modal-box" onClick={e => e.stopPropagation()}>
            <div className="text-center space-y-4">
              <div className="w-14 h-14 rounded-2xl bg-red-500/10 flex items-center justify-center mx-auto">
                <Trash2 size={24} style={{ color: '#f87171' }} />
              </div>
              <div>
                <h3 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>Uninstall Module?</h3>
                <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
                  All data associated with this module will be hidden. You can reinstall later.
                </p>
              </div>
              <div className="flex gap-3">
                <button onClick={() => setConfirmUninstall(null)} className="flex-1 btn-secondary py-2.5 rounded-xl">Cancel</button>
                <button
                  onClick={() => handleUninstall(confirmUninstall)}
                  className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
                  style={{ background: 'linear-gradient(135deg,#ef4444,#dc2626)', boxShadow: '0 4px 14px rgba(239,68,68,0.4)' }}
                >
                  Uninstall
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
