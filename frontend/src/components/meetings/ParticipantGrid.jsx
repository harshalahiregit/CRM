import { useEffect, useMemo, useState } from 'react'
import { Plus, Search, X, Check, Trash2, Users } from 'lucide-react'

/**
 * The attendance sheet: four columns, Name and Designation.
 *
 * What was here before was one long vertical list of participant cards, each
 * with seven typed fields — Name, Organisation, Side, Role, Email, Designation,
 * Phone. Filling it in meant retyping what the system already knew: a TPV
 * vendor's site engineer is registered, with that designation, against that
 * vendor. Typing the name again produced a second spelling of a real person,
 * and an attendance sheet nobody could read down because every row was a form.
 *
 * Here each column asks the two questions somebody filling in an attendance
 * sheet actually asks — which company, then which of their people — and the
 * name and designation come from the record. The organiser column skips the
 * first question: the company is you.
 *
 * ── Why the email is not on screen ──────────────────────────────────────
 * It is still stored, and the invitation still goes to it. But it is not what
 * identifies a person on an attendance sheet, it is the longest string in every
 * row, and showing it pushed the designation — the thing that says why someone
 * was in the room — off the edge of the card. It comes along with the pick,
 * silently, which is the only part of it anybody needs.
 *
 * Props:
 *   parties       [{ key, label, side, picks_entity, entities:[{id,name}], people:[] }]
 *   chosen        the participant rows, each carrying { party, party_ref, … }
 *   onAdd(person, party)   add one person to a column
 *   onRemove(id)           drop a participant row
 *   loadPeople(party, entityId) -> Promise<person[]>
 */
