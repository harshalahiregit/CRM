import { useState, useEffect, useCallback } from 'react'
import { Users, Bell, Palette, Shield, Plus, Trash2, KeyRound, Power, X } from 'lucide-react'
import { companyPortalApi } from '@/services/companyPortalApi'

const unwrap = r => r?.data ?? r
const ROLES = [
  ['company_admin', 'Company Admin'], ['company_hr', 'Company HR'], ['company_hiring_manager', 'Hiring Manager'],
  ['company_interviewer', 'Interviewer'], ['company_viewer', 'Viewer'],
]
const NOTIF = [
  ['email', 'Email (master)'], ['candidate_submission', 'Candidate Submission'], ['interview_updates', 'Interview Updates'],
  ['offer_updates', 'Offer Updates'], ['hiring_updates', 'Hiring Updates'], ['recruiter_messages', 'Recruiter Messages'],
]
const lbl = { display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
const inp = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }

export default function CompanySettings() {
  const [tab, setTab] = useState('team')
  const [toast, setToast] = useState(null)
  const show = (m, t = 'success') => { setToast({ m, t }); setTimeout(() => setToast(null), 3500) }
  const TABS = [['team', 'Team', Users], ['notifications', 'Notifications', Bell], ['branding', 'Branding', Palette], ['security', 'Security', Shield]]

  return (
    <div>
      {toast && <div style={{ position: 'fixed', top: 20, right: 20, zIndex: 9999, padding: '12px 20px', borderRadius: 14, fontSize: 13, fontWeight: 700, color: '#fff', maxWidth: 360, background: toast.t === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.m}</div>}
      <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: '0 0 16px' }}>Settings</h1>
      <div style={{ display: 'flex', gap: 8, marginBottom: 20, flexWrap: 'wrap' }}>
        {TABS.map(([k, l, Icon]) => (
          <button key={k} onClick={() => setTab(k)} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, fontSize: 13, fontWeight: 700, cursor: 'pointer', border: `1px solid ${tab === k ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`, background: tab === k ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)', color: tab === k ? '#a78bfa' : 'var(--text-muted)' }}><Icon size={14} /> {l}</button>
        ))}
      </div>
      {tab === 'team' && <Team show={show} />}
      {tab === 'notifications' && <Notifications show={show} />}
      {tab === 'branding' && <Branding show={show} />}
      {tab === 'security' && <Security show={show} />}
    </div>
  )
}

