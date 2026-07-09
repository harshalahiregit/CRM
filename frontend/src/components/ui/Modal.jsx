export default function Modal({ open, onClose, width = 'min(480px, 95vw)', children }) {
  if (!open) return null

  return (
    <>
      <div className="drawer-backdrop" onClick={onClose} />
      <div className="drawer-panel" style={{ width }}>
        {children}
      </div>
    </>
  )
}
