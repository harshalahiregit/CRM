export default function EmptyState({ icon: Icon, title, description, action }) {
  return (
    <div className="flex flex-col items-center justify-center min-h-[40vh] gap-4 text-center py-12">
      {Icon && (
        <div
          className="w-16 h-16 rounded-3xl flex items-center justify-center"
          style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid rgba(124,58,237,0.2)' }}
        >
          <Icon size={28} style={{ color: '#a78bfa' }} />
        </div>
      )}
      <div>
        <h3 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{title}</h3>
        {description && (
          <p className="text-sm mt-1 max-w-sm" style={{ color: 'var(--text-muted)' }}>{description}</p>
        )}
      </div>
      {action}
    </div>
  )
}
