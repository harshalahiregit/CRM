import { useState, useEffect } from 'react'
import { useParams, Link } from 'react-router-dom'
import { FileText, Loader2, XCircle, Download, CheckCircle2 } from 'lucide-react'
import { kickoffAckApi } from '@/services/kickoffAckApi'

/**
 * Public read of the kickoff minutes — the "View MOM PDF" link in the e-mail.
 *
 * No auth: the token in the URL is the credential, exactly as the sibling
 * acknowledgement page treats it. Reading is deliberately separate from signing
 * — this never burns the token, so a vendor can reopen the document while
 * deciding, and can still come back to acknowledge afterwards.
 *
 * The PDF is fetched as a blob rather than pointed at with a bare <iframe src>,
 * so a 404/410 arrives here as a readable message instead of the browser
 * rendering the API's error JSON as a broken document.
 */
export default function KickoffMom() {
  const { token } = useParams()
  const [url, setUrl] = useState(null)
  const [err, setErr] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let objectUrl
    let cancelled = false

    kickoffAckApi.mom(token)
      .then((blob) => {
        if (cancelled) return
        objectUrl = URL.createObjectURL(blob)
        setUrl(objectUrl)
        setLoading(false)
      })
      .catch(async (e) => {
        if (cancelled) return
        // The error body is a blob too, because responseType was set for the
        // success case — read it back before it can be reported.
        let msg = 'This link is not valid.'
        try {
          const body = e?.response?.data
          if (body instanceof Blob) msg = JSON.parse(await body.text())?.message || msg
          else if (body?.message) msg = body.message
        } catch { /* keep the default */ }
        setErr(msg)
        setLoading(false)
      })

    return () => { cancelled = true; if (objectUrl) URL.revokeObjectURL(objectUrl) }
  }, [token])

  const download = () => {
    if (!url) return
    const a = document.createElement('a')
    a.href = url
    a.download = 'Kickoff-Minutes.pdf'
    document.body.appendChild(a); a.click(); a.remove()
  }

  const shell = {
    minHeight: '100vh', background: '#f4f5f7', display: 'flex', flexDirection: 'column',
    fontFamily: '-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif',
  }

  if (loading) {
    return (
      <div style={{ ...shell, alignItems: 'center', justifyContent: 'center', gap: 12 }}>
        <Loader2 size={26} className="animate-spin" style={{ color: '#0d9488' }} />
        <div style={{ fontSize: 13.5, color: '#6b7280' }}>Opening the minutes…</div>
      </div>
    )
  }

  if (err) {
    return (
      <div style={{ ...shell, alignItems: 'center', justifyContent: 'center', padding: 24 }}>
        <div style={{
          width: 'min(420px,94vw)', background: '#fff', border: '1px solid #e5e7eb',
          borderRadius: 16, padding: '28px 26px', textAlign: 'center',
        }}>
          <XCircle size={30} style={{ color: '#ef4444' }} />
          <h1 style={{ fontSize: 17, fontWeight: 800, color: '#111827', margin: '12px 0 6px' }}>
            Can’t open these minutes
          </h1>
          <p style={{ fontSize: 13, color: '#6b7280', lineHeight: 1.6, margin: 0 }}>{err}</p>
          <p style={{ fontSize: 12, color: '#9ca3af', lineHeight: 1.6, margin: '14px 0 0' }}>
            You can still open the document from Step 1 of the vendor portal.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div style={shell}>
      <header style={{
        background: 'linear-gradient(135deg,#0d9488,#10b981)', padding: '16px 20px',
        display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <FileText size={20} style={{ color: '#fff' }} />
          <div>
            <div style={{ color: '#fff', fontSize: 15.5, fontWeight: 800 }}>Kickoff Meeting Minutes</div>
            <div style={{ color: 'rgba(255,255,255,.9)', fontSize: 11.5 }}>Read-only — no login required</div>
          </div>
        </div>
        <button onClick={download} style={{
          display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 16px',
          borderRadius: 9, border: 'none', cursor: 'pointer', background: 'rgba(255,255,255,.18)',
          color: '#fff', fontSize: 13, fontWeight: 700,
        }}>
          <Download size={14} /> Download
        </button>
      </header>

      <object data={url} type="application/pdf" style={{ flex: 1, width: '100%', border: 0, minHeight: '70vh' }}>
        {/* Mobile Safari and some in-app browsers refuse to embed a PDF. */}
        <div style={{ padding: 28, textAlign: 'center' }}>
          <p style={{ fontSize: 13.5, color: '#374151', margin: '0 0 14px' }}>
            Your browser can’t display the PDF inline.
          </p>
          <button onClick={download} style={{
            padding: '11px 26px', borderRadius: 9, border: 'none', cursor: 'pointer',
            background: '#0d9488', color: '#fff', fontSize: 14, fontWeight: 700,
          }}>
            Download the minutes
          </button>
        </div>
      </object>

      <footer style={{
        background: '#fff', borderTop: '1px solid #e5e7eb', padding: '12px 20px',
        display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: '#6b7280',
      }}>
        <CheckCircle2 size={14} style={{ color: '#0d9488', flex: 'none' }} />
        <span>
          Finished reading? Use the <strong style={{ color: '#374151' }}>Acknowledge MOM</strong> button
          in the same email to confirm receipt.
        </span>
      </footer>
    </div>
  )
}