function Team({ show }) {
  const [users, setUsers] = useState([])
  const [modal, setModal] = useState(null)
  const load = useCallback(() => companyPortalApi.users().then(r => setUsers(unwrap(r) || [])).catch(() => {}), [])
  useEffect(() => { load() }, [load])

  const act = async (fn, msg) => { try { await fn(); show(msg); load() } catch (e) { show(e.response?.data?.message || 'Failed', 'error') } }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
        <button onClick={() => setModal({ name: '', email: '', role: 'company_hr' })} style={primary}><Plus size={14} /> Invite User</button>
      </div>
      <div className="card-3d" style={{ padding: 0, overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
          <thead><tr style={{ background: 'var(--bg-input)' }}>{['Name', 'Email', 'Role', 'Status', 'Last Login', 'Actions'].map(h => <th key={h} style={{ textAlign: 'left', padding: '10px 12px', fontWeight: 700, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>)}</tr></thead>
          <tbody>{users.map(u => (
            <tr key={u.id} style={{ borderTop: '1px solid var(--border)' }}>
              <td style={{ padding: '10px 12px', color: 'var(--text-h)', fontWeight: 700 }}>{u.name}</td>
              <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{u.email}</td>
              <td style={{ padding: '10px 12px' }}>
                <select value={u.role} onChange={e => act(() => companyPortalApi.assignRole(u.id, e.target.value), 'Role updated')} style={{ ...inp, width: 'auto', padding: '5px 8px', cursor: 'pointer' }}>
                  {ROLES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </td>
              <td style={{ padding: '10px 12px' }}><span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: u.status === 'active' ? 'rgba(16,185,129,0.12)' : 'var(--bg-input)', color: u.status === 'active' ? '#10b981' : 'var(--text-muted)' }}>{u.status}</span></td>
              <td style={{ padding: '10px 12px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{u.last_login_at ? new Date(u.last_login_at).toLocaleDateString() : '—'}</td>
              <td style={{ padding: '10px 12px' }}>
                <div style={{ display: 'flex', gap: 6 }}>
                  <button title={u.status === 'active' ? 'Deactivate' : 'Activate'} onClick={() => act(() => companyPortalApi.setUserStatus(u.id, u.status !== 'active'), 'Status updated')} style={{ ...mini, color: u.status === 'active' ? '#f59e0b' : '#10b981' }}><Power size={12} /></button>
                  <button title="Reset password" onClick={() => act(() => companyPortalApi.resetUserPassword(u.id), 'Password reset — emailed')} style={mini}><KeyRound size={12} /></button>
                  <button title="Remove" onClick={() => window.confirm(`Remove ${u.name}?`) && act(() => companyPortalApi.removeUser(u.id), 'Removed')} style={{ ...mini, color: '#f87171' }}><Trash2 size={12} /></button>
                </div>
              </td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      {modal && <InviteModal m={modal} setM={setModal} onDone={() => { setModal(null); load() }} show={show} />}
    </div>
  )
}
function InviteModal({ m, setM, onDone, show }) {
  const [busy, setBusy] = useState(false)
  const submit = async () => {
    if (!m.name || !m.email) return show('Name and email required', 'error')
    setBusy(true)
    try { await companyPortalApi.createUser(m); show('Invited — credentials emailed'); onDone() }
    catch (e) { show(e.response?.data?.message || (e.response?.data?.errors && Object.values(e.response.data.errors)[0][0]) || 'Failed', 'error') }
    finally { setBusy(false) }
  }
  return (
    <div style={overlay} onClick={e => e.target === e.currentTarget && setM(null)}>
      <div className="card-3d" style={{ width: '100%', maxWidth: 420, padding: 22 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 14 }}><h3 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>Invite User</h3><button onClick={() => setM(null)} style={{ ...mini, padding: 6 }}><X size={14} /></button></div>
        <label style={lbl}>Name</label><input value={m.name} onChange={e => setM(s => ({ ...s, name: e.target.value }))} style={inp} />
        <label style={{ ...lbl, marginTop: 12 }}>Email</label><input type="email" value={m.email} onChange={e => setM(s => ({ ...s, email: e.target.value }))} style={inp} />
        <label style={{ ...lbl, marginTop: 12 }}>Role</label>
        <select value={m.role} onChange={e => setM(s => ({ ...s, role: e.target.value }))} style={{ ...inp, cursor: 'pointer' }}>{ROLES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 8 }}>A temporary password will be emailed to the user.</p>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16 }}><button onClick={() => setM(null)} style={{ ...mini, padding: '9px 16px' }}>Cancel</button><button onClick={submit} disabled={busy} style={primary}>{busy ? 'Inviting…' : 'Send Invite'}</button></div>
      </div>
    </div>
  )
}

function Notifications({ show }) {
  const [s, setS] = useState(null)
  useEffect(() => { companyPortalApi.adminProfile().then(r => { const d = unwrap(r); const ns = d.notification_settings || {}; setS(Object.fromEntries(NOTIF.map(([k]) => [k, ns[k] !== false]))) }).catch(() => {}) }, [])
  if (!s) return <div style={{ color: 'var(--text-muted)', padding: 20 }}>Loading…</div>
  const save = async () => { try { await companyPortalApi.updateNotifications(s); show('Notification settings saved') } catch { show('Failed', 'error') } }
  return (
    <div className="card-3d" style={{ padding: 20, maxWidth: 520 }}>
      <p style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 14 }}>Choose which email notifications your company receives.</p>
      {NOTIF.map(([k, l]) => (
        <label key={k} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 0', borderBottom: '1px solid var(--border)', cursor: 'pointer' }}>
          <span style={{ fontSize: 13.5, color: 'var(--text-h)', fontWeight: k === 'email' ? 800 : 500 }}>{l}</span>
          <input type="checkbox" checked={!!s[k]} onChange={e => setS(x => ({ ...x, [k]: e.target.checked }))} style={{ width: 18, height: 18, accentColor: '#7C3AED' }} />
        </label>
      ))}
      <button onClick={save} style={{ ...primary, marginTop: 16 }}>Save Settings</button>
    </div>
  )
}

function Branding({ show }) {
  const [b, setB] = useState(null)
  useEffect(() => { companyPortalApi.adminProfile().then(r => { const d = unwrap(r); setB({ primary_color: d.branding?.primary_color || '#7C3AED', secondary_color: d.branding?.secondary_color || '#10b981', email_footer: d.branding?.email_footer || '' }) }).catch(() => {}) }, [])
  if (!b) return <div style={{ color: 'var(--text-muted)', padding: 20 }}>Loading…</div>
  const save = async () => { try { await companyPortalApi.updateBranding(b); show('Branding saved') } catch { show('Failed', 'error') } }
  return (
    <div className="card-3d" style={{ padding: 20, maxWidth: 520 }}>
      <p style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 14 }}>Logo is managed on the Profile page.</p>
      <div style={{ display: 'flex', gap: 16, marginBottom: 14 }}>
        <div><label style={lbl}>Primary Color</label><input type="color" value={b.primary_color} onChange={e => setB(s => ({ ...s, primary_color: e.target.value }))} style={{ width: 60, height: 38, border: '1px solid var(--border)', borderRadius: 8, background: 'var(--bg-input)', cursor: 'pointer' }} /></div>
        <div><label style={lbl}>Secondary Color</label><input type="color" value={b.secondary_color} onChange={e => setB(s => ({ ...s, secondary_color: e.target.value }))} style={{ width: 60, height: 38, border: '1px solid var(--border)', borderRadius: 8, background: 'var(--bg-input)', cursor: 'pointer' }} /></div>
      </div>
      <label style={lbl}>Email Footer</label>
      <textarea rows={3} value={b.email_footer} onChange={e => setB(s => ({ ...s, email_footer: e.target.value }))} style={{ ...inp, resize: 'vertical' }} />
      <button onClick={save} style={{ ...primary, marginTop: 16 }}>Save Branding</button>
    </div>
  )
}

