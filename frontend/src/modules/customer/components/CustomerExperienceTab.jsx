import { useState, useEffect } from 'react'
import { Star, Gauge, Plus, X } from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'
import ConfirmIconButton from './ConfirmIconButton'

/**
 * §10 — Customer Experience (CSAT and NPS).
 *
 * The two are shown side by side but never blended into one headline number.
 * CSAT is a mean out of five; NPS is promoters minus detractors and runs from
 * -100 to +100. A single "satisfaction score" over both would be arithmetic
 * nobody could defend, so each keeps its own scale and its own wording.
 */
const NPS_TONE = { Excellent: '#10b981', Good: '#3b82f6', Poor: '#f59e0b', Critical: '#ef4444' }

const dt = s => (s ? new Date(String(s).replace(' ', 'T')).toLocaleDateString('en-IN',
  { day: '2-digit', month: 'short', year: 'numeric' }) : '—')

function Metric({ icon: Icon, label, value, suffix, sub, tone, provisional }) {
  return (
    <div className="card-3d" style={{ padding: 18 }}>
      <div className="flex items-center gap-2 mb-2">
        <Icon size={14} style={{ color: tone || 'var(--accent)' }} />
        <span className="label-caps" style={{ color: 'var(--text-muted)' }}>{label}</span>
      </div>
      <div className="flex items-baseline gap-1.5">
        <span style={{ fontSize: 30, fontWeight: 900, lineHeight: 1, color: tone || 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
          {value}
        </span>
        {suffix && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{suffix}</span>}
      </div>
      {sub && <p className="text-[11px]" style={{ color: 'var(--text-muted)', margin: '6px 0 0' }}>{sub}</p>}
      {provisional && (
        <p className="text-[11px]" style={{ color: '#f59e0b', margin: '4px 0 0' }}>
          Too few responses to rely on yet.
        </p>
      )}
    </div>
  )
}

export default function CustomerExperienceTab({ id, contacts = [] }) {
  const toast = useToast()
  const [data, setData] = useState(null)
  const [adding, setAdding] = useState(false)
  const [saving, setSaving] = useState(false)
  const blank = { metric: 'CSAT', score: '', comments: '', client_contact_id: '', collected_via: 'staff', responded_at: '' }
  const [form, setForm] = useState(blank)

  const load = () => customerApi.feedback.list(id).then(setData).catch(e => toast.error(e.message))
  useEffect(() => { load() }, [id])

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))
  // The score's ceiling depends on which metric is being recorded — 5 for CSAT,
  // 10 for NPS. The server enforces the same bound.
  const max = form.metric === 'CSAT' ? 5 : 10

  const openNew = () => {
    const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
    setForm({ ...blank, responded_at: d.toISOString().slice(0, 16) })
    setAdding(true)
  }

  const save = async () => {
    if (form.score === '') return toast.error('A score is required')
    setSaving(true)
    try {
      await customerApi.feedback.create(id, { ...form, client_contact_id: form.client_contact_id || null })
      toast.success('Response recorded')
      setAdding(false); load()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const del = async (r) => {
    try { await customerApi.feedback.remove(id, r.id); toast.success('Deleted'); load() }
    catch (e) { toast.error(e.message) }
  }

  const s = data?.summary
  const rows = data?.rows ?? null

  return (
    <div className="space-y-4">
      <div className="grid md:grid-cols-2 gap-3">
        <Metric icon={Star} label="CSAT"
          value={s?.csat ? s.csat.average : '—'}
          suffix={s?.csat ? `/ ${s.csat.out_of}` : null}
          sub={s?.csat ? `${s.csat.satisfied}% answered 4 or 5 · ${s.csat.responses} responses` : 'No responses yet'}
          provisional={s?.csat?.provisional} />
        <Metric icon={Gauge} label="NPS"
          value={s?.nps ? (s.nps.score > 0 ? `+${s.nps.score}` : s.nps.score) : '—'}
          sub={s?.nps
            ? `${s.nps.promoters} promoters · ${s.nps.passives} passive · ${s.nps.detractors} detractors`
            : 'No responses yet'}
          tone={s?.nps ? NPS_TONE[s.nps.band] : null}
          provisional={s?.nps?.provisional} />
      </div>

      {!adding && (
        <div className="flex justify-end">
          <button onClick={openNew} className="flex items-center gap-1 px-4 py-2 rounded-xl text-xs font-bold text-white"
            style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            <Plus size={13} /> Record Response
          </button>
        </div>
      )}

      {adding && (
        <div className="card-3d" style={{ padding: 18 }}>
          <div className="flex items-center justify-between mb-4">
            <p className="label-caps" style={{ color: 'var(--accent)' }}>Record Response</p>
            <button onClick={() => setAdding(false)} className="p-1 rounded-lg hover:bg-[rgba(239,68,68,0.08)]">
              <X size={15} style={{ color: 'var(--text-muted)' }} />
            </button>
          </div>
          <div className="grid md:grid-cols-2 gap-4">
            <div>
              <label className="label">Metric *</label>
              <select className="input-3d text-sm" value={form.metric}
                onChange={e => setForm(p => ({ ...p, metric: e.target.value, score: '' }))}>
                <option value="CSAT">CSAT — satisfaction with one interaction (0-5)</option>
                <option value="NPS">NPS — would they recommend us (0-10)</option>
              </select>
            </div>
            <div>
              <label className="label">Score * (0–{max})</label>
              <input type="number" min={0} max={max} className="input-3d text-sm" value={form.score}
                onChange={e => sf('score', e.target.value)} />
            </div>
            <div>
              <label className="label">Contact</label>
              <select className="input-3d text-sm" value={form.client_contact_id} onChange={e => sf('client_contact_id', e.target.value)}>
                <option value="">Not attributed</option>
                {contacts.map(c => (
                  <option key={c.id} value={c.id}>{[c.first_name, c.last_name].filter(Boolean).join(' ') || c.email}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="label">Collected via</label>
              <select className="input-3d text-sm" value={form.collected_via} onChange={e => sf('collected_via', e.target.value)}>
                <option value="staff">Staff recorded it</option>
                <option value="portal">Customer portal</option>
                <option value="email">Email survey</option>
              </select>
            </div>
            <div>
              <label className="label">Responded at *</label>
              <input type="datetime-local" className="input-3d text-sm" value={form.responded_at}
                onChange={e => sf('responded_at', e.target.value)} />
            </div>
            <div className="md:col-span-2">
              <label className="label">Comments</label>
              <textarea rows={2} className="input-3d text-sm resize-none" value={form.comments}
                onChange={e => sf('comments', e.target.value)} />
            </div>
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <button onClick={() => setAdding(false)} className="px-4 py-2 rounded-xl text-sm font-semibold"
              style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button onClick={save} disabled={saving} className="px-5 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-60"
              style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{saving ? 'Saving…' : 'Save'}</button>
          </div>
        </div>
      )}

      <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              {['Responded', 'Metric', 'Score', 'Via', 'Comments'].map(h =>
                <th key={h} className="py-3 px-4 text-left label-caps whitespace-nowrap">{h}</th>)}
              <th className="py-3 px-4" />
            </tr></thead>
            <tbody>
              {rows === null ? (
                <tr><td colSpan={6} className="p-3"><div className="skeleton h-8 rounded-lg" style={{ background: 'var(--border)' }} /></td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={6} className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No responses yet.</td></tr>
              ) : rows.map(r => (
                <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{dt(r.responded_at)}</td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-h)', fontWeight: 700 }}>{r.metric}</td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>
                    {r.score} / {r.metric === 'CSAT' ? 5 : 10}
                  </td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{r.collected_via}</td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{r.comments || '—'}</td>
                  <td className="py-3 px-4">
                    <div className="flex justify-end">
                      <ConfirmIconButton onConfirm={() => del(r)} title="Delete?" message="This response will be permanently removed." />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
