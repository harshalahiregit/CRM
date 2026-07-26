import { useState, useRef, useEffect, useMemo } from 'react'
import { Calendar as CalIcon, Clock, ChevronLeft, ChevronRight, X } from 'lucide-react'

/**
 * Modern date + time picker (SPK-1) — no external date library (the project has
 * none). Custom-built to match the HR design system: rounded, dark-mode aware
 * (CSS vars), animated, keyboard-navigable and accessible.
 *
 * Business rules enforced here (backend also rejects past dates with 422):
 *   • Every date before today is greyed out and not selectable.
 *   • When today is selected, only future times (next 15-min slot onward) show.
 *   • 15-minute intervals, 12-hour labels, keyboard input.
 *
 * Value/onChange use the same naive "YYYY-MM-DDTHH:mm" string a datetime-local
 * produces, so nothing downstream (storage, reminders, meet links) changes.
 */
const DAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const pad = n => String(n).padStart(2, '0')
const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const startOfDay = d => { const x = new Date(d); x.setHours(0, 0, 0, 0); return x }
const parse = v => { if (!v) return null; const d = new Date(v); return isNaN(d) ? null : d }

export default function DateTimePicker({ value, onChange, placeholder = 'Select date & time', minDate }) {
  const [open, setOpen] = useState(false)
  const boxRef = useRef(null)
  const sel = parse(value)
  const today = startOfDay(new Date())
  const floor = startOfDay(minDate ? new Date(minDate) : today)   // earliest allowed day
  const [view, setView] = useState(() => sel || new Date())        // month being shown

  useEffect(() => {
    const onDoc = e => { if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])
  useEffect(() => { if (sel) setView(sel) }, [value]) // eslint-disable-line

  // ── Time slots (15-min). If the chosen day is today, only future slots. ──
  const slots = useMemo(() => {
    const out = []
    const onToday = sel && startOfDay(sel).getTime() === today.getTime()
    const now = new Date()
    const minMinutes = onToday ? now.getHours() * 60 + now.getMinutes() : -1
    for (let m = 0; m < 24 * 60; m += 15) {
      if (m <= minMinutes) continue                 // block past + current slot today
      const h = Math.floor(m / 60), mm = m % 60
      const label = `${((h % 12) || 12)}:${pad(mm)} ${h < 12 ? 'AM' : 'PM'}`
      out.push({ h, mm, label, key: `${pad(h)}:${pad(mm)}` })
    }
    return out
  }, [sel && ymd(sel), value]) // eslint-disable-line

  const commit = (date, h, mm) => {
    const d = new Date(date)
    d.setHours(h, mm, 0, 0)
    onChange?.(`${ymd(d)}T${pad(h)}:${pad(mm)}`)
  }
  const pickDate = (day) => {
    // Keep the current time if valid for the new day, else default to first slot.
    const base = sel || new Date()
    let h = base.getHours(), mm = Math.floor(base.getMinutes() / 15) * 15
    const onToday = startOfDay(day).getTime() === today.getTime()
    if (onToday) {
      const now = new Date(), cur = now.getHours() * 60 + now.getMinutes()
      if (h * 60 + mm <= cur) { const next = Math.ceil((cur + 1) / 15) * 15; h = Math.floor(next / 60) % 24; mm = next % 60 }
    }
    if (!sel) { h = onToday ? h : 10; mm = onToday ? mm : 0 }
    commit(day, h, mm)
  }
  const pickTime = (s) => commit(sel || today, s.h, s.mm)
  const goToday  = () => { const t = new Date(); setView(t); pickDate(t) }
  const clear    = (e) => { e.stopPropagation(); onChange?.(''); setOpen(false) }

  // ── Month grid ──
  const first = new Date(view.getFullYear(), view.getMonth(), 1)
  const lead = first.getDay()
  const daysIn = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate()
  const cells = [...Array(lead).fill(null), ...Array.from({ length: daysIn }, (_, i) => new Date(view.getFullYear(), view.getMonth(), i + 1))]
  const years = Array.from({ length: 6 }, (_, i) => today.getFullYear() + i)

  const onKey = (e) => {
    if (!open && (e.key === 'Enter' || e.key === 'ArrowDown')) { e.preventDefault(); setOpen(true); return }
    if (!open) return
    if (e.key === 'Escape') { setOpen(false); return }
    const base = sel && sel >= floor ? new Date(sel) : new Date(Math.max(view.getTime(), floor.getTime()))
    const move = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key]
    if (move != null) {
      e.preventDefault()
      const nd = new Date(base); nd.setDate(nd.getDate() + move)
      if (startOfDay(nd) >= floor) { setView(nd); pickDate(nd) }
    }
  }

  const displayText = sel
    ? sel.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    : placeholder

  return (
    <div ref={boxRef} style={{ position: 'relative' }}>
      <div className="input-3d text-sm" role="button" tabIndex={0} aria-haspopup="dialog" aria-expanded={open}
        onClick={() => setOpen(o => !o)} onKeyDown={onKey}
        style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
        <CalIcon size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
        <span style={{ flex: 1, color: sel ? 'var(--text-h)' : 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{displayText}</span>
        {sel && <button type="button" onClick={clear} title="Clear" tabIndex={-1} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', padding: 0, display: 'flex' }}><X size={14} /></button>}
      </div>

      {open && (
        <div role="dialog" aria-label="Choose date and time"
          style={{ position: 'absolute', zIndex: 60, top: 'calc(100% + 6px)', left: 0, display: 'flex', gap: 0,
            background: 'var(--bg-card, var(--bg-input))', border: '1px solid var(--border)', borderRadius: 16,
            boxShadow: '0 16px 44px rgba(0,0,0,0.32)', overflow: 'hidden', animation: 'dtpIn .14s ease' }}>
          <style>{`@keyframes dtpIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}`}</style>

          {/* Calendar */}
          <div style={{ padding: 14, width: 268 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 10 }}>
              <button type="button" onClick={() => setView(new Date(view.getFullYear(), view.getMonth() - 1, 1))} style={navBtn} title="Previous month"><ChevronLeft size={15} /></button>
              <select value={view.getMonth()} onChange={e => setView(new Date(view.getFullYear(), +e.target.value, 1))}
                style={{ flex: 1, fontSize: 12.5, fontWeight: 700 }} className="input-3d" aria-label="Month">
                {MONTHS.map((m, i) => <option key={m} value={i}>{m}</option>)}
              </select>
              <select value={view.getFullYear()} onChange={e => setView(new Date(+e.target.value, view.getMonth(), 1))}
                style={{ width: 78, fontSize: 12.5, fontWeight: 700 }} className="input-3d" aria-label="Year">
                {years.map(y => <option key={y} value={y}>{y}</option>)}
              </select>
              <button type="button" onClick={() => setView(new Date(view.getFullYear(), view.getMonth() + 1, 1))} style={navBtn} title="Next month"><ChevronRight size={15} /></button>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 2, marginBottom: 4 }}>
              {DAYS.map(d => <div key={d} style={{ textAlign: 'center', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', padding: '2px 0' }}>{d}</div>)}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 2 }}>
              {cells.map((d, i) => {
                if (!d) return <div key={`e${i}`} />
                const disabled = startOfDay(d) < floor             // past date → greyed, not clickable
                const isSel = sel && ymd(sel) === ymd(d)
                const isToday = ymd(d) === ymd(today)
                return (
                  <button key={ymd(d)} type="button" disabled={disabled} onClick={() => pickDate(d)}
                    aria-label={d.toDateString()} aria-disabled={disabled} aria-selected={isSel}
                    style={{
                      height: 32, borderRadius: 9, fontSize: 12.5, fontWeight: isSel ? 800 : 600,
                      border: isToday && !isSel ? '1px solid rgba(124,58,237,0.5)' : '1px solid transparent',
                      background: isSel ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent',
                      color: isSel ? '#fff' : disabled ? 'var(--text-muted)' : 'var(--text-h)',
                      opacity: disabled ? 0.32 : 1, cursor: disabled ? 'not-allowed' : 'pointer', transition: 'all .12s',
                    }}
                    onMouseEnter={e => { if (!disabled && !isSel) e.currentTarget.style.background = 'rgba(124,58,237,0.12)' }}
                    onMouseLeave={e => { if (!isSel) e.currentTarget.style.background = 'transparent' }}>
                    {d.getDate()}
                  </button>
                )
              })}
            </div>

            <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
              <button type="button" onClick={goToday} style={{ ...footBtn, color: '#a78bfa' }}>Today</button>
              <button type="button" onClick={() => { onChange?.(''); }} style={{ ...footBtn, color: 'var(--text-muted)' }}>Clear</button>
            </div>
          </div>

          {/* Time column */}
          <div style={{ width: 118, borderLeft: '1px solid var(--border)', display: 'flex', flexDirection: 'column' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '12px 12px 8px', fontSize: 11, fontWeight: 800, color: 'var(--text-muted)' }}><Clock size={12} /> TIME</div>
            <div style={{ flex: 1, maxHeight: 268, overflowY: 'auto', padding: '0 8px 10px' }}>
              {!sel ? <p style={{ fontSize: 11, color: 'var(--text-muted)', padding: '6px 4px' }}>Pick a date first</p>
                : slots.length === 0 ? <p style={{ fontSize: 11, color: 'var(--text-muted)', padding: '6px 4px' }}>No future times left today</p>
                : slots.map(s => {
                  const active = sel && pad(sel.getHours()) === pad(s.h) && pad(sel.getMinutes()) === pad(s.mm)
                  return (
                    <button key={s.key} type="button" onClick={() => pickTime(s)}
                      style={{ display: 'block', width: '100%', textAlign: 'left', padding: '6px 9px', borderRadius: 8, fontSize: 12, fontWeight: active ? 800 : 600,
                        background: active ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent', color: active ? '#fff' : 'var(--text-h)', cursor: 'pointer', marginBottom: 2, border: 'none' }}
                      onMouseEnter={e => { if (!active) e.currentTarget.style.background = 'rgba(124,58,237,0.12)' }}
                      onMouseLeave={e => { if (!active) e.currentTarget.style.background = 'transparent' }}>
                      {s.label}
                    </button>
                  )
                })}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const navBtn  = { display: 'flex', alignItems: 'center', justifyContent: 'center', width: 28, height: 28, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', cursor: 'pointer', flexShrink: 0 }
const footBtn = { flex: 1, padding: '6px 0', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', fontSize: 11.5, fontWeight: 700, cursor: 'pointer' }
