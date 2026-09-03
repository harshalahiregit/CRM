import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { ShieldCheck, ShieldAlert, ShieldX, Search } from 'lucide-react'
import { medicalApi } from '@/services/medicalApi'

/**
 * What the QR on a medical certificate opens.
 *
 * No login, and deliberately thin: is this a real certificate, is it still
 * valid, and who signed it. A guard at a gate needs exactly that and has no
 * business seeing anyone's blood pressure — so the page shows the document's
 * standing, not its contents.
 *
 * An unknown number is answered the same way rather than as an error, so
 * scanning a forged code cannot be used to work out which numbers exist.
 */
export default function MedicalVerify() {
  const { certificate } = useParams()
  const [query, setQuery] = useState(certificate || '')
  const [result, setResult] = useState(null)
  const [busy, setBusy] = useState(false)

  const check = async (no) => {
    if (!no) return
    setBusy(true)
    try {
      setResult(await medicalApi.verify(no))
    } catch {
      setResult({ certificate_no: no, found: false, valid: false, message: 'This certificate could not be checked right now.' })
    } finally { setBusy(false) }
  }

  useEffect(() => { if (certificate) check(certificate) }, [certificate]) // eslint-disable-line react-hooks/exhaustive-deps

  const tone = !result ? '#64748b' : result.valid ? '#10b981' : result.found ? '#f59e0b' : '#ef4444'
  const Icon = !result ? ShieldCheck : result.valid ? ShieldCheck : result.found ? ShieldAlert : ShieldX

  return (
    <div style={{
      minHeight: '100vh', background: '#0b1020', color: '#e5e7eb',
      display: 'grid', placeItems: 'center', padding: 20,
      fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
    }}>
      <div style={{ width: 'min(460px, 100%)' }}>
        <h1 style={{ fontSize: 17, fontWeight: 800, margin: '0 0 4px', textAlign: 'center' }}>Medical certificate check</h1>
        <p style={{ fontSize: 12.5, color: '#94a3b8', margin: '0 0 18px', textAlign: 'center' }}>
          Enter or scan a certificate number to check whether it is current.
        </p>

        <form
          onSubmit={e => { e.preventDefault(); check(query.trim()) }}
          style={{ display: 'flex', gap: 8, marginBottom: 18 }}
        >
          <input
            value={query}
            onChange={e => setQuery(e.target.value)}
            placeholder="MED-TPV-2026-000123"
            style={{
              flex: 1, padding: '11px 14px', borderRadius: 10, border: '1px solid #1f2a44',
              background: '#0f172a', color: '#e5e7eb', fontSize: 14, fontFamily: 'monospace',
            }}
          />
          <button
            type="submit"
            disabled={busy}
            style={{
              padding: '11px 16px', borderRadius: 10, border: 'none', background: '#7C3AED',
              color: '#fff', fontWeight: 700, fontSize: 13, cursor: 'pointer', opacity: busy ? 0.6 : 1,
            }}
          >
            <Search size={15} />
          </button>
        </form>

        {result && (
          <div style={{
            background: '#0f172a', border: `1px solid ${tone}55`, borderRadius: 16, padding: 20,
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14 }}>
              <div style={{ width: 44, height: 44, borderRadius: 12, background: tone + '22', color: tone, display: 'grid', placeItems: 'center' }}>
                <Icon size={24} />
              </div>
              <div>
                <div style={{ fontSize: 15, fontWeight: 800, color: tone }}>
                  {result.valid ? 'Valid' : result.found ? 'Not valid' : 'Not found'}
                </div>
                <div style={{ fontSize: 12, color: '#94a3b8' }}>{result.message}</div>
              </div>
            </div>

            {result.found && (
              <dl style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 14px', margin: 0 }}>
                <Item label="Certificate" value={result.certificate_no} mono />
                <Item label="Worker" value={result.worker_name} />
                <Item label="Outcome" value={(result.fitness || '').replace(/_/g, ' ')} />
                <Item label="Examined" value={result.exam_date} />
                <Item label="Valid until" value={result.valid_until} />
                <Item label="Doctor" value={result.doctor_name} />
                <Item label="Licence no" value={result.license_no} mono />
              </dl>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

function Item({ label, value, mono }) {
  return (
    <div>
      <dt style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#64748b', fontWeight: 700 }}>{label}</dt>
      <dd style={{ margin: '2px 0 0', fontSize: 13, color: '#e5e7eb', fontFamily: mono ? 'monospace' : 'inherit' }}>{value || '—'}</dd>
    </div>
  )
}
