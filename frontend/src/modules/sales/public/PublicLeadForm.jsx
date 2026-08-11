import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { webToLeadApi } from '@/services/webToLeadApi'
import { Star, Upload, CheckCircle2 } from 'lucide-react'

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

  const toggleMultiSelect = (k, opt) => {
    setValues(p => {
      const current = Array.isArray(p[k]) ? p[k] : []
      const next = current.includes(opt) ? current.filter(x => x !== opt) : [...current, opt]
      return { ...p, [k]: next }
    })
  }

  const handleFileUpload = (k, file) => {
    if (!file) return
    const reader = new FileReader()
    reader.onload = (e) => {
      sv(k, { name: file.name, size: file.size, type: file.type, data: e.target.result })
    }
    reader.readAsDataURL(file)
  }

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
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg-main, linear-gradient(135deg,#faf8ff,#f3efff))', padding: 20 }}>
      <div style={{ width: '100%', maxWidth: 520, background: 'var(--bg-card, #fff)', color: 'var(--text-h, #1a1535)', borderRadius: 20, padding: 32, boxShadow: '0 12px 40px rgba(0,0,0,0.15)', border: '1px solid var(--border, rgba(124,58,237,0.12))' }}>
        {children}
      </div>
    </div>
  )

  if (status === 'loading') return wrap(<p style={{ textAlign: 'center', color: 'var(--text-muted, #6b63a0)' }}>Loading…</p>)
  if (status === 'notfound') return wrap(<p style={{ textAlign: 'center', color: '#ef4444' }}>This form is no longer available.</p>)
  if (status === 'done') return wrap(
    <div style={{ textAlign: 'center' }}>
      <div style={{ width: 56, height: 56, borderRadius: '50%', background: 'rgba(16,185,129,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 16px', color: '#10b981' }}>
        <CheckCircle2 size={32} />
      </div>
      <h2 style={{ fontWeight: 800, color: 'var(--text-h, #1a1535)', marginBottom: 8, fontSize: 22 }}>Submission Received</h2>
      <p style={{ color: 'var(--text-muted, #453d6b)', fontSize: 14 }}>{successMsg}</p>
    </div>
  )

  return wrap(
    <form onSubmit={submit}>
      <h1 style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h, #1a1535)', marginBottom: 4 }}>{form.name}</h1>
      <p style={{ fontSize: 13, color: 'var(--text-muted, #6b63a0)', marginBottom: 20 }}>Fill in your details and we'll be in touch.</p>
      {(form.fields || []).map(f => (
        <div key={f.key} style={{ marginBottom: 16 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-muted, #453d6b)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            {f.label}{f.required ? ' *' : ''}
          </label>

          {f.type === 'textarea' ? (
            <textarea rows={3} required={!!f.required} value={values[f.key] || ''} onChange={e => sv(f.key, e.target.value)} style={inputStyle} />
          ) : f.type === 'select' ? (
            <select required={!!f.required} value={values[f.key] || ''} onChange={e => sv(f.key, e.target.value)} style={inputStyle}>
              <option value="">Select option…</option>
              {(f.options || []).map(opt => <option key={opt} value={opt}>{opt}</option>)}
            </select>
          ) : f.type === 'multiselect' ? (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginTop: 4 }}>
              {(f.options || []).map(opt => {
                const checked = Array.isArray(values[f.key]) && values[f.key].includes(opt)
                return (
                  <label key={opt} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--text-h, #332a54)', cursor: 'pointer', padding: '8px 12px', borderRadius: 10, background: checked ? 'rgba(124,58,237,0.12)' : 'var(--bg-input, rgba(124,58,237,0.03))', border: checked ? '1px solid #7C3AED' : '1px solid var(--border, rgba(80,60,130,0.15))' }}>
                    <input type="checkbox" checked={checked} onChange={() => toggleMultiSelect(f.key, opt)} />
                    {opt}
                  </label>
                )
              })}
            </div>
          ) : f.type === 'rating' ? (
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '6px 0' }}>
              {[1, 2, 3, 4, 5].map(star => (
                <button type="button" key={star} onClick={() => sv(f.key, star)} style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 2 }}>
                  <Star size={24} fill={(values[f.key] || 0) >= star ? '#f59e0b' : 'none'} color={(values[f.key] || 0) >= star ? '#f59e0b' : '#cbd5e1'} />
                </button>
              ))}
              <span style={{ fontSize: 12, color: 'var(--text-muted, #6b63a0)', marginLeft: 6 }}>{values[f.key] ? `${values[f.key]} Star${values[f.key] > 1 ? 's' : ''}` : 'Select rating'}</span>
            </div>
          ) : f.type === 'file' || f.type === 'attachment' ? (
            <div>
              <label style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8, padding: '12px 16px', borderRadius: 12, border: '2px dashed rgba(124,58,237,0.3)', background: 'var(--bg-input, rgba(124,58,237,0.03))', cursor: 'pointer', fontSize: 13, color: '#7C3AED', fontWeight: 600 }}>
                <Upload size={16} />
                {values[f.key]?.name ? `File: ${values[f.key].name}` : 'Click to Upload Attachment'}
                <input type="file" onChange={e => handleFileUpload(f.key, e.target.files[0])} style={{ display: 'none' }} />
              </label>
            </div>
          ) : f.type === 'radio' ? (
            <div style={{ display: 'flex', gap: 12, marginTop: 4 }}>
              {(f.options || []).map(opt => (
                <label key={opt} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: 'var(--text-h, #332a54)', cursor: 'pointer' }}>
                  <input type="radio" name={f.key} checked={values[f.key] === opt} onChange={() => sv(f.key, opt)} />
                  {opt}
                </label>
              ))}
            </div>
          ) : (
            <input type={f.key === 'email' ? 'email' : 'text'} required={!!f.required} value={values[f.key] || ''} onChange={e => sv(f.key, e.target.value)} style={inputStyle} />
          )}
        </div>
      ))}
      {error && <p style={{ color: '#ef4444', fontSize: 12, marginBottom: 12 }}>{error}</p>}
      <button type="submit" disabled={status === 'submitting'} style={{ width: '100%', padding: '12px', borderRadius: 14, border: 'none', color: '#fff', fontWeight: 800, fontSize: 14, cursor: 'pointer', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: status === 'submitting' ? 0.6 : 1 }}>
        {status === 'submitting' ? 'Submitting…' : 'Submit Form'}
      </button>
    </form>
  )
}

const inputStyle = {
  width: '100%', padding: '11px 14px', borderRadius: 12, fontSize: 14,
  border: '1px solid var(--border, #d1d5db)', background: 'var(--bg-input, #f9fafb)',
  color: 'var(--text-h, #1f2937)', outline: 'none', boxSizing: 'border-box',
}
