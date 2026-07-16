import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { webToLeadApi } from '@/services/webToLeadApi'

export default function PublicLeadForm() {
  const { token } = useParams()
  const [form, setForm] = useState(null)
  const [values, setValues] = useState({})
  const [status, setStatus] = useState('loading') // loading | ready | notfound | submitting | done
  const [error, setError] = useState('')
  const [successMsg, setSuccessMsg] = useState('')

  useEffect(() => {
    webToLeadApi.publicForm(token)
      .then(f => { setForm(f); setStatus('ready') })
      .catch(() => setStatus('notfound'))
  }, [token])

  const sv = (k, v) => setValues(p => ({ ...p, [k]: v }))

  const submit = async (e) => {
    e.preventDefault()
    setError(''); setStatus('submitting')
    try {
      const res = await webToLeadApi.publicSubmit(token, values)
      setSuccessMsg(res.message || 'Thank you!')
      if (res.redirect_url) { window.location.href = res.redirect_url; return }
      setStatus('done')
    } catch (err) { setError(err.message); setStatus('ready') }
  }

  const wrap = (children) => (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(135deg,#faf8ff,#f3efff)', padding: 20 }}>
      <div style={{ width: '100%', maxWidth: 460, background: '#fff', borderRadius: 20, padding: 32, boxShadow: '0 12px 40px rgba(124,58,237,0.15)', border: '1px solid rgba(124,58,237,0.12)' }}>
        {children}
      </div>
    </div>
  )

  if (status === 'loading') return wrap(<p style={{ textAlign: 'center', color: '#6b63a0' }}>Loading…</p>)
  if (status === 'notfound') return wrap(<p style={{ textAlign: 'center', color: '#ef4444' }}>This form is no longer available.</p>)
  if (status === 'done') return wrap(
    <div style={{ textAlign: 'center' }}>
      <div style={{ width: 56, height: 56, borderRadius: '50%', background: 'rgba(16,185,129,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 16px', fontSize: 28 }}>✓</div>
      <h2 style={{ fontWeight: 800, color: '#1a1535', marginBottom: 8 }}>Submitted</h2>
      <p style={{ color: '#453d6b' }}>{successMsg}</p>
    </div>
  )

  return wrap(
    <form onSubmit={submit}>
      <h1 style={{ fontSize: 22, fontWeight: 900, color: '#1a1535', marginBottom: 4 }}>{form.name}</h1>
      <p style={{ fontSize: 13, color: '#6b63a0', marginBottom: 20 }}>Fill in your details and we'll be in touch.</p>
      {(form.fields || []).map(f => (
        <div key={f.key} style={{ marginBottom: 14 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#453d6b', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            {f.label}{f.required ? ' *' : ''}
          </label>
          {f.type === 'textarea'
            ? <textarea rows={3} required={!!f.required} value={values[f.key] || ''} onChange={e => sv(f.key, e.target.value)} style={inputStyle} />
            : <input type={f.key === 'email' ? 'email' : 'text'} required={!!f.required} value={values[f.key] || ''} onChange={e => sv(f.key, e.target.value)} style={inputStyle} />}
        </div>
      ))}
      {error && <p style={{ color: '#ef4444', fontSize: 12, marginBottom: 12 }}>{error}</p>}
      <button type="submit" disabled={status === 'submitting'} style={{ width: '100%', padding: '12px', borderRadius: 14, border: 'none', color: '#fff', fontWeight: 800, fontSize: 14, cursor: 'pointer', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: status === 'submitting' ? 0.6 : 1 }}>
        {status === 'submitting' ? 'Submitting…' : 'Submit'}
      </button>
    </form>
  )
}

const inputStyle = {
  width: '100%', padding: '11px 14px', borderRadius: 12, fontSize: 14,
  border: '1px solid rgba(80,60,130,0.28)', background: 'rgba(124,58,237,0.03)',
  color: '#1a1535', outline: 'none', boxSizing: 'border-box',
}
