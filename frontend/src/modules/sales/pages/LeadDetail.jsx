import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { helpdeskApi } from '@/services/helpdeskApi'
import {
  ArrowLeft, Flame, Thermometer, Snowflake, Building2, Mail, Phone, Globe,
  MapPin, User, Tag, XCircle, RotateCcw, Trash2, TrendingUp, Plus, FileText,
  HelpCircle, LifeBuoy, LayoutTemplate,
} from 'lucide-react'
import {
  useLead, useConvertLead, useDeleteLead, useMarkLeadLost, useRestoreLead,
  useAddLeadNote, useUpdateLead,
} from '@/hooks/useLeads'
import { useToast } from '@/hooks/useToast'
import ActivityTimeline from '../components/ActivityTimeline'
import FollowUpsPanel from '../components/FollowUpsPanel'
import WinProbabilityBadge from '../components/WinProbabilityBadge'
import NextBestActionChip from '../components/NextBestActionChip'
import StatusBadge from '../components/StatusBadge'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import { richHtml } from '@/lib/richText'

const TEMP_ICON = { Hot: Flame, Warm: Thermometer, Cold: Snowflake }
const TEMP_COLOR = { Hot: '#ef4444', Warm: '#f59e0b', Cold: '#3b82f6' }

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const TABS = [
  { key: 'profile', label: 'Profile' },
  { key: 'notes', label: 'Notes' },
  { key: 'proposals', label: 'Proposals' },
  { key: 'questionnaires', label: 'Questionnaires' },
  { key: 'activity', label: 'Activity Log' },
  { key: 'support', label: 'Support' },
  { key: 'templates', label: 'Templates' },
]

export default function LeadDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()

  const { data: lead, isLoading } = useLead(id)
  const [tab, setTab] = useState('profile')
  const [confirmAction, setConfirmAction] = useState(null) // 'lost' | 'delete' | null

  const markLost = useMarkLeadLost()
  const restore = useRestoreLead()
  const convert = useConvertLead()
  const deleteLead = useDeleteLead()

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

        {/* Tabs */}
        <div className="flex items-center gap-1 flex-wrap" style={{ borderBottom: '1px solid var(--border)' }}>
          {TABS.map(t => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className="px-4 py-2.5 text-xs font-bold transition-colors relative"
              style={{ color: tab === t.key ? '#a78bfa' : 'var(--text-muted)' }}
            >
              {t.label}
              {tab === t.key && (
                <div className="absolute bottom-0 left-0 right-0 h-0.5 rounded-full" style={{ background: '#7C3AED' }} />
              )}
            </button>
          ))}
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">

          {/* Main column */}
          <div className="lg:col-span-2 space-y-5">
            {tab === 'profile' && <ProfileTab lead={lead} />}
            {tab === 'notes' && <NotesTab lead={lead} toast={toast} />}
            {tab === 'proposals' && <ProposalsTab lead={lead} navigate={navigate} />}
            {tab === 'questionnaires' && <QuestionnairesTab lead={lead} />}
            {tab === 'activity' && (
              <div className="card-3d" style={{ padding: '20px' }}>
                <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Activity Log</h3>
                <ActivityTimeline events={(lead.activities || []).map(a => ({
                  type: a.type, label: a.description, detail: a.performer?.name, date: a.created_at,
                }))} />
              </div>
            )}
            {tab === 'support' && <LeadSupportTab lead={lead} />}
            {tab === 'templates' && (
              <div className="card-3d" style={{ padding: '20px' }}>
                <EmptyState icon={LayoutTemplate} title="Templates" description="Email and document templates for this lead will appear here." />
              </div>
            )}
          </div>

          {/* Sidebar */}
          <div className="space-y-5">
            <NextBestActionChip leadId={lead.id} />

            <div className="card-3d" style={{ padding: '20px' }}>
              <div className="flex items-center justify-between mb-4">
                <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Summary</h3>
                <WinProbabilityBadge lead={lead} />
              </div>
              <div className="space-y-3 text-xs">
                <SummaryRow label="Assigned to" value={lead.assigned_user?.name || 'Unassigned'} />
                <SummaryRow label="Created by" value={lead.creator?.name || '—'} />
                <SummaryRow label="Source" value={lead.source?.name || '—'} />
                <SummaryRow label="Lead value" value={fmt(lead.lead_value)} />
                <SummaryRow label="Last contact" value={fmtDate(lead.last_contact_date)} />
                <SummaryRow label="Created" value={fmtDate(lead.created_at)} />
              </div>
            </div>

            <div className="card-3d" style={{ padding: '20px' }}>
              <h3 className="font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Recent Activity</h3>
              <ActivityTimeline events={(lead.activities || []).slice(0, 3).map(a => ({
                type: a.type, label: a.description, detail: a.performer?.name, date: a.created_at,
              }))} />
              {(lead.activities || []).length > 3 && (
                <button onClick={() => setTab('activity')} className="text-xs font-bold mt-2" style={{ color: '#a78bfa' }}>
                  View all activity →
                </button>
              )}
            </div>

            <FollowUpsPanel subjectType="lead" subjectId={lead.id} />
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

