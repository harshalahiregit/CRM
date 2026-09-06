/**
 * HR settings — the controls that used to be constants in the code.
 *
 * The form is built from the schema the server sends, not a field list kept
 * here. A settings screen with its own copy of the fields is how a control ends
 * up on screen that nothing reads, or read by something that is not on screen.
 *
 * Every setting defaults to what the system already does, so opening this page
 * and saving changes nothing until somebody edits a value.
 */

import { useState, useEffect, useCallback } from 'react'
import { Settings2, Save, RotateCcw, Info } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'

const inputStyle = {
  padding: '8px 11px', background: 'var(--bg-input)',
  border: '1px solid var(--border)', color: 'var(--text-p)',
}

/** One control, chosen by the type the server declared. */
function Field({ field, value, onChange }) {
  const { key, label, type, hint } = field

  if (type === 'bool') {
    const on = value === true || value === 1 || value === '1'
    return (
      <div className="flex items-start justify-between gap-3 rounded-xl"
        style={{ padding: '10px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <div className="min-w-0">
          <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{label}</p>
          {hint && <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
        </div>
        <button type="button" onClick={() => onChange(key, !on)}
          aria-pressed={on} aria-label={label}
          className="w-11 h-6 rounded-full relative transition-all shrink-0"
          style={{ background: on ? '#10b981' : 'var(--border)' }}>
          <span className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
            style={{ left: on ? '22px' : '2px' }} />
        </button>
      </div>
    )
  }

  const inputType = type === 'time' ? 'time'
    : (type === 'int' || type === 'decimal') ? 'number'
    : type === 'email' ? 'email' : 'text'

  return (
    <label className="flex flex-col gap-1">
      <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <input type={inputType}
        step={type === 'decimal' ? '0.01' : type === 'int' ? '1' : undefined}
        min={(type === 'int' || type === 'decimal') ? '0' : undefined}
        value={value ?? ''}
        onChange={e => onChange(key, e.target.value)}
        className="rounded-lg text-sm w-full" style={inputStyle} />
      {hint && <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{hint}</span>}
    </label>
  )
}

export default function HrSettings() {
  const toast = useToast()

  const [schema,  setSchema]  = useState({})
  const [values,  setValues]  = useState({})
  const [saved,   setSaved]   = useState({})
  const [loading, setLoading] = useState(true)
  const [busy,    setBusy]    = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const d = await hrApi.settings.get()
      setSchema(d?.schema || {})
      setValues(d?.values || {})
      setSaved(d?.values || {})
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not load settings.')
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => { load() }, [load])

  const set = (k, v) => setValues(prev => ({ ...prev, [k]: v }))

  // Only what actually changed. Sending the whole set back would rewrite every
  // row on every save and make "who changed what" harder than it needs to be.
  const dirty = Object.keys(values).filter(k => String(values[k] ?? '') !== String(saved[k] ?? ''))

  const save = async () => {
    if (!dirty.length) return toast.info('Nothing has changed.')

    setBusy(true)
    try {
      const payload = Object.fromEntries(dirty.map(k => [k, values[k]]))
      const d = await hrApi.settings.save(payload)
      setValues(d?.values || values)
      setSaved(d?.values || values)
      toast.success('Settings saved.')
    } catch (e) {
      const errs = e?.response?.data?.errors
      toast.error(errs ? Object.values(errs).flat()[0] : (e?.response?.data?.message || 'That could not be saved.'))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <div className="p-7"><HrLoading /></div>

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <Settings2 size={18} /> HR settings
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Working hours, attendance rules, approval limits and leave defaults.
          </p>
        </div>
        <div className="flex gap-2">
          {!!dirty.length && (
            <button onClick={() => setValues(saved)}
              className="rounded-lg text-xs font-semibold flex items-center gap-1.5"
              style={{ padding: '8px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
              <RotateCcw size={13} /> Discard
            </button>
          )}
          <button onClick={save} disabled={busy || !dirty.length}
            className="rounded-lg text-xs font-bold flex items-center gap-1.5"
            style={{ padding: '8px 14px', background: 'var(--accent)', color: '#fff', opacity: (busy || !dirty.length) ? 0.5 : 1 }}>
            <Save size={13} /> {busy ? 'Saving…' : dirty.length ? `Save ${dirty.length} change${dirty.length === 1 ? '' : 's'}` : 'Saved'}
          </button>
        </div>
      </div>

      <p className="text-[11px] flex items-start gap-1.5" style={{ color: 'var(--text-muted)' }}>
        <Info size={12} style={{ marginTop: 1, flexShrink: 0 }} />
        Everything here starts at what the system already does, so nothing changes until you edit a value.
      </p>

      {Object.entries(schema).map(([section, fields]) => (
        <section key={section} className="rounded-2xl flex flex-col gap-3"
          style={{ padding: 18, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <h2 className="text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--text-h)' }}>{section}</h2>

          {/* Booleans read as a list; the rest grid, so a column of toggles does
              not end up two-across and unreadable. */}
          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))' }}>
            {fields.filter(f => f.type !== 'bool').map(f => (
              <Field key={f.key} field={f} value={values[f.key]} onChange={set} />
            ))}
          </div>

          {fields.some(f => f.type === 'bool') && (
            <div className="flex flex-col gap-2">
              {fields.filter(f => f.type === 'bool').map(f => (
                <Field key={f.key} field={f} value={values[f.key]} onChange={set} />
              ))}
            </div>
          )}
        </section>
      ))}
    </div>
  )
}
