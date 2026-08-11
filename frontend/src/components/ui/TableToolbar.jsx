import { useState } from 'react'
import { Search, X, Download, Copy, Printer, FileSpreadsheet, FileText, Check } from 'lucide-react'

/**
 * Search + export toolbar for a server-paginated listing.
 *
 * Exports are built from the rows the caller hands over, with no new
 * dependency. The project ships no xlsx/papaparse/jspdf and adding one to emit
 * a flat table would be a lot of bundle for something the platform already
 * does:
 *   - CSV  — a Blob, which is all a .csv is
 *   - Excel — an HTML table served as application/vnd.ms-excel. Excel, LibreOffice
 *     and Sheets all open it and keep the column split. It is not a real OOXML
 *     .xlsx; if a true one is ever needed that is when a library earns its place.
 *   - Copy — navigator.clipboard, tab-separated, which is what spreadsheets
 *     expect when you paste
 *   - Print — a detached window holding only the table, so the app chrome and
 *     sidebar do not land on the page
 *
 * columns: [{ key, label, export?(row) }]  — export() overrides the cell value,
 * for when the on-screen cell is a badge or an icon rather than text.
 */
export default function TableToolbar({
  search, setSearch, placeholder = 'Search…',
  columns = [], rows = [], filename = 'export', title = 'Export',
  right = null, exportScopeNote = null,
}) {
  const [copied, setCopied] = useState(false)

  // One matrix, reused by every exporter, so the four outputs can never disagree
  // about what a column contains.
  const matrix = () => {
    const head = columns.map(c => c.label)
    const body = rows.map(r => columns.map(c => {
      const v = c.export ? c.export(r) : r[c.key]
      return v === null || v === undefined ? '' : String(v)
    }))
    return { head, body }
  }

  const download = (blob, ext) => {
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${filename}-${new Date().toISOString().slice(0, 10)}.${ext}`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    // Revoking immediately can cancel the download in some browsers.
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  }

  const csvCell = (s) => {
    // Quote when the value contains a delimiter, a quote or a newline; double up
    // embedded quotes. Skipping this is how an address with a comma silently
    // shifts every later column.
    const needs = /[",\n\r]/.test(s)
    return needs ? `"${s.replace(/"/g, '""')}"` : s
  }

  const exportCsv = () => {
    const { head, body } = matrix()
    const csv = [head, ...body].map(r => r.map(csvCell).join(',')).join('\r\n')
    // BOM so Excel reads UTF-8 rather than mangling non-ASCII names.
    download(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }), 'csv')
  }

  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

  const tableHtml = () => {
    const { head, body } = matrix()
    return `<table border="1"><thead><tr>${head.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead>`
      + `<tbody>${body.map(r => `<tr>${r.map(c => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table>`
  }

  const exportExcel = () => {
    const html = `<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head>`
      + `<body>${tableHtml()}</body></html>`
    download(new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' }), 'xls')
  }

  const copy = async () => {
    const { head, body } = matrix()
    const tsv = [head, ...body].map(r => r.join('\t')).join('\n')
    try {
      await navigator.clipboard.writeText(tsv)
      setCopied(true)
      setTimeout(() => setCopied(false), 1600)
    } catch {
      // clipboard needs a secure context; say so rather than failing silently.
      window.prompt('Copy failed — select and copy manually:', tsv)
    }
  }

  const print = () => {
    const w = window.open('', '_blank', 'width=1000,height=700')
    if (!w) return   // popup blocked
    w.document.write(`<html><head><title>${esc(title)}</title><style>
      body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;padding:24px;color:#111}
      h1{font-size:16px;margin:0 0 4px} p{font-size:11px;color:#666;margin:0 0 16px}
      table{border-collapse:collapse;width:100%;font-size:11px}
      th,td{border:1px solid #ccc;padding:6px 8px;text-align:left}
      th{background:#f4f4f5;font-weight:700}
      @media print{@page{size:landscape;margin:12mm}}
    </style></head><body><h1>${esc(title)}</h1>
    <p>${rows.length} record(s) · ${new Date().toLocaleString()}</p>
    ${tableHtml()}</body></html>`)
    w.document.close()
    w.focus()
    // Let the document lay out before the print dialog measures it.
    setTimeout(() => w.print(), 250)
  }

  const Btn = ({ onClick, icon: Icon, label, tone }) => (
    <button onClick={onClick} title={label}
      className="inline-flex items-center gap-1.5 px-2.5 py-2 rounded-xl text-[11px] font-bold"
      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: tone || 'var(--text-muted)' }}>
      <Icon size={13} /> {label}
    </button>
  )

  return (
    <div className="card-3d" style={{ padding: '12px 14px' }}>
      <div className="flex gap-2.5 flex-wrap items-center">
        <div className="relative flex-1 min-w-[210px]">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input className="input-3d pl-9 text-sm" placeholder={placeholder}
            value={search} onChange={e => setSearch(e.target.value)} />
          {search && (
            <button onClick={() => setSearch('')} className="absolute right-3 top-1/2 -translate-y-1/2"
              style={{ color: 'var(--text-muted)' }} title="Clear"><X size={13} /></button>
          )}
        </div>

        <div className="flex gap-1.5 flex-wrap items-center">
          <Btn onClick={exportExcel} icon={FileSpreadsheet} label="Excel" tone="#10b981" />
          <Btn onClick={exportCsv} icon={FileText} label="CSV" tone="#3b82f6" />
          <Btn onClick={copy} icon={copied ? Check : Copy} label={copied ? 'Copied' : 'Copy'} tone={copied ? '#10b981' : undefined} />
          <Btn onClick={print} icon={Printer} label="Print" />
          {right}
        </div>
      </div>

      {/* Server-paginated lists export the CURRENT page. Saying so beats a user
          discovering it after opening a 25-row file they expected to hold 900. */}
      {exportScopeNote && (
        <p className="text-[10px] mt-2 inline-flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
          <Download size={10} /> {exportScopeNote}
        </p>
      )}
    </div>
  )
}