/* ── Profile Tab ─────────────────────────────────────────── */
function ProfileTab({ lead }) {
  const toast = useToast()
  const updateLead = useUpdateLead()
  const [chance, setChance] = useState(lead.conversion_chance ?? 0)

  const saveChance = (value) => {
    setChance(value)
    updateLead.mutate({ id: lead.id, data: { conversion_chance: Number(value) } }, {
      onError: (e) => toast.error(e.message),
    })
  }

  return (
    <div className="space-y-5">
      <div className="card-3d" style={{ padding: '20px' }}>
        <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Contact Details</h3>
        <div className="grid grid-cols-2 gap-4 text-xs">
          <InfoRow icon={Building2} label="Company" value={lead.company} />
          <InfoRow icon={Mail} label="Email" value={lead.email} />
          <InfoRow icon={Phone} label="Phone" value={lead.phone} />
          <InfoRow icon={Globe} label="Website" value={lead.website} />
          <InfoRow icon={MapPin} label="Location" value={[lead.city, lead.state, lead.country].filter(Boolean).join(', ') || '—'} />
          <InfoRow icon={Tag} label="Tags" value={lead.tags} />
          <InfoRow icon={FileText} label="GST" value={lead.gst} />
          <InfoRow icon={FileText} label="PAN" value={lead.pan} />
          <InfoRow icon={Building2} label="Industry" value={lead.industry} />
          <InfoRow icon={TrendingUp} label="Campaign" value={lead.campaign} />
          <InfoRow icon={Flame} label="Priority" value={lead.priority ? lead.priority[0].toUpperCase() + lead.priority.slice(1) : '—'} />
          <InfoRow icon={FileText} label="Expected Close" value={lead.expected_close_date ? String(lead.expected_close_date).slice(0, 10) : '—'} />
        </div>
        {lead.description && (
          <div className="rich-content text-xs mt-4 pt-4" style={{ color: 'var(--text-muted)', borderTop: '1px solid var(--border)' }}
            dangerouslySetInnerHTML={richHtml(lead.description)} />
        )}
      </div>

      <div className="card-3d" style={{ padding: '20px' }}>
        <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Score &amp; Conversion</h3>
        <div className="grid grid-cols-2 gap-6">
          <div>
            <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>Lead score</p>
            <div className="flex items-end gap-2">
              <p className="font-black text-2xl" style={{ color: 'var(--text-h)' }}>{lead.lead_score ?? 0}</p>
              <p className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>/ 100 · {lead.lead_temperature || '—'}</p>
            </div>
            <div className="h-1.5 rounded-full mt-2 overflow-hidden" style={{ background: 'var(--border)' }}>
              <div className="h-full rounded-full" style={{ width: `${Math.min(lead.lead_score ?? 0, 100)}%`, background: TEMP_COLOR[lead.lead_temperature] || '#6b7280' }} />
            </div>
          </div>
          <div>
            <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>Conversion chance: {chance}%</p>
            <input
              type="range" min="0" max="100" value={chance}
              onChange={e => setChance(e.target.value)}
              onMouseUp={e => saveChance(e.target.value)}
              onTouchEnd={e => saveChance(e.target.value)}
              className="w-full"
            />
          </div>
        </div>
      </div>
    </div>
  )
}

