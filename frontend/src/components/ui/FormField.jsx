export default function FormField({ label, hint, error, required, children }) {
  return (
    <div>
      {label && (
        <label className="label">
          {label}
          {required && ' *'}
          {hint && (
            <span style={{ color: 'var(--text-muted)', fontWeight: 400, textTransform: 'none', letterSpacing: 'normal' }}>
              {' '}({hint})
            </span>
          )}
        </label>
      )}
      {children}
      {error && (
        <p className="text-xs mt-1.5 font-medium" style={{ color: '#f87171' }}>{error}</p>
      )}
    </div>
  )
}

export function Input(props) {
  return <input className="input-3d text-sm w-full" {...props} />
}

export function Textarea(props) {
  return <textarea className="input-3d text-sm w-full resize-none" {...props} />
}

export function Select({ children, ...props }) {
  return <select className="input-3d text-sm w-full" {...props}>{children}</select>
}
