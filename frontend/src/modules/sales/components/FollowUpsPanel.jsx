import { useState, useEffect, useCallback } from 'react'
import { Plus, Phone, Users, Mail, MessageCircle, MessageSquare, MapPin, Check, Trash2 } from 'lucide-react'
import { followUpApi } from '@/services/followUpApi'
import { useToast } from '@/hooks/useToast'
import ConfirmIconButton from '@/modules/customer/components/ConfirmIconButton'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { richHtml } from '@/lib/richText'

const TYPE_ICON = { call: Phone, meeting: Users, email: Mail, whatsapp: MessageCircle, sms: MessageSquare, visit: MapPin }
const TYPES = ['call', 'meeting', 'email', 'whatsapp', 'sms', 'visit']
const fmtDT = s => s ? new Date(s).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'
const BLANK = { type: 'call', title: '', due_at: '', priority: 'medium', notes: '' }

/**
 * Reusable follow-ups panel for any subject (lead/customer/invoice/…).
 *   <FollowUpsPanel subjectType="lead" subjectId={lead.id} />
 */
export default function FollowUpsPanel({ subjectType, subjectId }) {
  const toast = useToast()
  const [rows, setRows] = useState(null)
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState(BLANK)
  const [completing, setCompleting] = useState(null) // reminder being completed
  const [outcome, setOutcome] = useState('')
  const [next, setNext] = useState('')
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const load = useCallback(() => {
    if (!subjectType || !subjectId) return
    followUpApi.forSubject(subjectType, subjectId).then(setRows).catch(e => toast.error(e.message))
  }, [subjectType, subjectId])
  useEffect(() => { load() }, [load])

  const save = async () => {
    if (!form.title.trim() || !form.due_at) return toast.error('Title & due date required')
    try {
      await followUpApi.create({ ...form, remindable_type: subjectType, remindable_id: subjectId })
      toast.success('Follow-up logged'); setForm(BLANK); setAdding(false); load()
    } catch (e) { toast.error(e.message) }
  }

  const doComplete = async (id) => {
    try {
      await followUpApi.complete(id, { outcome: outcome || undefined, next_follow_up: next || undefined })
      toast.success('Marked done'); setCompleting(null); setOutcome(''); setNext(''); load()
    } catch (e) { toast.error(e.message) }
  }

  const del = async (id) => { try { await followUpApi.delete(id); toast.success('Deleted'); load() } catch (e) { toast.error(e.message) } }

  return (
    <div className="card-3d">
      <div className="flex items-center justify-between mb-3">
        <p className="label-caps">Follow-ups</p>
        <button onClick={() => setAdding(a => !a)} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--accent)' }}>
          <Plus size={13} /> Log follow-up
        </button>
      </div>

      {adding && (
        <div className="rounded-xl p-3 mb-3 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <div className="grid grid-cols-2 gap-2">
            <select className="input-3d text-sm" value={form.type} onChange={e => sf('type', e.target.value)}>{TYPES.map(t => <option key={t} value={t}>{t[0].toUpperCase() + t.slice(1)}</option>)}</select>
            <select className="input-3d text-sm" value={form.priority} onChange={e => sf('priority', e.target.value)}><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select>
          </div>
          <input className="input-3d text-sm" placeholder="Title, e.g. Call about renewal" value={form.title} onChange={e => sf('title', e.target.value)} />
          <input type="datetime-local" className="input-3d text-sm" value={form.due_at} onChange={e => sf('due_at', e.target.value)} />
          <RichTextEditor value={form.notes} onChange={v => sf('notes', v)} placeholder="Notes…" minHeight={80} />
          <div className="flex justify-end gap-2">
            <button onClick={() => setAdding(false)} className="px-3 py-1.5 rounded-lg text-xs font-semibold" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button onClick={save} className="px-4 py-1.5 rounded-lg text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Save</button>
          </div>
        </div>
      )}

      <div className="space-y-2">
        {rows === null ? <p className="text-xs text-center py-4" style={{ color: 'var(--text-muted)' }}>Loading…</p>
          : rows.length === 0 ? <p className="text-xs text-center py-6" style={{ color: 'var(--text-muted)' }}>No follow-ups yet.</p>
          : rows.map(r => {
            const Icon = TYPE_ICON[r.type] || Phone
            const done = !!r.completed_at
            return (
              <div key={r.id} className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', opacity: done ? 0.65 : 1 }}>
                <div className="flex items-start gap-2">
                  <div className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style={{ background: 'rgba(124,58,237,0.12)' }}><Icon size={13} style={{ color: '#a78bfa' }} /></div>
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-bold" style={{ color: 'var(--text-h)', textDecoration: done ? 'line-through' : 'none' }}>{r.title}</p>
                    <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{fmtDT(r.due_at)} · {r.type}{r.priority === 'high' ? ' · High' : ''}</p>
                    {/* The note was captured on create but never rendered back, so
                        whatever was typed here was effectively write-only. */}
                    {r.notes && <div className="rich-content text-[11px] mt-1" style={{ color: 'var(--text-muted)' }} dangerouslySetInnerHTML={richHtml(r.notes)} />}
                    {r.outcome && <p className="text-[11px] mt-1" style={{ color: 'var(--text-body)' }}>↳ {r.outcome}</p>}
                  </div>
                  <div className="flex gap-1 items-center">
                    {!done && <button onClick={() => setCompleting(completing === r.id ? null : r.id)} title="Mark done"><Check size={14} style={{ color: '#10b981' }} /></button>}
                    <ConfirmIconButton onConfirm={() => del(r.id)} title="Delete follow-up?" message="This follow-up will be removed." />
                  </div>
                </div>
                {completing === r.id && (
                  <div className="mt-2 pt-2 space-y-2" style={{ borderTop: '1px solid var(--border)' }}>
                    <input className="input-3d text-sm" placeholder="Outcome (optional)" value={outcome} onChange={e => setOutcome(e.target.value)} />
                    <div className="flex items-center gap-2">
                      <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Next:</span>
                      <input type="datetime-local" className="input-3d text-sm flex-1" value={next} onChange={e => setNext(e.target.value)} />
                      <button onClick={() => doComplete(r.id)} className="px-3 py-1.5 rounded-lg text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>Done</button>
                    </div>
                  </div>
                )}
              </div>
            )
          })}
      </div>
    </div>
  )
}
