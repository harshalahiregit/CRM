import { Check } from 'lucide-react'

/**
 * Step-progress indicator for multi-step forms (meeting 1.1): numbered circles
 * → check marks when complete, connector lines, optional steps labeled.
 *
 *   <StepperNav steps={[{key,label,optional}]} current={key} completed={Set} onStepClick />
 */
export default function StepperNav({ steps, current, completed = new Set(), onStepClick }) {
  const currentIdx = steps.findIndex(s => s.key === current)

  return (
    <div className="flex items-center w-full px-1">
      {steps.map((s, i) => {
        const isDone = completed.has(s.key)
        const isCurrent = s.key === current
        const clickable = onStepClick && (isDone || i <= currentIdx)
        return (
          <div key={s.key} className={`flex items-center ${i < steps.length - 1 ? 'flex-1' : ''}`}>
            <button
              type="button"
              onClick={() => clickable && onStepClick(s.key)}
              className={`flex flex-col items-center gap-1 ${clickable ? 'cursor-pointer' : 'cursor-default'}`}
            >
              <span
                className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-black transition-all"
                style={isDone
                  ? { background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff' }
                  : isCurrent
                    ? { background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', boxShadow: '0 0 0 4px rgba(124,58,237,0.15)' }
                    : { background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
              >
                {isDone ? <Check size={14} /> : i + 1}
              </span>
              <span className="text-[10px] font-bold whitespace-nowrap" style={{ color: isCurrent ? 'var(--accent)' : 'var(--text-muted)' }}>
                {s.label}{s.optional && <span style={{ color: 'var(--text-faint)', fontWeight: 400 }}> (optional)</span>}
              </span>
            </button>
            {i < steps.length - 1 && (
              <div className="flex-1 h-0.5 mx-2 mb-4 rounded-full transition-colors"
                style={{ background: isDone ? '#10b981' : 'var(--border)' }} />
            )}
          </div>
        )
      })}
    </div>
  )
}
