import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { createPortal } from 'react-dom'
import { ArrowLeft, Plus, Trash2, Copy, LayoutTemplate, Edit2, Search, Eye, Files, X, FileText, TrendingUp } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import { useToast } from '@/hooks/useToast'
import ProposalDocument from '../components/ProposalDocument'

const UNCATEGORISED = 'Uncategorised'

export default function ProposalTemplates() {
  const navigate = useNavigate()
  const toast = useToast()
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('all')
  const [preview, setPreview] = useState(null)
  const [busyId, setBusyId] = useState(null)

  const load = () => {
    setLoading(true)
    salesApi.proposalTemplates.list()
      .then(d => setData(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => toast.error(e.message))
      .finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [])

  // Create / edit use the SAME full-page Cover + Pages editor as the proposal builder.
  const openCreate = () => navigate('/app/sales/proposal-templates/new')
  const openEdit = (t) => navigate(`/app/sales/proposal-templates/${t.id}/edit`)

  const handleClone = async (template) => {
    setBusyId(template.id)
    try {
      const proposal = await salesApi.proposalTemplates.clone(template.id)
      toast.success('Proposal created from template')
      navigate(`/app/sales/proposals/${proposal.id}`)
    } catch (e) {
      toast.error(e.message)
    } finally { setBusyId(null) }
  }

  /** Copy the TEMPLATE (clone makes a proposal) — the way to build a variant. */
  const handleDuplicate = async (template) => {
    setBusyId(template.id)
    try {
      const copy = await salesApi.proposalTemplates.duplicate(template.id)
      toast.success(`Duplicated as "${copy.name}"`)
      load()
    } catch (e) {
      toast.error(e.message)
    } finally { setBusyId(null) }
  }

  const handleDelete = async () => {
    try {
      await salesApi.proposalTemplates.delete(confirmDelete.id)
      toast.success('Template deleted')
      setConfirmDelete(null)
      load()
    } catch (e) {
      toast.error(e.message)
    }
  }

  const categories = useMemo(
    () => [...new Set(data.map(t => t.category || UNCATEGORISED))].sort(),
    [data],
  )

  // Filter first, then group — so an empty category disappears instead of
  // leaving a bare heading behind.
  const grouped = useMemo(() => {
    const q = search.trim().toLowerCase()
    const rows = data.filter(t => {
      if (category !== 'all' && (t.category || UNCATEGORISED) !== category) return false
      if (!q) return true
      return [t.name, t.description, t.category].filter(Boolean).some(v => v.toLowerCase().includes(q))
    })
    return rows.reduce((acc, t) => {
      const key = t.category || UNCATEGORISED
      ;(acc[key] ||= []).push(t)
      return acc
    }, {})
  }, [data, search, category])

  const shown = Object.values(grouped).reduce((n, l) => n + l.length, 0)

  return (
    <div className="space-y-6 animate-fade-in">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate('/app/sales/proposals')}
            className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]"
            style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div>
            <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Sales & Revenue</p>
            <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>Proposal Templates</h1>
            {!loading && data.length > 0 && (
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                {shown === data.length ? `${data.length} templates` : `${shown} of ${data.length} templates`}
              </p>
            )}
          </div>
        </div>
        <button onClick={openCreate}
          className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]"
          style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          <Plus size={15} /> New Template
        </button>
      </div>

      {/* Search + category filter — only worth showing once there's a library */}
      {!loading && data.length > 1 && (
        <div className="flex items-center gap-2 flex-wrap">
          <div className="relative flex-1 min-w-[200px]">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input value={search} onChange={e => setSearch(e.target.value)}
              className="input-3d text-sm" style={{ paddingLeft: 34 }}
              placeholder="Search templates by name, description or category…" />
          </div>
          <div className="flex items-center gap-1.5 flex-wrap">
            <FilterChip active={category === 'all'} onClick={() => setCategory('all')}>All</FilterChip>
            {categories.map(c => (
              <FilterChip key={c} active={category === c} onClick={() => setCategory(c)}>{c}</FilterChip>
            ))}
          </div>
        </div>
      )}

      {/* Gallery, grouped by category to match the proposal wizard's picker */}
      {loading ? (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {[1, 2, 3].map(i => <div key={i} className="skeleton h-48 rounded-2xl" style={{ background: 'var(--border)' }} />)}
        </div>
      ) : !data.length ? (
        <EmptyState
          icon={LayoutTemplate}
          title="No templates yet"
          description="Create reusable proposal templates so your team can start new proposals faster."
          action={<button onClick={openCreate} className="btn-3d">Create your first template</button>}
        />
      ) : !shown ? (
        <EmptyState
          icon={Search}
          title="No templates match"
          description="Try a different search, or clear the category filter."
          action={<button onClick={() => { setSearch(''); setCategory('all') }} className="btn-3d">Clear filters</button>}
        />
      ) : (
        <div className="space-y-6">
          {Object.entries(grouped).sort(([a], [b]) => a.localeCompare(b)).map(([cat, list]) => (
            <div key={cat}>
              <p className="label-caps mb-2" style={{ color: '#a78bfa' }}>{cat} · {list.length}</p>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {list.map(t => (
                  <TemplateCard
                    key={t.id} t={t} busy={busyId === t.id}
                    onUse={() => handleClone(t)}
                    onPreview={() => setPreview(t)}
                    onDuplicate={() => handleDuplicate(t)}
                    onEdit={() => openEdit(t)}
                    onDelete={() => setConfirmDelete(t)}
                  />
                ))}
              </div>
            </div>
          ))}
        </div>
      )}

      {preview && createPortal(
        <PreviewModal template={preview} onClose={() => setPreview(null)}
          onUse={() => { const t = preview; setPreview(null); handleClone(t) }} />,
        document.body,
      )}

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this template?"
          message={
            confirmDelete.usage_count
              ? `This will permanently delete "${confirmDelete.name}". ${confirmDelete.usage_count} proposal${confirmDelete.usage_count === 1 ? '' : 's'} built from it stay unaffected.`
              : `This will permanently delete "${confirmDelete.name}".`
          }
          confirmLabel="Delete"
          tone="danger"
          onCancel={() => setConfirmDelete(null)}
          onConfirm={handleDelete}
        />
      )}
    </div>
  )
}

