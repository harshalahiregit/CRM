// PcloudPicker — a small built-in file browser for pCloud, which (unlike Google
// Drive and OneDrive) ships no drop-in picker widget. It signs in, lists folders,
// lets the user multi-select files, downloads them and hands back File objects via
// onPicked(File[]) — the same contract the native pickers use, so any caller can
// treat all three providers identically. Shared by <CloudImport> and the vendor
// Attachments panel.
import { useState, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { Folder, FileText, Check, X, Loader2, ArrowLeft, ChevronDown } from 'lucide-react'
import pcloud from '@/lib/cloud/pcloud'
import { PcloudIcon } from '@/lib/cloud/icons'

function fmtBytes(n) {
  if (!n) return ''
  const u = ['B', 'KB', 'MB', 'GB']
  let i = 0, v = n
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return `${v < 10 && i > 0 ? v.toFixed(1) : Math.round(v)} ${u[i]}`
}

export default function PcloudPicker({ accent = '#7C3AED', onClose, onPicked }) {
  const [session, setSession] = useState(null)   // { token, host }
  const [stack, setStack] = useState([{ id: 0, name: 'pCloud' }]) // breadcrumb
  const [items, setItems] = useState([])
  const [selected, setSelected] = useState({})    // fileId -> item
  const [loading, setLoading] = useState(true)
  const [downloading, setDownloading] = useState(false)
  const [err, setErr] = useState('')

  const here = stack[stack.length - 1]

  // Authorize once on mount.
  useEffect(() => {
    let alive = true
    pcloud.authorize()
      .then((s) => { if (alive) setSession(s) })
      .catch((e) => { if (alive) { setErr(e?.message || 'Sign-in failed.'); setLoading(false) } })
    return () => { alive = false }
  }, [])

  // (Re)load whenever the session or current folder changes.
  useEffect(() => {
    if (!session) return
    let alive = true
    setLoading(true); setErr('')
    pcloud.listFolder(session, here.id)
      .then((list) => { if (alive) setItems(list) })
      .catch((e) => { if (alive) setErr(e?.message || 'Could not open that folder.') })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [session, here.id])

  const enter = (folder) => setStack((s) => [...s, { id: folder.id, name: folder.name }])
  const up = () => setStack((s) => (s.length > 1 ? s.slice(0, -1) : s))
  const toggle = (file) => setSelected((sel) => {
    const next = { ...sel }
    if (next[file.id]) delete next[file.id]; else next[file.id] = file
    return next
  })

  const attach = async () => {
    const picks = Object.values(selected)
    if (!picks.length || !session) return
    setDownloading(true); setErr('')
    try {
      const files = []
      for (const f of picks) files.push(await pcloud.downloadFile(session, f))
      onPicked(files)
    } catch (e) {
      setErr(e?.message || 'Could not download the selected files.')
      setDownloading(false)
    }
  }

  const count = Object.keys(selected).length

  return createPortal(
    <div role="dialog" aria-modal="true" onClick={onClose}
      style={{ position: 'fixed', inset: 0, zIndex: 9999, background: 'rgba(0,0,0,0.6)', backdropFilter: 'blur(4px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}>
      <div onClick={(e) => e.stopPropagation()}
        className="w-full max-w-md rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '80vh' }}>
        {/* Header + breadcrumb */}
        <div className="flex items-center gap-2 px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <PcloudIcon size={16} />
          <div className="flex-1 min-w-0 flex items-center gap-1 text-[12px] font-semibold" style={{ color: 'var(--text-h)' }}>
            {stack.length > 1 && (
              <button onClick={up} aria-label="Back" className="hover:opacity-60 mr-0.5"><ArrowLeft size={14} /></button>
            )}
            <span className="truncate">{here.name}</span>
          </div>
          <button onClick={onClose} aria-label="Close" className="hover:opacity-60"><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        {/* Listing */}
        <div className="flex-1 overflow-y-auto px-2 py-2">
          {loading ? (
            <div className="flex items-center justify-center gap-2 py-10 text-[12px]" style={{ color: 'var(--text-muted)' }}>
              <Loader2 size={15} className="animate-spin" /> {session ? 'Loading…' : 'Waiting for sign-in…'}
            </div>
          ) : items.length === 0 ? (
            <p className="text-center text-[12px] py-10" style={{ color: 'var(--text-muted)' }}>This folder is empty.</p>
          ) : (
            <ul className="space-y-0.5">
              {items.map((it) => (
                <li key={`${it.isFolder ? 'd' : 'f'}-${it.id}`}>
                  {it.isFolder ? (
                    <button onClick={() => enter(it)} className="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-left hover:bg-[var(--bg-input)]">
                      <Folder size={15} style={{ color: accent }} />
                      <span className="flex-1 text-[12px] font-medium truncate" style={{ color: 'var(--text-h)' }}>{it.name}</span>
                      <ChevronDown size={13} style={{ transform: 'rotate(-90deg)', color: 'var(--text-muted)' }} />
                    </button>
                  ) : (
                    <button onClick={() => toggle(it)} className="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-left hover:bg-[var(--bg-input)]">
                      <span className="flex items-center justify-center rounded" style={{ width: 16, height: 16, border: `1.5px solid ${selected[it.id] ? accent : 'var(--border)'}`, background: selected[it.id] ? accent : 'transparent' }}>
                        {selected[it.id] && <Check size={11} color="#fff" />}
                      </span>
                      <FileText size={15} style={{ color: 'var(--text-muted)' }} />
                      <span className="flex-1 min-w-0">
                        <span className="block text-[12px] font-medium truncate" style={{ color: 'var(--text-h)' }}>{it.name}</span>
                        {it.size > 0 && <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{fmtBytes(it.size)}</span>}
                      </span>
                    </button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>

        {/* Footer */}
        <div className="px-4 py-3 flex items-center gap-2" style={{ borderTop: '1px solid var(--border)' }}>
          {err && <p className="flex-1 text-[10.5px] truncate" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
          {!err && <span className="flex-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>{count ? `${count} selected` : 'Select files to attach'}</span>}
          <button onClick={attach} disabled={!count || downloading}
            className="flex items-center gap-1.5 text-[11px] font-bold px-3 py-1.5 rounded-lg disabled:opacity-40"
            style={{ background: accent, color: '#fff' }}>
            {downloading ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
            {downloading ? 'Attaching…' : `Attach${count ? ` ${count}` : ''}`}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
