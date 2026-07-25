import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Code2, RefreshCw, Copy, Check, Send,
  Shield, Globe, Key, AlertCircle
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

export default function WidgetSettings() {
  const qc = useQueryClient()
  const [copied, setCopied] = useState(false)
  const [origin, setOrigin] = useState('')
  const [demo, setDemo] = useState({ name: '', email: '', subject: '', message: '' })
  const [demoResult, setDemoResult] = useState(null)

  const { data: settings, isLoading } = useQuery({
    queryKey: ['helpdesk-widget'],
    queryFn: helpdeskApi.widget.get,
  })
  const refetch = () => qc.invalidateQueries({ queryKey: ['helpdesk-widget'] })

  const update = useMutation({ mutationFn: (d) => helpdeskApi.widget.update(d), onSuccess: refetch })
  const rotate = useMutation({ mutationFn: () => helpdeskApi.widget.rotate(), onSuccess: refetch })
  const submitDemo = useMutation({
    mutationFn: () => helpdeskApi.public.submitTicket(settings.public_key, demo),
    onSuccess: (r) => {
      setDemoResult(r)
      setDemo({ name: '', email: '', subject: '', message: '' })
    },
  })

  if (isLoading) {
    return (
      <div className="space-y-4">
        {[1, 2].map(i => (
          <div key={i} className="h-48 rounded-2xl animate-pulse" style={{ background: 'var(--border)' }} />
        ))}
      </div>
    )
  }

  const copy = (txt) => {
    navigator.clipboard?.writeText(txt)
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  return (
    <div className="max-w-3xl space-y-5 animate-[tiltIn_0.35s_ease_forwards]">

      {/* Header */}
      <div>
        <p className="label-caps mb-0.5">Helpdesk</p>
        <h1
          className="font-black"
          style={{ fontSize: 'clamp(1.3rem,2.2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}
        >
          Support <span className="text-gradient">Widget</span>
        </h1>
        <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>
          Embed a support form on any website
        </p>
      </div>

      {/* Embed settings */}
      <section
        className="rounded-2xl p-5 space-y-4"
        style={{
          background: 'var(--bg-card)',
          border: '1px solid var(--border)',
          boxShadow: 'var(--shadow-card)',
        }}
      >
        {/* Section header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <div
              className="w-8 h-8 rounded-xl flex items-center justify-center"
              style={{ background: 'rgba(34,211,238,0.12)' }}
            >
              <Code2 size={15} style={{ color: '#22d3ee' }} />
            </div>
            <div>
              <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Embed on Your Website</h2>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Copy this snippet into your HTML</p>
            </div>
          </div>

          {/* Enabled toggle */}
          <label className="flex items-center gap-2 cursor-pointer">
            <span className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>
              {settings.is_enabled ? 'Enabled' : 'Disabled'}
            </span>
            <button
              onClick={() => update.mutate({ is_enabled: !settings.is_enabled })}
              className="relative w-11 h-6 rounded-full transition-colors duration-200"
              style={{ background: settings.is_enabled ? '#22d3ee' : 'var(--border)' }}
            >
              <span
                className="absolute top-0.5 w-5 h-5 rounded-full bg-white transition-all duration-200"
                style={{ left: settings.is_enabled ? 22 : 2 }}
              />
            </button>
          </label>
        </div>

        {/* Code snippet */}
        <div
          className="relative rounded-xl p-4 font-mono text-xs overflow-auto"
          style={{
            background: 'var(--bg-global)',
            border: '1px solid var(--border)',
            color: '#67e8f9',
            lineHeight: 1.6,
          }}
        >
          <pre className="whitespace-pre-wrap break-all">{settings.embed_snippet}</pre>
          <button
            onClick={() => copy(settings.embed_snippet)}
            className="absolute top-3 right-3 flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-1.5 rounded-lg transition-all hover:opacity-80"
            style={{ background: 'rgba(34,211,238,0.15)', color: '#22d3ee' }}
          >
            {copied ? <Check size={10} /> : <Copy size={10} />}
            {copied ? 'Copied!' : 'Copy'}
          </button>
        </div>

        {/* Key info */}
        <div
          className="flex items-center gap-3 p-3 rounded-xl"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}
        >
          <Key size={13} style={{ color: '#22d3ee', flexShrink: 0 }} />
          <div className="flex-1 min-w-0">
            <p className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
              Public Key
            </p>
            <code className="text-xs font-mono" style={{ color: 'var(--text-h)' }}>
              {settings.public_key}
            </code>
          </div>
          <button
            onClick={() => rotate.mutate()}
            className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg transition-all hover:opacity-80"
            style={{ background: 'rgba(239,68,68,0.1)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.2)' }}
          >
            <RefreshCw size={11} className={rotate.isPending ? 'animate-spin' : ''} />
            Rotate
          </button>
        </div>

        {/* Allowed origin */}
        <div>
          <label
            className="block text-xs font-bold uppercase tracking-wider mb-2"
            style={{ color: 'var(--text-muted)' }}
          >
            <Globe size={11} className="inline mr-1.5" />
            Allowed Origin
          </label>
          <div className="flex gap-2">
            <div className="relative flex-1">
              <Shield
                size={13}
                className="absolute left-3 top-1/2 -translate-y-1/2"
                style={{ color: 'var(--text-muted)' }}
              />
              <input
                value={origin}
                onChange={e => setOrigin(e.target.value)}
                placeholder={settings.allowed_origin || 'e.g. acme.com — blank = any origin'}
                className="w-full text-sm rounded-xl pl-9 pr-4 py-2.5 outline-none"
                style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}
              />
            </div>
            <button
              onClick={() => update.mutate({ allowed_origin: origin || null })}
              className="text-xs font-bold px-4 py-2 rounded-xl transition-all hover:opacity-80"
              style={{ background: 'rgba(34,211,238,0.12)', color: '#22d3ee', border: '1px solid rgba(34,211,238,0.2)' }}
            >
              Save
            </button>
          </div>
        </div>
      </section>

      {/* Live demo / preview */}
      <section
        className="rounded-2xl p-5 space-y-4"
        style={{
          background: 'var(--bg-card)',
          border: '1px solid var(--border)',
          boxShadow: 'var(--shadow-card)',
        }}
      >
        <div className="flex items-center gap-2 mb-1">
          <div
            className="w-8 h-8 rounded-xl flex items-center justify-center"
            style={{ background: 'rgba(139,92,246,0.12)' }}
          >
            <Send size={14} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Preview — Public Submission</h2>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Tests the real public endpoint with your widget key
            </p>
          </div>
        </div>

        {demoResult ? (
          <div
            className="flex items-start gap-3 p-4 rounded-xl"
            style={{ background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.2)' }}
          >
            <div
              className="w-7 h-7 rounded-xl flex items-center justify-center shrink-0 mt-0.5"
              style={{ background: 'rgba(16,185,129,0.15)' }}
            >
              <Check size={14} style={{ color: '#10b981' }} />
            </div>
            <div className="flex-1">
              <p className="text-sm font-semibold" style={{ color: '#10b981' }}>
                {demoResult.message}
              </p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Reference: <strong style={{ color: 'var(--text-h)' }}>{demoResult.reference}</strong>
              </p>
            </div>
            <button
              onClick={() => setDemoResult(null)}
              className="text-xs font-semibold hover:opacity-70 transition-opacity"
              style={{ color: '#22d3ee' }}
            >
              Submit another
            </button>
          </div>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            <InputField
              placeholder="Your name"
              value={demo.name}
              onChange={e => setDemo({ ...demo, name: e.target.value })}
            />
            <InputField
              placeholder="Email address"
              value={demo.email}
              onChange={e => setDemo({ ...demo, email: e.target.value })}
              type="email"
            />
            <InputField
              placeholder="Subject"
              value={demo.subject}
              onChange={e => setDemo({ ...demo, subject: e.target.value })}
              className="sm:col-span-2"
            />
            <textarea
              placeholder="Message…"
              value={demo.message}
              onChange={e => setDemo({ ...demo, message: e.target.value })}
              rows={3}
              className="sm:col-span-2 text-sm rounded-xl px-3.5 py-2.5 outline-none resize-none"
              style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}
            />
            <button
              disabled={submitDemo.isPending}
              onClick={() => submitDemo.mutate()}
              className="sm:col-span-2 flex items-center justify-center gap-2 text-sm font-bold py-2.5 rounded-xl transition-all duration-200 disabled:opacity-50 hover:-translate-y-0.5"
              style={{
                background: 'linear-gradient(135deg,#22d3ee,#0891b2)',
                color: '#fff',
                boxShadow: '0 4px 14px rgba(6,182,212,0.35)',
              }}
            >
              <Send size={14} />
              {submitDemo.isPending ? 'Submitting…' : 'Submit as External User'}
            </button>
            {submitDemo.isError && (
              <div
                className="sm:col-span-2 flex items-center gap-2 p-3 rounded-xl text-xs"
                style={{ background: 'rgba(239,68,68,0.08)', color: '#ef4444' }}
              >
                <AlertCircle size={13} />
                {submitDemo.error?.message}
              </div>
            )}
          </div>
        )}
      </section>
    </div>
  )
}

function InputField({ placeholder, value, onChange, type = 'text', className = '' }) {
  return (
    <input
      type={type}
      placeholder={placeholder}
      value={value}
      onChange={onChange}
      className={`text-sm rounded-xl px-3.5 py-2.5 outline-none ${className}`}
      style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)', width: '100%' }}
    />
  )
}
