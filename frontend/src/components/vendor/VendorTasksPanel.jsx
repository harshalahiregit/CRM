import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { ListTodo, AlertTriangle, CheckCircle2, Clock, ArrowUpRight, Plus, Search } from 'lucide-react'

/**
 * Tasks raised against a vendor.
 *
 * Module-agnostic and presentational: it takes a `fetcher` and knows nothing about
 * TPV or Purchase, so those two modules stay independent while the same table isn't
 * written twice.
 *
 * Creating is OPT-IN. Pass `onAdd` and the header gains an add button, matching
 * how the Projects tab raises a project already scoped to its vendor; the caller
 * owns the form, so this component still knows nothing about either module. A
 * caller that passes nothing keeps the previous read-only panel exactly.
 */

/**
 * Status palette, fixed and never themed. `good`/`warning`/`critical` are reserved
 * for state and never reused as decorative accents. Each tile pairs its colour with
 * an icon AND a label, because on a light surface warning is sub-3:1 — colour must
 * never be the only carrier of meaning.
 */
const STATUS = {
  neutral:  'var(--text-muted)',
  good:     '#0ca30c',
  warning:  '#fab219',
  critical: '#d03b3b',
}

/**
 * The Link column is CATEGORICAL identity (how the task reached this vendor), not
 * status, so it takes categorical slots 1 and 2 in fixed order — NOT the module
 * accent. Using the accent made "Related" and "Assigned" render identically on the
 * TPV tab, whose accent is itself blue.
 *
 * Validated both modes: CVD ΔE 24.7 protan / 32.7 tritan, normal-vision 33.6,
 * both ≥3:1 on their surface. Each chip still carries its word, so identity never
 * rests on hue alone.
 */
const LINK_TONE = { related: '#2a78d6', assigned: '#eb6834' }

