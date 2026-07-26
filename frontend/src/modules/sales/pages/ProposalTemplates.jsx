import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, Plus, Trash2, Copy, LayoutTemplate, Edit2 } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import { useToast } from '@/hooks/useToast'

export default function ProposalTemplates() {
  const navigate = useNavigate()
  const toast = useToast()
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [confirmDelete, setConfirmDelete] = useState(null)

  const load = () => {
    setLoading(true)
    salesApi.proposalTemplates.list().then(d => { setData(d); setLoading(false) })
  }
  useEffect(() => { load() }, [])

  // Create / edit use the SAME full-page Cover + Pages editor as the proposal builder.
  const openCreate = () => navigate('/app/sales/proposal-templates/new')
  const openEdit = (t) => navigate(`/app/sales/proposal-templates/${t.id}/edit`)

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

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this template?"
          message={`This will permanently delete "${confirmDelete.name}". Proposals already created from it are unaffected.`}
          confirmLabel="Delete"
          tone="danger"
          onCancel={() => setConfirmDelete(null)}
          onConfirm={handleDelete}
        />
      )}
    </div>
  )
}
