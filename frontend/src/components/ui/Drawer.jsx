import { X } from 'lucide-react'

export default function Drawer({ open, onClose, title, width = 'min(820px, 95vw)', children, footer }) {
  if (!open) return null

  return (
    <>
      <div className="drawer-backdrop" onClick={onClose} />
      <div className="drawer-panel" style={{ width }}>
        <div className="drawer-header">
          <h2 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{title}</h2>
          <button onClick={onClose} className="btn-icon">
            <X size={18} />
          </button>
        </div>

        <div className="drawer-body">{children}</div>

        {footer && <div className="drawer-footer">{footer}</div>}
      </div>
    </>
  )
}