export default function VendorTasksPanel({
  queryKey, fetcher, accent = '#7C3AED', emptyHint,
  onAdd, addLabel = 'Add Task', searchable = false,
}) {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  // App-wide default is staleTime 5min — right for reference data, wrong for a work
  // list that changes as tasks are assigned. Always refetch when the tab opens.
  const { data, isLoading, isError } = useQuery({
    queryKey,
    queryFn: fetcher,
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  const summary = data?.summary || {}
  const all = useMemo(() => (Array.isArray(data?.tasks) ? data.tasks : []), [data])

  // Client-side, over the list already loaded — the same fields the Projects tab
  // searches on, so neither tab needs a round-trip to filter.
  const tasks = useMemo(() => {
    const needle = q.trim().toLowerCase()
    if (! needle) return all

    return all.filter(t => [t.name, t.status, t.priority, ...(t.assignees || [])]
      .filter(Boolean)
      .some(f => String(f).toLowerCase().includes(needle)))
  }, [all, q])

  if (isLoading) return <Shell><Muted>Loading tasks…</Muted></Shell>
  if (isError)   return <Shell><span style={{ color: STATUS.critical, fontSize: 13, fontWeight: 700 }}>Could not load tasks.</span></Shell>

  const stats = [
    { key: 'total',    label: 'Total',    value: summary.total ?? 0,    icon: ListTodo,      tone: STATUS.neutral },
    { key: 'open',     label: 'Open',     value: summary.open ?? 0,     icon: Clock,         tone: STATUS.warning },
    { key: 'complete', label: 'Complete', value: summary.complete ?? 0, icon: CheckCircle2,  tone: STATUS.good },
    { key: 'overdue',  label: 'Overdue',  value: summary.overdue ?? 0,  icon: AlertTriangle, tone: STATUS.critical },
  ]

  return (
    <div>
      {/* Search + add, only for callers that opted in. */}
      {(searchable || onAdd) && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12, flexWrap: 'wrap' }}>
          {searchable && (
            <div style={{ position: 'relative', flex: 1, minWidth: 200 }}>
              <Search size={13} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
              <input
                value={q} onChange={e => setQ(e.target.value)} placeholder="Search tasks…"
                style={{
                  width: '100%', padding: '8px 12px 8px 32px', borderRadius: 10, fontSize: 13,
                  background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)',
                }}
              />
            </div>
          )}
          {onAdd && (
            <button type="button" onClick={onAdd} style={{
              display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 16px', borderRadius: 10,
              border: 'none', background: accent, color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer',
            }}>
              <Plus size={14} /> {addLabel}
            </button>
          )}
        </div>
      )}

      {/* Stat tiles, not a chart: four single headline numbers. The VALUE wears
          primary ink; the coloured icon beside it carries the status identity, so
          the row reads as one system rather than four competing colours. */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(148px,1fr))', gap: 10, marginBottom: 16 }}>
        {stats.map(s => {
          const muted = s.value === 0
          return (
            <div key={s.key} style={{
              padding: '13px 15px', borderRadius: 12,
              background: 'var(--bg-card)', border: '1px solid var(--border)',
            }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 7 }}>
                <s.icon size={13} strokeWidth={2.4} style={{ color: muted ? 'var(--text-muted)' : s.tone, flexShrink: 0 }} />
                <span style={{ fontSize: 10.5, fontWeight: 700, letterSpacing: '0.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
                  {s.label}
                </span>
              </div>
              <div style={{
                fontSize: 26, fontWeight: 800, lineHeight: 1, letterSpacing: '-0.02em',
                fontVariantNumeric: 'tabular-nums',
                color: muted ? 'var(--text-muted)' : 'var(--text-h)',
              }}>{s.value}</div>
            </div>
          )
        })}
      </div>

      {tasks.length === 0 ? (
        <div style={{
          padding: '38px 24px', textAlign: 'center', borderRadius: 12,
          background: 'var(--bg-card)', border: '1px dashed var(--border)',
        }}>
          <ListTodo size={24} strokeWidth={1.8} style={{ color: 'var(--text-muted)', marginBottom: 10 }} />
          <p style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)', margin: 0 }}>
            {q.trim() ? 'No matching tasks' : 'No tasks yet'}
          </p>
          <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '6px auto 0', lineHeight: 1.55, maxWidth: 400 }}>
            {q.trim()
              ? `Nothing matches “${q.trim()}”.`
              : (emptyHint || 'Assign a task to this vendor’s login, or set a task’s “Related To” to this vendor.')}
          </p>
        </div>
      ) : (
        <div style={{ borderRadius: 12, border: '1px solid var(--border)', overflow: 'hidden', background: 'var(--bg-card)' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr>
                  {['Task', 'Link', 'Status', 'Priority', 'Assignees', 'Due', ''].map(h => (
                    <th key={h} style={{
                      padding: '9px 14px', textAlign: 'left', whiteSpace: 'nowrap',
                      fontSize: 10, fontWeight: 700, letterSpacing: '0.05em', textTransform: 'uppercase',
                      color: 'var(--text-muted)', borderBottom: '1px solid var(--border)',
                    }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {tasks.map((t, i) => {
                  const overdue = t.is_open && t.due_date && new Date(t.due_date) < new Date(new Date().toDateString())
                  return (
                    // The whole row opens the task, matching the Projects tab. The
                    // explicit "Open" link stays for keyboard and middle-click.
                    <tr key={t.id}
                      onClick={t.url ? () => navigate(t.url) : undefined}
                      style={{
                        borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                        cursor: t.url ? 'pointer' : 'default',
                      }}>
                      <td style={{ padding: '11px 14px', fontWeight: 650, color: 'var(--text-h)' }}>{t.name}</td>

                      {/* Why the task is on this tab: raised against the vendor, or
                          assigned to their portal login. Both are their work. */}
                      <td style={{ padding: '11px 14px' }}>
                        <Tag tone={t.link === 'assigned' ? LINK_TONE.assigned : LINK_TONE.related}>
                          {t.link === 'assigned' ? 'Assigned' : 'Related'}
                        </Tag>
                      </td>

                      <td style={{ padding: '11px 14px' }}>
                        <Tag tone={t.is_open ? STATUS.warning : STATUS.good} dot>
                          {String(t.status || '').replace(/_/g, ' ')}
                        </Tag>
                      </td>

                      <td style={{ padding: '11px 14px', color: 'var(--text-muted)', textTransform: 'capitalize' }}>
                        {t.priority || '—'}
                      </td>

                      <td style={{ padding: '11px 14px', color: 'var(--text-muted)' }}>
                        {t.assignees?.length ? t.assignees.join(', ') : '—'}
                      </td>

                      <td style={{
                        padding: '11px 14px', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums',
                        color: overdue ? STATUS.critical : 'var(--text-muted)', fontWeight: overdue ? 700 : 400,
                      }}>
                        {overdue && <AlertTriangle size={11} style={{ display: 'inline', marginRight: 4, verticalAlign: '-1px' }} />}
                        {t.due_date ? new Date(t.due_date).toLocaleDateString() : '—'}
                      </td>

                      <td style={{ padding: '11px 14px', textAlign: 'right' }} onClick={e => e.stopPropagation()}>
                        <Link to={t.url} style={{
                          display: 'inline-flex', alignItems: 'center', gap: 3,
                          fontSize: 12, fontWeight: 700, color: accent, textDecoration: 'none',
                        }}>Open <ArrowUpRight size={12} /></Link>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

/* A status/identity chip. Never colour alone — always a word, optionally a dot. */
function Tag({ tone, children, dot }) {
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 5,
      fontSize: 11, fontWeight: 700, padding: '3px 9px', borderRadius: 7,
      whiteSpace: 'nowrap', textTransform: 'capitalize',
      color: tone, background: `color-mix(in srgb, ${tone} 12%, transparent)`,
    }}>
      {dot && <span style={{ width: 5, height: 5, borderRadius: 999, background: tone, flexShrink: 0 }} />}
      {children}
    </span>
  )
}

const Shell = ({ children }) => (
  <div style={{ padding: 26, borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>{children}</div>
)
const Muted = ({ children }) => <span style={{ color: 'var(--text-muted)', fontSize: 13 }}>{children}</span>
