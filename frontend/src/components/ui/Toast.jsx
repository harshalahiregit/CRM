import { createContext, useCallback, useContext, useState } from 'react'
import { CheckCircle2, XCircle, Info, X } from 'lucide-react'

const ToastContext = createContext(null)

const ICONS = {
  success: CheckCircle2,
  error: XCircle,
  info: Info,
}

const COLORS = {
  success: '#10b981',
  error: '#f87171',
  info: '#a78bfa',
}

let idCounter = 0

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const dismiss = useCallback((id) => {
    setToasts((prev) => prev.filter((t) => t.id !== id))
  }, [])

  const show = useCallback((message, type = 'info', duration = 4000) => {
    const id = ++idCounter
    setToasts((prev) => [...prev, { id, message, type }])
    if (duration > 0) {
      setTimeout(() => dismiss(id), duration)
    }
    return id
  }, [dismiss])

  const toast = {
    success: (message, duration) => show(message, 'success', duration),
    error: (message, duration) => show(message, 'error', duration),
    info: (message, duration) => show(message, 'info', duration),
    dismiss,
  }

  return (
    <ToastContext.Provider value={toast}>
      {children}
      <div className="fixed bottom-24 right-4 z-[100] flex flex-col gap-2 items-end pointer-events-none">
        {toasts.map((t) => {
          const Icon = ICONS[t.type] || Info
          return (
            <div key={t.id} className={`toast-${t.type} pointer-events-auto relative`} style={{ position: 'static' }}>
              <Icon size={18} style={{ color: COLORS[t.type], flexShrink: 0 }} />
              <p className="text-sm font-semibold flex-1" style={{ color: 'var(--text-h)' }}>{t.message}</p>
              <button onClick={() => dismiss(t.id)} className="flex-shrink-0" style={{ color: 'var(--text-muted)' }}>
                <X size={14} />
              </button>
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
