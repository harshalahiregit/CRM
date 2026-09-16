import { useEffect, useMemo, useRef, useState } from 'react'
import { Search, CheckSquare, Square, X, ChevronDown, FileEdit } from 'lucide-react'
import { S } from './MedicalBits'

/**
 * Choosing who to examine — one list, whoever they are.
 *
 * Before this the same people were offered twice on the same screen: a dropdown
 * to pick a worker, and a separate list showing them again with their medical
 * standing. Two menus of the same thing, only one of which could actually be
 * used to choose, and neither searchable once a vendor had more than a screenful
 * of workers.
 *
 * This is one list, and it does the three things that make it usable on a site:
 *
 *  - a search box, because a vendor with two hundred workers is normal;
 *  - a tick box against every name, because a doctor arriving for a session
 *    examines a group, not one person;
 *  - select-all at the top, for the common case of "this vendor's whole crew
 *    today", with a count so a mis-tap is visible before it matters.
 *
 * Selecting several builds a QUEUE rather than examining them at once — an
 * examination is per person by definition. The form works through the queue and
 * moves on as each is saved, which is the actual shape of a session.
 *
 * Deliberately audience-agnostic. Vendor workers arrive with a vendor above
 * them; internal staff, clients and visitors do not. The caller resolves that
 * and hands over a flat list either way, so this stays one component instead of
 * two that drift.
 */
