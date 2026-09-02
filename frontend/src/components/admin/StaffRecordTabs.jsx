/**
 * Account, Activity and Notes — the three tabs beside Profile and Permissions.
 *
 * None of this data is new. last_login_at, last_login_ip, user_sessions,
 * audit_logs and the shared notes table were all being written on every login
 * and every change; none of it was reachable from this screen, so "is this
 * account still being used" could only be answered from the database.
 *
 * Each tab fetches when it is opened rather than with the modal. Four requests
 * to show one profile is the wrong trade when most people never leave the first
 * tab.
 */

import { useState, useEffect, useCallback } from 'react'
import { Monitor, LogOut, Clock, Plus, Trash2, ArrowRight, ArrowLeft } from 'lucide-react'
import api from '@/lib/api'
import { useToast } from '@/components/ui/Toast'

const when = ts => {
  if (!ts) return '—'
  const d = new Date(ts)
  return Number.isNaN(d.getTime()) ? '—'
    : d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function Loading()  { return <p className="text-xs py-6 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p> }
function Empty({ t }) { return <p className="text-xs py-6 text-center" style={{ color: 'var(--text-muted)' }}>{t}</p> }

/** One fetch-on-open hook, so the three tabs behave identically. */
function useTabData(path, open) {
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState(null)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const res = await api.get(path)
      setData(res?.data?.data ?? null)
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not load this.')
    } finally {
      setLoading(false)
    }
  }, [path])

  useEffect(() => { if (open) load() }, [open, load])

  return { data, loading, error, reload: load }
}

/* ── Account ─────────────────────────────────────────────────────────── */

