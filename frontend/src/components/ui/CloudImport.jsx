// CloudImport — shows the cloud providers (Google Drive / OneDrive / pCloud) as
// direct buttons and hands the picked files back as File objects via onFiles().
// Drop it next to any local file input; the host module uploads the returned
// files through its own existing path (same as a local pick).
//
//   <CloudImport onFiles={(files) => upload.mutate(files)} />
//
// Google Drive and OneDrive open their own picker; pCloud has no widget, so it
// opens the shared in-app <PcloudPicker> browser. A provider whose client id is
// not set yet appears greyed with a tooltip, so all three are always visible and
// light up once configured.
import { useState, useCallback } from 'react'
import { Loader2 } from 'lucide-react'
import { CLOUD_PROVIDERS } from '@/lib/cloud'
import PcloudPicker from '@/components/ui/PcloudPicker'

export default function CloudImport({
  onFiles,
  variant = 'button', // 'button' (icon + label) | 'icon' (icon only, compact)
  accent = 'var(--color-primary-500, #6366f1)',
  disabled = false,
}) {
  const [busy, setBusy] = useState(null) // provider id currently working
  const [err, setErr] = useState('')
  const [browse, setBrowse] = useState(false) // pCloud browser open

  // onFiles receives the picked File[] plus the provider id ('google' | 'onedrive'
  // | 'pcloud') so a caller can tag where the bytes came from; ignore it if not needed.
  const deliver = useCallback((files, source) => {
    if (files && files.length) onFiles(files, source)
  }, [onFiles])

  const choose = async (provider) => {
    if (!provider.configured || busy) return
    setErr('')
    if (provider.browse) { setBrowse(true); return } // pCloud → built-in browser
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

  const iconOnly = variant === 'icon'

  return (
    <div>
      <div className={iconOnly ? 'flex items-center gap-1.5' : 'flex flex-wrap gap-2'}>
        {CLOUD_PROVIDERS.map((p) => {
          const working = busy === p.id
          const title = p.configured ? `Import from ${p.label}` : `${p.label} — not configured`
          return iconOnly ? (
            <button key={p.id} type="button" disabled={disabled || !p.configured} onClick={() => choose(p)}
              aria-label={title} title={title}
              className="hover:opacity-70 transition-opacity disabled:opacity-30 disabled:cursor-not-allowed p-1">
              {working ? <Loader2 size={16} className="animate-spin" style={{ color: accent }} /> : <p.Icon size={16} />}
            </button>
          ) : (
            <button key={p.id} type="button" disabled={disabled || !p.configured} onClick={() => choose(p)}
              title={title}
              className="flex-1 min-w-[92px] flex items-center justify-center gap-1.5 text-[11px] font-semibold py-2 px-2 rounded-lg transition-colors hover:opacity-80 disabled:cursor-not-allowed"
              style={{ border: '1px solid var(--border)', color: 'var(--text-body)', background: 'var(--bg-input)', opacity: p.configured ? 1 : 0.45 }}>
              {working ? <Loader2 size={13} className="animate-spin" /> : <p.Icon size={14} />}
              <span className="truncate">{p.label}</span>
            </button>
          )
        })}
      </div>

      {err && <p className="text-[10px] mt-1" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {browse && (
        <PcloudPicker accent={accent}
          onClose={() => setBrowse(false)}
          onPicked={(files) => { setBrowse(false); deliver(files, 'pcloud') }} />
      )}
    </div>
  )
}