function FilterChip({ active, onClick, children }) {
  return (
    <button onClick={onClick}
      className="px-3 py-1.5 rounded-xl text-xs font-bold transition-colors"
      style={active
        ? { background: 'rgba(124,58,237,0.14)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.3)' }
        : { background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
      {children}
    </button>
  )
}

function TemplateCard({ t, busy, onUse, onPreview, onDuplicate, onEdit, onDelete }) {
  const pages = t.pages_count ?? t.pages?.length ?? 0
  const uses = t.usage_count ?? 0

  return (
    <div className="card-3d flex flex-col" style={{ padding: '20px' }}>
      <div className="flex items-start justify-between">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center mb-3"
          style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)' }}>
          <LayoutTemplate size={18} className="text-white" />
        </div>
        <button onClick={onPreview} title="Preview this template"
          className="w-8 h-8 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]"
          style={{ border: '1px solid var(--border)' }}>
          <Eye size={13} style={{ color: 'var(--text-muted)' }} />
        </button>
      </div>

      <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{t.name}</p>
      {t.description && <p className="text-xs mt-1.5 line-clamp-2" style={{ color: 'var(--text-muted)' }}>{t.description}</p>}

      {/* Page count and how often it's actually been used — the link from
          proposals.template_id existed but was never shown anywhere. */}
      <div className="flex items-center gap-3 mt-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>
        <span className="inline-flex items-center gap-1"><FileText size={11} /> {pages || 1} page{pages === 1 ? '' : 's'}</span>
        <span className="inline-flex items-center gap-1" style={uses ? { color: '#10b981' } : undefined}>
          <TrendingUp size={11} /> {uses ? `used ${uses}×` : 'never used'}
        </span>
      </div>

      <div className="mt-auto pt-4 flex gap-2">
        <button onClick={onUse} disabled={busy}
          className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold transition-all hover:scale-[1.02] disabled:opacity-60"
          style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid rgba(124,58,237,0.25)', color: '#a78bfa' }}>
          <Copy size={12} /> {busy ? 'Working…' : 'Use Template'}
        </button>
        <button onClick={onDuplicate} disabled={busy} className="btn-icon" title="Duplicate this template">
          <Files size={14} style={{ color: 'var(--text-muted)' }} />
        </button>
        <button onClick={onEdit} className="btn-icon" title="Edit template">
          <Edit2 size={14} style={{ color: 'var(--text-muted)' }} />
        </button>
        <button onClick={onDelete} className="btn-icon" title="Delete template">
          <Trash2 size={14} style={{ color: '#f87171' }} />
        </button>
      </div>
    </div>
  )
}

/**
 * See the template as the client would before committing to it.
 *
 * Reuses ProposalDocument — the same renderer the wizard's Review step and the
 * public portal use — so the preview can't drift from the real thing. A template
 * has no line items or client, so it's shaped into the fields that component
 * reads and the money section simply doesn't appear.
 */
function PreviewModal({ template, onClose, onUse }) {
  const asProposal = {
    subject: template.name,
    cover: template.cover,
    pages: template.pages || [],
    notes: template.content,
    terms: template.terms,
    line_items: [],
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.55)' }}>
      <div className="w-full max-w-4xl rounded-2xl flex flex-col overflow-hidden"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '90vh' }}>
        <div className="flex items-center justify-between gap-3 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="min-w-0">
            <p className="label-caps" style={{ color: '#a78bfa' }}>Template preview</p>
            <p className="font-bold text-sm truncate" style={{ color: 'var(--text-h)' }}>{template.name}</p>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <button onClick={onUse}
              className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-white"
              style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)' }}>
              <Copy size={12} /> Use Template
            </button>
            <button onClick={onClose} className="w-8 h-8 rounded-xl flex items-center justify-center"
              style={{ border: '1px solid var(--border)' }}>
              <X size={14} style={{ color: 'var(--text-muted)' }} />
            </button>
          </div>
        </div>
        <div className="overflow-y-auto p-5">
          <ProposalDocument proposal={asProposal} />
        </div>
      </div>
    </div>
  )
}
