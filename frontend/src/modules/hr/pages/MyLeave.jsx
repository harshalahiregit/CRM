/**
 * An employee's own leave: what is left, what has been asked for, and applying.
 *
 * The CRM's other leave screens are HR's — they file leave on somebody's behalf
 * and take an employee id. This one accepts none, matching the server, so a
 * person can only ever see and touch their own.
 *
 * The day count comes from the server's preview while dates are being picked,
 * not from arithmetic here. Weekends, holidays and half-days are policy, and a
 * client that counted them itself would eventually disagree with what the
 * employee is actually charged.
 */

import { useState, useEffect, useCallback } from 'react'
import { CalendarOff, Plus, X, Send, Paperclip, AlertTriangle } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import { openAuthedFile } from '@/lib/openAuthedFile'

const STATUS = {
  Draft:     { fg: '#94a3b8', bg: 'var(--bg-input)',           label: 'Draft' },
  Submitted: { fg: '#fbbf24', bg: 'rgba(251,191,36,0.12)',     label: 'Waiting on approval' },
  Approved:  { fg: '#34d399', bg: 'rgba(52,211,153,0.12)',     label: 'Approved' },
  Rejected:  { fg: '#f87171', bg: 'rgba(248,113,113,0.12)',    label: 'Rejected' },
  Cancelled: { fg: '#94a3b8', bg: 'var(--bg-input)',           label: 'Withdrawn' },
}

const day = d => (d ? String(d).slice(0, 10) : '—')

function Pill({ status }) {
  const s = STATUS[status] || { fg: 'var(--text-muted)', bg: 'var(--bg-input)', label: status }
  return (
    <span className="rounded-md text-[10px] font-bold uppercase tracking-wider"
      style={{ padding: '3px 8px', color: s.fg, background: s.bg }}>{s.label}</span>
  )
}

const EMPTY = { leave_type_id: '', from_date: '', to_date: '', half_day: false, reason: '' }

