import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Code2, RefreshCw, Copy, Check, Send } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

export default function WidgetSettings() {
  const qc = useQueryClient()
  const [copied, setCopied] = useState(false)
  const [origin, setOrigin] = useState('')
  const [demo, setDemo] = useState({ name: '', email: '', subject: '', message: '' })
  const [demoResult, setDemoResult] = useState(null)

  const { data: settings, isLoading } = useQuery({ queryKey: ['helpdesk-widget'], queryFn: helpdeskApi.widget.get })
  const refetch = () => qc.invalidateQueries({ queryKey: ['helpdesk-widget'] })

  const update = useMutation({ mutationFn: (d) => helpdeskApi.widget.update(d), onSuccess: refetch })
  const rotate = useMutation({ mutationFn: () => helpdeskApi.widget.rotate(), onSuccess: refetch })
  const submitDemo = useMutation({
    mutationFn: () => helpdeskApi.public.submitTicket(settings.public_key, demo),
    onSuccess: (r) => { setDemoResult(r); setDemo({ name: '', email: '', subject: '', message: '' }) },
  })

  if (isLoading) return <div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />

  const copy = (txt) => { navigator.clipboard?.writeText(txt); setCopied(true); setTimeout(() => setCopied(false), 1500) }

  return (
    <div className="text-slate-200 max-w-3xl space-y-5">
      <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Support Widget</h1>

      {/* Key + embed */}
      <section className="rounded-2xl border p-4 space-y-3" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
        <div className="flex items-center gap-2">
          <Code2 size={16} style={{ color: '#22d3ee' }} />
          <h2 className="font-semibold text-sm" style={{ color: 'var(--text-h)' }}>Embed on your website</h2>
          <label className="ml-auto flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={!!settings.is_enabled} onChange={e => update.mutate({ is_enabled: e.target.checked })} />
            Enabled
          </label>
        </div>

        <div className="text-xs p-3 rounded-lg font-mono break-all relative" style={{ background: 'var(--bg-global)', color: '#67e8f9' }}>
          {settings.embed_snippet}
          <button onClick={() => copy(settings.embed_snippet)} className="absolute top-2 right-2 flex items-center gap-1 text-[10px]" style={{ color: 'var(--text-muted)' }}>
            {copied ? <Check size={11} /> : <Copy size={11} />}{copied ? 'Copied' : 'Copy'}
          </button>
        </div>

        <div className="flex items-center gap-2 text-xs">
          <span style={{ color: 'var(--text-muted)' }}>Public key:</span>
          <code style={{ color: 'var(--text-h)' }}>{settings.public_key}</code>
          <button onClick={() => rotate.mutate()} className="ml-auto flex items-center gap-1 hover:underline" style={{ color: '#f87171' }}>
            <RefreshCw size={11} /> Rotate
          </button>
        </div>

        <div className="flex items-center gap-2">
          <input value={origin} onChange={e => setOrigin(e.target.value)} placeholder={settings.allowed_origin || 'allowed origin (e.g. acme.com) — blank = any'}
            className="flex-1 text-xs bg-transparent border rounded-lg px-3 py-2 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
          <button onClick={() => update.mutate({ allowed_origin: origin || null })} className="text-xs px-3 py-2 rounded-lg" style={{ background: 'rgba(6,182,212,0.15)', color: '#22d3ee' }}>Save origin</button>
        </div>
      </section>

      {/* Live preview of the public submission (uses the real public endpoint) */}
      <section className="rounded-2xl border p-4 space-y-2" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
        <h2 className="font-semibold text-sm mb-1" style={{ color: 'var(--text-h)' }}>Preview — public submission</h2>
        {demoResult ? (
          <div className="text-sm p-3 rounded-lg" style={{ background: 'rgba(16,185,129,0.1)', color: '#10b981' }}>
            ✓ {demoResult.message} <strong>({demoResult.reference})</strong>
            <button onClick={() => setDemoResult(null)} className="ml-2 underline text-xs">submit another</button>
          </div>
        ) : (
          <div className="grid gap-2 sm:grid-cols-2">
            <input placeholder="Name" value={demo.name} onChange={e => setDemo({ ...demo, name: e.target.value })} className="text-sm bg-transparent border rounded-lg px-3 py-2 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
            <input placeholder="Email" value={demo.email} onChange={e => setDemo({ ...demo, email: e.target.value })} className="text-sm bg-transparent border rounded-lg px-3 py-2 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
            <input placeholder="Subject" value={demo.subject} onChange={e => setDemo({ ...demo, subject: e.target.value })} className="sm:col-span-2 text-sm bg-transparent border rounded-lg px-3 py-2 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
            <textarea placeholder="Message" value={demo.message} onChange={e => setDemo({ ...demo, message: e.target.value })} rows={2} className="sm:col-span-2 text-sm bg-transparent border rounded-lg px-3 py-2 outline-none resize-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
            <button disabled={submitDemo.isPending} onClick={() => submitDemo.mutate()} className="sm:col-span-2 flex items-center justify-center gap-1.5 text-xs font-semibold py-2 rounded-xl" style={{ background: 'linear-gradient(135deg,#22d3ee,#0891b2)', color: '#fff' }}>
              <Send size={13} /> {submitDemo.isPending ? 'Submitting…' : 'Submit as external user'}
            </button>
            {submitDemo.isError && <p className="sm:col-span-2 text-xs text-red-400">{submitDemo.error?.message}</p>}
          </div>
        )}
      </section>
    </div>
  )
}
