import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { Save, RotateCcw, Search, Mail, AlertTriangle, Eye, Code, Check } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'
import RichTextEditor from '@/components/ui/RichTextEditor'

/**
 * Email Templates — the content side of outgoing email (SMTP delivery stays on the
 * Email/SMTP page). Categories, templates and the merge-field browser all come
 * FROM THE SERVER, so a new template or merge field appears here with no frontend
 * change. Preview renders with sample data and never sends anything.
 */
const EDITABLE = ['name', 'subject', 'body_html', 'body_text', 'description', 'enabled']
const pick = (t) => EDITABLE.reduce((a, k) => ({ ...a, [k]: t?.[k] ?? '' }), {})

export default function EmailTemplatesSettings() {
  const toast = useToast()
  const [data, setData] = useState(null)        // { templates, categories, merge_fields }
  const [selected, setSelected] = useState(null)
  const [server, setServer] = useState(null)
  const [form, setForm] = useState(null)
  const [preview, setPreview] = useState(null)  // { subject, html, text, validation }
  const [errors, setErrors] = useState({})
  const [saving, setSaving] = useState(false)
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('all')
  const [tab, setTab] = useState('html')        // html | text | preview

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const dirty = useMemo(() => {
    if (!form || !server) return false
    return EDITABLE.some(k => String(form[k] ?? '') !== String(server[k] ?? ''))
  }, [form, server])

  // Warn before losing edits on tab close / reload.
  const dirtyRef = useRef(dirty)
  dirtyRef.current = dirty
  useEffect(() => {
    const onBeforeUnload = (e) => { if (!dirtyRef.current) return; e.preventDefault(); e.returnValue = '' }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [])

  const load = useCallback((keepSelection) => {
    settingsApi.emailTemplates.list()
      .then(d => {
        setData(d)
        const first = keepSelection
          ? d.templates.find(t => t.key === keepSelection)
          : d.templates[0]
        if (first) { setSelected(first.key); setServer(first); setForm(pick(first)) }
      })
      .catch(e => toast.error(e.message))
  }, [toast])

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const selectTemplate = (t) => {
    if (t.key === selected) return   // re-clicking the open template is a no-op
    if (dirty && !window.confirm('You have unsaved template changes. Discard them?')) return
    setSelected(t.key); setServer(t); setForm(pick(t)); setErrors({}); setPreview(null)
  }

  // Debounced live preview of the UNSAVED draft — never sends.
  // A generation counter discards late responses: without it, the reply for a
  // previously selected template can overwrite the current one's preview/errors.
  const previewGen = useRef(0)
  useEffect(() => {
    if (!form || !selected) return
    const id = setTimeout(() => {
      const gen = ++previewGen.current
      settingsApi.emailTemplates.preview(selected, form)
        .then(p => {
          if (gen !== previewGen.current) return
          setPreview(p)
          setErrors({ ...(p?.validation?.errors || {}), ...(p?.validation?.warnings || {}) })
        })
        .catch(() => {})
    }, 400)
    return () => clearTimeout(id)
  }, [form, selected])

  const save = async () => {
    setSaving(true)
    try {
      const t = await settingsApi.emailTemplates.update(selected, form)
      setServer(t); setForm(pick(t)); setErrors({})
      setData(d => ({ ...d, templates: d.templates.map(x => x.key === t.key ? t : x) }))
      toast.success('Template saved')
    } catch (e) {
      setErrors(e.fieldErrors || {})
      toast.error(e.message)
    } finally { setSaving(false) }
  }

  const restore = async () => {
    if (!window.confirm('Restore the shipped default? Your customisations for this template will be discarded.')) return
    try {
      const t = await settingsApi.emailTemplates.restore(selected)
      setServer(t); setForm(pick(t)); setErrors({})
      setData(d => ({ ...d, templates: d.templates.map(x => x.key === t.key ? t : x) }))
      toast.success('Default template restored')
    } catch (e) { toast.error(e.message) }
  }

  const insert = (token) => sf('subject', `${form.subject ?? ''}${token}`)

  const filtered = useMemo(() => {
    if (!data) return []
    const q = search.trim().toLowerCase()
    return data.templates.filter(t =>
      (category === 'all' || t.category === category) &&
      (!q || `${t.key} ${t.name} ${t.subject} ${t.description}`.toLowerCase().includes(q)))
  }, [data, search, category])

  if (!data || !form) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  const err = (k) => errors?.[k]

  return (
    <div className="space-y-4">
      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Email Templates</h2>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Content for every outgoing email. Editing creates a workspace copy — "Restore default" brings the shipped version back.
        </p>
      </div>

      <div className="flex flex-col lg:flex-row gap-4 items-start">
        {/* Template list */}
        <div className="card-3d w-full lg:w-72 flex-shrink-0" style={{ padding: 12 }}>
          <div className="relative mb-2">
            <Search size={13} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
            <input className="input-3d text-sm" style={{ paddingLeft: 30 }} placeholder="Search templates…"
              value={search} onChange={e => setSearch(e.target.value)} />
          </div>
          <select className="input-3d text-sm mb-2" value={category} onChange={e => setCategory(e.target.value)}>
            <option value="all">All categories</option>
            {Object.entries(data.categories).map(([v, label]) => <option key={v} value={v}>{label}</option>)}
          </select>

          <div style={{ maxHeight: '52vh', overflowY: 'auto' }}>
            {filtered.length === 0 && <p className="text-xs p-2" style={{ color: 'var(--text-muted)' }}>No templates match.</p>}
            {filtered.map(t => (
              <button key={t.key} onClick={() => selectTemplate(t)}
                className="w-full text-left px-2.5 py-2 rounded-lg mb-0.5"
                style={selected === t.key
                  ? { background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff' }
                  : { color: 'var(--text-body)' }}>
                <div className="text-[12.5px] font-semibold flex items-center gap-1.5">
                  {t.name}
                  {t.customised && <span title="Customised" style={{ width: 6, height: 6, borderRadius: '50%', background: selected === t.key ? '#fff' : '#f59e0b', display: 'inline-block' }} />}
                </div>
                <div className="text-[10.5px] opacity-70">{data.categories[t.category] || t.category}</div>
              </button>
            ))}
          </div>
        </div>

        {/* Editor */}
        <div className="flex-1 min-w-0 w-full space-y-4">
          <div className="card-3d">
            <div className="flex items-start justify-between gap-3 mb-4 flex-wrap">
              <div>
                <h3 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
                  <Mail size={14} style={{ color: '#a78bfa' }} /> {server?.name}
                </h3>
                <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  <code>{server?.key}</code>{server?.description ? ` · ${server.description}` : ''}
                </p>
              </div>
              <div className="flex items-center gap-2">
                {server?.customised && <span className="text-[10px] font-bold px-2 py-0.5 rounded" style={{ background: 'rgba(245,158,11,0.15)', color: '#f59e0b' }}>Customised v{server.version}</span>}
                <label className="flex items-center gap-1.5 text-xs font-bold cursor-pointer" style={{ color: form.enabled ? '#10b981' : 'var(--text-muted)' }}>
                  <input type="checkbox" checked={!!form.enabled} onChange={e => sf('enabled', e.target.checked)} />
                  {form.enabled ? 'Enabled' : 'Disabled'}
                </label>
              </div>
            </div>

            <div className="grid md:grid-cols-2 gap-4 mb-4">
              <div><label className="label">Template Name</label>
                <input className="input-3d text-sm" value={form.name ?? ''} onChange={e => sf('name', e.target.value)}
                  style={err('name') ? { borderColor: '#ef4444' } : undefined} /></div>
              <div><label className="label">Description</label>
                <input className="input-3d text-sm" value={form.description ?? ''} onChange={e => sf('description', e.target.value)} /></div>
            </div>

            <div className="mb-4">
              <label className="label">Subject</label>
              <input className="input-3d text-sm" value={form.subject ?? ''} onChange={e => sf('subject', e.target.value)}
                style={err('subject') ? { borderColor: '#ef4444' } : undefined} />
              {err('subject') && <div className="text-[11px] mt-1 font-semibold" style={{ color: '#ef4444' }}>{err('subject')}</div>}
            </div>

            {/* Body tabs */}
            <div className="flex items-center gap-1 mb-2">
              {[['html', 'HTML body', Code], ['text', 'Plain text', Code], ['preview', 'Preview', Eye]].map(([k, label, Icon]) => (
                <button key={k} onClick={() => setTab(k)} className="text-[11px] font-bold px-2.5 py-1 rounded-lg flex items-center gap-1"
                  style={tab === k
                    ? { background: 'rgba(124,58,237,0.14)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.35)' }
                    : { color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                  <Icon size={11} /> {label}
                </button>
              ))}
            </div>

            {tab === 'html' && (
              <RichTextEditor value={form.body_html ?? ''} onChange={v => sf('body_html', v)} minHeight={240} />
            )}
            {tab === 'text' && (
              <textarea rows={12} className="input-3d text-xs font-mono resize-none"
                placeholder="Leave blank to generate the plain-text part from the HTML body."
                value={form.body_text ?? ''} onChange={e => sf('body_text', e.target.value)} />
            )}
            {tab === 'preview' && (
              <div>
                <div className="text-[11px] mb-1.5" style={{ color: 'var(--text-muted)' }}>
                  Subject: <strong style={{ color: 'var(--text-h)' }}>{preview?.subject || '—'}</strong>
                </div>
                <div className="rounded-xl overflow-auto" style={{ border: '1px solid var(--border)', background: '#fff', maxHeight: '46vh' }}>
                  {/* sandbox="" is load-bearing: preview renders the UNSAVED draft,
                      which has not been through the server sanitiser yet. Never
                      add allow-scripts here. */}
                  <iframe title="Email preview" sandbox="" srcDoc={preview?.html || ''}
                    style={{ width: '100%', height: '46vh', border: 'none' }} />
                </div>
                <p className="text-[10.5px] mt-1.5" style={{ color: 'var(--text-muted)' }}>Rendered with sample data. No email is sent.</p>
              </div>
            )}

            {err('body_html') && <div className="text-[11px] mt-1 font-semibold" style={{ color: '#ef4444' }}>{err('body_html')}</div>}
            {err('merge_fields') && (
              <div className="flex items-start gap-2 mt-2 text-[11px] font-semibold" style={{ color: '#f59e0b' }}>
                <AlertTriangle size={12} style={{ marginTop: 1, flexShrink: 0 }} /> {err('merge_fields')}
              </div>
            )}
            {dirty && (
              <div className="flex items-center gap-2 mt-3 text-xs font-bold" style={{ color: '#f59e0b' }}>
                <AlertTriangle size={13} /> Unsaved changes
              </div>
            )}
          </div>

          {/* Merge field browser */}
          <div className="card-3d">
            <h3 className="font-bold text-sm mb-1" style={{ color: 'var(--text-h)' }}>Merge Fields</h3>
            <p className="text-[11px] mb-3" style={{ color: 'var(--text-muted)' }}>
              Click to append to the subject, or type it into the body. Unknown fields render as empty text.
            </p>
            <div className="space-y-2.5" style={{ maxHeight: '30vh', overflowY: 'auto' }}>
              {Object.entries(data.merge_fields).map(([group, fields]) => (
                <div key={group}>
                  <div className="text-[10px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>{group}</div>
                  <div className="flex flex-wrap gap-1.5">
                    {fields.map(f => (
                      <button key={f.token} type="button" title={f.description} onClick={() => insert(f.token)}
                        className="text-[10px] font-bold px-1.5 py-0.5 rounded"
                        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                        {f.token}
                      </button>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="flex justify-between items-center gap-3">
            <button onClick={restore} disabled={!server?.customised}
              className="flex items-center gap-2 text-sm font-bold px-4 py-2 rounded-xl disabled:opacity-50"
              style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              <RotateCcw size={14} /> Restore default
            </button>
            <button onClick={save} disabled={saving || !!err('subject') || !!err('body_html')}
              className="btn-3d flex items-center gap-2 disabled:opacity-60">
              {saving ? <Check size={14} /> : <Save size={14} />} {saving ? 'Saving…' : 'Save Template'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
