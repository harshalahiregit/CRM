import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { Save, RotateCcw, Lock, Unlock, Hash, AlertTriangle } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'

/**
 * Document Numbering settings — the UI for the numbering engine. Every document
 * type, its format, reset policy and a live preview. The catalogue, reset rules
 * and placeholder list all come FROM THE SERVER (registry-driven), so a new
 * document type or placeholder appears here with no frontend change.
 */
const EDITABLE = ['format', 'prefix', 'suffix', 'minimum_digits', 'padding', 'starting_number', 'reset_rule', 'enabled', 'locked', 'manual_override', 'decrement_on_delete']

const pick = (cfg) => EDITABLE.reduce((a, k) => ({ ...a, [k]: cfg?.[k] ?? '' }), {})

export default function DocumentNumberingSettings() {
  const toast = useToast()
  const [catalogue, setCatalogue] = useState(null)   // { types, grouped, reset_rules, placeholders }
  const [selected, setSelected] = useState(null)     // document_type key
  const [server, setServer] = useState(null)         // last saved config from the API
  const [form, setForm] = useState(null)             // editable copy
  const [preview, setPreview] = useState('')
  const [errors, setErrors] = useState({})
  const [saving, setSaving] = useState(false)

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  // Dirty = editable fields differ from what the server last returned.
  const dirty = useMemo(() => {
    if (!form || !server) return false
    return EDITABLE.some(k => String(form[k] ?? '') !== String(server[k] ?? ''))
  }, [form, server])

  // Warn before losing edits on tab close / reload.
  const dirtyRef = useRef(dirty)
  dirtyRef.current = dirty
  useEffect(() => {
    const onBeforeUnload = (e) => {
      if (!dirtyRef.current) return
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [])

  useEffect(() => {
    settingsApi.numbering.list()
      .then(d => {
        setCatalogue(d)
        const first = d?.types?.[0]
        if (first) { setSelected(first.key); setServer(first.config); setForm(pick(first.config)); setPreview(first.config?.preview || '') }
      })
      .catch(e => toast.error(e.message))
  }, [])

  const selectType = (key) => {
    if (dirty && !window.confirm('You have unsaved numbering changes. Discard them?')) return
    setSelected(key)
    setErrors({})
    settingsApi.numbering.get(key)
      .then(cfg => { setServer(cfg); setForm(pick(cfg)); setPreview(cfg?.preview || '') })
      .catch(e => toast.error(e.message))
  }

  // Live preview of the UNSAVED draft — never consumes a number.
  const refreshPreview = useCallback((draft) => {
    if (!selected || !draft) return
    settingsApi.numbering.preview(selected, { config: draft })
      .then(r => { setPreview(r.preview || ''); setErrors(r.errors || {}) })
      .catch(e => { setPreview(''); setErrors(e.fieldErrors || { format: e.message }) })
  }, [selected])

  useEffect(() => {
    if (!form) return
    const t = setTimeout(() => refreshPreview(form), 350)   // debounce keystrokes
    return () => clearTimeout(t)
  }, [form, refreshPreview])

  const save = async () => {
    setSaving(true)
    try {
      const cfg = await settingsApi.numbering.update(selected, form)
      setServer(cfg); setForm(pick(cfg)); setPreview(cfg.preview || ''); setErrors({})
      toast.success('Numbering settings saved')
    } catch (e) {
      setErrors(e.fieldErrors || {})
      toast.error(e.message)
    } finally { setSaving(false) }
  }

  const reset = async () => {
    if (!window.confirm('Start a new numbering period? Existing numbers are kept; the counter restarts at the starting number.')) return
    try {
      const cfg = await settingsApi.numbering.reset(selected)
      setServer(cfg); setForm(pick(cfg)); setPreview(cfg.preview || '')
      toast.success('Numbering sequence reset')
    } catch (e) { toast.error(e.message) }
  }

  if (!catalogue || !form) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  const locked = !!server?.locked
  const err = (k) => errors?.[k]

  return (
    <div className="space-y-4">
      {/* Document type selector */}
      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Document Numbering</h2>
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
          The single source of truth for document numbers. A type keeps its module's existing numbering until you enable it here.
        </p>
        <label className="label">Document Type</label>
        <select className="input-3d text-sm" value={selected || ''} onChange={e => selectType(e.target.value)}>
          {Object.entries(catalogue.grouped || {}).map(([module, types]) => (
            <optgroup key={module} label={module}>
              {types.map(t => <option key={t.key} value={t.key}>{t.label}</option>)}
            </optgroup>
          ))}
        </select>
        {dirty && (
          <div className="flex items-center gap-2 mt-3 text-xs font-bold" style={{ color: '#f59e0b' }}>
            <AlertTriangle size={13} /> Unsaved changes
          </div>
        )}
      </div>

      {/* Format editor */}
      <div className="card-3d">
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Format</h2>
          <span className="flex items-center gap-1.5 text-xs font-bold" style={{ color: locked ? '#ef4444' : '#10b981' }}>
            {locked ? <><Lock size={13} /> Locked</> : <><Unlock size={13} /> Unlocked</>}
          </span>
        </div>

        <div className="grid md:grid-cols-2 gap-4">
          <div className="md:col-span-2">
            <label className="label">Format</label>
            <input className="input-3d text-sm" value={form.format ?? ''} onChange={e => sf('format', e.target.value)}
              style={err('format') ? { borderColor: '#ef4444' } : undefined} placeholder="{PREFIX}-{YYYY}-{NEXT}" />
            {err('format') && <div className="text-[11px] mt-1 font-semibold" style={{ color: '#ef4444' }}>{err('format')}</div>}
            <div className="flex flex-wrap gap-1.5 mt-2">
              {(catalogue.placeholders || []).map(p => (
                <button key={p.token} type="button" title={p.description}
                  onClick={() => sf('format', `${form.format ?? ''}${p.token}`)}
                  className="text-[10px] font-bold px-1.5 py-0.5 rounded"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                  {p.token}
                </button>
              ))}
            </div>
          </div>

          <div><label className="label">Prefix</label>
            <input className="input-3d text-sm" value={form.prefix ?? ''} onChange={e => sf('prefix', e.target.value)}
              style={err('prefix') ? { borderColor: '#ef4444' } : undefined} />
            {err('prefix') && <div className="text-[11px] mt-1 font-semibold" style={{ color: '#ef4444' }}>{err('prefix')}</div>}</div>

          <div><label className="label">Suffix</label>
            <input className="input-3d text-sm" value={form.suffix ?? ''} onChange={e => sf('suffix', e.target.value)}
              style={err('suffix') ? { borderColor: '#ef4444' } : undefined} />
            {err('suffix') && <div className="text-[11px] mt-1 font-semibold" style={{ color: '#ef4444' }}>{err('suffix')}</div>}</div>

          <div><label className="label">Minimum Digits (padding width)</label>
            <input type="number" min="1" max="12" className="input-3d text-sm" value={form.minimum_digits ?? 4}
              onChange={e => sf('minimum_digits', e.target.value === '' ? '' : Number(e.target.value))}
              style={err('minimum_digits') ? { borderColor: '#ef4444' } : undefined} />
            {err('minimum_digits') && <div className="text-[11px] mt-1 font-semibold" style={{ color: '#ef4444' }}>{err('minimum_digits')}</div>}</div>

          <div><label className="label">Padding Character</label>
            <input maxLength={1} className="input-3d text-sm" value={form.padding ?? '0'} onChange={e => sf('padding', e.target.value)} /></div>

          <div><label className="label">Reset Rule</label>
            <select className="input-3d text-sm" value={form.reset_rule || 'never'} onChange={e => sf('reset_rule', e.target.value)}>
              {Object.entries(catalogue.reset_rules || {}).map(([v, label]) => <option key={v} value={v}>{label}</option>)}
            </select></div>

          <div><label className="label">Starting Number</label>
            <input type="number" min="1" className="input-3d text-sm" value={form.starting_number ?? 1}
              onChange={e => sf('starting_number', e.target.value === '' ? '' : Number(e.target.value))}
              style={err('starting_number') ? { borderColor: '#ef4444' } : undefined} />
            {err('starting_number') && <div className="text-[11px] mt-1 font-semibold" style={{ color: '#ef4444' }}>{err('starting_number')}</div>}</div>
        </div>

        {/* Live preview + derived runtime state */}
        <div className="mt-5 p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <div className="text-[11px] font-bold uppercase mb-2" style={{ color: 'var(--text-muted)' }}>Next number (preview — does not consume)</div>
          <div className="flex items-center gap-3 flex-wrap">
            <span className="text-lg font-black flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
              <Hash size={16} style={{ color: '#a78bfa' }} />{preview || '—'}
            </span>
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Current: <strong style={{ color: 'var(--text-h)' }}>{server?.current_number ?? 0}</strong>
              {' · '}Period: <strong style={{ color: 'var(--text-h)' }}>{server?.period_key ?? '—'}</strong>
            </span>
          </div>
        </div>
      </div>

      {/* Behaviour */}
      <div className="card-3d">
        <h2 className="font-bold text-base mb-4" style={{ color: 'var(--text-h)' }}>Behaviour</h2>
        <div className="grid md:grid-cols-2 gap-3">
          {[
            ['enabled', 'Enabled — this engine numbers the document type'],
            ['locked', 'Locked — prevent further changes'],
            ['manual_override', 'Allow manual override of the number'],
            ['decrement_on_delete', 'Decrement on delete (opt-in; only releases the newest number)'],
          ].map(([k, label]) => (
            <label key={k} className="flex items-center gap-2 text-sm font-semibold cursor-pointer" style={{ color: 'var(--text-body)' }}>
              <input type="checkbox" checked={!!form[k]} onChange={e => sf(k, e.target.checked)} /> {label}
            </label>
          ))}
        </div>
        <p className="text-[11px] mt-3" style={{ color: 'var(--text-muted)' }}>
          Numbers are never reused and gaps are permitted — a cancelled or rolled-back document leaves its number burnt, which keeps the sequence audit-safe.
        </p>
      </div>

      <div className="flex justify-between items-center gap-3">
        <button onClick={reset} className="flex items-center gap-2 text-sm font-bold px-4 py-2 rounded-xl"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          <RotateCcw size={14} /> Reset sequence
        </button>
        <button onClick={save} disabled={saving || Object.keys(errors).length > 0}
          className="btn-3d flex items-center gap-2 disabled:opacity-60">
          <Save size={14} /> {saving ? 'Saving…' : 'Save Settings'}
        </button>
      </div>
    </div>
  )
}
