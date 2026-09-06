import { useCallback, useEffect, useState } from 'react'
import { Stethoscope, Plus, RefreshCw, X, Copy, Check } from 'lucide-react'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'
import { useToast } from '@/hooks/useToast'
import { medicalApi } from '@/services/medicalApi'
import { S, Pill } from '@/components/medical/MedicalBits'

/**
 * The doctor directory — who may examine, and for which vendor side.
 *
 * Creating a doctor creates a login and the practising profile together, because
 * either alone is useless. Removing one only deactivates: certificates already
 * issued must keep naming the doctor who signed them, so a doctor is never
 * deleted out from under their own signature.
 */
export default function MedicalDoctors() {
  const toast = useToast()
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [showNew, setShowNew] = useState(false)
  const [editing, setEditing] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [resetting, setResetting] = useState(null)
  const [credentials, setCredentials] = useState(null)

  const load = useCallback(() => {
    medicalApi.doctors.list()
      .then(d => { setLoadError(null); setRows(d) })
      .catch(e => { setRows([]); setLoadError(e) })
  }, [])

  useEffect(() => { load() }, [load])

  /**
   * Issue a new password.
   *
   * The one set at creation is shown once and only hashed after that, so an
   * admin who did not write it down had no way back into the account and the
   * doctor was effectively locked out for good.
   */
  const resetPassword = async () => {
    try {
      const res = await medicalApi.doctors.resetPassword(resetting.id)
      setCredentials({ email: resetting.user?.email, password: res.temporary_password })
    } catch (e) { toast.error(e) } finally { setResetting(null) }
  }

  const deactivate = async () => {
    try {
      await medicalApi.doctors.deactivate(confirm.id)
      toast.success('Doctor deactivated.')
      load()
    } catch (e) { toast.error(e) } finally { setConfirm(null) }
  }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>

      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>MEDICAL</p>
          <h1 style={{ margin: '2px 0 0', fontSize: 22, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
            <Stethoscope size={20} /> Doctors
          </h1>
          <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 12.5 }}>
            The logins that may perform examinations, and the licences their certificates carry.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={() => setShowNew(true)} style={S.btnPrimary}><Plus size={15} /> Add a doctor</button>
          <button onClick={load} style={S.btn}><RefreshCw size={14} /> Refresh</button>
        </div>
      </header>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Doctor', 'Licence', 'Qualification', 'Clinic', 'Serves', 'Status', ''].map((h, i) => <th key={i} style={S.th}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? (
                <tr><td colSpan={7} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
              ) : rows === null ? (
                <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>No doctor logins yet.</td></tr>
              ) : rows.map(d => (
                <tr key={d.id} style={{ borderTop: '1px solid var(--border)' }}>
                  <td style={{ ...S.td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {d.user?.name || '—'}
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{d.user?.email}</div>
                  </td>
                  <td style={S.td}>
                    {d.license_no || <span style={{ color: '#f59e0b' }}>Missing</span>}
                    {d.council && <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{d.council}</div>}
                  </td>
                  <td style={{ ...S.td, color: 'var(--text-muted)' }}>{d.qualification || '—'}</td>
                  <td style={{ ...S.td, color: 'var(--text-muted)' }}>{d.clinic_name || '—'}</td>
                  <td style={S.td}>
                    {(d.modules?.length ? d.modules : ['tpv', 'purchase']).map(m => (
                      <Pill key={m} tone="#7C3AED">{m === 'tpv' ? 'TPV' : 'Purchase'}</Pill>
                    ))}
                  </td>
                  <td style={S.td}>
                    {d.is_signable
                      ? <Pill tone="#10b981">Can issue</Pill>
                      : d.is_active
                        ? <Pill tone="#f59e0b">No licence</Pill>
                        : <Pill tone="#6b7280">Inactive</Pill>}
                  </td>
                  <td style={{ ...S.td, whiteSpace: 'nowrap' }}>
                    <button onClick={() => setEditing(d)} style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}>Edit</button>
                    {d.is_active && (
                      <button
                        onClick={() => setResetting(d)}
                        title="Issue a new password — the current one cannot be read back"
                        style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5, marginLeft: 6 }}
                      >
                        Reset password
                      </button>
                    )}
                    {d.is_active && (
                      <button
                        onClick={() => setConfirm(d)}
                        style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5, marginLeft: 6, color: '#ef4444', borderColor: '#ef444455' }}
                      >
                        Deactivate
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <DoctorModal
        open={showNew}
        onClose={() => setShowNew(false)}
        onSaved={(res) => {
          load()
          // Shown once, to the admin who created it — never stored in the clear.
          if (res?.temporary_password) setCredentials({ email: res.data?.user?.email, password: res.temporary_password })
        }}
      />

      <DoctorModal
        open={!!editing}
        doctor={editing}
        onClose={() => setEditing(null)}
        onSaved={() => { load(); setEditing(null) }}
      />

      <CredentialsModal credentials={credentials} onClose={() => setCredentials(null)} />

      {resetting && <ConfirmDialog
        title="Issue a new password?"
        message={`${resetting?.user?.name || 'This doctor'} will be signed out everywhere and the current password will stop working. The new one is shown once — hand it over straight away.`}
        confirmLabel="Reset password"
        onConfirm={resetPassword}
        onCancel={() => setResetting(null)}
      />}

      {/* ConfirmDialog renders when mounted — there is no `open` prop. */}
      {confirm && <ConfirmDialog
        title="Deactivate this doctor?"
        message={`${confirm?.user?.name || 'This doctor'} will no longer be able to sign in or issue certificates. Certificates they have already signed keep their name — nothing is deleted.`}
        confirmLabel="Deactivate"
        onConfirm={deactivate}
        onCancel={() => setConfirm(null)}
      />}
    </div>
  )
}