function Security({ show }) {
  const [d, setD] = useState(null)
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [busy, setBusy] = useState(false)
  useEffect(() => { companyPortalApi.security().then(r => setD(unwrap(r))).catch(() => {}) }, [])
  if (!d) return <div style={{ color: 'var(--text-muted)', padding: 20 }}>Loading…</div>
  const change = async () => {
    if (pw.password !== pw.password_confirmation) return show('Passwords do not match', 'error')
    setBusy(true)
    try { await companyPortalApi.changePassword(pw); show('Password changed'); setPw({ current_password: '', password: '', password_confirmation: '' }) }
    catch (e) { show(e.response?.data?.message || 'Failed', 'error') }
    finally { setBusy(false) }
  }
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(300px,1fr))', gap: 16 }}>
      <div className="card-3d" style={{ padding: 20 }}>
        <p style={{ ...lbl, marginBottom: 10, color: '#a78bfa' }}>Account</p>
        <p style={{ fontSize: 13, color: 'var(--text-h)' }}>Last login: <b>{d.last_login_at ? new Date(d.last_login_at).toLocaleString() : '—'}</b></p>
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 4 }}>from {d.last_login_ip || '—'}</p>
        <p style={{ ...lbl, margin: '16px 0 8px' }}>Active Sessions ({d.sessions?.length || 0})</p>
        {(d.sessions || []).map(s => <div key={s.id} style={{ fontSize: 12, color: 'var(--text-muted)', padding: '4px 0' }}>{s.name} · last used {s.last_used_at ? new Date(s.last_used_at).toLocaleString() : '—'}{s.current ? ' · (this device)' : ''}</div>)}
        <p style={{ ...lbl, margin: '16px 0 8px' }}>Login History</p>
        {(d.history || []).slice(0, 6).map((h, i) => <div key={i} style={{ fontSize: 11.5, color: 'var(--text-muted)', padding: '3px 0' }}>{h.at ? new Date(h.at).toLocaleString() : '—'} · {h.ip || '—'}</div>)}
        {(!d.history || !d.history.length) && <p style={{ fontSize: 12, color: 'var(--text-muted)' }}>No history yet.</p>}
      </div>
      <div className="card-3d" style={{ padding: 20 }}>
        <p style={{ ...lbl, marginBottom: 12, color: '#a78bfa' }}>Change Password</p>
        <label style={lbl}>Current Password</label><input type="password" value={pw.current_password} onChange={e => setPw(s => ({ ...s, current_password: e.target.value }))} style={inp} />
        <label style={{ ...lbl, marginTop: 12 }}>New Password</label><input type="password" value={pw.password} onChange={e => setPw(s => ({ ...s, password: e.target.value }))} style={inp} placeholder="min 8 chars" />
        <label style={{ ...lbl, marginTop: 12 }}>Confirm New Password</label><input type="password" value={pw.password_confirmation} onChange={e => setPw(s => ({ ...s, password_confirmation: e.target.value }))} style={inp} />
        <button onClick={change} disabled={busy} style={{ ...primary, marginTop: 16 }}>{busy ? 'Saving…' : 'Change Password'}</button>
      </div>
    </div>
  )
}

const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.72)', zIndex: 1100, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }
const mini = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: 7, borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }
const primary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer' }
