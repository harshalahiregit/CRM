import { useState } from 'react'
import { ArrowUpCircle, CalendarPlus, Ban } from 'lucide-react'
import { Overlay, ModalFooter } from '@/components/ui/kit3d'

/**
 * The three things you can do to a temporary vendor's access window.
 *
 * Convert to Permanent · Extend Access · Close Access.
 *
 * Purchase had all three and TPV had none of them — TPV offered Suspend and
 * Offboard instead, which are different and much blunter instruments. Suspend
 * pauses a vendor for a reason; Offboard ends the engagement, locks the login
 * and terminates every on-site worker, and is not reversible. Neither answers
 * the question a temporary vendor's window actually raises, which is "the
 * shutdown slipped by a week": promoting a contractor for ever was too much and
 * letting them be locked out on the day was too little, so the real answer got
 * done by editing dates in the database.
 *
 * Extracted rather than copied. Both engines already had the endpoints — TPV's
 * were built and left unreachable, with no button anywhere in the app pointing
 * at them — and copying Purchase's 180 lines of buttons and modals across is
 * precisely how these two workspaces drifted in the first place. The engine is
 * three functions, passed in.
 *
 * Props:
 *   vendor      the vendor row; `validity_countdown.is_temporary` decides whether
 *               any of this renders at all
 *   onConvert   () => Promise          make permanent, remove the expiry
 *   onExtend    ({validity_days, extension_reason}) => Promise
 *   onExpire    () => Promise          end the window now
 *   onDone      (message, ok) => void  report back to the page's notice banner
 */
