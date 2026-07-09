import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Flame, Thermometer, Snowflake, Building2, Mail, Phone, Globe,
  MapPin, User, Tag, XCircle, RotateCcw, Trash2, TrendingUp, Plus,
} from 'lucide-react'
import {
  useLead, useUpdateLeadStatus, useAssignLead, useConvertLead,
  useDeleteLead, useMarkLeadLost, useMarkLeadJunk, useRestoreLead, useAddLeadNote,
} from '@/hooks/useLeads'
import { useToast } from '@/hooks/useToast'
import ActivityTimeline from '../components/ActivityTimeline'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'

const TEMP_ICON = { Hot: Flame, Warm: Thermometer, Cold: Snowflake }
const TEMP_COLOR = { Hot: '#ef4444', Warm: '#f59e0b', Cold: '#3b82f6' }

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

export default function LeadDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()

  const { data: lead, isLoading } = useLead(id)
  const markLost = useMarkLeadLost()
  const markJunk = useMarkLeadJunk()
  const restore = useRestoreLead()
  const convert = useConvertLead()
  const deleteLead = useDeleteLead()
  const addNote = useAddLeadNote()

  const [confirmAction, setConfirmAction] = useState(null) // 'lost' | 'delete' | null
  const [noteText, setNoteText] = useState('')

  if (isLoading) {
    return (
      <div className="space-y-4 animate-fade-in">
        {[1, 2, 3].map(i => <div key={i} className="skeleton h-28 rounded-2xl" style={{ background: 'var(--border)' }} />)}
      </div>
    )
  }

  if (!lead) {
    return (
      <EmptyState
        title="Lead not found"
        action={<button onClick={() => navigate('/app/sales/leads')} className="btn-3d">Back to Leads</button>}
      />
    )
  }

  const TempIcon = TEMP_ICON[lead.lead_temperature] || Thermometer

  const events = [
    ...(lead.activities || []).map(a => ({
      type: a.type,
      label: a.description,
      detail: a.performer?.name,
      date: a.created_at,
    })),
  ]

  const handleMarkLost = () => {
    markLost.mutate(lead.id, {
      onSuccess: () => { toast.success('Lead marked as lost'); setConfirmAction(null) },
      onError: (e) => toast.error(e.message),
    })
  }

  const handleDelete = () => {
    deleteLead.mutate(lead.id, {
      onSuccess: () => { toast.success('Lead deleted'); navigate('/app/sales/leads') },
      onError: (e) => toast.error(e.message),
    })
  }

  const handleRestore = () => {
    restore.mutate(lead.id, {
      onSuccess: () => toast.success('Lead restored'),
      onError: (e) => toast.error(e.message),
    })
  }

  const handleConvert = () => {
    convert.mutate({ id: lead.id, data: {} }, {
      onSuccess: () => toast.success('Lead converted to customer'),
      onError: (e) => toast.error(e.message),
    })
  }

  const handleAddNote = () => {
    if (!noteText.trim()) return
    addNote.mutate({ id: lead.id, data: { content: noteText.trim() } }, {
      onSuccess: () => { toast.success('Note added'); setNoteText('') },
      onError: (e) => toast.error(e.message),
    })
  }

  return (
    <>
      <div className="space-y-6 animate-fade-in">

        {/* Top bar */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-3">
            <button onClick={() => navigate('/app/sales/leads')}
              className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]"
              style={{ border: '1px solid var(--border)' }}>
              <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
            </button>
            <div>
              <div className="flex items-center gap-2">
                <p className="font-black text-base" style={{ color: 'var(--text-h)' }}>{lead.name}</p>
                <span className="text-[11px] font-bold px-2.5 py-1 rounded-lg text-white" style={{ background: lead.status?.color || '#6b7280' }}>
                  {lead.status?.name || '—'}
                </span>
                <span className="inline-flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-lg" style={{ background: `${TEMP_COLOR[lead.lead_temperature]}15`, color: TEMP_COLOR[lead.lead_temperature] }}>
                  <TempIcon size={11} />{lead.lead_score}
                </span>
              </div>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{lead.company || lead.title || '—'}</p>
            </div>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            {(lead.lost || lead.junk) ? (
              <button onClick={handleRestore} disabled={restore.isPending}
                className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
                style={{ background: 'rgba(16,185,129,0.1)', border: '1px solid rgba(16,185,129,0.25)', color: '#10b981' }}>
                <RotateCcw size={13} /> Restore
              </button>
            ) : (
              <>
                {!lead.date_converted && (
                  <button onClick={handleConvert} disabled={convert.isPending}
                    className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
                    style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid rgba(124,58,237,0.25)', color: '#a78bfa' }}>
                    <TrendingUp size={13} /> Convert
                  </button>
                )}
                <button onClick={() => setConfirmAction('lost')}
                  className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                  <XCircle size={13} /> Mark Lost
                </button>
              </>
            )}
            <button onClick={() => setConfirmAction('delete')}
              className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
              style={{ background: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.2)', color: '#f87171' }}>
              <Trash2 size={13} /> Delete
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">

          {/* Main column */}
          <div className="lg:col-span-2 space-y-5">

            <div className="card-3d" style={{ padding: '20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Details</h3>
              <div className="grid grid-cols-2 gap-4 text-xs">
                <InfoRow icon={Building2} label="Company" value={lead.company} />
                <InfoRow icon={Mail} label="Email" value={lead.email} />
                <InfoRow icon={Phone} label="Phone" value={lead.phone} />
                <InfoRow icon={Globe} label="Website" value={lead.website} />
                <InfoRow icon={MapPin} label="Location" value={[lead.city, lead.state, lead.country].filter(Boolean).join(', ') || '—'} />
                <InfoRow icon={Tag} label="Source" value={lead.source?.name} />
                <InfoRow icon={User} label="Assigned to" value={lead.assigned_user?.name || 'Unassigned'} />
                <InfoRow label="Lead value" value={fmt(lead.lead_value)} />
              </div>
              {lead.description && (
                <p className="text-xs mt-4 pt-4" style={{ color: 'var(--text-muted)', borderTop: '1px solid var(--border)' }}>
                  {lead.description}
                </p>
              )}
            </div>

            {/* Notes */}
            <div className="card-3d" style={{ padding: '20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Notes</h3>
              <div className="flex gap-2 mb-4">
                <input
                  className="input-3d text-sm flex-1"
                  placeholder="Add a note…"
                  value={noteText}
                  onChange={e => setNoteText(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && handleAddNote()}
                />
                <button onClick={handleAddNote} disabled={addNote.isPending || !noteText.trim()} className="btn-icon">
                  <Plus size={16} />
                </button>
              </div>
              {(lead.notes || []).length === 0 ? (
                <p className="text-xs text-center py-4" style={{ color: 'var(--text-muted)' }}>No notes yet.</p>
              ) : (
                <div className="space-y-3">
                  {lead.notes.map(note => (
                    <div key={note.id} className="text-xs pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
                      <p style={{ color: 'var(--text-h)' }}>{note.content}</p>
                      <p className="mt-1" style={{ color: 'var(--text-muted)' }}>
                        {note.creator?.name || 'Unknown'} · {fmtDate(note.created_at)}
                      </p>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* Sidebar */}
          <div className="space-y-5">
            <div className="card-3d" style={{ padding: '20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Activity</h3>
              <ActivityTimeline events={events} />
            </div>
          </div>
        </div>
      </div>

      {confirmAction === 'lost' && (
        <ConfirmDialog
          title="Mark lead as lost?"
          message="This lead will be moved out of the active pipeline. You can restore it later."
          confirmLabel="Mark Lost"
          tone="danger"
          onConfirm={handleMarkLost}
          onCancel={() => setConfirmAction(null)}
        />
      )}

      {confirmAction === 'delete' && (
        <ConfirmDialog
          title="Delete this lead?"
          message="This cannot be undone. All notes, activity, and questionnaire responses for this lead will be permanently removed."
          confirmLabel="Delete"
          tone="danger"
          onConfirm={handleDelete}
          onCancel={() => setConfirmAction(null)}
        />
      )}
    </>
  )
}

function InfoRow({ icon: Icon, label, value }) {
  return (
    <div className="flex items-start gap-2">
      {Icon && <Icon size={13} className="mt-0.5 flex-shrink-0" style={{ color: 'var(--text-muted)' }} />}
      <div>
        <p style={{ color: 'var(--text-muted)' }}>{label}</p>
        <p className="font-semibold mt-0.5" style={{ color: 'var(--text-h)' }}>{value || '—'}</p>
      </div>
    </div>
  )
}