export default function ParticipantGrid({ parties = [], chosen = [], onAdd, onRemove, loadPeople }) {
  if (!parties.length) {
    return (
      <div style={{ padding: 16, borderRadius: 10, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center' }}>
        <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>Loading the attendance sheet…</p>
      </div>
    )
  }

  // Anybody stored before this grid existed has no party, so they would simply
  // vanish from a screen that only draws four columns. They get their own row
  // underneath instead, where they can be seen and removed.
  const unplaced = chosen.filter(p => !p.party)

  return (
    <div>
      {/* Four columns is the point of the sheet, so the track has to be narrow
          enough that all four still fit beside the meeting-summary panel — at
          230px the fourth wrapped onto its own row and the grid read as three
          columns and an afterthought. Names ellipsis rather than wrap; the
          column stretches on a wider screen. */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(165px, 1fr))', gap: 10, alignItems: 'start' }}>
        {parties.map(party => (
          <PartyColumn
            key={party.key}
            party={party}
            chosen={chosen.filter(p => p.party === party.key)}
            onAdd={person => onAdd(person, party)}
            onRemove={onRemove}
            loadPeople={loadPeople}
          />
        ))}
      </div>

      {unplaced.length > 0 && (
        <div style={{ marginTop: 14, padding: 12, borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)' }}>
          <p style={{ margin: '0 0 8px', fontSize: 11, fontWeight: 800, letterSpacing: '.05em', textTransform: 'uppercase', color: '#f59e0b' }}>
            Not yet placed in a column
          </p>
          <p style={{ margin: '0 0 10px', fontSize: 11.5, color: 'var(--text-muted)' }}>
            Added before the attendance sheet had columns. Remove and re-pick them to file them under a party.
          </p>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
            {unplaced.map(p => <PersonChip key={p.id} person={p} onRemove={() => onRemove(p.id)} />)}
          </div>
        </div>
      )}
    </div>
  )
}

function PartyColumn({ party, chosen, onAdd, onRemove, loadPeople }) {
  // The organiser column has no company step, so its people arrive with the
  // column itself and this stays null for its whole life.
  const [entityId, setEntityId] = useState('')
  const [people, setPeople] = useState(party.picks_entity ? [] : (party.people || []))
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')

  useEffect(() => {
    if (!party.picks_entity) { setPeople(party.people || []); return }
    if (!entityId) { setPeople([]); return }
    let alive = true
    setLoading(true)
    loadPeople(party.key, entityId)
      .then(rows => { if (alive) setPeople(Array.isArray(rows) ? rows : []) })
      .catch(() => { if (alive) setPeople([]) })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [party.key, party.picks_entity, party.people, entityId, loadPeople])

  const taken = useMemo(() => new Set(chosen.map(p => p.party_ref).filter(Boolean)), [chosen])

  const matches = useMemo(() => {
    const term = q.trim().toLowerCase()
    if (!term) return people
    return people.filter(p => [p.name, p.designation].some(f => (f || '').toLowerCase().includes(term)))
  }, [people, q])

  const entityName = party.picks_entity
    ? (party.entities || []).find(e => String(e.id) === String(entityId))?.name
    : null

  return (
    <div style={{ borderRadius: 12, border: '1px solid var(--border)', background: 'var(--bg-card)', overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
      <div style={{ padding: '9px 11px', borderBottom: '1px solid var(--border)', background: 'var(--bg-input)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <Users size={12} style={{ color: '#a78bfa', flexShrink: 0 }} />
          <span style={{ fontSize: 10.5, fontWeight: 800, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--text-h)' }}>
            {party.label}
          </span>
          {chosen.length > 0 && (
            <span style={{ marginLeft: 'auto', fontSize: 10.5, fontWeight: 800, color: '#a78bfa' }}>{chosen.length}</span>
          )}
        </div>
      </div>

      <div style={{ padding: 10, display: 'flex', flexDirection: 'column', gap: 9 }}>
        {party.picks_entity && (
          <select
            value={entityId}
            onChange={e => { setEntityId(e.target.value); setOpen(false); setQ('') }}
            style={selectStyle}
          >
            <option value="">Select {party.label.toLowerCase()}…</option>
            {(party.entities || []).map(e => <option key={e.id} value={e.id}>{e.name}</option>)}
          </select>
        )}

        {chosen.length > 0 && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {chosen.map(p => <PersonChip key={p.id} person={p} onRemove={() => onRemove(p.id)} block />)}
          </div>
        )}

        {/* The picker. Inline rather than a portalled popover: a column is
            narrow, the list belongs under the company it came from, and the
            whole point of the grid is seeing all four at once. */}
        {party.picks_entity && !entityId ? (
          <p style={{ margin: 0, fontSize: 11.5, color: 'var(--text-muted)' }}>
            Pick a {party.label.toLowerCase()} to see its team.
          </p>
        ) : (
          <>
            <button type="button" onClick={() => setOpen(o => !o)} style={addPersonBtn}>
              {open ? <X size={12} /> : <Plus size={12} />} {open ? 'Done' : 'Add person'}
            </button>

            {open && (
              <div style={{ borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', overflow: 'hidden' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 8px', borderBottom: '1px solid var(--border)' }}>
                  <Search size={12} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                  <input
                    value={q}
                    onChange={e => setQ(e.target.value)}
                    placeholder="Search name or designation…"
                    style={{ flex: 1, minWidth: 0, background: 'transparent', border: 'none', outline: 'none', color: 'var(--text-h)', fontSize: 12 }}
                  />
                </div>
                <div style={{ maxHeight: 220, overflowY: 'auto' }}>
                  {loading ? (
                    <p style={emptyNote}>Loading {entityName || 'the team'}…</p>
                  ) : matches.length === 0 ? (
                    <p style={emptyNote}>
                      {people.length === 0
                        ? `No people are registered against ${entityName || 'this party'} yet.`
                        : `Nobody matches “${q}”.`}
                    </p>
                  ) : matches.map(person => {
                    const already = taken.has(person.ref)
                    return (
                      <button
                        key={person.ref}
                        type="button"
                        disabled={already}
                        onClick={() => onAdd(person)}
                        title={already ? 'Already on the sheet' : undefined}
                        style={{
                          width: '100%', textAlign: 'left', display: 'flex', alignItems: 'center', gap: 6,
                          padding: '7px 9px', background: 'transparent', border: 'none',
                          borderBottom: '1px solid var(--border)',
                          cursor: already ? 'default' : 'pointer', opacity: already ? 0.45 : 1,
                        }}
                        onMouseEnter={e => { if (!already) e.currentTarget.style.background = 'var(--bg-card)' }}
                        onMouseLeave={e => { e.currentTarget.style.background = 'transparent' }}
                      >
                        <span style={{ flex: 1, minWidth: 0 }}>
                          <span style={nameLine}>{person.name || '—'}</span>
                          <span style={designationLine}>{person.designation || 'No designation on record'}</span>
                        </span>
                        {already && <Check size={12} style={{ color: '#10b981', flexShrink: 0 }} />}
                      </button>
                    )
                  })}
                </div>
              </div>
            )}
          </>
        )}

        {chosen.length === 0 && !open && (
          <p style={{ margin: 0, fontSize: 11.5, color: 'var(--text-muted)' }}>Nobody from this party yet.</p>
        )}
      </div>
    </div>
  )
}

/** One person on the sheet: the name, and under it what they do. Nothing else. */
function PersonChip({ person, onRemove, block }) {
  return (
    <div style={{
      display: 'flex', alignItems: 'center', gap: 6,
      padding: '6px 8px', borderRadius: 9,
      background: 'var(--bg-input)', border: '1px solid var(--border)',
      width: block ? '100%' : 'auto',
    }}>
      <span style={{ flex: 1, minWidth: 0 }}>
        <span style={nameLine}>{person.name || 'Unnamed'}</span>
        <span style={designationLine}>{person.designation || '—'}</span>
      </span>
      <button
        type="button"
        onClick={onRemove}
        title={`Remove ${person.name || 'this person'}`}
        aria-label={`Remove ${person.name || 'this person'}`}
        style={{ width: 22, height: 22, flexShrink: 0, borderRadius: 6, border: '1px solid rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)', color: '#ef4444', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
      >
        <Trash2 size={11} />
      </button>
    </div>
  )
}

const nameLine = { display: 'block', fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }
const designationLine = { display: 'block', fontSize: 11, color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }
const emptyNote = { padding: '14px 10px', margin: 0, textAlign: 'center', fontSize: 11.5, color: 'var(--text-muted)' }
const selectStyle = { width: '100%', padding: '6px 8px', borderRadius: 8, fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer' }
const addPersonBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 5, padding: '6px 9px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: 'pointer', color: '#a78bfa', background: 'rgba(124,58,237,0.08)', border: '1px solid rgba(124,58,237,0.25)' }