export function AccountTab({ staffId, open }) {
  const toast = useToast()
  const { data, loading, error, reload } = useTabData(`/admin/staff/${staffId}/account`, open)
  const [busy, setBusy] = useState(false)

  const revoke = async () => {
    setBusy(true)
    try {
      const res = await api.post(`/admin/staff/${staffId}/sessions/revoke`)
      toast.success(res?.data?.message || 'Sessions ended.')
      reload()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not end the sessions.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <Loading />
  if (error)   return <Empty t={error} />
  if (!data)   return null

  const sessions = Array.isArray(data.sessions) ? data.sessions : []

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))' }}>
        {[['Last signed in', when(data.last_login_at)],
          ['From', data.last_login_ip || '—'],
          ['Account created', when(data.created_at)],
          ['Status', data.status]].map(([k, v]) => (
          <div key={k} className="rounded-xl" style={{ padding: 11, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <p className="text-[9px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{k}</p>
            <p className="text-xs font-semibold mt-0.5" style={{ color: 'var(--text-h)' }}>{v}</p>
          </div>
        ))}
      </div>

      <div>
        <div className="flex items-center justify-between mb-2">
          <p className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
            Signed in on {sessions.length} device{sessions.length === 1 ? '' : 's'}
          </p>
          {sessions.length > 0 && (
            // Deliberately separate from deactivating the account: signing a lost
            // phone out and locking somebody out of the company are different
            // decisions, and having only the second means the first gets done with it.
            <button onClick={revoke} disabled={busy}
              className="rounded-lg text-[11px] font-bold flex items-center gap-1.5"
              style={{ padding: '5px 10px', background: 'rgba(248,113,113,0.12)', color: '#f87171', border: '1px solid rgba(248,113,113,0.3)' }}>
              <LogOut size={11} /> {busy ? 'Ending…' : 'Sign out everywhere'}
            </button>
          )}
        </div>

        {!sessions.length ? <Empty t="Not signed in anywhere right now." /> : (
          <div className="flex flex-col gap-1.5">
            {sessions.map(s => (
              <div key={s.id} className="rounded-xl flex items-center gap-2.5"
                style={{ padding: '9px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <Monitor size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                <div className="flex-1 min-w-0">
                  <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>
                    {[s.browser, s.device].filter(Boolean).join(' · ') || 'Unknown device'}
                  </p>
                  <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                    {s.ip || 'no IP'} · last active {when(s.last_activity_at)}
                  </p>
                </div>
                {s.remember_me && (
                  <span className="text-[9px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                    Remembered
                  </span>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Activity ────────────────────────────────────────────────────────── */

export function ActivityTab({ staffId, open }) {
  const { data, loading, error } = useTabData(`/admin/staff/${staffId}/activity`, open)

  if (loading) return <Loading />
  if (error)   return <Empty t={error} />
  if (!data?.length) return <Empty t="Nothing recorded for this person yet." />

  return (
    <div className="flex flex-col gap-1.5">
      {data.map(r => {
        // Both directions in one list. "Who changed this person's permissions"
        // matters as much as "what did this person change", and looking in two
        // places for one answer is how the question stops being asked.
        const byThem = r.direction === 'by_them'
        return (
          <div key={r.id} className="rounded-xl flex items-start gap-2.5"
            style={{ padding: '9px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <span style={{ color: byThem ? '#60a5fa' : '#fbbf24', marginTop: 2, flexShrink: 0 }}>
              {byThem ? <ArrowRight size={12} /> : <ArrowLeft size={12} />}
            </span>
            <div className="flex-1 min-w-0">
              <p className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>
                {String(r.action || '').replace(/_/g, ' ')}
                <span className="font-normal" style={{ color: 'var(--text-muted)' }}> · {r.subject}</span>
              </p>
              <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                {byThem ? 'by them' : `to them, by ${r.actor_name || 'someone'}`} · {when(r.created_at)}
              </p>
              {r.comment && <p className="text-[11px] mt-1" style={{ color: 'var(--text-p)' }}>{r.comment}</p>}
            </div>
          </div>
        )
      })}
      <p className="text-[10px] text-center pt-1" style={{ color: 'var(--text-muted)' }}>
        <Clock size={9} style={{ display: 'inline', marginRight: 3 }} />
        The 100 most recent entries.
      </p>
    </div>
  )
}

/* ── Notes ───────────────────────────────────────────────────────────── */

export function NotesTab({ staffId, open }) {
  const toast = useToast()
  const { data, loading, error, reload } = useTabData(`/admin/staff/${staffId}/notes`, open)

  const [title,   setTitle]   = useState('')
  const [content, setContent] = useState('')
  const [busy,    setBusy]    = useState(false)

  const add = async () => {
    // The title is what the shared notes table treats as a note's identity, and
    // the column is NOT NULL — so it is asked for here rather than refused later.
    if (!title.trim()) return toast.error('Give the note a title.')

    setBusy(true)
    try {
      await api.post(`/admin/staff/${staffId}/notes`, { title: title.trim(), content: content.trim() || null })
      toast.success('Note added.')
      setTitle(''); setContent('')
      reload()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not add that note.')
    } finally {
      setBusy(false)
    }
  }

  const remove = async id => {
    setBusy(true)
    try {
      await api.delete(`/admin/staff/${staffId}/notes/${id}`)
      toast.success('Note removed.')
      reload()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not remove that note.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="rounded-xl flex flex-col gap-2" style={{ padding: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <input value={title} onChange={e => setTitle(e.target.value)} maxLength={120}
          placeholder="Title — e.g. Handover, Probation review"
          className="rounded-lg text-sm w-full"
          style={{ padding: '8px 11px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />
        <textarea rows={2} value={content} onChange={e => setContent(e.target.value)} maxLength={5000}
          placeholder="Anything colleagues should know (optional)"
          className="rounded-lg text-sm w-full"
          style={{ padding: '8px 11px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />
        <button type="button" onClick={add} disabled={busy}
          className="rounded-lg text-xs font-bold flex items-center gap-1.5 self-start"
          style={{ padding: '7px 13px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
          <Plus size={13} /> Add note
        </button>
      </div>

      {loading ? <Loading />
        : error ? <Empty t={error} />
        : !data?.length ? <Empty t="No notes on this person yet." />
        : (
          <div className="flex flex-col gap-1.5">
            {data.map(n => (
              <div key={n.id} className="rounded-xl flex items-start gap-2.5"
                style={{ padding: '10px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div className="flex-1 min-w-0">
                  <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{n.title}</p>
                  {n.content && (
                    <p className="text-[11px] mt-0.5 whitespace-pre-wrap" style={{ color: 'var(--text-p)' }}>{n.content}</p>
                  )}
                  <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                    {n.creator?.name || 'Someone'} · {when(n.created_at)}
                  </p>
                </div>
                <button type="button" onClick={() => remove(n.id)} disabled={busy}
                  aria-label={`Remove note ${n.title}`}
                  style={{ color: 'var(--text-muted)', flexShrink: 0 }}>
                  <Trash2 size={13} />
                </button>
              </div>
            ))}
          </div>
        )}
    </div>
  )
}