/* ── Notes Tab ───────────────────────────────────────────── */
function NotesTab({ lead, toast }) {
  const addNote = useAddLeadNote()
  const [content, setContent] = useState('')
  const [contactDate, setContactDate] = useState('')

  const handleAdd = () => {
    if (!content.trim()) return
    addNote.mutate({ id: lead.id, data: { content: content.trim(), contact_date: contactDate || null } }, {
      onSuccess: () => { toast.success('Note added'); setContent(''); setContactDate('') },
      onError: (e) => toast.error(e.message),
    })
  }

  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Notes</h3>
      <div className="flex gap-2 mb-4 flex-wrap">
        <input
          className="input-3d text-sm flex-1 min-w-[200px]"
          placeholder="Add a note…"
          value={content}
          onChange={e => setContent(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && handleAdd()}
        />
        <input
          type="date"
          className="input-3d text-sm"
          value={contactDate}
          onChange={e => setContactDate(e.target.value)}
        />
        <button onClick={handleAdd} disabled={addNote.isPending || !content.trim()} className="btn-icon">
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
                {note.creator?.name || 'Unknown'} · {fmtDate(note.contact_date || note.created_at)}
              </p>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

/* ── Proposals Tab ───────────────────────────────────────── */
function ProposalsTab({ lead, navigate }) {
  const proposals = lead.proposals || []
  if (proposals.length === 0) {
    return (
      <div className="card-3d" style={{ padding: '20px' }}>
        <EmptyState icon={FileText} title="No proposals yet" description="Proposals created for this lead will appear here." />
      </div>
    )
  }
  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Proposals</h3>
      <div className="space-y-2">
        {proposals.map(p => (
          <div
            key={p.id}
            onClick={() => navigate(`/app/sales/proposals/${p.id}`)}
            className="flex items-center justify-between p-3 rounded-xl cursor-pointer transition-colors hover:bg-[rgba(124,58,237,0.06)]"
            style={{ border: '1px solid var(--border)' }}
          >
            <div>
              <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{p.subject}</p>
              <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{fmtDate(p.date)}</p>
            </div>
            <StatusBadge status={p.status} />
          </div>
        ))}
      </div>
    </div>
  )
}

/* ── Questionnaires Tab ──────────────────────────────────── */
function QuestionnairesTab({ lead }) {
  const responses = lead.questionnaire_responses || []
  if (responses.length === 0) {
    return (
      <div className="card-3d" style={{ padding: '20px' }}>
        <EmptyState icon={HelpCircle} title="No questionnaire responses" description="Responses submitted for this lead will appear here." />
      </div>
    )
  }
  return (
    <div className="space-y-4">
      {responses.map(r => (
        <div key={r.id} className="card-3d" style={{ padding: '20px' }}>
          <div className="flex items-center justify-between mb-3">
            <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{r.questionnaire?.title || 'Questionnaire'}</h3>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{fmtDate(r.submitted_at)}</p>
          </div>
          <div className="space-y-2 text-xs">
            {Object.entries(r.answers || {}).map(([key, value]) => (
              <div key={key} className="flex justify-between gap-4 pb-2" style={{ borderBottom: '1px solid var(--border)' }}>
                <span style={{ color: 'var(--text-muted)' }}>{key}</span>
                <span className="font-semibold text-right" style={{ color: 'var(--text-h)' }}>{String(value)}</span>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}

/**
 * Support tab — real Helpdesk integration (owner of Helpdesk: Shivam).
 * A lead's support history is its converted customer's tickets: once the lead
 * has a linked customer (client_id), we list that customer's tickets and let the
 * rep raise a new one, both via helpdeskApi. Before conversion there's no
 * customer to attach a ticket to, so we prompt to convert first.
 */
function LeadSupportTab({ lead }) {
  const toast = useToast()
  const customerId = lead.client_id || lead.customer_id || null
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(false)
  const [subject, setSubject] = useState('')
  const [saving, setSaving] = useState(false)

  const load = () => {
    if (!customerId) return
    setLoading(true)
    helpdeskApi.tickets.list({ customer_id: customerId })
      .then(r => setRows(Array.isArray(r) ? r : (r?.data ?? [])))
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }
  useEffect(load, [customerId])

  const raise = async () => {
    if (!subject.trim()) return toast.error('Subject required')
    setSaving(true)
    try {
      await helpdeskApi.tickets.create({ subject: subject.trim(), customer_id: Number(customerId) })
      toast.success('Ticket raised')
      setSubject(''); load()
    } catch (e) { toast.error(e.message || 'Failed to raise ticket') }
    finally { setSaving(false) }
  }

  if (!customerId) {
    return (
      <div className="card-3d" style={{ padding: '20px' }}>
        <EmptyState icon={LifeBuoy} title="No linked customer yet"
          description="Convert this lead to a customer to track its support tickets here." />
      </div>
    )
  }

  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <div className="flex items-center gap-2 mb-4">
        <input className="input-3d text-sm flex-1" placeholder="New ticket subject…"
          value={subject} onChange={e => setSubject(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && raise()} />
        <button onClick={raise} disabled={saving}
          className="px-3 py-2 rounded-xl text-sm font-bold text-white shrink-0"
          style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: saving ? 0.7 : 1 }}>
          Raise ticket
        </button>
      </div>
      {loading ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading tickets…</p>
      ) : rows.length === 0 ? (
        <EmptyState icon={LifeBuoy} title="No support tickets" description="No tickets are linked to this customer yet." />
      ) : (
        <div className="space-y-2">
          {rows.map(t => (
            <div key={t.id} className="flex items-center justify-between gap-3 px-3 py-2 rounded-xl"
              style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
              <span className="text-sm truncate" style={{ color: 'var(--text-h)' }}>#{t.id} · {t.subject}</span>
              <span className="text-[11px] font-bold px-2 py-0.5 rounded-lg shrink-0"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{t.status}</span>
            </div>
          ))}
        </div>
      )}
    </div>
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

function SummaryRow({ label, value }) {
  return (
    <div className="flex items-center justify-between">
      <span style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{value}</span>
    </div>
  )
}
