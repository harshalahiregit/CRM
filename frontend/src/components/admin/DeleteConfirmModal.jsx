import { AlertTriangle, X } from 'lucide-react'

export default function DeleteConfirmModal({ title, message, onConfirm, onCancel }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.7)' }}>
      <div className="rounded-2xl shadow-2xl max-w-md w-full" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        
        {/* Modal Header */}
        <div className="flex items-center justify-between p-6" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full flex items-center justify-center" style={{ background: 'rgba(239,68,68,0.15)' }}>
              <AlertTriangle size={20} style={{ color: '#ef4444' }} />
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

        {/* Modal Body */}
        <div className="p-6">
          <p className="text-sm leading-relaxed" style={{ color: 'var(--text-muted)' }}>
            {message}
          </p>
        </div>

        {/* Action Buttons */}
        <div className="flex gap-3 p-6" style={{ borderTop: '1px solid var(--border)' }}>
          <button
            onClick={onCancel}
            className="flex-1 px-6 py-3 rounded-xl text-sm font-bold"
            style={{
              background: 'var(--bg-hover)',
              border: '1px solid var(--border)',
              color: 'var(--text-muted)'
            }}
          >
            Cancel
          </button>
          <button
            onClick={onConfirm}
            className="flex-1 px-6 py-3 rounded-xl text-sm font-bold"
            style={{
              background: 'linear-gradient(135deg, #ef4444, #dc2626)',
              color: '#fff'
            }}
          >
            Delete
          </button>
        </div>
      </div>
    </div>
  )
}
