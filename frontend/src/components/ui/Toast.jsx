import { createContext, useCallback, useContext, useState } from 'react'
import { CheckCircle2, XCircle, Info, X, AlertTriangle, Lightbulb } from 'lucide-react'

const ToastContext = createContext(null)

const TYPES = {
  success: { Icon: CheckCircle2,  accent: '#10b981', tint: 'rgba(16,185,129,0.10)', ring: 'rgba(16,185,129,0.35)' },
  error:   { Icon: XCircle,       accent: '#f87171', tint: 'rgba(239,68,68,0.10)',  ring: 'rgba(239,68,68,0.35)'  },
  warning: { Icon: AlertTriangle, accent: '#f59e0b', tint: 'rgba(245,158,11,0.10)', ring: 'rgba(245,158,11,0.35)' },
  info:    { Icon: Info,          accent: '#a78bfa', tint: 'rgba(124,58,237,0.10)', ring: 'rgba(124,58,237,0.35)' },
}

let idCounter = 0

/**
 * Splits a composed message from `services/apiError.js` back into its parts:
 *   line 1       -> title
 *   "• …" lines  -> per-field details
 *   "Tip: …"     -> actionable hint
 * A plain one-line string (the common success case) just becomes the title.
 */
function parseMessage(message) {
  const lines = String(message ?? '').split('\n').map(l => l.trim()).filter(Boolean)
  const title = lines.shift() || ''
  const details = lines.filter(l => l.startsWith('•')).map(l => l.replace(/^•\s*/, ''))
  const tipLine = lines.find(l => l.toLowerCase().startsWith('tip:'))
  return { title, details, tip: tipLine ? tipLine.replace(/^tip:\s*/i, '') : null }
}

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const dismiss = useCallback((id) => {
    setToasts((prev) => prev.filter((t) => t.id !== id))
  }, [])

  /**
   * `message` may be a string OR an Error from apiError.js. Passing the error
   * itself is richest, but `e.message` alone still carries the detail because
   * apiError composes it into the string — so existing call sites keep working.
   */
  const show = useCallback((message, type = 'info', duration) => {
    const id = ++idCounter
    const isErrObj = message && typeof message === 'object' && ('title' in message || 'message' in message)
    const parsed = isErrObj
      ? {
          title: message.title || message.message || 'Something went wrong',
          details: (message.details || []).map(d => String(d).replace(/^•\s*/, '')),
          tip: message.tip ?? null,
        }
      : parseMessage(message)

    // Errors with detail to read stay up longer; a plain success can be brief.
    const ms = duration ?? (type === 'error' ? (parsed.details.length ? 9000 : 6000) : 4000)

    setToasts((prev) => [...prev, { id, type, ...parsed }])
    if (ms > 0) setTimeout(() => dismiss(id), ms)
    return id
  }, [dismiss])

  const toast = {
    success: (m, d) => show(m, 'success', d),
    error:   (m, d) => show(m, 'error', d),
    warning: (m, d) => show(m, 'warning', d),
    info:    (m, d) => show(m, 'info', d),
    dismiss,
  }

  return (
    <ToastContext.Provider value={toast}>
      {children}
      <div className="fixed bottom-24 right-4 z-[100] flex flex-col gap-2.5 items-end pointer-events-none"
        style={{ maxWidth: 'min(420px, calc(100vw - 2rem))' }}>
        {toasts.map((t) => {
          const { Icon, accent, tint, ring } = TYPES[t.type] || TYPES.info
          return (
            <div key={t.id}
              className="pointer-events-auto w-full rounded-2xl overflow-hidden animate-slide-up flex"
              style={{
                // OPAQUE base first: a translucent-only surface shows whatever is
                // behind it, which is what made these hard to read.
                background: 'var(--bg-card)',
                border: `1px solid ${ring}`,
                boxShadow: '0 10px 30px rgba(0,0,0,0.18)',
              }}>
              {/* Accent spine — type is legible at a glance, not only by tint */}
              <span style={{ width: 4, background: accent, flexShrink: 0 }} />
              <div className="flex-1 min-w-0" style={{ background: tint }}>
                <div className="flex items-start gap-2.5 px-3.5 py-3">
                  <Icon size={17} style={{ color: accent, flexShrink: 0, marginTop: 1 }} />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-bold leading-snug" style={{ color: 'var(--text-h)' }}>{t.title}</p>

                    {/* Per-field validation detail — tells the user exactly which
                        input to fix instead of a generic failure message. */}
                    {t.details?.length > 0 && (
                      <ul className="mt-1.5 space-y-1">
                        {t.details.map((d, i) => (
                          <li key={i} className="text-[12px] leading-snug flex gap-1.5" style={{ color: 'var(--text-body)' }}>
                            <span style={{ color: accent, flexShrink: 0 }}>•</span>
                            <span className="min-w-0">{d}</span>
                          </li>
                        ))}
                      </ul>
                    )}

                    {t.tip && (
                      <div className="mt-2 flex items-start gap-1.5 rounded-lg px-2 py-1.5"
                        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                        <Lightbulb size={12} style={{ color: '#f59e0b', flexShrink: 0, marginTop: 1 }} />
                        <span className="text-[11px] leading-snug" style={{ color: 'var(--text-muted)' }}>{t.tip}</span>
                      </div>
                    )}
                  </div>
                  <button onClick={() => dismiss(t.id)} className="flex-shrink-0 rounded-md p-0.5 hover:opacity-70"
                    style={{ color: 'var(--text-muted)' }} aria-label="Dismiss">
                    <X size={14} />
                  </button>
                </div>
              </div>
            </div>
          )
        })}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  const ctx = useContext(ToastContext)
  if (!ctx) {
    throw new Error('useToast must be used within a ToastProvider')
  }
  return ctx
}