export default function SubjectPicker({
  people,
  loading,
  selected,
  onSelectedChange,
  onOpen,
  search,
  onSearchChange,
  emptyHint,
  header,
  meta,
  // Who has an examination part-typed and unsaved. Shown on the row so a
  // doctor returns to their own unfinished work instead of starting it again.
  draftIds = [],
  openLabel = 'Open',
}) {
  const [localSearch, setLocalSearch] = useState(search ?? '')
  const debounce = useRef(null)

  // Typing is local and instant; the server hears about it once the doctor
  // stops. A request per keystroke on a 200-name list is a stuttering list.
  useEffect(() => {
    if (!onSearchChange) return
    clearTimeout(debounce.current)
    debounce.current = setTimeout(() => onSearchChange(localSearch), 250)
    return () => clearTimeout(debounce.current)
  }, [localSearch]) // eslint-disable-line react-hooks/exhaustive-deps

  // Filtered here as well as on the server: the list is already in the browser,
  // so narrowing it should not wait for a round trip.
  const shown = useMemo(() => {
    const q = localSearch.trim().toLowerCase()
    if (!q) return people
    return people.filter(p => [p.name, p.worker_code, p.email, p.phone, p.context]
      .some(v => String(v ?? '').toLowerCase().includes(q)))
  }, [people, localSearch])

  const ids = shown.map(p => p.id)
  const allShownSelected = ids.length > 0 && ids.every(id => selected.includes(id))

  const toggle = (id) => onSelectedChange(
    selected.includes(id) ? selected.filter(x => x !== id) : [...selected, id],
  )

  /** Select-all applies to what is ON SCREEN, which is what the word means when a filter is active. */
  const toggleAll = () => onSelectedChange(
    allShownSelected ? selected.filter(id => !ids.includes(id)) : [...new Set([...selected, ...ids])],
  )

  return (
    <div className="pr-glass" style={{ padding: 14, display: 'flex', flexDirection: 'column', minHeight: 0 }}>
      {header}

      <div style={{ position: 'relative', marginTop: header ? 10 : 0 }}>
        <Search size={15} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
        <input
          value={localSearch}
          onChange={e => setLocalSearch(e.target.value)}
          placeholder="Search by name, code, phone…"
          style={{ ...S.input, paddingLeft: 34, paddingRight: localSearch ? 34 : 12, minHeight: 44 }}
        />
        {localSearch && (
          <button type="button" onClick={() => setLocalSearch('')} aria-label="Clear search"
            style={{ position: 'absolute', right: 6, top: '50%', transform: 'translateY(-50%)', width: 28, height: 28, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }}>
            <X size={14} />
          </button>
        )}
      </div>

      {/* Select-all, with the count beside it so a mis-tap is visible. */}
      <div style={{
        display: 'flex', alignItems: 'center', gap: 9, marginTop: 10, padding: '9px 10px',
        borderRadius: 11, background: 'var(--bg-input)', border: '1px solid var(--border)', minHeight: 44,
      }}>
        <button type="button" onClick={toggleAll} disabled={!ids.length}
          style={{ display: 'flex', alignItems: 'center', gap: 8, border: 'none', background: 'transparent', cursor: ids.length ? 'pointer' : 'default', color: allShownSelected ? '#a78bfa' : 'var(--text-muted)', fontSize: 12.5, fontWeight: 800, padding: 0 }}>
          {allShownSelected ? <CheckSquare size={17} /> : <Square size={17} />}
          Select all{localSearch ? ' shown' : ''}
        </button>
        <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)' }}>
          {selected.length > 0
            ? <strong style={{ color: '#a78bfa' }}>{selected.length} selected</strong>
            : meta?.truncated
              // Said, rather than letting a capped list look like the whole list.
              ? <>{shown.length} of <strong style={{ color: 'var(--text-h)' }}>{meta.total.toLocaleString()}</strong></>
              : `${shown.length} ${shown.length === 1 ? 'person' : 'people'}`}
        </span>
        {selected.length > 0 && (
          <button type="button" onClick={() => onSelectedChange([])}
            style={{ ...S.btn, padding: '4px 9px', fontSize: 11, minHeight: 30 }}>Clear</button>
        )}
      </div>

      <div style={{ marginTop: 8, overflowY: 'auto', flex: 1, minHeight: 0, maxHeight: '46vh' }}>
        {loading ? (
          <Hint>Loading…</Hint>
        ) : shown.length === 0 ? (
          <Hint>{localSearch ? `Nobody matches “${localSearch}”.` : (emptyHint || 'Nobody to show.')}</Hint>
        ) : shown.map(p => {
          const isSelected = selected.includes(p.id)
          const hasDraft = draftIds.some(id => String(id) === String(p.id))
          const tone = clearanceTone(p.clearance?.state)

          return (
            <div key={p.id}
              style={{
                display: 'flex', alignItems: 'center', gap: 10, padding: '9px 10px', marginBottom: 5,
                borderRadius: 11, minHeight: 52, cursor: 'pointer',
                background: isSelected ? 'rgba(124,58,237,0.14)' : 'var(--bg-input)',
                border: `1px solid ${isSelected ? 'rgba(124,58,237,0.45)' : 'var(--border)'}`,
              }}
              onClick={() => toggle(p.id)}
            >
              <span style={{ color: isSelected ? '#a78bfa' : 'var(--text-muted)', display: 'flex', flexShrink: 0 }}>
                {isSelected ? <CheckSquare size={18} /> : <Square size={18} />}
              </span>

              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {p.name}
                </div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {[p.worker_code, p.context, p.designation, p.phone].filter(Boolean).join(' · ') || '—'}
                </div>
              </div>

              {/* Unsaved work, said on the row — otherwise the only way to find
                  it is to open the person and be surprised. */}
              {hasDraft && (
                <span title="You have an unfinished examination for this person"
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 9.5, fontWeight: 800, padding: '3px 7px', borderRadius: 6, background: '#10b9811f', color: '#10b981', flexShrink: 0 }}>
                  <FileEdit size={10} /> DRAFT
                </span>
              )}

              {p.clearance && (
                <span style={{ fontSize: 9.5, fontWeight: 800, padding: '3px 7px', borderRadius: 6, background: `${tone}1f`, color: tone, flexShrink: 0 }}>
                  {clearanceWord(p.clearance.state)}
                </span>
              )}

              {/* Open one directly without disturbing a selection already made. */}
              <button type="button" aria-label={`${openLabel} ${p.name}`}
                onClick={e => { e.stopPropagation(); onOpen(p.id) }}
                style={{ ...S.btn, padding: '5px 9px', fontSize: 11, minHeight: 34, flexShrink: 0 }}>
                {openLabel} <ChevronDown size={12} style={{ transform: 'rotate(-90deg)' }} />
              </button>
            </div>
          )
        })}
      </div>

      {meta?.truncated && selected.length === 0 && (
        <p style={{ margin: '8px 0 0', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
          Showing the first {meta.showing} of {meta.total.toLocaleString()}. Type a name or code above to find
          somebody who is not on this page.
        </p>
      )}

    </div>
  )
}

const Hint = ({ children }) => (
  <p style={{ fontSize: 12.5, color: 'var(--text-muted)', padding: '14px 4px', margin: 0 }}>{children}</p>
)

const clearanceTone = (state) => ({
  cleared: '#10b981', pending: '#f59e0b', hold: '#f59e0b',
  rejected: '#ef4444', unfit: '#ef4444', expired: '#f97316',
}[state] || '#6b7280')

const clearanceWord = (state) => ({
  cleared: 'CLEAR', pending: 'PENDING', hold: 'HOLD',
  rejected: 'REJECTED', unfit: 'UNFIT', expired: 'EXPIRED',
}[state] || 'NONE')
