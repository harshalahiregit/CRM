/**
 * The company holiday calendar on SangoeTrack — now editable.
 *
 * This is the first screen here that writes company-wide reference data. An
 * approval affects one request; a wrong holiday shifts every leave calculation
 * for everyone. So deleting asks first and names what is being removed, and the
 * form says plainly that it changes the calendar for the whole company.
 *
 * SangoeTrack refuses a holiday dated before today — its own rule, kept here so
 * the refusal arrives while you are typing rather than after a round trip. Past
 * dates belong in an import, which is where backfilling a year makes sense.
 */

import { useState, useEffect, useCallback, useMemo } from 'react'
import { PartyPopper, Plus, Pencil, Trash2, ChevronLeft, ChevronRight } from 'lucide-react'
import { sangoeTrackApi, trackErrorMessage } from '@/services/sangoeTrackApi'
import { useToast } from '@/hooks/useToast'
import LoadError from '@/components/ui/LoadError'
import EmptyState from '@/components/ui/EmptyState'
import { TrackHeader, ExportButton } from './TrackShell'
import { isoDate } from './useTrackHistory'

const CSV = [
  { key: 'occasion',   label: 'Occasion' },
  { key: 'start_date', label: 'From' },
  { key: 'end_date',   label: 'To' },
  { key: 'days',       label: 'Days' },
]

const fmt = iso => {
  if (!iso) return '—'
  return new Date(iso + 'T00:00:00').toLocaleDateString('en-IN', {
    weekday: 'short', day: 'numeric', month: 'short',
  })
}

/* ── add / edit ──────────────────────────────────────────────────────── */

function HolidayForm({ editing, onSaved, onCancel }) {
  const today = isoDate(new Date())
  const [form, setForm] = useState(() => ({
    occasion:   editing?.occasion ?? '',
    start_date: editing?.start_date ?? today,
    end_date:   editing?.end_date ?? today,
  }))
  const [busy, setBusy] = useState(false)
  const toast = useToast()

  const set = (k, v) => setForm(prev => {
    const next = { ...prev, [k]: v }
    // Moving the start past the end is a typo, not a request for a negative
    // holiday — carry the end along rather than rejecting it.
    if (k === 'start_date' && next.end_date < v) next.end_date = v
    return next
  })

  // SangoeTrack's rule, checked here so the message arrives before the request.
  const inPast = form.start_date < today
  const valid  = form.occasion.trim() !== '' && form.start_date && form.end_date && !inPast

  async function save() {
    setBusy(true)
    try {
      const data = { ...form, occasion: form.occasion.trim() }
      if (editing) await sangoeTrackApi.holidays.update(editing.id, data)
      else         await sangoeTrackApi.holidays.create(data)
      toast.success(editing ? 'Holiday updated' : `${data.occasion} added to the calendar`)
      onSaved()
    } catch (err) {
      toast.error(trackErrorMessage(err, 'Could not save the holiday.'))
    } finally {
      setBusy(false)
    }
  }

  const field = {
    background: 'var(--bg-input)', border: '1px solid var(--border)',
    color: 'var(--text-h)', padding: '7px 10px', borderRadius: 8, fontSize: 13,
  }

  return (
    <div className="rounded-xl p-4 flex flex-col gap-3"
      style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>
        {editing ? `Edit “${editing.occasion}”` : 'Add a holiday'}
      </p>

      <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))' }}>
        <label className="flex flex-col gap-1" style={{ gridColumn: '1 / -1' }}>
          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Occasion</span>
          <input value={form.occasion} onChange={e => set('occasion', e.target.value)}
            maxLength={190} autoFocus placeholder="Diwali" style={field} />
        </label>

        <label className="flex flex-col gap-1">
          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>From</span>
          <input type="date" value={form.start_date} onChange={e => set('start_date', e.target.value)} style={field} />
        </label>

        <label className="flex flex-col gap-1">
          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>To</span>
          <input type="date" value={form.end_date} min={form.start_date}
            onChange={e => set('end_date', e.target.value)} style={field} />
        </label>
      </div>

      {inPast && (
        <p className="text-[11px]" style={{ color: '#fbbf24' }}>
          SangoeTrack will not accept a holiday that has already passed. Use an import to backfill
          an earlier calendar.
        </p>
      )}

      <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
        This changes the calendar for everyone, and leave calculations follow it.
      </p>

      <div className="flex gap-2">
        <button onClick={save} disabled={busy || !valid}
          className="rounded-lg text-xs font-bold disabled:opacity-40"
          style={{ padding: '7px 14px', background: '#7C3AED', color: '#fff' }}>
          {busy ? 'Saving…' : editing ? 'Save changes' : 'Add holiday'}
        </button>
        <button onClick={onCancel} disabled={busy}
          className="rounded-lg text-xs font-semibold disabled:opacity-50"
          style={{ padding: '7px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          Cancel
        </button>
      </div>
    </div>
  )
}

/* ── page ────────────────────────────────────────────────────────────── */

