/**
 * Demo enquiries captured by SangoeTrack's public site.
 *
 * These are sales leads living inside an HR system — an oddity of how
 * SangoeTrack grew, not a considered design. They are surfaced here because the
 * pipeline runs there today and splitting it would mean two half-lists. If lead
 * management ever moves properly into the CRM, this screen is the thing to
 * retire, not to extend.
 *
 * SangoeTrack stores the first stage as 'new'; it reads as "Received" to anyone
 * looking at a pipeline, so that is what is shown.
 */

import { useState, useEffect, useCallback, useMemo } from 'react'
import { MessageSquare, Phone, Mail, Copy, Check, Building2 } from 'lucide-react'
import { sangoeTrackApi, trackErrorMessage } from '@/services/sangoeTrackApi'
import { useToast } from '@/hooks/useToast'
import LoadError from '@/components/ui/LoadError'
import EmptyState from '@/components/ui/EmptyState'
import { TrackHeader } from './TrackShell'

const STAGES = [
  { value: 'new',       label: 'Received',  fg: '#fbbf24' },
  { value: 'contacted', label: 'Contacted', fg: '#60a5fa' },
  { value: 'converted', label: 'Converted', fg: '#34d399' },
  { value: 'closed',    label: 'Closed',    fg: 'var(--text-muted)' },
]

const stageOf = v => STAGES.find(s => s.value === v) ?? STAGES[0]

function CopyableLine({ icon: Icon, value, href }) {
  const [copied, setCopied] = useState(false)
  if (!value) return null
  return (
    <div className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
      <Icon size={12} style={{ flexShrink: 0 }} />
      <a href={href} className="hover:underline" style={{ color: 'var(--text-h)', wordBreak: 'break-all' }}>{value}</a>
      <button
        onClick={() => { navigator.clipboard?.writeText(value); setCopied(true); setTimeout(() => setCopied(false), 1500) }}
        title={`Copy ${value}`} className="flex-shrink-0">
        {copied ? <Check size={11} style={{ color: '#34d399' }} /> : <Copy size={11} />}
      </button>
    </div>
  )
}

function LeadCard({ lead, onSaved }) {
  const [stage, setStage] = useState(lead.status ?? 'new')
  const [notes, setNotes] = useState(lead.notes ?? '')
  const [busy, setBusy]   = useState(false)
  const toast = useToast()

  const dirty = stage !== (lead.status ?? 'new') || notes !== (lead.notes ?? '')
  const s = stageOf(stage)

  async function save() {
    setBusy(true)
    try {
      await sangoeTrackApi.demoRequests.update(lead.id, stage, notes.trim() || null)
      toast.success('Lead updated')
      onSaved()
    } catch (err) {
      toast.error(trackErrorMessage(err, 'Could not update this lead.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="rounded-xl p-4 flex flex-col gap-3"
      style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>

      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <div className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{lead.company_name}</div>
          <div className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {lead.name}
            {lead.num_employees > 0 && ` · ${lead.num_employees} employees`}
          </div>
        </div>
        <span className="rounded-md text-[11px] font-bold px-2 py-1"
          style={{ color: s.fg, background: 'var(--bg-input)' }}>
          {s.label}
        </span>
      </div>

      <div className="flex flex-col gap-1">
        <CopyableLine icon={Phone} value={lead.phone} href={`tel:${lead.phone}`} />
        <CopyableLine icon={Mail}  value={lead.email} href={`mailto:${lead.email}`} />
        {lead.address && (
          <div className="flex items-start gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
            <Building2 size={12} style={{ flexShrink: 0, marginTop: 2 }} />
            <span>{lead.address}</span>
          </div>
        )}
      </div>

      <div className="flex flex-wrap gap-1.5">
        {STAGES.map(st => (
          <button key={st.value} onClick={() => setStage(st.value)} aria-pressed={stage === st.value}
            className="rounded-lg text-[11px] font-semibold"
            style={{
              padding: '5px 11px',
              background: stage === st.value ? 'var(--bg-input)' : 'transparent',
              border: `1px solid ${stage === st.value ? st.fg : 'var(--border)'}`,
              color: stage === st.value ? st.fg : 'var(--text-muted)',
            }}>
            {st.label}
          </button>
        ))}
      </div>

      <textarea
        value={notes} onChange={e => setNotes(e.target.value)}
        rows={2} maxLength={2000}
        placeholder="Notes — what was said, what happens next"
        className="rounded-lg text-sm px-2.5 py-2 resize-y"
        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
      />

      <div className="flex items-center gap-2">
        <button onClick={save} disabled={busy || !dirty}
          className="rounded-lg text-xs font-bold disabled:opacity-40"
          style={{ padding: '7px 14px', background: '#7C3AED', color: '#fff' }}>
          {busy ? 'Saving…' : 'Save changes'}
        </button>
        {lead.created_at && (
          <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            Received {String(lead.created_at).slice(0, 10)}
          </span>
        )}
      </div>
    </div>
  )
}

export default function TrackDemoRequests() {
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)
  const [filter, setFilter]   = useState('all')

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await sangoeTrackApi.demoRequests.list()
      setRows(Array.isArray(res?.data) ? res.data : [])
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const counts = useMemo(() => {
    const c = { all: rows.length }
    STAGES.forEach(s => { c[s.value] = rows.filter(r => (r.status ?? 'new') === s.value).length })
    return c
  }, [rows])

  const visible = useMemo(
    () => (filter === 'all' ? rows : rows.filter(r => (r.status ?? 'new') === filter)),
    [rows, filter]
  )

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <TrackHeader
        title="Demo Requests"
        subtitle="Enquiries from SangoeTrack's public site."
        onRefresh={load}
        loading={loading}
      />

      <div className="flex flex-wrap gap-1.5">
        {[{ value: 'all', label: 'All', fg: 'var(--text-h)' }, ...STAGES].map(s => (
          <button key={s.value} onClick={() => setFilter(s.value)} aria-pressed={filter === s.value}
            className="rounded-lg text-xs font-semibold flex items-center gap-1.5"
            style={{
              padding: '7px 12px',
              background: filter === s.value ? 'var(--bg-input)' : 'transparent',
              border: `1px solid ${filter === s.value ? (s.fg === 'var(--text-h)' ? '#7C3AED' : s.fg) : 'var(--border)'}`,
              color: filter === s.value ? s.fg : 'var(--text-muted)',
            }}>
            {s.label}
            <span style={{ fontVariantNumeric: 'tabular-nums', opacity: 0.75 }}>{counts[s.value] ?? 0}</span>
          </button>
        ))}
      </div>

      {error ? (
        <LoadError error={error} onRetry={load} title="Could not load enquiries" />
      ) : loading ? (
        <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : visible.length === 0 ? (
        <EmptyState icon={MessageSquare}
          title={filter === 'all' ? 'No enquiries yet' : `Nothing at ${stageOf(filter).label.toLowerCase()}`}
          description={filter === 'all'
            ? 'Requests from the SangoeTrack site will land here.'
            : 'Try another stage.'} />
      ) : (
        <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(330px, 1fr))' }}>
          {visible.map(lead => <LeadCard key={lead.id} lead={lead} onSaved={load} />)}
        </div>
      )}
    </div>
  )
}
