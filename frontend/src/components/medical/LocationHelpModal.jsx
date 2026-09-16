import { useMemo } from 'react'
import { MapPin, X, Monitor, Apple, ShieldAlert, RefreshCw } from 'lucide-react'
import Modal from '@/components/ui/Modal'

/**
 * How to switch location back on, for somebody who has never had to.
 *
 * Location is now mandatory on an examination — it is part of what makes the
 * certificate evidence. That turns a refused permission from an inconvenience
 * into a hard stop, and "Location permission refused" is not something a doctor
 * standing on a site can act on.
 *
 * Two things make this awkward, and the modal handles both. A browser will not
 * re-prompt once permission has been denied for a site, so pressing the button
 * again does nothing at all — the block has to be cleared by hand. And the
 * setting exists in two places: the browser's own, and the operating system's,
 * which silently overrides it. Fixing one and not the other looks like the
 * instructions are wrong.
 *
 * The steps are shown for the platform the visitor is actually on, with the
 * other available beside it rather than hidden — a shared site tablet is not
 * always the machine whose settings need changing.
 */
export default function LocationHelpModal({ open, onClose, onRetry, reason }) {
  // Only to choose which panel opens first; both are always reachable.
  const isMac = useMemo(
    () => typeof navigator !== 'undefined' && /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || ''),
    [],
  )

  if (!open) return null

  return (
    <Modal open onClose={onClose} style={{ width: 'min(620px, 96vw)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 16px', borderBottom: '1px solid var(--border)' }}>
        <div style={{ width: 32, height: 32, borderRadius: 10, background: '#f59e0b22', color: '#f59e0b', display: 'grid', placeItems: 'center' }}>
          <ShieldAlert size={17} />
        </div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h3 style={{ margin: 0, fontSize: 15, fontWeight: 900, color: 'var(--text-h)' }}>Turn on location to continue</h3>
          <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--text-muted)' }}>
            {reason === 'unavailable'
              ? 'This device or browser cannot report a location.'
              : 'Your browser is blocking location for this site.'}
          </p>
        </div>
        <button onClick={onClose} aria-label="Close" style={ICON_BTN}><X size={17} /></button>
      </div>

      <div style={{ padding: 16, maxHeight: '65vh', overflowY: 'auto' }}>
        <p style={{ margin: '0 0 14px', fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.6 }}>
          An examination records <strong style={{ color: 'var(--text-h)' }}>where</strong> it happened — it is printed on the
          certificate and is part of what makes it verifiable afterwards. It cannot be submitted without one.
          <br /><br />
          Once you have said &ldquo;Block&rdquo;, the browser will not ask again, so pressing the button once more does
          nothing until the block is cleared by hand below.
        </p>

        <Section title="In the browser" icon={MapPin} open>
          <Step n="1">Click the <strong>padlock</strong> (or the icon left of the web address) in the address bar.</Step>
          <Step n="2">Find <strong>Location</strong> in the list that appears.</Step>
          <Step n="3">Set it to <strong>Allow</strong>.</Step>
          <Step n="4">Reload this page and try again.</Step>
          <Note>
            In Safari it is <strong>Safari → Settings for This Website…</strong>, then set Location to <strong>Allow</strong>.
          </Note>
        </Section>

        <Section title="Windows 10 and 11" icon={Monitor} open={!isMac}>
          <Step n="1">Open <strong>Settings</strong> (Windows key, then type &ldquo;Settings&rdquo;).</Step>
          <Step n="2">Go to <strong>Privacy &amp; security → Location</strong>.</Step>
          <Step n="3">Turn on <strong>Location services</strong>.</Step>
          <Step n="4">Turn on <strong>Let apps access your location</strong>.</Step>
          <Step n="5">Scroll down and turn on <strong>Let desktop apps access your location</strong> — your browser is one of these, and this switch is the one people miss.</Step>
          <Step n="6">Come back, reload the page and try again.</Step>
        </Section>

        <Section title="macOS" icon={Apple} open={isMac}>
          <Step n="1">Open <strong>System Settings</strong> (Apple menu → System Settings).</Step>
          <Step n="2">Go to <strong>Privacy &amp; Security → Location Services</strong>.</Step>
          <Step n="3">Turn <strong>Location Services</strong> on.</Step>
          <Step n="4">Find your browser in the list and switch it on.</Step>
          <Step n="5">You may need the padlock at the bottom and your password to change this.</Step>
          <Step n="6">Come back, reload the page and try again.</Step>
          <Note>
            On an iPad it is <strong>Settings → Privacy &amp; Security → Location Services</strong>, then your browser.
          </Note>
        </Section>

        <div style={{
          marginTop: 14, padding: '11px 12px', borderRadius: 11,
          background: 'rgba(124,58,237,0.08)', border: '1px solid rgba(124,58,237,0.28)',
          fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.55,
        }}>
          <strong style={{ color: '#a78bfa' }}>Still not working?</strong> Location only works on a secure (https) address.
          If this page is open on a plain http address, ask your administrator — no browser will report a location on one.
        </div>
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '12px 16px', borderTop: '1px solid var(--border)' }}>
        <button onClick={onClose} style={BTN}>Close</button>
        <button onClick={() => { onRetry?.(); onClose?.() }} style={{ ...BTN, ...PRIMARY }}>
          <RefreshCw size={14} /> Try again
        </button>
      </div>
    </Modal>
  )
}

/* ── Pieces ──────────────────────────────────────────────────────────────── */

function Section({ title, icon: Icon, children, open }) {
  return (
    <details open={open} style={{
      marginBottom: 10, borderRadius: 12, border: '1px solid var(--border)',
      background: 'var(--bg-input)', overflow: 'hidden',
    }}>
      <summary style={{
        display: 'flex', alignItems: 'center', gap: 8, padding: '11px 13px',
        cursor: 'pointer', fontSize: 13, fontWeight: 800, color: 'var(--text-h)',
        listStyle: 'none', minHeight: 44,
      }}>
        <Icon size={15} style={{ color: '#a78bfa' }} /> {title}
      </summary>
      <div style={{ padding: '2px 13px 12px' }}>{children}</div>
    </details>
  )
}

const Step = ({ n, children }) => (
  <div style={{ display: 'flex', gap: 9, alignItems: 'flex-start', marginTop: 8 }}>
    <span style={{
      flexShrink: 0, width: 19, height: 19, borderRadius: '50%', display: 'grid', placeItems: 'center',
      background: '#7C3AED22', color: '#a78bfa', fontSize: 10.5, fontWeight: 900,
    }}>{n}</span>
    <span style={{ fontSize: 12.5, color: 'var(--text-body, #c8c3dd)', lineHeight: 1.5 }}>{children}</span>
  </div>
)

const Note = ({ children }) => (
  <p style={{ margin: '10px 0 0 28px', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>{children}</p>
)

const ICON_BTN = {
  width: 32, height: 32, borderRadius: 9, display: 'grid', placeItems: 'center',
  border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer',
}
const BTN = {
  display: 'inline-flex', alignItems: 'center', gap: 6, minHeight: 40, padding: '9px 16px',
  borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)',
  color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700,
}
const PRIMARY = { background: '#7C3AED', border: 'none', color: '#fff' }