export default function MyLeave() {
  const toast = useToast()

  const [rows,     setRows]     = useState([])
  const [balances, setBalances] = useState([])
  const [loading,  setLoading]  = useState(true)
  const [error,    setError]    = useState(null)
  const [busy,     setBusy]     = useState(false)

  const [creating, setCreating] = useState(false)
  const [form,     setForm]     = useState(EMPTY)
  const [file,     setFile]     = useState(null)
  const [preview,  setPreview]  = useState(null)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const [list, bal] = await Promise.all([hrApi.myLeave.list(), hrApi.myLeave.balances()])
      setRows(Array.isArray(list) ? list : (list?.data ?? []))
      setBalances(Array.isArray(bal) ? bal : (bal?.data ?? []))
    } catch (e) {
      setError(e?.response?.status === 403
        ? (e?.response?.data?.message || 'Your login is not linked to an employee record. Contact HR.')
        : 'Could not load your leave.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  /**
   * Ask the server what this range costs, whenever it is complete.
   *
   * Debounced, because it fires on every keystroke of a date field, and the
   * result is thrown away if the dates changed while it was in flight — an
   * out-of-order reply would show a day count for a range nobody is looking at.
   */
  useEffect(() => {
    if (!form.from_date || !form.to_date) { setPreview(null); return }

    let cancelled = false
    const id = setTimeout(async () => {
      try {
        const p = await hrApi.myLeave.preview({
          from_date: form.from_date, to_date: form.to_date,
          leave_type_id: form.leave_type_id || undefined, half_day: form.half_day,
        })
        if (!cancelled) setPreview(p)
      } catch {
        if (!cancelled) setPreview(null)
      }
    }, 300)

    return () => { cancelled = true; clearTimeout(id) }
  }, [form.from_date, form.to_date, form.leave_type_id, form.half_day])

  const apply = async () => {
    if (!form.leave_type_id) return toast.error('Which kind of leave?')
    if (!form.from_date || !form.to_date) return toast.error('Pick both dates.')

    setBusy(true)
    try {
      await hrApi.myLeave.apply({ ...form, half_day: form.half_day ? 1 : 0 }, file)
      toast.success('Applied. It is with your approver now.')
      setCreating(false); setForm(EMPTY); setFile(null); setPreview(null)
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That could not be submitted.')
    } finally {
      setBusy(false)
    }
  }

  const withdraw = async id => {
    setBusy(true)
    try {
      await hrApi.myLeave.cancel(id)
      toast.success('Withdrawn.')
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That could not be withdrawn.')
    } finally {
      setBusy(false)
    }
  }

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const inputStyle = {
    padding: '8px 11px', background: 'var(--bg-input)',
    border: '1px solid var(--border)', color: 'var(--text-p)',
  }

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <CalendarOff size={18} /> My leave
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            What you have left, what you have asked for, and applying for more.
          </p>
        </div>
        <button onClick={() => setCreating(v => !v)}
          className="rounded-lg text-xs font-bold flex items-center gap-1.5"
          style={{ padding: '8px 13px', background: 'var(--accent)', color: '#fff' }}>
          <Plus size={14} /> Apply for leave
        </button>
      </div>

      {/* Balances first: nobody decides to take leave without knowing this. */}
      {!!balances.length && (
        <div className="grid gap-2" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))' }}>
          {balances.map(b => (
            <div key={b.id} className="rounded-xl" style={{ padding: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <p className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                {b.leave_type?.name || b.leave_type_name || 'Leave'}
              </p>
              <p className="text-lg font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                {b.available_balance ?? 0}
                <span className="text-[11px] font-normal" style={{ color: 'var(--text-muted)' }}> of {b.allocated ?? 0} days</span>
              </p>
            </div>
          ))}
        </div>
      )}

      {creating && (
        <div className="rounded-2xl flex flex-col gap-3" style={{ padding: 18, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))' }}>
            <label className="flex flex-col gap-1">
              <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Kind of leave</span>
              <select value={form.leave_type_id} onChange={e => set('leave_type_id', e.target.value)}
                className="rounded-lg text-sm w-full" style={inputStyle}>
                <option value="">
                  {balances.length ? 'Select…' : 'No leave allocated to you yet'}
                </option>
                {balances.map(b => (
                  <option key={b.leave_type_id} value={b.leave_type_id}>
                    {b.leave_type?.name || b.leave_type_name} ({b.available_balance ?? 0} left)
                  </option>
                ))}
              </select>
            </label>

            <label className="flex flex-col gap-1">
              <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>From</span>
              <input type="date" value={form.from_date} onChange={e => set('from_date', e.target.value)}
                className="rounded-lg text-sm w-full" style={inputStyle} />
            </label>

            <label className="flex flex-col gap-1">
              <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>To</span>
              <input type="date" min={form.from_date || undefined} value={form.to_date}
                onChange={e => set('to_date', e.target.value)}
                className="rounded-lg text-sm w-full" style={inputStyle} />
            </label>
          </div>

          <label className="flex items-center gap-2 self-start cursor-pointer">
            <input type="checkbox" checked={form.half_day} onChange={e => set('half_day', e.target.checked)} />
            <span className="text-xs" style={{ color: 'var(--text-p)' }}>Half day</span>
          </label>

          {/* The server's count, not one worked out here — weekends, holidays and
              half-days are policy, and two implementations would disagree. */}
          {preview && (
            <p className="text-[11px] rounded-lg" style={{ padding: '7px 9px', background: 'rgba(96,165,250,0.1)', color: '#60a5fa' }}>
              {preview.total_days ?? preview.days ?? '—'} day
              {(preview.total_days ?? preview.days) === 1 ? '' : 's'} will be deducted.
              {preview.message ? ` ${preview.message}` : ''}
            </p>
          )}

          <label className="flex flex-col gap-1">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Reason</span>
            <textarea rows={2} value={form.reason} onChange={e => set('reason', e.target.value)}
              className="rounded-lg text-sm w-full" style={inputStyle} />
          </label>

          <div className="flex flex-col gap-1.5">
            <label className="rounded-lg text-[11px] font-semibold flex items-center gap-1.5 self-start cursor-pointer"
              style={{ padding: '6px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
              <Paperclip size={12} /> {file ? file.name : 'Attach a document (optional)'}
              <input type="file" accept=".pdf,.png,.jpg,.jpeg,.webp,.heic" style={{ display: 'none' }}
                onChange={e => setFile(e.target.files?.[0] || null)} />
            </label>
            {file && (
              <button type="button" onClick={() => setFile(null)}
                className="text-[10px] self-start underline" style={{ color: 'var(--text-muted)' }}>
                Remove
              </button>
            )}
          </div>

          <div className="flex gap-2">
            <button onClick={apply} disabled={busy}
              className="rounded-lg text-xs font-bold flex items-center gap-1.5"
              style={{ padding: '9px 16px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
              <Send size={13} /> {busy ? 'Submitting…' : 'Apply'}
            </button>
            <button onClick={() => { setCreating(false); setForm(EMPTY); setFile(null) }}
              className="rounded-lg text-xs font-semibold"
              style={{ padding: '9px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
              Cancel
            </button>
          </div>
        </div>
      )}

      {loading ? <HrLoading /> : error ? (
        <div className="rounded-xl text-xs flex items-start gap-1.5"
          style={{ padding: 14, background: 'rgba(248,113,113,0.1)', color: '#f87171' }}>
          <AlertTriangle size={13} style={{ marginTop: 1, flexShrink: 0 }} /> {error}
        </div>
      ) : !rows.length ? (
        <HrEmpty icon={CalendarOff} title="No leave applied for" hint="Nothing yet. Apply above when you need to." />
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map(r => (
            <div key={r.id} className="rounded-xl flex items-center gap-3 flex-wrap"
              style={{ padding: '12px 14px', background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div className="flex-1 min-w-[180px]">
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
                  {r.leave_type?.name || r.leave_type_name || 'Leave'}
                  <span className="font-normal text-[11px] ml-1.5" style={{ color: 'var(--text-muted)' }}>
                    {day(r.from_date)}{r.to_date && r.to_date !== r.from_date ? ` → ${day(r.to_date)}` : ''}
                  </span>
                </p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  {r.total_days ?? r.days ?? '—'} day{(r.total_days ?? r.days) === 1 ? '' : 's'}
                  {r.half_day ? ' · half day' : ''}
                  {r.reason ? ` · ${String(r.reason).slice(0, 60)}` : ''}
                </p>
                {r.rejection_reason && (
                  <p className="text-[11px] mt-0.5" style={{ color: '#f87171' }}>{r.rejection_reason}</p>
                )}
              </div>

              {r.attachment_path && (
                <button onClick={() => openAuthedFile(hrApi.myLeave.fileBlob, r.id, (m, t) => toast[t === 'error' ? 'error' : 'success'](m))}
                  className="rounded-lg text-[11px] font-semibold flex items-center gap-1"
                  style={{ padding: '5px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
                  <Paperclip size={11} /> Document
                </button>
              )}

              <Pill status={r.status} />

              {/* Only while it is still yours to withdraw. */}
              {['Draft', 'Submitted'].includes(r.status) && (
                <button onClick={() => withdraw(r.id)} disabled={busy}
                  className="rounded-lg text-[11px] font-semibold flex items-center gap-1"
                  style={{ padding: '5px 10px', background: 'rgba(248,113,113,0.1)', color: '#f87171', border: '1px solid rgba(248,113,113,0.3)' }}>
                  <X size={11} /> Withdraw
                </button>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