export default function TrackHolidays() {
  const thisYear = new Date().getFullYear()
  const [year, setYear]       = useState(thisYear)
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)
  const [adding, setAdding]   = useState(false)
  const [editing, setEditing] = useState(null)
  const [confirming, setConfirming] = useState(null)
  const toast = useToast()

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await sangoeTrackApi.holidays.list(year)
      setRows(res?.data?.rows ?? [])
    } catch (err) {
      setError(err)
      setRows([])
    } finally {
      setLoading(false)
    }
  }, [year])

  useEffect(() => { load() }, [load])

  async function remove(h) {
    try {
      await sangoeTrackApi.holidays.remove(h.id)
      toast.success(`Removed ${h.occasion}`)
      setConfirming(null)
      load()
    } catch (err) {
      toast.error(trackErrorMessage(err, 'Could not remove the holiday.'))
    }
  }

  // Soonest first, and anything already passed sinks below — "when is the next
  // holiday" is the question this screen exists to answer.
  const { upcoming, past } = useMemo(() => {
    const today = isoDate(new Date())
    return {
      upcoming: rows.filter(h => h.end_date >= today),
      past:     rows.filter(h => h.end_date < today).reverse(),
    }
  }, [rows])

  const Row = ({ h, dim }) => (
    <div className="flex flex-wrap items-center justify-between gap-2 px-3.5 py-3"
      style={{ borderTop: '1px solid var(--border)', opacity: dim ? 0.6 : 1 }}>
      <div style={{ minWidth: 0 }}>
        <div className="font-semibold text-sm" style={{ color: 'var(--text-h)' }}>{h.occasion}</div>
        <div className="text-xs" style={{ color: 'var(--text-muted)' }}>
          {fmt(h.start_date)}
          {h.days > 1 && ` – ${fmt(h.end_date)} · ${h.days} days`}
        </div>
      </div>
      <div className="flex items-center gap-1.5">
        <button onClick={() => { setEditing(h); setAdding(false) }} title={`Edit ${h.occasion}`}
          className="rounded-lg" style={{ padding: '6px 8px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
          <Pencil size={13} />
        </button>
        <button onClick={() => setConfirming(h)} title={`Remove ${h.occasion}`}
          className="rounded-lg" style={{ padding: '6px 8px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#f87171' }}>
          <Trash2 size={13} />
        </button>
      </div>
    </div>
  )

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5" style={{ maxWidth: 820 }}>
      <TrackHeader
        title="Holidays"
        subtitle={upcoming.length
          ? `Next up: ${upcoming[0].occasion}, ${fmt(upcoming[0].start_date)}.`
          : 'The company holiday calendar on SangoeTrack.'}
        onRefresh={load}
        loading={loading}
      />

      <div className="flex flex-wrap items-center gap-2">
        <button onClick={() => setYear(y => y - 1)} title="Previous year"
          className="rounded-lg flex items-center justify-center"
          style={{ width: 30, height: 30, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <ChevronLeft size={14} />
        </button>
        <span className="text-sm font-bold px-1" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{year}</span>
        <button onClick={() => setYear(y => y + 1)} title="Next year"
          className="rounded-lg flex items-center justify-center"
          style={{ width: 30, height: 30, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <ChevronRight size={14} />
        </button>
        {year !== thisYear && (
          <button onClick={() => setYear(thisYear)}
            className="rounded-lg text-xs font-semibold"
            style={{ padding: '6px 12px', background: 'transparent', border: '1px solid var(--border)', color: '#a78bfa' }}>
            This year
          </button>
        )}

        <div className="flex-1" />

        <ExportButton filename={`holidays-${year}`} rows={rows} columns={CSV} />

        {!adding && !editing && (
          <button onClick={() => setAdding(true)}
            className="rounded-lg text-xs font-bold flex items-center gap-1.5"
            style={{ padding: '8px 14px', background: '#7C3AED', color: '#fff' }}>
            <Plus size={13} /> Add holiday
          </button>
        )}
      </div>

      {(adding || editing) && (
        <HolidayForm
          editing={editing}
          onSaved={() => { setAdding(false); setEditing(null); load() }}
          onCancel={() => { setAdding(false); setEditing(null) }}
        />
      )}

      {error ? (
        <LoadError error={error} onRetry={load} title="Could not load holidays" />
      ) : loading ? (
        <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : rows.length === 0 ? (
        <EmptyState icon={PartyPopper}
          title={`No holidays on the ${year} calendar`}
          description="Add them here, and they apply to everyone on SangoeTrack." />
      ) : (
        <div className="flex flex-col gap-5">
          {upcoming.length > 0 && (
            <section className="flex flex-col gap-2">
              <h2 className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                Coming up
              </h2>
              <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                {upcoming.map(h => <Row key={h.id} h={h} />)}
              </div>
            </section>
          )}

          {past.length > 0 && (
            <section className="flex flex-col gap-2">
              <h2 className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                Already passed
              </h2>
              <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                {past.map(h => <Row key={h.id} h={h} dim />)}
              </div>
            </section>
          )}
        </div>
      )}

      {/* Deleting reference data everyone depends on gets a confirmation that
          names what is going, not a generic "are you sure". */}
      {confirming && (
        <div role="dialog" aria-modal="true" aria-label="Confirm removal"
          onClick={() => setConfirming(null)}
          className="fixed inset-0 z-50 flex items-center justify-center p-6"
          style={{ background: 'rgba(0,0,0,0.65)' }}>
          <div onClick={e => e.stopPropagation()}
            className="rounded-xl p-5 flex flex-col gap-3"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', width: 'min(420px, 100%)' }}>
            <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>
              Remove “{confirming.occasion}”?
            </p>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {fmt(confirming.start_date)}
              {confirming.days > 1 && ` – ${fmt(confirming.end_date)}`}
              . It disappears from the calendar for everyone, and any leave counted around it
              will be recalculated as a working day.
            </p>
            <div className="flex gap-2">
              <button onClick={() => remove(confirming)}
                className="rounded-lg text-xs font-bold"
                style={{ padding: '7px 14px', background: '#dc2626', color: '#fff' }}>
                Remove it
              </button>
              <button onClick={() => setConfirming(null)}
                className="rounded-lg text-xs font-semibold"
                style={{ padding: '7px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                Keep it
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