export default function VendorAccessControls({ vendor, onConvert, onExtend, onExpire, onDone }) {
  // null | 'ask' | 'busy' — the confirm step, then the in-flight state. A
  // promotion removes an expiry somebody set deliberately, so it asks first.
  const [converting, setConverting] = useState(null)
  // { validity_days, extension_reason } while the extend form is open.
  const [extending, setExtending] = useState(null)
  const [expiring, setExpiring] = useState(false)
  const [busy, setBusy] = useState(false)

  // Driven by the server's own derivation of registration_type — the same field
  // the promotion writes, so the buttons disappear the moment they have been
  // used and cannot be pressed twice.
  if (!vendor?.validity_countdown?.is_temporary) return null

  const name = vendor.company_name || vendor.name || 'This vendor'
  const report = (r, fallback, ok = true) => onDone?.(r?.message || fallback, ok)
  const fail = (e, fallback) => onDone?.(e?.response?.data?.message || fallback, false)

  const convert = async () => {
    setConverting('busy')
    try {
      report(await onConvert(), 'This vendor is now permanent.')
      setConverting(null)
    } catch (e) {
      fail(e, 'Could not convert this vendor.')
      setConverting(null)
    }
  }

  /** Move the window. The reason is required by the server, so it is required here. */
  const extend = async () => {
    if (!extending.extension_reason.trim()) return
    setBusy(true)
    try {
      report(await onExtend({
        validity_days: Number(extending.validity_days) || undefined,
        extension_reason: extending.extension_reason.trim(),
      }), 'The access window has been extended.')
      setExtending(null)
    } catch (e) {
      fail(e, 'Could not extend the access window.')
    } finally { setBusy(false) }
  }

  /** End it now — signs the vendor out and suspends the portal login. */
  const expire = async () => {
    setBusy(true)
    try {
      report(await onExpire(), 'The access window has been closed.')
      setExpiring(false)
    } catch (e) {
      fail(e, 'Could not close the access window.')
    } finally { setBusy(false) }
  }

  return (
    <>
      <button onClick={() => setConverting('ask')} style={{ ...actBtn, color: '#10b981', borderColor: 'rgba(16,185,129,0.4)' }}
        title="Make this vendor permanent and remove the access expiry">
        <ArrowUpCircle size={14} /> Convert to Permanent
      </button>
      {/* The middle option. Without it "three more days" meant either promoting
          a contractor for ever or letting them be locked out on the day. */}
      <button onClick={() => setExtending({ validity_days: 7, extension_reason: '' })}
        style={{ ...actBtn, color: '#0ea5e9', borderColor: 'rgba(14,165,233,0.4)' }}
        title="Give this vendor more time without making them permanent">
        <CalendarPlus size={14} /> Extend Access
      </button>
      <button onClick={() => setExpiring(true)}
        style={{ ...actBtn, color: '#ef4444', borderColor: 'rgba(239,68,68,0.4)' }}
        title="End this vendor's access now">
        <Ban size={14} /> Close Access
      </button>

      {/* Convert — it asks first, because it removes an expiry somebody set
          deliberately and there is no undo. */}
      {converting && (
        <Overlay onClose={() => converting !== 'busy' && setConverting(null)} width={460} showClose={false}>
          <Head icon={ArrowUpCircle} tone="#10b981" title="Convert to Permanent"
            onClose={() => setConverting(null)} disabled={converting === 'busy'} />
          <div style={{ padding: 22 }}>
            <p style={para}>
              <strong style={{ color: 'var(--text-h)' }}>{name}</strong> becomes a permanent vendor. The
              access expiry is removed, the portal login is re-opened if the window had already closed,
              and the vendor is emailed to say the countdown no longer applies.
            </p>
            <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 4px' }}>
              This is recorded against the vendor and cannot be undone from here.
            </p>
            <ModalFooter onClose={() => setConverting(null)} onConfirm={convert}
              loading={converting === 'busy'} confirmLabel="Convert to Permanent" color="#10b981" />
          </div>
        </Overlay>
      )}

      {/* Extend — a period and a reason. The reason is mandatory server-side, so
          the confirm stays disabled until there is one rather than letting
          somebody submit into a 422. */}
      {extending && (
        <Overlay onClose={() => !busy && setExtending(null)} width={480} showClose={false}>
          <Head icon={CalendarPlus} tone="#0ea5e9" title="Extend Access"
            onClose={() => setExtending(null)} disabled={busy} />
          <div style={{ padding: 22 }}>
            <p style={para}>
              Gives <strong style={{ color: 'var(--text-h)' }}>{name}</strong> more time without making
              them permanent. The expiry reminders start again from the new date, and a vendor already
              locked out is let back in.
            </p>
            <div style={{ marginBottom: 14 }}>
              <label style={fieldLabel}>Extend by (days) *</label>
              <input type="number" min="1" max="365" value={extending.validity_days}
                onChange={e => setExtending(x => ({ ...x, validity_days: e.target.value }))}
                style={{ width: 120, padding: 9, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13, outline: 'none' }} />
            </div>
            <div style={{ marginBottom: 14 }}>
              <label style={fieldLabel}>Reason *</label>
              <textarea rows={3} value={extending.extension_reason}
                onChange={e => setExtending(x => ({ ...x, extension_reason: e.target.value }))}
                placeholder="e.g. Shutdown slipped a week; crew still on site."
                style={{ width: '100%', padding: 10, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, outline: 'none', resize: 'vertical' }} />
              <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>
                Recorded against the vendor. Months later this is the only thing that answers why the date moved.
              </div>
            </div>
            <ModalFooter onClose={() => setExtending(null)} onConfirm={extend}
              loading={busy} disabled={!extending.extension_reason.trim()}
              confirmLabel="Extend Access" color="#0ea5e9" />
          </div>
        </Overlay>
      )}

      {/* Close now. */}
      {expiring && (
        <Overlay onClose={() => !busy && setExpiring(false)} width={460} showClose={false}>
          <Head icon={Ban} tone="#ef4444" title="Close Access"
            onClose={() => setExpiring(false)} disabled={busy} />
          <div style={{ padding: 22 }}>
            <p style={para}>
              Ends <strong style={{ color: 'var(--text-h)' }}>{name}</strong>&apos;s access immediately.
              They are signed out of the portal wherever they are logged in, and cannot sign back in
              until the window is extended or the account is made permanent.
            </p>
            <ModalFooter onClose={() => setExpiring(false)} onConfirm={expire}
              loading={busy} confirmLabel="Close Access Now" color="#ef4444" />
          </div>
        </Overlay>
      )}
    </>
  )
}

/** Modal header. Closes on ✕ only — never a backdrop click. */
function Head({ icon: Icon, tone, title, onClose, disabled }) {
  return (
    <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
      <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
        <Icon size={18} style={{ color: tone }} /> {title}
      </h3>
      <button onClick={onClose} disabled={disabled} aria-label="Close"
        style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 18, color: 'var(--text-muted)' }}>✕</button>
    </div>
  )
}

const actBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 13px', borderRadius: 9, fontSize: 12.5, fontWeight: 700, cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)' }
const para = { marginTop: 0, fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.6 }
const fieldLabel = { display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-h)', marginBottom: 6 }
