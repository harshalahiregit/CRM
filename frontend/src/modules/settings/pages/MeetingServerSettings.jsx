import { useState, useEffect } from 'react'
import { Save, Video, AlertTriangle, CheckCircle2 } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'

const PUBLIC_HOST = 'meet.jit.si'

/** Reduce whatever was typed to a bare host, the way the server will store it. */
const hostOf = (value) => {
  const v = String(value || '').trim().replace(/^[a-z][a-z0-9+.-]*:\/\//i, '')
  return v.split('/')[0].replace(/\.+$/, '').toLowerCase()
}

/**
 * Where online meetings are actually held.
 *
 * This exists to answer one question people keep hitting: "why is it asking me
 * to sign in with Google?" The answer is that the default server is 8x8's free
 * public Jitsi, which requires whoever STARTS a room to authenticate with
 * Google, GitHub or Facebook. That is enforced on 8x8's side — the application
 * cannot switch it off — so the only real fix is a different server, and this
 * is where that is chosen.
 */
export default function MeetingServerSettings() {
  const toast = useToast()
  const [values, setValues] = useState(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    settingsApi.group.get('meetings')
      .then(d => setValues(d?.values || {}))
      .catch(e => toast.error(e.message))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const d = await settingsApi.group.update('meetings', {
        ...values,
        // Store the host, not the URL — the link is built as https://<host>/<room>,
        // so a stored scheme would produce https://https://meet…/room.
        jitsi_domain: hostOf(values.jitsi_domain) || null,
      })
      setValues(d?.values || {})
      toast.success('Meeting server saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (!values) {
    return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>
  }

  const current = hostOf(values.jitsi_domain) || PUBLIC_HOST
  const onPublic = current === PUBLIC_HOST

  return (
    <div className="space-y-4">
      <div className="card-3d">
        <div className="flex items-center gap-2 mb-1">
          <Video size={16} style={{ color: '#7C3AED' }} />
          <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Meeting server</h2>
        </div>
        <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>
          Online meetings are held on a Jitsi server. Leave this empty to use the free public one,
          or point it at your own.
        </p>

        {onPublic ? (
          <div className="flex items-start gap-2 p-3 rounded-lg mb-4"
            style={{ background: 'rgba(245,158,11,0.10)', border: '1px solid rgba(245,158,11,0.30)' }}>
            <AlertTriangle size={15} style={{ color: '#d97706', flexShrink: 0, marginTop: 1 }} />
            <div className="text-xs leading-relaxed" style={{ color: 'var(--text-h)' }}>
              <strong>You are on the free public server ({PUBLIC_HOST}).</strong> Whoever starts a
              meeting there is asked to sign in with Google, GitHub or Facebook first — that is 8x8&apos;s
              rule on their public instance, and nothing in this app can turn it off. Everyone else
              joins normally once the host is in.
              <div className="mt-2">
                To remove the sign-in, run your own Jitsi server and enter its address below. It is a
                single Docker deployment (<code>jitsi/docker-jitsi-meet</code>) and needs no account,
                no keys and no per-user licence.
              </div>
            </div>
          </div>
        ) : (
          <div className="flex items-start gap-2 p-3 rounded-lg mb-4"
            style={{ background: 'rgba(34,197,94,0.10)', border: '1px solid rgba(34,197,94,0.30)' }}>
            <CheckCircle2 size={15} style={{ color: '#16a34a', flexShrink: 0, marginTop: 1 }} />
            <div className="text-xs leading-relaxed" style={{ color: 'var(--text-h)' }}>
              Meetings are held on <strong>{current}</strong>. No social sign-in is required, provided
              that server does not ask for one.
            </div>
          </div>
        )}

        <div className="grid md:grid-cols-2 gap-4">
          <div>
            <label className="label">Jitsi server</label>
            <input
              className="input-3d text-sm"
              value={values.jitsi_domain ?? ''}
              onChange={e => setValues(p => ({ ...p, jitsi_domain: e.target.value }))}
              placeholder={PUBLIC_HOST}
            />
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              Just the address — <code>meet.yourcompany.com</code>. A full URL is fine too; it will be
              trimmed to the host.
            </p>
          </div>
        </div>

        {/* Changing this must not look like it will fix meetings already sent out. */}
        <p className="text-xs mt-4" style={{ color: 'var(--text-muted)' }}>
          Meetings already created keep the link they were created with, so invitations that have gone
          out keep working. Only new meetings use a changed server.
        </p>

        <div className="flex justify-end mt-5">
          <button onClick={save} disabled={saving} className="btn-3d-primary text-sm">
            <Save size={14} /> {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  )
}