/* ── Add / edit ──────────────────────────────────────────────────────────── */

function DoctorModal({ open, doctor, onClose, onSaved }) {
  const toast = useToast()
  const isEdit = !!doctor
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({})

  useEffect(() => {
    if (!open) return
    setForm(isEdit ? {
      name: doctor.user?.name || '', phone: doctor.phone || '',
      license_no: doctor.license_no || '', council: doctor.council || '',
      qualification: doctor.qualification || '', designation: doctor.designation || '',
      clinic_name: doctor.clinic_name || '', clinic_address: doctor.clinic_address || '',
      modules: doctor.modules || [],
    } : {
      name: '', email: '', phone: '', password: '',
      license_no: '', council: '', qualification: '', designation: '',
      clinic_name: '', clinic_address: '', modules: [],
    })
  }, [open, doctor, isEdit])

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const toggleModule = (m) => setForm(f => {
    const list = f.modules || []
    return { ...f, modules: list.includes(m) ? list.filter(x => x !== m) : [...list, m] }
  })

  const save = async () => {
    setBusy(true)
    try {
      const payload = { ...form }
      // An empty list means "both sides", which is the usual arrangement — send
      // null rather than [] so the server reads it that way.
      if (!payload.modules?.length) payload.modules = null
      const res = isEdit
        ? await medicalApi.doctors.update(doctor.id, payload)
        : await medicalApi.doctors.create(payload)
      toast.success(isEdit ? 'Doctor updated.' : 'Doctor login created.')
      onSaved?.(res)
      onClose?.()
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} style={{ width: 'min(620px, 96vw)' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 16px', borderBottom: '1px solid var(--border)' }}>
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 900, color: 'var(--text-h)' }}>
          {isEdit ? 'Edit doctor' : 'Add a doctor'}
        </h2>
        <button onClick={onClose} className="btn-icon"><X size={18} /></button>
      </div>

      <div style={{ padding: 16, display: 'grid', gap: 12, maxHeight: '68vh', overflowY: 'auto' }}>
        <Row>
          <F label="Name"><input value={form.name || ''} onChange={e => set('name', e.target.value)} style={S.input} /></F>
          {!isEdit && <F label="Email (their login)"><input type="email" value={form.email || ''} onChange={e => set('email', e.target.value)} style={S.input} /></F>}
          <F label="Phone"><input value={form.phone || ''} onChange={e => set('phone', e.target.value)} style={S.input} /></F>
        </Row>

        {!isEdit && (
          <F label="Password" hint="Leave blank and one is generated, shown once">
            <input type="text" value={form.password || ''} onChange={e => set('password', e.target.value)} style={S.input} />
          </F>
        )}

        <Row>
          <F label="Licence number"><input value={form.license_no || ''} onChange={e => set('license_no', e.target.value)} placeholder="MH-123456" style={S.input} /></F>
          <F label="Council"><input value={form.council || ''} onChange={e => set('council', e.target.value)} style={S.input} /></F>
        </Row>
        <Row>
          <F label="Qualification"><input value={form.qualification || ''} onChange={e => set('qualification', e.target.value)} placeholder="MBBS, AFIH" style={S.input} /></F>
          <F label="Designation"><input value={form.designation || ''} onChange={e => set('designation', e.target.value)} style={S.input} /></F>
        </Row>
        <Row>
          <F label="Clinic"><input value={form.clinic_name || ''} onChange={e => set('clinic_name', e.target.value)} style={S.input} /></F>
          <F label="Clinic address"><input value={form.clinic_address || ''} onChange={e => set('clinic_address', e.target.value)} style={S.input} /></F>
        </Row>

        <F label="Vendor sides they serve" hint="Neither ticked = both">
          <div style={{ display: 'flex', gap: 8 }}>
            {['tpv', 'purchase'].map(m => (
              <button
                key={m}
                type="button"
                onClick={() => toggleModule(m)}
                style={{
                  ...S.btn,
                  background: form.modules?.includes(m) ? '#7C3AED' : 'var(--bg-card)',
                  color: form.modules?.includes(m) ? '#fff' : 'var(--text-muted)',
                }}
              >
                {m === 'tpv' ? 'TPV vendors' : 'Purchase vendors'}
              </button>
            ))}
          </div>
        </F>
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '12px 16px', borderTop: '1px solid var(--border)' }}>
        <button onClick={onClose} style={S.btn}>Cancel</button>
        <button onClick={save} disabled={busy} style={{ ...S.btnPrimary, opacity: busy ? 0.5 : 1 }}>
          {busy ? 'Saving…' : isEdit ? 'Save changes' : 'Create login'}
        </button>
      </div>
    </Modal>
  )
}

