import { AlertTriangle, X } from 'lucide-react'

export default function ConfirmDialog({
  title = 'Are you sure?',
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  tone = 'danger', // 'danger' | 'primary'
  onConfirm,
  onCancel,
}) {
  const accent = tone === 'danger' ? '#ef4444' : '#7C3AED'
  const confirmGradient = tone === 'danger'
    ? 'linear-gradient(135deg, #ef4444, #dc2626)'
    : 'linear-gradient(135deg, #7C3AED, #6d28d9)'

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.7)' }}>
      <div className="rounded-2xl shadow-2xl max-w-md w-full" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-6" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full flex items-center justify-center" style={{ background: `${accent}26` }}>
              <AlertTriangle size={20} style={{ color: accent }} />
            </div>
            <h2 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{title}</h2>
          </div>
          <button
            onClick={onCancel}
            className="p-2 rounded-lg"
            style={{ background: 'transparent' }}
            onMouseEnter={(e) => e.currentTarget.style.background = 'var(--bg-hover)'}
            onMouseLeave={(e) => e.currentTarget.style.background = 'transparent'}
          >
            <X size={20} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="p-6">
          <p className="text-sm leading-relaxed" style={{ color: 'var(--text-muted)' }}>
            {message}
          </p>
        </div>

        <div className="flex gap-3 p-6" style={{ borderTop: '1px solid var(--border)' }}>
          <button
            onClick={onCancel}
            className="flex-1 px-6 py-3 rounded-xl text-sm font-bold"
            style={{ background: 'var(--bg-hover)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}
          >
            {cancelLabel}
          </button>
          <button
            onClick={onConfirm}
            className="flex-1 px-6 py-3 rounded-xl text-sm font-bold"
            style={{ background: confirmGradient, color: '#fff' }}
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}
