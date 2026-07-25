import { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Loader2, Save } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import { useToast } from '@/hooks/useToast'
import CoverEditor from '../components/CoverEditor'
import PagesEditor from '../components/PagesEditor'
import RichTextEditor from '@/components/ui/RichTextEditor'
import FormField, { Input, Textarea } from '@/components/ui/FormField'

const BLANK = {
  name: '', description: '', category: '', terms: '',
  cover: { enabled: true, image: '', title: 'Proposal', heading: '', body: '' },
  pages: [{ title: 'Page 1', content: '' }],
}

/**
 * Full-page proposal-template editor — the SAME Cover + Pages experience as the
 * proposal builder's Content step, so creating/editing a template looks and
 * works exactly like building a proposal. Replaces the old cramped side-drawer.
 */
export default function ProposalTemplateEditor() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const isEdit = !!id
  const [form, setForm] = useState(BLANK)
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(isEdit)
  const [saving, setSaving] = useState(false)
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => { salesApi.proposalTemplates.categories().then(setCategories).catch(() => {}) }, [])

  useEffect(() => {
    if (!isEdit) return
    salesApi.proposalTemplates.list()
      .then(list => {
        const t = (list || []).find(x => String(x.id) === String(id))
        if (!t) { toast.error('Template not found'); navigate('/app/sales/proposal-templates'); return }
        setForm({
          name: t.name || '', description: t.description || '', category: t.category || '', terms: t.terms || '',
          cover: t.cover || BLANK.cover,
          pages: (t.pages || []).length ? t.pages.map(p => ({ title: p.title, content: p.content })) : [{ title: 'Page 1', content: '' }],
        })
        setLoading(false)
      })
      .catch(e => { toast.error(e.message); navigate('/app/sales/proposal-templates') })
  }, [id])

  const save = async () => {
    if (!form.name.trim()) return toast.error('Template name is required')
    setSaving(true)
    try {
      if (isEdit) await salesApi.proposalTemplates.update(id, form)
      else await salesApi.proposalTemplates.create(form)
      toast.success(isEdit ? 'Template updated' : 'Template created')
      navigate('/app/sales/proposal-templates')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (loading) return <div className="flex justify-center py-20"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>

  return (
    <div className="space-y-6 animate-fade-in">
      {/* Header — mirrors the proposal wizard */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate('/app/sales/proposal-templates')}
            className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]"
            style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div>
            <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Proposal Template</p>
            <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>{isEdit ? 'Edit Template' : 'New Template'}</h1>
          </div>
        </div>
        <button onClick={save} disabled={saving}
          className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03] disabled:opacity-60"
          style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          {saving ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />} {isEdit ? 'Save Template' : 'Create Template'}
        </button>
      </div>

      {/* Meta */}
      <div className="card-3d grid gap-4 md:grid-cols-3" style={{ padding: 20 }}>
        <FormField label="Name" required>
          <Input value={form.name} onChange={e => sf('name', e.target.value)} placeholder="Template name" />
        </FormField>
        <FormField label="Category" hint="pick an existing one or type a new one">
          <Input list="template-categories" value={form.category} onChange={e => sf('category', e.target.value)} placeholder="e.g. Consulting" />
          <datalist id="template-categories">{categories.map(c => <option key={c} value={c} />)}</datalist>
        </FormField>
        <FormField label="Description" hint="optional">
          <Textarea rows={1} value={form.description} onChange={e => sf('description', e.target.value)} />
        </FormField>
      </div>

      {/* Cover + Pages — identical components to the proposal builder */}
      <div className="card-3d" style={{ padding: 20 }}>
        <CoverEditor value={form.cover} onChange={cover => sf('cover', cover)} />
        <PagesEditor pages={form.pages} onChange={pages => sf('pages', pages)} />
      </div>

      {/* Default terms */}
      <div className="card-3d" style={{ padding: 20 }}>
        <FormField label="Terms & Conditions" hint="Pre-filled on proposals built from this template">
          <RichTextEditor value={form.terms} onChange={v => sf('terms', v)} placeholder="Payment terms, validity, scope notes…" minHeight={140} />
        </FormField>
      </div>
    </div>
  )
}
