import { useState, useEffect } from 'react'
import { Star, Gauge, Check } from 'lucide-react'
import { clientPortalApi } from '@/lib/clientPortalApi'

/**
 * §10 — where the customer actually answers.
 *
 * CSAT and NPS existed only as a staff register: somebody typed in a number
 * they had been told on a call. That is a record of a conversation, not a
 * survey, and the person whose opinion it is had no way to give it. The legacy
 * CRM did let customers submit feedback, so this was a regression too.
 *
 * Two questions, deliberately. CSAT asks about the last piece of work; NPS asks
 * about the relationship. Blending them into one "rate us" would produce a
 * number that answers neither.
 */
const card = {
  background: 'var(--bg-card,#12141b)',
  border: '1px solid var(--border,#2a2f3a)',
  borderRadius: 14,
}

const SCALES = {
  CSAT: {
    icon: Star,
    title: 'How satisfied are you with our recent work?',
    hint: '0 = not at all, 5 = completely',
    max: 5,
    ends: ['Not at all', 'Completely'],
  },
  NPS: {
    icon: Gauge,
    title: 'How likely are you to recommend us to a colleague?',
    hint: '0 = not at all likely, 10 = extremely likely',
    max: 10,
    ends: ['Not at all likely', 'Extremely likely'],
  },
}

function Scale({ metric, existing, onSaved }) {
  const cfg = SCALES[metric]
  const Icon = cfg.icon
  const [score, setScore] = useState(existing?.score ?? null)
  const [comments, setComments] = useState(existing?.comments ?? '')
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    setScore(existing?.score ?? null)
    setComments(existing?.comments ?? '')
  }, [existing?.id])

  const submit = async () => {
    if (score === null) return setErr('Please choose a number first.')
    setSaving(true); setErr('')
    try {
      await clientPortalApi.feedback.submit({ metric, score, comments: comments || null })
      setSaved(true)
      setTimeout(() => setSaved(false), 4000)
      onSaved?.()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save that. Please try again.')
    } finally { setSaving(false) }
  }

  return (
    <div style={{ ...card, padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 4 }}>
        <Icon size={15} style={{ color: '#a78bfa' }} />
        <h2 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h,#fff)', margin: 0 }}>{cfg.title}</h2>
      </div>
      <p style={{ fontSize: 11.5, color: 'var(--text-muted,#9ca3af)', margin: '0 0 14px' }}>{cfg.hint}</p>

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
        {Array.from({ length: cfg.max + 1 }, (_, n) => {
          const on = score === n
          return (
            <button key={n} type="button" onClick={() => { setScore(n); setErr('') }}
              aria-pressed={on}
              style={{
                width: 40, height: 40, borderRadius: 10, fontSize: 13, fontWeight: 800, cursor: 'pointer',
                background: on ? 'rgba(124,58,237,0.22)' : 'var(--bg-input,#0f1117)',
                color: on ? '#a78bfa' : 'var(--text-muted,#9ca3af)',
                border: `1px solid ${on ? 'rgba(124,58,237,0.55)' : 'var(--border,#2a2f3a)'}`,
              }}>{n}</button>
          )
        })}
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6 }}>
        <span style={{ fontSize: 10.5, color: 'var(--text-faint,#6b7280)' }}>{cfg.ends[0]}</span>
        <span style={{ fontSize: 10.5, color: 'var(--text-faint,#6b7280)' }}>{cfg.ends[1]}</span>
      </div>

      <textarea rows={3} value={comments} onChange={(e) => setComments(e.target.value)}
        placeholder="Anything you would like to add? (optional)"
        style={{
          width: '100%', marginTop: 14, padding: '10px 12px', borderRadius: 10, resize: 'vertical',
          background: 'var(--bg-input,#0f1117)', border: '1px solid var(--border,#2a2f3a)',
          color: 'var(--text-body,#cbd5e1)', fontSize: 12.5, fontFamily: 'inherit',
        }} />

      {err && <p style={{ fontSize: 12, color: '#ef4444', margin: '8px 0 0' }}>{err}</p>}

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12 }}>
        <button onClick={submit} disabled={saving}
          style={{
            padding: '9px 18px', borderRadius: 10, border: 'none', cursor: saving ? 'default' : 'pointer',
            background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff',
            fontSize: 12.5, fontWeight: 700, opacity: saving ? 0.6 : 1,
          }}>
          {saving ? 'Sending…' : existing ? 'Update my answer' : 'Send'}
        </button>
        {saved && (
          <span style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 12, color: '#10b981' }}>
            <Check size={13} /> Thank you
          </span>
        )}
        {existing && !saved && (
          <span style={{ fontSize: 11.5, color: 'var(--text-muted,#9ca3af)' }}>
            You answered {new Date(existing.responded_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })}
            {' '}— you can change it.
          </span>
        )}
      </div>
    </div>
  )
}

export default function ClientPortalFeedback() {
  const [mine, setMine] = useState(null)

  const load = () => clientPortalApi.feedback.mine().then(setMine).catch(() => setMine([]))
  useEffect(() => { load() }, [])

  // The most recent answer per metric — that is what the form starts from, so
  // somebody revisiting the page sees what they said rather than a blank slate.
  const latest = (metric) => (mine ?? []).find((r) => r.metric === metric)

  return (
    <div style={{ display: 'grid', gap: 16, maxWidth: 760 }}>
      <div>
        <h1 style={{ fontSize: 23, fontWeight: 800, color: 'var(--text-h,#fff)', margin: 0 }}>Your feedback</h1>
        <p style={{ fontSize: 13, color: 'var(--text-muted,#9ca3af)', margin: '4px 0 0' }}>
          Two quick questions. Your answers go straight to your account team.
        </p>
      </div>

      {mine === null ? (
        <div style={{ ...card, padding: 22, fontSize: 13, color: 'var(--text-muted,#9ca3af)' }}>Loading…</div>
      ) : (
        <>
          <Scale metric="CSAT" existing={latest('CSAT')} onSaved={load} />
          <Scale metric="NPS"  existing={latest('NPS')}  onSaved={load} />
        </>
      )}
    </div>
  )
}
