import { useState, useEffect, useMemo } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ArrowLeft, ArrowRight, FileText, LayoutTemplate, Loader2, Search } from 'lucide-react'
import { salesDocumentTemplateApi } from '@/services/salesDocumentTemplateApi'
import { useToast } from '@/hooks/useToast'

/**
 * The "how do you want to start?" step for invoices and estimates, mirroring the
 * proposal wizard's first step: a blank document, or one of the saved line-item
 * templates.
 *
 * A separate route rather than a step inside the drawer so the choice is a real
 * page you can land on, link to and come back from — the same shape as
 * /app/sales/proposals/new.
 *
 * It only makes the choice; the document itself is still filled in on the list
 * page's form, which this hands off to via the query string that page already
 * understands (`?new=1&client_id=…`, plus `template=`). Keeping one form means
 * the two entry points can't drift apart.
 */
const CONFIG = {
  invoice: {
    label: 'Invoice',
    listPath: '/app/sales/invoices',
    blankHint: 'Start from scratch — add every line yourself',
  },
  estimate: {
    label: 'Estimate',
    listPath: '/app/sales/estimates',
    blankHint: 'Start from scratch — add every line yourself',
  },
}

const money = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

export default function DocumentStart({ docType = 'invoice' }) {
  const cfg = CONFIG[docType] || CONFIG.invoice
  const navigate = useNavigate()
  const toast = useToast()
  const [params] = useSearchParams()
  const clientId = params.get('client_id') || ''

  const [templates, setTemplates] = useState(null)
  const [search, setSearch] = useState('')

  useEffect(() => {
    salesDocumentTemplateApi.list(docType)
      .then(d => setTemplates(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => { toast.error(e.message); setTemplates([]) })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [docType])

  /** Hand off to the form. `client_id` is carried through so arriving from a
      customer profile still preselects that customer. */
  const go = (templateId = null) => {
    const q = new URLSearchParams({ new: '1' })
    if (clientId) q.set('client_id', clientId)
    if (templateId) q.set('template', String(templateId))
    navigate(`${cfg.listPath}?${q.toString()}`)
  }

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return templates || []
    return (templates || []).filter(t =>
      [t.name, t.description].filter(Boolean).some(v => v.toLowerCase().includes(q)))
  }, [templates, search])

  const totalOf = (t) =>
    (t.line_items || []).reduce((sum, i) => sum + Number(i.total || 0), 0)

  return (
    <div className="space-y-6 animate-fade-in">
      {/* Header */}
      <div className="flex items-center gap-3">
        <button onClick={() => navigate(cfg.listPath)}
          className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]"
          style={{ border: '1px solid var(--border)' }}>
          <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
        </button>
        <div>
          <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>New {cfg.label}</p>
          <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>
            How do you want to start?
          </h1>
        </div>
      </div>

      {/* Blank */}
      <button onClick={() => go()}
        className="w-full card-3d flex items-center gap-4 hover:scale-[1.005] transition-transform text-left"
        style={{ padding: '20px' }}>
        <div className="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0"
          style={{ background: 'rgba(124,58,237,0.12)' }}>
          <FileText size={20} style={{ color: 'var(--accent)' }} />
        </div>
        <div className="min-w-0">
          <p className="font-black text-sm" style={{ color: 'var(--text-h)' }}>Blank {cfg.label}</p>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{cfg.blankHint}</p>
        </div>
        <ArrowRight size={16} className="ml-auto flex-shrink-0" style={{ color: 'var(--text-muted)' }} />
      </button>

      {/* Templates */}
      {templates === null ? (
        <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
          <Loader2 size={13} className="animate-spin" /> Loading templates…
        </div>
      ) : !templates.length ? (
        <div className="card-3d text-center" style={{ padding: '28px 20px' }}>
          <LayoutTemplate size={22} className="mx-auto mb-2" style={{ color: 'var(--text-faint)' }} />
          <p className="text-sm font-bold mb-1" style={{ color: 'var(--text-h)' }}>No {cfg.label.toLowerCase()} templates yet</p>
          <p className="text-xs max-w-md mx-auto" style={{ color: 'var(--text-muted)' }}>
            Build a {cfg.label.toLowerCase()} once, then use <strong>Save as template</strong> above its line items.
            Saved templates will appear here for one-click reuse.
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex items-center justify-between gap-3 flex-wrap">
            <p className="label-caps" style={{ color: 'var(--accent)' }}>Start from a template</p>
            {templates.length > 3 && (
              <div className="relative">
                <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
                <input value={search} onChange={e => setSearch(e.target.value)}
                  className="input-3d text-xs" style={{ paddingLeft: 32, width: 220 }} placeholder="Search templates…" />
              </div>
            )}
          </div>

          {!filtered.length ? (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No templates match “{search}”.</p>
          ) : (
            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
              {filtered.map(t => (
                <button key={t.id} onClick={() => go(t.id)}
                  className="card-3d text-left hover:scale-[1.01] transition-transform flex flex-col"
                  style={{ padding: '16px' }}>
                  <LayoutTemplate size={16} style={{ color: 'var(--accent)' }} />
                  <p className="font-bold text-sm mt-2" style={{ color: 'var(--text-h)' }}>{t.name}</p>
                  {t.description && (
                    <p className="text-[11px] mt-0.5 line-clamp-2" style={{ color: 'var(--text-muted)' }}>{t.description}</p>
                  )}
                  {/* What you actually get, so the choice isn't blind. */}
                  <div className="flex items-center justify-between gap-2 mt-3 pt-3 text-[11px]"
                    style={{ borderTop: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                    <span>{t.items_count ?? (t.line_items || []).length} item(s)</span>
                    <span className="font-bold" style={{ color: 'var(--text-h)' }}>{money(totalOf(t))}</span>
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