/* ── The one-time credentials ────────────────────────────────────────────── */

function CredentialsModal({ credentials, onClose }) {
  const [copied, setCopied] = useState(false)

  const copy = () => {
    navigator.clipboard?.writeText(`${credentials.email} / ${credentials.password}`)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <Modal open={!!credentials} onClose={onClose} style={{ width: 'min(460px, 96vw)' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 16px', borderBottom: '1px solid var(--border)' }}>
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 900, color: 'var(--text-h)' }}>Login created</h2>
        <button onClick={onClose} className="btn-icon"><X size={18} /></button>
      </div>
      <div style={{ padding: 16 }}>
        <p style={{ margin: '0 0 12px', fontSize: 12.5, color: 'var(--text-muted)' }}>
          Hand these to the doctor now — the password is not stored in a readable form and cannot be shown again.
        </p>
        <div className="pr-glass" style={{ padding: 12, borderRadius: 10, fontFamily: 'monospace', fontSize: 13 }}>
          <div>{credentials?.email}</div>
          <div style={{ fontWeight: 800, color: 'var(--text-h)' }}>{credentials?.password}</div>
        </div>
        <button onClick={copy} style={{ ...S.btn, marginTop: 12 }}>
          {copied ? <Check size={14} /> : <Copy size={14} />} {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', padding: '12px 16px', borderTop: '1px solid var(--border)' }}>
        <button onClick={onClose} style={S.btnPrimary}>Done</button>
      </div>
    </Modal>
  )
}

function Row({ children }) {
  return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>{children}</div>
}

function F({ label, hint, children }) {
  return (
    <div>
      <label style={S.label}>{label}</label>
      {children}
      {hint && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 3 }}>{hint}</div>}
    </div>
  )
}
