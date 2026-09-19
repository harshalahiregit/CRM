import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useParams, Link } from 'react-router-dom'
import { LifeBuoy, BookOpen, Check, AlertTriangle, Loader2 } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/**
 * Public, no-auth support form — one URL anyone can be sent.
 *
 * The widget already existed as a JavaScript snippet to paste into a site, and
 * the embed code was the only thing the Widget screen handed out. That is no use
 * to somebody who just wants to put "Contact support" in an email signature, on
 * a notice board or behind a link on a page they cannot edit the markup of
 * (SIR-000025). This is the same endpoint, the same widget key and the same
 * throttle — reachable by URL instead of by <script>.
 *
 * The key in the path is a PUBLIC identifier, not a secret: it selects the
 * tenant and nothing more, exactly as it does for the embedded widget and the
 * help centre at /kb/:key. An unknown or disabled key is refused by the server
 * with one message that never confirms whether the tenant exists.
 */
export default function PublicSupport() {
  const { key } = useParams()

  const [form, setForm] = useState({ name: '', email: '', subject: '', message: '' })
  const [reference, setReference] = useState(null)
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const submit = useMutation({
    mutationFn: () => helpdeskApi.public.submitTicket(key, form),
    onSuccess: (res) => setReference(res?.reference || res?.data?.reference || null),
  })

  const complete =
    form.name.trim() && form.email.trim() && form.subject.trim() && form.message.trim()

  /* ── Filed ─────────────────────────────────────────────────────────────── */
  if (reference !== null) {
    return (
      <Shell>
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center">
          <div className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-emerald-500 text-white">
            <Check size={22} />
          </div>
          <h2 className="text-lg font-semibold text-emerald-900">Your request has been received</h2>
          {reference && (
            <>
              <p className="mt-3 text-sm text-emerald-800">Your reference number is</p>
              <p className="mt-1 text-2xl font-bold tracking-wide text-emerald-900">{reference}</p>
            </>
          )}
          {/* Said here because the reply lands in their inbox, not on this page —
              there is no account to come back and log into. */}
          <p className="mx-auto mt-4 max-w-md text-sm leading-relaxed text-emerald-800">
            Our team will reply to <strong>{form.email}</strong>. Quote
            {reference ? <> <strong>{reference}</strong></> : ' your reference number'} in
            any follow-up so it reaches the same thread.
          </p>

          <button
            type="button"
            onClick={() => { setReference(null); setForm({ name: '', email: '', subject: '', message: '' }) }}
            className="mt-5 rounded-lg border border-emerald-300 bg-white px-4 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100"
          >
            Submit another request
          </button>
        </div>
      </Shell>
    )
  }

  /* ── The form ──────────────────────────────────────────────────────────── */
  return (
    <Shell>
      <form
        onSubmit={(e) => { e.preventDefault(); if (complete && !submit.isPending) submit.mutate() }}
        className="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Labelled label="Your name">
            <input value={form.name} onChange={set('name')} maxLength={255} required
              placeholder="Priya Sharma" className={inputClass} />
          </Labelled>
          <Labelled label="Email address">
            <input type="email" value={form.email} onChange={set('email')} maxLength={255} required
              placeholder="you@company.com" className={inputClass} />
          </Labelled>
        </div>

        <Labelled label="Subject">
          <input value={form.subject} onChange={set('subject')} maxLength={255} required
            placeholder="Briefly — what do you need help with?" className={inputClass} />
        </Labelled>

        <Labelled label="How can we help?">
          <textarea rows={7} value={form.message} onChange={set('message')} maxLength={5000} required
            placeholder="Tell us what happened, and what you expected instead."
            className={inputClass} />
          <span className="mt-1 block text-xs text-gray-400">
            {form.message.length}/5000
          </span>
        </Labelled>

        {/*
          Honeypot. Real people never see it; the server REJECTS any submission
          that fills it ('hp' => 'prohibited'), so it must stay empty and stay
          out of the tab order.
        */}
        <input type="text" name="hp" tabIndex={-1} autoComplete="off" aria-hidden
          className="absolute h-0 w-0 overflow-hidden opacity-0" />

        {submit.isError && (
          <p className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
            <AlertTriangle size={16} className="mt-0.5 shrink-0" />
            {/*
              helpdeskApi runs every call through handleErr, which throws a
              NORMALISED error — `.title` and `.message`, no `.response`. Reading
              err.response.data.message here (the raw axios shape) would always
              be undefined, so a real refusal would show the generic fallback and
              the reason the server gave would be lost.
            */}
            <span>
              {submit.error?.title
                || submit.error?.message
                || 'Your request could not be sent. Please check your details and try again.'}
            </span>
          </p>
        )}

        <button
          type="submit"
          disabled={!complete || submit.isPending}
          className="flex w-full items-center justify-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {submit.isPending ? <><Loader2 size={16} className="animate-spin" /> Sending…</> : 'Submit request'}
        </button>

        {/* Many requests are answered by an article, and this saves both sides a
            round trip. Same key, so it is the same tenant's help centre. */}
        <p className="pt-1 text-center text-sm text-gray-500">
          Looking for an answer first?{' '}
          <Link to={`/kb/${key}`} className="inline-flex items-center gap-1 font-medium text-teal-700 hover:underline">
            <BookOpen size={14} /> Browse the help centre
          </Link>
        </p>
      </form>
    </Shell>
  )
}

/* ── chrome ──────────────────────────────────────────────────────────────── */

const inputClass =
  'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 '
  + 'focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500'

function Labelled({ label, children }) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-gray-700">{label}</span>
      {children}
    </label>
  )
}

/*
 * Deliberately light-only, like the help centre it sits beside: this page is
 * shown to the public on someone else's site or in an email, where the CRM's
 * theme variables are not in play.
 */
function Shell({ children }) {
  return (
    <div className="min-h-screen bg-gradient-to-b from-teal-50 to-white px-4 py-10">
      <div className="mx-auto max-w-2xl">
        <div className="mb-6 flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600 text-white">
            <LifeBuoy size={20} />
          </span>
          <div>
            <h1 className="text-xl font-bold text-gray-900">Contact support</h1>
            <p className="text-sm text-gray-500">Tell us what you need and we will get back to you by email.</p>
          </div>
        </div>
        {children}
      </div>
    </div>
  )
}
