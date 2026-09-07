import { useState, useEffect } from 'react'
import { Send, Save, ShieldCheck, AlertTriangle, MessageCircle } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'

const BLANK = { provider: 'cloud', access_token: '', phone_number_id: '', waba_id: '', api_version: 'v21.0', enabled: false }

// Where this workspace's WhatsApp actually leaves from. "It works" and "it works,
// from the platform's number" look identical on a settings screen otherwise, and
// the difference is what the recipient sees as the sender.
const SOURCE = {
  tenant:   { label: 'This workspace’s own number', tone: '#10b981' },
  platform: { label: 'The platform number (shared fallback)', tone: '#f59e0b' },
  none:     { label: 'Nothing configured — WhatsApp will not send', tone: '#f87171' },
}

export default function WhatsAppSettings() {
  const toast = useToast()
  const [form, setForm] = useState(BLANK)
  const [hasToken, setHasToken] = useState(false)
  const [source, setSource] = useState('none')
  const [template, setTemplate] = useState(null)
  const [sender, setSender] = useState(null)
  const [warnings, setWarnings] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [verifying, setVerifying] = useState(false)
  const [testing, setTesting] = useState(false)
  const [testTo, setTestTo] = useState('')

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const hydrate = (d) => {
    const s = d.settings || {}
    setForm({ ...BLANK, ...Object.fromEntries(Object.entries(s).filter(([k]) => k in BLANK)), access_token: '' })
    setHasToken(!!s.has_token)
    setSource(d.source || 'none')
    setTemplate(d.template || null)
    if (s.display_phone_number) setSender({ display_phone_number: s.display_phone_number, verified_name: s.verified_name })
  }

  useEffect(() => {
    settingsApi.whatsapp.get().then(hydrate).catch(e => toast.error(e.message)).finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const d = await settingsApi.whatsapp.update(form)
      setHasToken(!!d.settings?.has_token)
      setSource(d.source)
      sf('access_token', '')
      toast.success('WhatsApp settings saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const verify = async () => {
    setVerifying(true)
    try {
      const r = await settingsApi.whatsapp.verify()
      setSender(r.sender)
      setWarnings(r.warnings || [])
      toast.success(`Connected as ${r.sender?.verified_name || 'this account'}`)
    } catch (e) { toast.error(e.message) } finally { setVerifying(false) }
  }

  const sendTest = async () => {
    if (!testTo) return toast.error('Enter a mobile number to send the test to')
    setTesting(true)
    try {
      const r = await settingsApi.whatsapp.test(testTo)
      toast.success(r.message)
    } catch (e) { toast.error(e.message) } finally { setTesting(false) }
  }

  if (loading) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  const src = SOURCE[source] || SOURCE.none

  return (
    <div className="space-y-4">
      <div className="card-3d">
        <div className="flex items-start justify-between mb-5 gap-4 flex-wrap">
          <div>
            <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>WhatsApp sender</h2>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              Notifications this workspace sends over WhatsApp leave from this number. Leave it
              empty to use the platform&rsquo;s.
            </p>
          </div>
          <span className="text-[11px] font-bold px-3 py-1.5 rounded-xl whitespace-nowrap"
            style={{ background: `${src.tone}1f`, color: src.tone }}>{src.label}</span>
        </div>

        <div className="grid md:grid-cols-2 gap-4">
          <div>
            <label className="label">Phone number ID</label>
            <input className="input-3d text-sm" value={form.phone_number_id || ''}
              onChange={e => sf('phone_number_id', e.target.value)} placeholder="600265183160455" />
          </div>
          <div>
            <label className="label">WhatsApp Business account ID</label>
            <input className="input-3d text-sm" value={form.waba_id || ''}
              onChange={e => sf('waba_id', e.target.value)} placeholder="539109509291449" />
          </div>
          <div className="md:col-span-2">
            <label className="label">Access token{hasToken ? ' (saved — leave blank to keep it)' : ''}</label>
            <input className="input-3d text-sm" type="password" value={form.access_token}
              onChange={e => sf('access_token', e.target.value)}
              placeholder={hasToken ? '•'.repeat(24) : 'Paste the System User token'} />
            {/* The single most common way this integration dies. */}
            <p className="text-[11px] mt-1.5" style={{ color: 'var(--text-muted)' }}>
              Use a <b>System User</b> token from Business Settings, not one copied out of the
              Graph API Explorer — an Explorer token expires within the hour and WhatsApp stops
              sending on its own.
            </p>
          </div>
          <div>
            <label className="label">API version</label>
            <input className="input-3d text-sm" value={form.api_version || ''}
              onChange={e => sf('api_version', e.target.value)} placeholder="v21.0" />
          </div>
          <div className="flex items-end">
            <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-h)' }}>
              <input type="checkbox" checked={!!form.enabled} onChange={e => sf('enabled', e.target.checked)} />
              Send from this number
            </label>
          </div>
        </div>

        <div className="flex gap-3 pt-5 flex-wrap">
          <button onClick={save} disabled={saving}
            className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white"
            style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: saving ? 0.7 : 1 }}>
            <Save size={15} /> {saving ? 'Saving…' : 'Save'}
          </button>
          <button onClick={verify} disabled={verifying}
            className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold"
            style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            <ShieldCheck size={15} /> {verifying ? 'Checking…' : 'Check connection'}
          </button>
        </div>

        {sender && (
          <div className="mt-4 px-4 py-3 rounded-xl text-sm" style={{ background: 'var(--bg-input)' }}>
            <span style={{ color: 'var(--text-h)' }}><b>{sender.verified_name}</b></span>
            <span style={{ color: 'var(--text-muted)' }}> · {sender.display_phone_number}</span>
            {sender.quality_rating && <span style={{ color: 'var(--text-muted)' }}> · quality {sender.quality_rating}</span>}
          </div>
        )}

        {warnings.map(w => (
          <div key={w} className="mt-2 px-4 py-3 rounded-xl text-xs flex items-start gap-2"
            style={{ background: 'rgba(245,158,11,0.12)', color: '#f59e0b' }}>
            <AlertTriangle size={15} className="shrink-0 mt-0.5" /><span>{w}</span>
          </div>
        ))}
      </div>

      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Send a test</h2>
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
          Sends the standard <code>hello_world</code> template, which every WhatsApp account has
          from the start — so this works before any of your own templates are approved.
        </p>
        <div className="flex gap-3 flex-wrap">
          <input className="input-3d text-sm flex-1" style={{ minWidth: 200 }} value={testTo}
            onChange={e => setTestTo(e.target.value)} placeholder="98765 43210" />
          <button onClick={sendTest} disabled={testing}
            className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white"
            style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: testing ? 0.7 : 1 }}>
            <Send size={15} /> {testing ? 'Sending…' : 'Send test'}
          </button>
        </div>
      </div>

      <div className="card-3d">
        <h2 className="font-bold text-base mb-1 flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <MessageCircle size={16} /> Why notifications go out as a template
        </h2>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          WhatsApp only carries free-form text for 24 hours after <i>the person</i> messages you.
          Everything the CRM sends starts at our end — an approval, a rejection, a reminder — so it
          has to go out as a template Meta has approved, or it is refused.
          {template && <> This workspace uses <b style={{ color: 'var(--text-h)' }}>{template.name}</b> ({template.language}).</>}
        </p>
        <p className="text-xs mt-2" style={{ color: 'var(--text-muted)' }}>
          Turn WhatsApp on per category under <b>Settings → Notifications</b>. It is off by
          default, because every message costs money.
        </p>
      </div>
    </div>
  )
}
