import { RefreshCw, Download } from 'lucide-react'
import api from '@/lib/api'

/**
 * The shared furniture every module's Report section is built from.
 *
 * The brief asked for a Report section in every module, which is exactly the
 * situation where each one quietly grows its own header, its own filter bar and
 * its own slightly different table — and then a change to any of them has to be
 * made five times. One shell, one table, one stat tile.
 *
 * Deliberately unopinionated about CONTENT: each module decides its own filters,
 * figures and groupings, because a sales report and a helpdesk report have
 * almost nothing in common except their shape.
 */
export default function ReportShell({ eyebrow, title, subtitle, onRefresh, onExport, filters }) {
  return (
    <>
      <header className="flex items-end justify-between gap-3 flex-wrap mb-4">
        <div>
          <p className="text-[11px] font-black tracking-widest" style={{ color: '#a78bfa' }}>{eyebrow}</p>
          <h1 className="text-xl font-black mt-0.5" style={{ color: 'var(--text-h)' }}>{title}</h1>
          {subtitle && <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>}
        </div>
        <div className="flex gap-2">
          {onRefresh && (
            <button onClick={onRefresh} className="btn-3d text-sm flex items-center gap-1.5 px-3 py-2">
              <RefreshCw size={14} /> Refresh
            </button>
          )}
          {onExport && (
            <>
              <button onClick={() => onExport('csv')} className="btn-3d text-sm flex items-center gap-1.5 px-3 py-2">
                <Download size={14} /> CSV
              </button>
              <button onClick={() => onExport('xlsx')} className="btn-3d text-sm flex items-center gap-1.5 px-3 py-2">
                <Download size={14} /> Excel
              </button>
            </>
          )}
        </div>
      </header>

      {filters && (
        <div className="card-3d p-3 mb-4 flex flex-wrap gap-3 items-start">
          {filters}
        </div>
      )}
    </>
  )
}

export function Card({ title, icon: Icon, children, style }) {
  return (
    <section className="card-3d p-4" style={style}>
      <h2 className="text-sm font-black flex items-center gap-2 mb-3" style={{ color: 'var(--text-h)' }}>
        {Icon && <Icon size={15} style={{ color: '#a78bfa' }} />} {title}
      </h2>
      {children}
    </section>
  )
}

export const Empty = ({ children }) => (
  <p className="text-[12.5px] m-0" style={{ color: 'var(--text-muted)' }}>{children}</p>
)

export function StatRow({ children, compact }) {
  return (
    <div className="grid gap-3" style={{
      gridTemplateColumns: `repeat(auto-fit, minmax(${compact ? 130 : 150}px, 1fr))`,
    }}>
      {children}
    </div>
  )
}

export function Stat({ label, value, sub, tone }) {
  return (
    <div className="card-3d p-3">
      <div className="text-[10.5px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</div>
      <div className="text-lg font-black mt-0.5" style={{ color: tone || 'var(--text-h)' }}>{value}</div>
      {sub && <div className="text-[10.5px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{sub}</div>}
    </div>
  )
}

export function Table({ head, rows }) {
  return (
    // Wide tables scroll inside their own box rather than pushing the page
    // sideways — a report is mostly tables, and several of them are wide.
    <div style={{ overflowX: 'auto' }}>
      <table className="w-full text-[12.5px]" style={{ borderCollapse: 'collapse' }}>
        <thead>
          <tr className="text-left text-[10.5px] uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
            {head.map((h, i) => <th key={i} className="px-2.5 py-2 font-bold whitespace-nowrap">{h}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
              {r.map((cell, j) => (
                <td key={j} className="px-2.5 py-2 whitespace-nowrap"
                  style={{ color: j === 0 ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: j === 0 ? 700 : 500 }}>
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Fetch a report export and hand it to the browser as a file.
 *
 * Goes through the authenticated client rather than a plain link, because a
 * bare <a href> carries no bearer token and would come back as a 401 page
 * saved to disk under the name of the spreadsheet.
 */
export async function download(url, params, format, filename) {
  const clean = Object.fromEntries(Object.entries(params || {}).filter(([, v]) => v))
  const res = await api.get(url, { params: { ...clean, format }, responseType: 'blob' })
  const href = window.URL.createObjectURL(new Blob([res.data]))
  const link = document.createElement('a')
  link.href = href
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(href)
}
