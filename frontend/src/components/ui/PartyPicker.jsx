import { useState, useEffect, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { X, Search, ArrowLeft, Loader2, Building2, Users, ChevronRight, Check } from 'lucide-react'
import { taskApi } from '@/services/taskApi'

/**
 * Assign a task OR a project to a named person at a client, a vendor or a TPV.
 *
 * Shared, not task-owned: projects assign the same people from the same
 * directory, and a second copy of this walk is a second place for the rules to
 * drift.
 *
 * Three stages — kind, then company, then person — and the middle one is the
 * point. A flat list of every contact in the tenant is thousands of rows in
 * which two people called R. Kumar at two different suppliers are
 * indistinguishable, and it is not how anybody thinks about it either: the work
 * is for Southgate, and then it is for whoever at Southgate does this.
 *
 * What comes back is {party_type, party_id} — never a user id. These people
 * mostly have no login in this system at all, which is exactly why they could
 * not be assigned before.
 *
 * Closes on ✕ or Cancel only. Clicking the backdrop does not dismiss it: three
 * stages in, a stray click outside the panel would throw away the whole walk.
 */
export default function PartyPicker({ open, onClose, onPick, chosen = [], multi = false, accent = '#7C3AED' }) {
  const [orgType, setOrgType] = useState(null)
  const [org, setOrg] = useState(null)
  const [q, setQ] = useState('')
  // Ticked-but-not-yet-sent, keyed "type:id" so two contacts with the same id in
  // two different tables cannot collide.
  const [picked, setPicked] = useState(() => new Map())

  useEffect(() => {
    if (!open) return
    setOrgType(null); setOrg(null); setQ(''); setPicked(new Map())
  }, [open])

  const { data: kinds = [] } = useQuery({
    queryKey: ['task-party-kinds'], queryFn: taskApi.parties.kinds, enabled: open,
  })

  const { data: orgs = [], isLoading: orgsLoading } = useQuery({
    queryKey: ['task-party-orgs', orgType],
    queryFn: () => taskApi.parties.orgs(orgType),
    enabled: open && !!orgType,
  })

  const { data: people = [], isLoading: peopleLoading } = useQuery({
    queryKey: ['task-party-people', orgType, org?.id],
    queryFn: () => taskApi.parties.people(orgType, org.id),
    enabled: open && !!orgType && !!org,
  })

  // Somebody already on the task is not a choice — picking them again is a
  // no-op server-side, so offering it is only ever a misread.
  const takenKeys = useMemo(
    () => new Set(chosen.map((c) => `${c.party_type}:${c.party_id}`)),
    [chosen],
  )

  if (!open) return null

  const keyOf = (p) => `${p.party_type}:${p.party_id}`
  const togglePick = (p) => setPicked((m) => {
    const next = new Map(m)
    next.has(keyOf(p)) ? next.delete(keyOf(p)) : next.set(keyOf(p), p)
    return next
  })
  const confirm = () => {
    if (!picked.size) return
    onPick([...picked.values()])
    onClose()
  }

  const ql = q.trim().toLowerCase()
  const match = (...fields) => !ql || fields.filter(Boolean).some((f) => String(f).toLowerCase().includes(ql))

  const kind = kinds.find((k) => k.org_type === orgType)
  const back = () => (org ? (setOrg(null), setQ('')) : (setOrgType(null), setQ('')))

  return (
    <div style={overlay}>
      <div style={panel}>
        {/* Header */}
        <div style={head}>
          {orgType && (
            <button onClick={back} title="Back" style={iconBtn}><ArrowLeft size={18} /></button>
          )}
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>
              {org ? org.name : kind ? kind.label : 'Assign to someone outside the team'}
            </div>
            <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
              {org
                ? 'Pick the person who will do this'
                : kind
                  ? `Choose the ${kind.label.toLowerCase()}, then the person`
                  : 'A client, a vendor or a third-party vendor'}
            </div>
          </div>
          <button onClick={onClose} aria-label="Close" style={iconBtn}><X size={18} /></button>
        </div>

        {/* Search — only once there is a list worth filtering. */}
        {orgType && (
          <div style={searchWrap}>
            <Search size={14} style={{ color: 'var(--text-muted)' }} />
            <input autoFocus value={q} onChange={(e) => setQ(e.target.value)}
              placeholder={org ? 'Search people…' : 'Search…'} style={searchInput} />
          </div>
        )}

        <div style={{ overflowY: 'auto', flex: 1 }}>
          {/* Stage 1 — which kind of company */}
          {!orgType && kinds.map((k) => (
            <button key={k.org_type} onClick={() => setOrgType(k.org_type)} style={row}>
              <Building2 size={15} style={{ color: accent, flexShrink: 0 }} />
              <span style={{ flex: 1, minWidth: 0 }}>
                <span style={rowTitle}>{k.label}</span>
                <span style={rowSub}>{k.party_label}</span>
              </span>
              <ChevronRight size={14} style={{ color: 'var(--text-muted)' }} />
            </button>
          ))}

          {/* Stage 2 — which company */}
          {orgType && !org && (
            orgsLoading
              ? <Busy />
              : (() => {
                const list = orgs.filter((o) => match(o.name, o.sublabel))
                if (!list.length) return <Empty text={`No ${kind?.label?.toLowerCase() || 'teams'} match that.`} />
                return list.map((o) => (
                  <button key={o.id} onClick={() => { setOrg(o); setQ('') }} style={row}>
                    <Building2 size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                    <span style={{ flex: 1, minWidth: 0 }}>
                      <span style={rowTitle}>{o.name}</span>
                      {o.sublabel ? <span style={rowSub}>{o.sublabel}</span> : null}
                    </span>
                    <ChevronRight size={14} style={{ color: 'var(--text-muted)' }} />
                  </button>
                ))
              })()
          )}

          {/* Stage 3 — which person */}
          {org && (
            peopleLoading
              ? <Busy />
              : (() => {
                const list = people.filter((p) => match(p.name, p.role, p.email))
                if (!list.length) {
                  return (
                    <Empty text={
                      ql
                        ? 'Nobody here matches that.'
                        : /* An empty team is not a bug and the difference matters:
                             the fix is to add a contact on that company's page,
                             not to look somewhere else in the task module. */
                          `${org.name} has no contacts yet. Add one on their Contacts tab first.`
                    } />
                  )
                }
                return list.map((p) => {
                  const taken = takenKeys.has(keyOf(p))
                  const ticked = picked.has(keyOf(p))
                  return (
                    <button key={keyOf(p)} disabled={taken}
                      onClick={() => { if (multi) { togglePick(p) } else { onPick([p]); onClose() } }}
                      style={{
                        ...row,
                        opacity: taken ? 0.45 : 1,
                        cursor: taken ? 'default' : 'pointer',
                        background: ticked ? `color-mix(in srgb, ${accent} 14%, transparent)` : 'none',
                      }}>
                      {multi && (
                        <span className="flex shrink-0 items-center justify-center"
                          style={{
                            width: 16, height: 16, borderRadius: 4,
                            border: `1.5px solid ${ticked ? accent : 'var(--border)'}`,
                            background: ticked ? accent : 'transparent',
                          }}>
                          {ticked && <Check size={11} strokeWidth={3} style={{ color: '#fff' }} />}
                        </span>
                      )}
                      <span style={avatarStyle(accent)}>{(p.name || '?').slice(0, 1).toUpperCase()}</span>
                      <span style={{ flex: 1, minWidth: 0 }}>
                        <span style={rowTitle}>{p.name}</span>
                        <span style={rowSub}>
                          {[p.role, p.email].filter(Boolean).join(' · ') || 'No role recorded'}
                        </span>
                      </span>
                      {taken && <span style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-muted)' }}>Already on this task</span>}
                    </button>
                  )
                })
              })()
          )}
        </div>

        <div style={foot}>
          {/* An external assignee is told by email, not by a badge in a portal
              they may not have — so the person choosing needs to know that. */}
          <span style={{ fontSize: 10.5, color: 'var(--text-muted)', flex: 1 }}>
            <Users size={11} style={{ display: 'inline', marginRight: 4, verticalAlign: -1 }} />
            {multi && picked.size
              ? `${picked.size} selected. They are emailed the task, and see it — and only it — on their portal.`
              : 'They are emailed the task, and see it — and only it — on their portal.'}
          </span>
          <button onClick={onClose} style={cancelBtn}>Cancel</button>
          {multi && (
            /* The count sits ON the button: "Assign" with nothing ticked is a
               button that looks like it should do something and cannot. */
            <button onClick={confirm} disabled={!picked.size}
              style={{
                ...cancelBtn,
                background: picked.size ? accent : 'var(--bg-input)',
                color: picked.size ? '#fff' : 'var(--text-muted)',
                cursor: picked.size ? 'pointer' : 'not-allowed',
              }}>
              {picked.size ? `Assign ${picked.size}` : 'Assign'}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

/* ── chrome ──────────────────────────────────────────────────────────────── */

function Busy() {
  return (
    <div style={{ display: 'flex', justifyContent: 'center', padding: 28, color: 'var(--text-muted)' }}>
      <Loader2 size={18} className="animate-spin" />
    </div>
  )
}

function Empty({ text }) {
  return <p style={{ padding: '22px 18px', fontSize: 12, textAlign: 'center', color: 'var(--text-muted)' }}>{text}</p>
}

const overlay = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 70,
  display: 'flex', alignItems: 'flex-start', justifyContent: 'center',
  padding: '10vh 16px 16px', backdropFilter: 'blur(2px)',
}

const panel = {
  background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16,
  width: '100%', maxWidth: 470, maxHeight: '72vh',
  display: 'flex', flexDirection: 'column', overflow: 'hidden',
  boxShadow: '0 20px 60px rgba(0,0,0,0.4)',
}

const head = {
  display: 'flex', alignItems: 'center', gap: 10,
  padding: '14px 16px', borderBottom: '1px solid var(--border)',
}

const iconBtn = {
  background: 'none', border: 'none', cursor: 'pointer',
  color: 'var(--text-muted)', display: 'flex', padding: 2,
}

const searchWrap = {
  display: 'flex', alignItems: 'center', gap: 8,
  padding: '9px 16px', borderBottom: '1px solid var(--border)',
}

const searchInput = {
  flex: 1, background: 'none', border: 'none', outline: 'none',
  fontSize: 13, color: 'var(--text-h)',
}

const row = {
  display: 'flex', alignItems: 'center', gap: 10, width: '100%',
  padding: '10px 16px', background: 'none', border: 'none',
  borderBottom: '1px solid var(--border)', cursor: 'pointer', textAlign: 'left',
}

const rowTitle = {
  display: 'block', fontSize: 13, fontWeight: 600, color: 'var(--text-h)',
  whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
}

const rowSub = {
  display: 'block', fontSize: 11, color: 'var(--text-muted)',
  whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
}

/* Takes the accent rather than closing over one — this component is shared, and
   a module-scope constant here silently painted every caller task-purple. */
const avatarStyle = (accent) => ({
  width: 26, height: 26, borderRadius: '50%', flexShrink: 0,
  display: 'flex', alignItems: 'center', justifyContent: 'center',
  fontSize: 11, fontWeight: 800,
  background: `color-mix(in srgb, ${accent} 16%, transparent)`, color: accent,
})

const foot = {
  display: 'flex', alignItems: 'center', gap: 10,
  padding: '10px 16px', borderTop: '1px solid var(--border)',
}

const cancelBtn = {
  fontSize: 12, fontWeight: 700, padding: '6px 12px', borderRadius: 9,
  background: 'var(--bg-input)', border: '1px solid var(--border)',
  color: 'var(--text-body)', cursor: 'pointer',
}
