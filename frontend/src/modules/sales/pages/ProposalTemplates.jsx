import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, Plus, Trash2, Copy, LayoutTemplate, Edit2 } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import PagesEditor from '../components/PagesEditor'
import RichTextEditor from '@/components/ui/RichTextEditor'
import Drawer from '@/components/ui/Drawer'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'
import { useToast } from '@/hooks/useToast'

const EMPTY_FORM = { name: '', description: '', category: '', content: '', terms: '', is_default: false, pages: [{ title: 'Page 1', content: '' }] }

export default function ProposalTemplates() {
  const navigate = useNavigate()
  const toast = useToast()
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [showDrawer, setShowDrawer] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [categories, setCategories] = useState([])
  const [creating, setCreating] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [editing, setEditing] = useState(null)   // null = create, template = edit

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const load = () => {
    setLoading(true)
    salesApi.proposalTemplates.list().then(d => { setData(d); setLoading(false) })
  }
  useEffect(() => { load() }, [])
  useEffect(() => { salesApi.proposalTemplates.categories().then(setCategories).catch(() => {}) }, [])

  const openCreate = () => { setEditing(null); setForm(EMPTY_FORM); setShowDrawer(true) }

  const openEdit = (t) => {
    setEditing(t)
    setForm({
      name: t.name || '', description: t.description || '', category: t.category || '',
      content: t.content || '', terms: t.terms || '', is_default: !!t.is_default,
      pages: (t.pages || []).length ? t.pages.map(p => ({ title: p.title, content: p.content })) : [{ title: 'Page 1', content: '' }],
    })
    setShowDrawer(true)
  }

  const handleSave = async () => {
    if (!form.name) return toast.error('Template name is required')
    setCreating(true)
    try {
      if (editing) { await salesApi.proposalTemplates.update(editing.id, form); toast.success('Template updated') }
      else { await salesApi.proposalTemplates.create(form); toast.success('Template created') }
      setShowDrawer(false); setEditing(null); setForm(EMPTY_FORM)
      load()
    } catch (e) {
      toast.error(e.message)
    } finally {
      setCreating(false)
    }
  }

  const handleClone = async (template) => {
    try {
      const proposal = await salesApi.proposalTemplates.clone(template.id)
      toast.success('Proposal created from template')
      navigate(`/app/sales/proposals/${proposal.id}`)
    } catch (e) {
      toast.error(e.message)
    }
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
          </div>
        </div>
        <button onClick={openCreate}
          className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]"
          style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          <Plus size={15} /> New Template
        </button>
      </div>

      {/* Gallery grid */}
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
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {data.map(t => (
            <div key={t.id} className="card-3d flex flex-col" style={{ padding: '20px' }}>
              <div className="w-10 h-10 rounded-2xl flex items-center justify-center mb-3"
                style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)' }}>
                <LayoutTemplate size={18} className="text-white" />
              </div>
              <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{t.name}</p>
              {t.category && (
                <span className="inline-block mt-1.5 w-fit px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>
                  {t.category}
                </span>
              )}
              {t.description && <p className="text-xs mt-2 line-clamp-2" style={{ color: 'var(--text-muted)' }}>{t.description}</p>}
              <div className="mt-auto pt-4 flex gap-2">
                <button onClick={() => handleClone(t)}
                  className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold transition-all hover:scale-[1.02]"
                  style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid rgba(124,58,237,0.25)', color: '#a78bfa' }}>
                  <Copy size={12} /> Use Template
                </button>
                <button onClick={() => openEdit(t)} className="btn-icon" title="Edit template">
                  <Edit2 size={14} style={{ color: 'var(--text-muted)' }} />
                </button>
                <button onClick={() => setConfirmDelete(t)} className="btn-icon">
                  <Trash2 size={14} style={{ color: '#f87171' }} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Create drawer */}
      <Drawer
        open={showDrawer}
        onClose={() => setShowDrawer(false)}
        width="min(980px, 96vw)"
        title={editing ? 'Edit Proposal Template' : 'New Proposal Template'}
        footer={
          <button onClick={handleSave} disabled={creating} className="btn-3d w-full">
            {creating ? 'Saving…' : editing ? 'Save Template' : 'Create Template'}
          </button>
        }
      >
        <div className="space-y-4">
          <FormField label="Name" required>
            <Input value={form.name} onChange={e => sf('name', e.target.value)} />
          </FormField>
          <FormField label="Category" hint="optional — pick an existing one or type a new one">
            <Input list="template-categories" value={form.category} onChange={e => sf('category', e.target.value)} placeholder="e.g. Consulting" />
            <datalist id="template-categories">{categories.map(c => <option key={c} value={c} />)}</datalist>
          </FormField>
          <FormField label="Description" hint="optional">
            <Textarea rows={2} value={form.description} onChange={e => sf('description', e.target.value)} />
          </FormField>
          <FormField label="Content Pages" hint="Multi-page rich content — copied into proposals created from this template">
            <PagesEditor pages={form.pages} onChange={pages => sf('pages', pages)} minHeight={220} />
          </FormField>
          <FormField label="Terms & Conditions" hint="Default terms — pre-filled on proposals built from this template">
            <RichTextEditor value={form.terms} onChange={v => sf('terms', v)} placeholder="Payment terms, validity, scope notes…" minHeight={140} />
          </FormField>
        </div>
      </Drawer>

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this template?"
          message={`This will permanently delete "${confirmDelete.name}". Proposals already created from it are unaffected.`}
          confirmLabel="Delete"
          tone="danger"
          onConfirm={handleDelete}
          onCancel={() => setConfirmDelete(null)}
        />
      )}
    </div>
  )
}
