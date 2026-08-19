import { useState, useRef } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { X, Upload, FileSpreadsheet, CheckCircle2, AlertTriangle, FileDown } from 'lucide-react'
import { inventoryApi, INV_ACCENT } from '@/services/inventoryApi'

/**
 * Bulk import (blueprint §1 "Import items" / "Import opening stock").
 *
 * Shows the exact columns the importer understands and offers a matching
 * template download, because the commonest reason an import fails is a header
 * the parser never asked for. Results are reported per row: what went in, what
 * was skipped, and why.
 */

const TITLES = {
  'items':         { title: 'Import items', blurb: 'Create or update items in bulk. A row whose Sku Code already exists updates that item instead of duplicating it.' },
  'opening-stock': { title: 'Import opening stock', blurb: 'Set each item’s starting balance at a warehouse. The difference is written to the ledger as a real movement, so day one is auditable.' },
}

export default function ImportModal({ kind, onClose, onDone }) {
  const [file, setFile] = useState(null)
  const [result, setResult] = useState(null)
  const [err, setErr] = useState('')
  const input = useRef(null)

  const { data: columns = {} } = useQuery({
    queryKey: ['inv-import-template', kind],
    queryFn: () => inventoryApi.products.importTemplate(kind),
    enabled: Boolean(kind),
  })

  const run = useMutation({
    mutationFn: () => inventoryApi.products.import(kind, file),
    onSuccess: (r) => { setResult(r); setErr(''); onDone?.() },
    onError: (e) => setErr(e?.message || 'That import failed.'),
  })

  if (!kind) return null
  const meta = TITLES[kind] || { title: 'Import', blurb: '' }

  const close = () => { setFile(null); setResult(null); setErr(''); onClose?.() }

  // A header-only CSV the user can fill in — same columns the parser reads.
  const downloadTemplate = () => {
    const headers = Object.keys(columns)
    const csv = headers.join(',') + '\n'
    const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }))
    const a = document.createElement('a')
    a.href = url; a.download = `${kind}-template.csv`; a.click()
    URL.revokeObjectURL(url)
  }

  const pick = (list) => {
    // Snapshot before the input is reset, or the File reference is gone.
    const files = Array.from(list || [])
    if (files.length) { setFile(files[0]); setResult(null); setErr('') }
  }

  return (
    <div className="fixed inset-0 z-[75] flex items-start justify-center p-4 overflow-y-auto" style={{ background: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-[520px] rounded-2xl mt-[6vh] mb-8 p-5" onClick={e => e.stopPropagation()}
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>

        <div className="flex items-start justify-between gap-3 mb-1">
          <h2 className="font-black text-base flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <FileSpreadsheet size={17} style={{ color: INV_ACCENT }} /> {meta.title}
          </h2>
          <button onClick={close} aria-label="Close" className="hover:opacity-70"><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>{meta.blurb}</p>

        {/* Result */}
        {result ? (
          <div>
            <div className="flex items-center gap-2 p-3 rounded-xl mb-3"
              style={{ background: `color-mix(in srgb, ${INV_ACCENT} 12%, transparent)`, color: INV_ACCENT }}>
              <CheckCircle2 size={16} />
              <span className="text-xs font-bold">
                {kind === 'items'
                  ? `${result.created} created · ${result.updated} updated · ${result.failed} failed`
                  : `${result.applied} applied · ${result.skipped} already matched · ${result.failed} failed`}
              </span>
            </div>

            {result.errors?.length > 0 && (
              <div className="rounded-xl p-3 mb-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', maxHeight: 190, overflowY: 'auto' }}>
                <p className="flex items-center gap-1.5 text-[11px] font-bold mb-1.5" style={{ color: 'var(--color-danger-500)' }}>
                  <AlertTriangle size={12} /> Rows that didn’t import
                </p>
                <ul className="space-y-1">
                  {result.errors.map((e, i) => (
                    <li key={i} className="text-[11px]" style={{ color: 'var(--text-body)' }}>{e}</li>
                  ))}
                </ul>
              </div>
            )}

            <div className="flex justify-end gap-2">
              <button onClick={() => { setResult(null); setFile(null) }} className="px-3 py-2 rounded-xl text-xs font-bold"
                style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Import another</button>
              <button onClick={close} className="px-4 py-2 rounded-xl text-xs font-bold" style={{ background: INV_ACCENT, color: '#fff' }}>Done</button>
            </div>
          </div>
        ) : (
          <>
            {/* Columns the parser understands */}
            <div className="rounded-xl p-3 mb-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <div className="flex items-center gap-2 mb-2">
                <span className="text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Columns</span>
                <button onClick={downloadTemplate} className="ml-auto flex items-center gap-1 text-[11px] font-bold" style={{ color: INV_ACCENT }}>
                  <FileDown size={11} /> Download template
                </button>
              </div>
              <ul className="space-y-0.5" style={{ maxHeight: 150, overflowY: 'auto' }}>
                {Object.entries(columns).map(([key, label]) => (
                  <li key={key} className="text-[11px]" style={{ color: 'var(--text-body)' }}>
                    <span className="font-mono font-bold" style={{ color: 'var(--text-h)' }}>{key}</span>
                    <span style={{ color: 'var(--text-muted)' }}> — {label}</span>
                  </li>
                ))}
              </ul>
            </div>

            {/* Dropzone */}
            <div
              onClick={() => input.current?.click()}
              onDragOver={e => e.preventDefault()}
              onDrop={e => { e.preventDefault(); pick(e.dataTransfer.files) }}
              className="rounded-xl py-6 px-3 text-center cursor-pointer mb-3"
              style={{ border: `1px dashed ${file ? INV_ACCENT : 'var(--border)'}`, background: file ? `color-mix(in srgb, ${INV_ACCENT} 7%, transparent)` : 'transparent' }}>
              <Upload size={18} className="mx-auto mb-1.5" style={{ color: file ? INV_ACCENT : 'var(--text-muted)' }} />
              <p className="text-xs font-semibold" style={{ color: file ? INV_ACCENT : 'var(--text-body)' }}>
                {file ? file.name : 'Drop a .xlsx or .csv here, or click to browse'}
              </p>
              <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>Max 10 MB · first worksheet is read</p>
              <input ref={input} type="file" accept=".xlsx,.csv,.txt" hidden
                onChange={e => { pick(e.target.files); e.target.value = '' }} />
            </div>

            {err && <p className="text-[11px] mb-3" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

            <div className="flex justify-end gap-2">
              <button onClick={close} className="px-3 py-2 rounded-xl text-xs font-bold"
                style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
              <button disabled={!file || run.isPending} onClick={() => run.mutate()}
                className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold disabled:opacity-40"
                style={{ background: INV_ACCENT, color: '#fff' }}>
                <Upload size={13} /> {run.isPending ? 'Importing…' : 'Import'}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}
