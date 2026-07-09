import { ChevronLeft, ChevronRight } from 'lucide-react'

export default function Pagination({ page, totalPages, onChange }) {
  if (totalPages <= 1) return null

  const pages = Array.from({ length: totalPages }, (_, i) => i + 1)
    .filter((p) => p === 1 || p === totalPages || Math.abs(p - page) <= 1)

  return (
    <div className="flex items-center justify-center gap-1.5 py-4">
      <button
        onClick={() => onChange(page - 1)}
        disabled={page <= 1}
        className="btn-icon disabled:opacity-30 disabled:pointer-events-none"
      >
        <ChevronLeft size={16} />
      </button>

      {pages.map((p, idx) => {
        const prev = pages[idx - 1]
        const showEllipsis = prev && p - prev > 1
        return (
          <span key={p} className="flex items-center gap-1.5">
            {showEllipsis && <span style={{ color: 'var(--text-muted)' }}>&hellip;</span>}
            <button
              onClick={() => onChange(p)}
              className="w-8 h-8 rounded-xl text-sm font-bold"
              style={p === page
                ? { background: 'linear-gradient(135deg, #7C3AED, #6d28d9)', color: '#fff' }
                : { color: 'var(--text-muted)' }}
            >
              {p}
            </button>
          </span>
        )
      })}

      <button
        onClick={() => onChange(page + 1)}
        disabled={page >= totalPages}
        className="btn-icon disabled:opacity-30 disabled:pointer-events-none"
      >
        <ChevronRight size={16} />
      </button>
    </div>
  )
}
