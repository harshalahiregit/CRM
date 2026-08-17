// CloudImport — one control that lets a user pull files from Google Drive,
// OneDrive or pCloud and hands them back as File objects via onFiles(). Drop it
// next to any local file input; the host module uploads the returned files
// through its own existing path (same as a local pick).
//
//   <CloudImport onFiles={(files) => upload.mutate(files)} />
//
// Native-picker providers (Google, OneDrive) open their own widget. pCloud has no
// widget, so this component renders a small built-in browser over the adapter's
// authorize/listFolder/downloadFile calls. Providers whose client ids aren't set
// yet appear disabled with a "Not configured" hint, so the menu is present
// everywhere immediately and lights up once the ids are added.
import { useState, useRef, useEffect, useCallback } from 'react'
import { Cloud, ChevronDown, Loader2 } from 'lucide-react'
import { CLOUD_PROVIDERS } from '@/lib/cloud'
import PcloudPicker from '@/components/ui/PcloudPicker'

export default function CloudImport({
  onFiles,
  variant = 'button', // 'button' | 'icon'
  label = 'From cloud',
  accent = 'var(--color-primary-500, #6366f1)',
  disabled = false,
}) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(null) // provider id currently working
  const [err, setErr] = useState('')
  const [browse, setBrowse] = useState(false) // pCloud browser open
  const wrap = useRef(null)

  useEffect(() => {
    if (!open) return
    const onDoc = (e) => { if (wrap.current && !wrap.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [open])

  // onFiles receives the picked File[] plus the provider id ('google' | 'onedrive'
  // | 'pcloud'), so a caller can tag where the bytes came from. Callers that don't
  // care can simply ignore the second argument.
  const deliver = useCallback((files, source) => {
    if (files && files.length) onFiles(files, source)
  }, [onFiles])

  const choose = async (provider) => {
    if (!provider.configured) return
    setErr('')
    setOpen(false)
    if (provider.browse) { setBrowse(true); return } // pCloud → built-in browser
    // Native picker (Google Drive / OneDrive)
    try {
      setBusy(provider.id)
      const files = await provider.pick()
      deliver(files, provider.id)
    } catch (e) {
      setErr(e?.message || `Could not open ${provider.label}.`)
    } finally {
      setBusy(null)
    }
  }

  return (
    <div ref={wrap} className="relative inline-block">
      {variant === 'icon' ? (
        <button type="button" disabled={disabled} onClick={() => setOpen(o => !o)}
          aria-label="Import from cloud" title="Import from cloud storage"
          className="hover:opacity-70 transition-opacity disabled:opacity-40">
          {busy ? <Loader2 size={14} className="animate-spin" style={{ color: accent }} /> : <Cloud size={14} style={{ color: 'var(--text-muted)' }} />}
        </button>
      ) : (
        <button type="button" disabled={disabled} onClick={() => setOpen(o => !o)}
          className="w-full flex items-center justify-center gap-2 text-[11px] font-semibold py-2 rounded-lg transition-colors hover:opacity-80 disabled:opacity-40"
          style={{ border: '1px solid var(--border)', color: 'var(--text-body)', background: 'var(--bg-input)' }}>
          {busy ? <Loader2 size={13} className="animate-spin" /> : <Cloud size={13} />}
          {busy ? 'Importing…' : label}
          <ChevronDown size={12} style={{ opacity: 0.6 }} />
        </button>
      )}

      {open && (
        <div className="absolute z-50 mt-1 right-0 min-w-[190px] rounded-xl py-1 shadow-lg"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          {CLOUD_PROVIDERS.map((p) => (
            <button key={p.id} type="button" onClick={() => choose(p)} disabled={!p.configured}
              className="w-full flex items-center gap-2.5 px-3 py-2 text-left transition-colors hover:bg-[var(--bg-input)] disabled:cursor-not-allowed"
              style={{ opacity: p.configured ? 1 : 0.5 }}>
              <p.Icon size={16} />
              <span className="flex-1 min-w-0">
                <span className="block text-[12px] font-semibold truncate" style={{ color: 'var(--text-h)' }}>{p.label}</span>
                {!p.configured && <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>Not configured</span>}
              </span>
            </button>
          ))}
        </div>
      )}

      {err && <p className="text-[10px] mt-1" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {browse && (
        <PcloudPicker accent={accent}
          onClose={() => setBrowse(false)}
          onPicked={(files) => { setBrowse(false); deliver(files, 'pcloud') }} />
      )}
    </div>
  )
}
