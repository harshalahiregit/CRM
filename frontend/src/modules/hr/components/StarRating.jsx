import { Star } from 'lucide-react'

// Shared 5-star control — used by the interviews queue, the feedback drawer and
// the candidate workspace so the rating always looks and behaves the same.
export default function StarRating({ value, onChange, readOnly = false }) {
  return (
    <div className="flex gap-1">
      {[1, 2, 3, 4, 5].map(n => (
        <button key={n} type="button" disabled={readOnly} onClick={() => onChange?.(n)} style={{ cursor: readOnly ? 'default' : 'pointer' }}>
          <Star size={readOnly ? 13 : 20} style={{ color: n <= value ? '#fbbf24' : 'var(--border)', fill: n <= value ? '#fbbf24' : 'none' }} />
        </button>
      ))}
    </div>
  )
}
