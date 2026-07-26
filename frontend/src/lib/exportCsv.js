/**
 * Client-side CSV export.
 *
 * Shared because TicketGrid already hand-rolled this inline with a hardcoded
 * column list; a second copy-paste for Tasks would have been the third.
 *
 * columns: [{ key, label, value? }]  — `value(row)` overrides row[key].
 */
export function exportCsv(filename, rows, columns) {
  const cells = columns.map(c => c.label)
  const lines = [cells.map(escapeCell).join(',')]

  for (const row of rows) {
    lines.push(columns.map(c => escapeCell(c.value ? c.value(row) : row[c.key])).join(','))
  }

  // BOM so Excel opens UTF-8 (₹, names with accents) without mangling it.
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename.endsWith('.csv') ? filename : `${filename}.csv`
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

function escapeCell(v) {
  if (v === null || v === undefined) return ''
  const s = String(v)
  // A leading =, +, - or @ makes Excel treat the cell as a formula. Prefix with
  // a quote so an exported task named "=cmd|..." can't execute on open.
  const safe = /^[=+\-@]/.test(s) ? `'${s}` : s
  return /[",\r\n]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe
}

/** Timestamped filename, e.g. tasks-2026-07-16.csv */
export const stampedName = (base) => `${base}-${new Date().toISOString().split('T')[0]}`
