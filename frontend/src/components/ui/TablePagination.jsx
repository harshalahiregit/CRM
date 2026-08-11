import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react'

/**
 * Pagination bar for a SERVER-paginated table: page-size selector, a windowed
 * page list, and an honest "showing X–Y of Z".
 *
 * Separate from the existing ui/Pagination, which takes only
 * (page, totalPages, onChange) and has no page-size control. Eight accounts
 * pages depend on that signature, so widening it would mean touching all of
 * them for a feature they don't ask for.
 *
 * meta: { current_page, last_page, per_page, total } — Laravel's paginator
 * payload, passed through unchanged.
 */
const SIZES = [10, 25, 50, 100]

export default function TablePagination({ meta, onPage, onPerPage, sizes = SIZES }) {
  const page = Number(meta?.current_page || 1)
  const last = Number(meta?.last_page || 1)
  const per = Number(meta?.per_page || sizes[0])
  const total = Number(meta?.total || 0)

  // Row range for THIS page. Derived from the server's numbers rather than
  // counting the rendered rows, so a short final page still reads correctly.
  const from = total === 0 ? 0 : (page - 1) * per + 1
  const to = Math.min(page * per, total)

  // First, last, and a ±1 window around the current page — with gaps marked so
  // 1 … 7 8 9 … 42 never looks like consecutive pages.
  const pages = Array.from({ length: last }, (_, i) => i + 1)
    .filter(p => p === 1 || p === last || Math.abs(p - page) <= 1)

  const Nav = ({ onClick, disabled, icon: Icon, label }) => (
    <button onClick={onClick} disabled={disabled} title={label}
      className="w-8 h-8 rounded-xl inline-flex items-center justify-center"
      style={{
        background: 'var(--bg-input)', border: '1px solid var(--border)',
        color: 'var(--text-muted)', opacity: disabled ? 0.35 : 1,
        cursor: disabled ? 'default' : 'pointer',
      }}>
      <Icon size={14} />
    </button>
  )

  return (
    <div className="flex items-center justify-between gap-3 flex-wrap px-1 py-3">
      <div className="flex items-center gap-2">
        <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Rows</span>
        <select
          value={per}
          onChange={e => onPerPage(Number(e.target.value))}
          className="input-3d text-xs"
          style={{ width: 74, padding: '5px 8px' }}
        >
          {sizes.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          {total === 0 ? 'No records' : `Showing ${from}–${to} of ${total}`}
        </span>
      </div>

      {last > 1 && (
        <div className="flex items-center gap-1.5">
          <Nav onClick={() => onPage(1)} disabled={page <= 1} icon={ChevronsLeft} label="First" />
          <Nav onClick={() => onPage(page - 1)} disabled={page <= 1} icon={ChevronLeft} label="Previous" />

          {pages.map((p, i) => {
            const prev = pages[i - 1]
            return (
              <span key={p} className="flex items-center gap-1.5">
                {prev && p - prev > 1 && <span style={{ color: 'var(--text-muted)' }}>…</span>}
                <button onClick={() => onPage(p)}
                  className="w-8 h-8 rounded-xl text-xs font-bold"
                  style={p === page
                    ? { background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', border: 'none' }
                    : { background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                  {p}
                </button>
              </span>
            )
          })}

          <Nav onClick={() => onPage(page + 1)} disabled={page >= last} icon={ChevronRight} label="Next" />
          <Nav onClick={() => onPage(last)} disabled={page >= last} icon={ChevronsRight} label="Last" />
        </div>
      )}
    </div>
  )
}
