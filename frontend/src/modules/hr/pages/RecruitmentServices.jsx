import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, Building2, ClipboardList, LayoutDashboard, Search, X, Copy, Check, Trash2,
  Pencil, Send, ThumbsUp, ThumbsDown, Eye, ArrowRightCircle, ExternalLink,
  Users, Link2, Bell, CheckCircle2, Clock, Star, FileText, StickyNote,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const unwrap = r => r?.data ?? r
const EMPLOYMENT_TYPES = ['Full-time', 'Part-time', 'Contract', 'Internship']
const WORK_MODES = ['Onsite', 'Remote', 'Hybrid']
const PRIORITIES = ['Low', 'Medium', 'High', 'Critical']
const REQ_STATUS = {
  Submitted:     { c: '#3b82f6', bg: 'rgba(59,130,246,0.12)' },
  Under_Review:  { c: '#f59e0b', bg: 'rgba(245,158,11,0.12)' },
  Approved:      { c: '#10b981', bg: 'rgba(16,185,129,0.12)' },
  Rejected:      { c: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  Converted:     { c: '#a78bfa', bg: 'rgba(124,58,237,0.14)' },
}
const statusLabel = s => (s || '').replace(/_/g, ' ')
const money = (a, b) => (!a && !b) ? '—' : `₹${a ? (a / 100000).toFixed(1) : '0'}–${b ? (b / 100000).toFixed(1) : '0'}L`

const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const label = { display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
const Field = ({ l, children, full }) => <div style={{ gridColumn: full ? '1 / -1' : 'auto' }}><label style={label}>{l}</label>{children}</div>

function Overlay({ onClose, width = 640, children }) {
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.72)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}
      onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="card-3d" style={{ width: '100%', maxWidth: width, maxHeight: '90vh', overflowY: 'auto', padding: 24 }}>{children}</div>
    </div>
  )
}

export default function RecruitmentServices() {
  const navigate = useNavigate()
  const [tab, setTab] = useState('dashboard')
  const [stats, setStats] = useState({})
  const [companies, setCompanies] = useState([])
  const [requests, setRequests] = useState([])
  const [recruiters, setRecruiters] = useState([])
  const [loading, setLoading] = useState(true)
  const [toast, setToast] = useState(null)
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('All')

  const [companyModal, setCompanyModal] = useState(null) // {form, editingId}
  const [requestModal, setRequestModal] = useState(null) // {form}
  const [clientReq, setClientReq] = useState(null)       // request whose client-collaboration drawer is open
  const [busy, setBusy] = useState(false)

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const [d, c, r] = await Promise.all([
        hrApi.recruitmentServices.dashboard(),
        hrApi.recruitmentServices.companies(),
        hrApi.recruitmentServices.requests(),
      ])
      setStats(unwrap(d) || {}); setCompanies(unwrap(c) || []); setRequests(unwrap(r) || [])
    } catch (e) { showToast(e.response?.data?.message || 'Failed to load', 'error') }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { fetchAll() }, [fetchAll])
  useEffect(() => { hrApi.candidates.recruiters().then(x => setRecruiters(unwrap(x) || x || [])).catch(() => {}) }, [])

  const refresh = fetchAll

  // ── Company handlers ──
  const saveCompany = async () => {
    const f = companyModal.form
    if (!f.name?.trim()) return showToast('Company name is required', 'error')
    setBusy(true)
    try {
      if (companyModal.editingId) await hrApi.recruitmentServices.updateCompany(companyModal.editingId, f)
      else await hrApi.recruitmentServices.createCompany(f)
      setCompanyModal(null); showToast('Company saved'); refresh()
    } catch (e) { showToast(e.response?.data?.message || 'Failed to save company', 'error') }
    finally { setBusy(false) }
  }
  const deleteCompany = async (c) => {
    if (!window.confirm(`Delete "${c.name}"?`)) return
    try { await hrApi.recruitmentServices.deleteCompany(c.id); showToast('Company deleted'); refresh() }
    catch (e) { showToast(e.response?.data?.message || 'Failed to delete', 'error') }
  }
  const copyLink = (c) => {
    const url = `${window.location.origin}/hiring-request/${c.public_token}`
    Promise.resolve(navigator.clipboard?.writeText(url))
      .then(() => showToast('Public submission link copied'))
      .catch(() => showToast('Could not copy — copy it manually', 'error'))
  }

  // ── Request handlers ──
  const saveRequest = async () => {
    const f = requestModal.form
    if (!f.external_company_id) return showToast('Select a company', 'error')
    if (!f.job_title?.trim()) return showToast('Job title is required', 'error')
    if (f.salary_min && f.salary_max && Number(f.salary_min) > Number(f.salary_max)) return showToast('Salary Max cannot be less than Salary Min', 'error')
    setBusy(true)
    try {
      const payload = {
        ...f,
        number_of_positions: Number(f.number_of_positions) || 1,
        salary_min: f.salary_min === '' ? null : Number(f.salary_min),
        salary_max: f.salary_max === '' ? null : Number(f.salary_max),
        required_skills: typeof f.required_skills === 'string'
          ? f.required_skills.split(',').map(s => s.trim()).filter(Boolean) : (f.required_skills || []),
      }
      await hrApi.recruitmentServices.createRequest(payload)
      setRequestModal(null); showToast('Hiring request created'); refresh()
    } catch (e) { showToast(e.response?.data?.message || 'Failed to save request', 'error') }
    finally { setBusy(false) }
  }
  const review = async (r, decision) => {
    let notes = ''
    if (decision === 'reject') { notes = window.prompt('Reason for rejection?') ; if (notes == null) return }
    try { await hrApi.recruitmentServices.review(r.id, decision, notes || ''); showToast(`Request ${decision === 'approve' ? 'approved' : decision === 'reject' ? 'rejected' : 'moved to review'}`); refresh() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }
  const assign = async (r, recruiterId) => {
    try { await hrApi.recruitmentServices.assign(r.id, recruiterId || null); showToast('Recruiter updated'); refresh() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }
  const convert = async (r) => {
    if (busy) return
    if (!window.confirm(`Convert "${r.job_title}" into a Manpower Request? The standard recruitment flow will continue from there.`)) return
    setBusy(true)
    try {
      const res = await hrApi.recruitmentServices.convert(r.id)
      const mrId = unwrap(res)?.manpower_request_id
      showToast('Converted to Manpower Request'); refresh()
      if (mrId && window.confirm('Open the Manpower Requests screen?')) navigate('/app/hr/manpower-requests')
    } catch (e) { showToast(e.response?.data?.message || 'Failed to convert', 'error') }
    finally { setBusy(false) }
  }

  const filteredReq = requests.filter(r =>
    (statusF === 'All' || r.status === statusF) &&
    (!search || r.job_title?.toLowerCase().includes(search.toLowerCase()) || r.external_company?.name?.toLowerCase().includes(search.toLowerCase())))
  const filteredCo = companies.filter(c => !search || c.name?.toLowerCase().includes(search.toLowerCase()))

  const TABS = [
    { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { key: 'companies', label: 'External Companies', icon: Building2 },
    { key: 'requests', label: 'Hiring Requests', icon: ClipboardList },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      {toast && <div style={{ position: 'fixed', top: 20, right: 20, zIndex: 9999, padding: '12px 20px', borderRadius: 14, fontSize: 13, fontWeight: 700, color: '#fff', background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p style={{ ...label, marginBottom: 2 }}>HR · Recruitment Services</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>External Hiring Intake</h1>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          {tab === 'companies' && <button onClick={() => setCompanyModal({ form: { name: '', status: 'Active' } })} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> Add Company</button>}
          {tab === 'requests' && <button onClick={() => setRequestModal({ form: { number_of_positions: 1, employment_type: 'Full-time', priority: 'Medium' } })} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> New Hiring Request</button>}
        </div>
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 20, flexWrap: 'wrap' }}>
        {TABS.map(t => (
          <button key={t.key} onClick={() => setTab(t.key)} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, fontSize: 13, fontWeight: 700, cursor: 'pointer',
            border: `1px solid ${tab === t.key ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`, background: tab === t.key ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)', color: tab === t.key ? '#a78bfa' : 'var(--text-muted)' }}>
            <t.icon size={14} /> {t.label}
          </button>
        ))}
      </div>

      {loading ? <HrLoading label="Loading recruitment services…" /> : (
        <>
          {/* ── DASHBOARD ── */}
          {tab === 'dashboard' && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12 }}>
              {[
                ['External Companies', stats.companies, '#a78bfa'], ['Active', stats.active_companies, '#10b981'],
                ['Total Requests', stats.total_requests, '#60a5fa'], ['Submitted', stats.submitted, '#3b82f6'],
                ['Under Review', stats.under_review, '#f59e0b'], ['Approved', stats.approved, '#10b981'],
                ['Rejected', stats.rejected, '#ef4444'], ['Converted', stats.converted, '#a78bfa'],
                ['Completed', stats.completed, '#22d3ee'], ['Candidates Delivered', stats.candidates_delivered, '#f472b6'],
              ].map(([l, v, c]) => (
                <div key={l} className="kpi-3d" style={{ padding: 16, textAlign: 'center' }}>
                  <div style={{ fontSize: 24, fontWeight: 900, color: c }}>{v ?? 0}</div>
                  <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 2 }}>{l}</div>
                </div>
              ))}
            </div>
          )}

          {/* ── COMPANIES ── */}
          {tab === 'companies' && (
            <>
              <div className="card-3d" style={{ padding: '12px 16px', marginBottom: 16, position: 'relative' }}>
                <Search size={14} style={{ position: 'absolute', left: 26, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search companies…" style={{ ...inputStyle, paddingLeft: 32 }} />
              </div>
              {filteredCo.length === 0 ? <HrEmpty icon={Building2} title="No external companies yet" hint="Add the client companies you provide recruitment services for." /> : (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))', gap: 12 }}>
                  {filteredCo.map(c => (
                    <div key={c.id} className="card-3d" style={{ padding: 16 }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                        <div>
                          <p style={{ fontWeight: 800, color: 'var(--text-h)', fontSize: 14 }}>{c.name}</p>
                          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}>{c.industry || '—'}{c.location ? ` · ${c.location}` : ''}</p>
                        </div>
                        <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, height: 'fit-content', background: c.status === 'Active' ? 'rgba(16,185,129,0.12)' : 'var(--bg-input)', color: c.status === 'Active' ? '#10b981' : 'var(--text-muted)' }}>{c.status}</span>
                      </div>
                      {(c.contact_person || c.contact_email) && <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 8 }}>{c.contact_person}{c.contact_email ? ` · ${c.contact_email}` : ''}</p>}
                      <p style={{ fontSize: 11, color: '#a78bfa', marginTop: 6, fontWeight: 700 }}>{c.hiring_requests_count ?? 0} hiring request(s)</p>
                      <div style={{ display: 'flex', gap: 6, marginTop: 12, flexWrap: 'wrap' }}>
                        <button onClick={() => copyLink(c)} style={miniBtn}><Copy size={11} /> Copy Link</button>
                        <button onClick={() => setCompanyModal({ form: { ...c }, editingId: c.id })} style={miniBtn}><Pencil size={11} /> Edit</button>
                        <button onClick={() => deleteCompany(c)} style={{ ...miniBtn, color: '#f87171' }}><Trash2 size={11} /></button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </>
          )}

          {/* ── HIRING REQUESTS ── */}
          {tab === 'requests' && (
            <>
              <div className="card-3d" style={{ padding: '12px 16px', marginBottom: 16, display: 'flex', gap: 12, alignItems: 'center' }}>
                <div style={{ position: 'relative', flex: 1 }}>
                  <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                  <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search by job title or company…" style={{ ...inputStyle, paddingLeft: 32 }} />
                </div>
                <select value={statusF} onChange={e => setStatusF(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
                  <option value="All">All Status</option>
                  {Object.keys(REQ_STATUS).map(s => <option key={s} value={s}>{statusLabel(s)}</option>)}
                </select>
              </div>
              {filteredReq.length === 0 ? <HrEmpty icon={ClipboardList} title="No hiring requests" hint="External companies submit requests, or create one on their behalf." /> : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {filteredReq.map(r => {
                    const st = REQ_STATUS[r.status] || {}
                    return (
                      <div key={r.id} className="card-3d" style={{ padding: 16, display: 'flex', justifyContent: 'space-between', gap: 14, flexWrap: 'wrap' }}>
                        <div style={{ flex: 1, minWidth: 200 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                            <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>HR-{r.id}</span>
                            <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 14.5 }}>{r.job_title}</span>
                            <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: st.bg, color: st.c }}>{statusLabel(r.status)}</span>
                            <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{r.source}</span>
                          </div>
                          <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
                            <Building2 size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {r.external_company?.name || '—'}
                            {r.department ? ` · ${r.department}` : ''} · {r.number_of_positions} position(s) · {money(r.salary_min, r.salary_max)}
                          </p>
                          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>
                            Recruiter: {r.assigned_recruiter?.name || 'Unassigned'}
                            {r.manpower_request_id ? ` · → MR-${r.manpower_request_id} (${r.manpower_request?.status || ''})` : ''}
                          </p>
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, alignItems: 'stretch' }}>
                          {['Submitted', 'Under_Review'].includes(r.status) && (
                            <div style={{ display: 'flex', gap: 6 }}>
                              {r.status === 'Submitted' && <button onClick={() => review(r, 'under-review')} style={{ ...actBtn, color: '#f59e0b', background: 'rgba(245,158,11,0.12)' }}><Eye size={12} /> Review</button>}
                              <button onClick={() => review(r, 'approve')} style={{ ...actBtn, color: '#10b981', background: 'rgba(16,185,129,0.14)' }}><ThumbsUp size={12} /> Approve</button>
                              <button onClick={() => review(r, 'reject')} style={{ ...actBtn, color: '#f87171', background: 'rgba(239,68,68,0.1)' }}><ThumbsDown size={12} /></button>
                            </div>
                          )}
                          {r.status !== 'Converted' && r.status !== 'Rejected' && (
                            <select value={r.assigned_recruiter?.id || ''} onChange={e => assign(r, e.target.value)} style={{ ...inputStyle, fontSize: 12, padding: '6px 8px', cursor: 'pointer' }}>
                              <option value="">Assign recruiter…</option>
                              {recruiters.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                          )}
                          {r.status === 'Approved' && <button onClick={() => convert(r)} disabled={busy} style={{ ...actBtn, color: '#fff', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', justifyContent: 'center', opacity: busy ? 0.6 : 1 }}><ArrowRightCircle size={13} /> Convert to Manpower Request</button>}
                          {(r.status === 'Converted' || r.status === 'Completed') && <button onClick={() => setClientReq(r)} style={{ ...actBtn, color: '#f472b6', background: 'rgba(244,114,182,0.12)', justifyContent: 'center' }}><Users size={12} /> Client Collaboration</button>}
                          {(r.status === 'Converted' || r.status === 'Completed') && <button onClick={() => navigate('/app/hr/manpower-requests')} style={{ ...actBtn, color: '#a78bfa', background: 'rgba(124,58,237,0.1)', justifyContent: 'center' }}><ExternalLink size={12} /> View Manpower Request</button>}
                        </div>
                      </div>
                    )
                  })}
                </div>
              )}
            </>
          )}
        </>
      )}

      {/* ── Company modal ── */}
      {companyModal && (
        <Overlay onClose={() => setCompanyModal(null)} width={780}>
          <h2 style={{ color: 'var(--text-h)', margin: '0 0 16px', fontSize: 17, fontWeight: 800 }}>{companyModal.editingId ? 'Edit' : 'Add'} External Company</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 14 }}>
            <Field l="Company Name *" full><input value={companyModal.form.name || ''} onChange={e => setCompanyModal(m => ({ ...m, form: { ...m.form, name: e.target.value } }))} style={inputStyle} placeholder="e.g. Acme Corp" /></Field>
            <Field l="Industry"><input value={companyModal.form.industry || ''} onChange={e => setCompanyModal(m => ({ ...m, form: { ...m.form, industry: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Status"><select value={companyModal.form.status || 'Active'} onChange={e => setCompanyModal(m => ({ ...m, form: { ...m.form, status: e.target.value } }))} style={{ ...inputStyle, cursor: 'pointer' }}><option>Active</option><option>Inactive</option></select></Field>
            <Field l="Contact Person"><input value={companyModal.form.contact_person || ''} onChange={e => setCompanyModal(m => ({ ...m, form: { ...m.form, contact_person: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Contact Email"><input type="email" value={companyModal.form.contact_email || ''} onChange={e => setCompanyModal(m => ({ ...m, form: { ...m.form, contact_email: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Contact Phone"><input value={companyModal.form.contact_phone || ''} onChange={e => setCompanyModal(m => ({ ...m, form: { ...m.form, contact_phone: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Location"><input value={companyModal.form.location || ''} onChange={e => setCompanyModal(m => ({ ...m, form: { ...m.form, location: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Notes" full><textarea rows={2} value={companyModal.form.notes || ''} onChange={e => setCompanyModal(m => ({ ...m, form: { ...m.form, notes: e.target.value } }))} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
          </div>
          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 18 }}>
            <button onClick={() => setCompanyModal(null)} style={ghostBtn}>Cancel</button>
            <button onClick={saveCompany} disabled={busy} style={primaryBtn}>{busy ? 'Saving…' : 'Save Company'}</button>
          </div>
        </Overlay>
      )}

      {/* ── Hiring request modal ── */}
      {requestModal && (
        <Overlay onClose={() => setRequestModal(null)} width={960}>
          <h2 style={{ color: 'var(--text-h)', margin: '0 0 16px', fontSize: 17, fontWeight: 800 }}>New Hiring Request</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))', gap: 14 }}>
            <Field l="External Company *" full><select value={requestModal.form.external_company_id || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, external_company_id: e.target.value } }))} style={{ ...inputStyle, cursor: 'pointer' }}><option value="">Select company…</option>{companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field l="Job Title *"><input value={requestModal.form.job_title || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, job_title: e.target.value } }))} style={inputStyle} placeholder="e.g. Site Engineer" /></Field>
            <Field l="Department"><input value={requestModal.form.department || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, department: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Employment Type"><select value={requestModal.form.employment_type || 'Full-time'} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, employment_type: e.target.value } }))} style={{ ...inputStyle, cursor: 'pointer' }}>{EMPLOYMENT_TYPES.map(t => <option key={t}>{t}</option>)}</select></Field>
            <Field l="Work Mode"><select value={requestModal.form.work_mode || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, work_mode: e.target.value } }))} style={{ ...inputStyle, cursor: 'pointer' }}><option value="">—</option>{WORK_MODES.map(t => <option key={t}>{t}</option>)}</select></Field>
            <Field l="Positions"><input type="number" min="1" value={requestModal.form.number_of_positions || 1} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, number_of_positions: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Priority"><select value={requestModal.form.priority || 'Medium'} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, priority: e.target.value } }))} style={{ ...inputStyle, cursor: 'pointer' }}>{PRIORITIES.map(t => <option key={t}>{t}</option>)}</select></Field>
            <Field l="Experience"><input value={requestModal.form.experience_required || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, experience_required: e.target.value } }))} style={inputStyle} placeholder="e.g. 3-5 years" /></Field>
            <Field l="Location"><input value={requestModal.form.location || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, location: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Salary Min"><input type="number" value={requestModal.form.salary_min || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, salary_min: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Salary Max"><input type="number" value={requestModal.form.salary_max || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, salary_max: e.target.value } }))} style={inputStyle} /></Field>
            <Field l="Required Skills (comma-separated)" full><input value={requestModal.form.required_skills || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, required_skills: e.target.value } }))} style={inputStyle} placeholder="e.g. AutoCAD, Surveying" /></Field>
            <Field l="Job Description" full><textarea rows={3} value={requestModal.form.job_description || ''} onChange={e => setRequestModal(m => ({ ...m, form: { ...m.form, job_description: e.target.value } }))} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
          </div>
          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 18 }}>
            <button onClick={() => setRequestModal(null)} style={ghostBtn}>Cancel</button>
            <button onClick={saveRequest} disabled={busy} style={primaryBtn}>{busy ? 'Saving…' : 'Create Request'}</button>
          </div>
        </Overlay>
      )}

      {/* ── Client collaboration drawer ── */}
      {clientReq && (
        <ClientCollaboration request={clientReq} showToast={showToast} onClose={() => setClientReq(null)} onChanged={refresh} />
      )}
    </div>
  )
}

/* ─────────── Phase 2: Client collaboration drawer ─────────── */
const SUB_STATUS = {
  'Pending Client Review': { c: '#f59e0b', bg: 'rgba(245,158,11,0.12)' },
  'Accepted':              { c: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  'Rejected':              { c: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  'Need More Profiles':    { c: '#60a5fa', bg: 'rgba(96,165,250,0.12)' },
}

function ClientCollaboration({ request, showToast, onClose, onChanged }) {
  const [loading, setLoading] = useState(true)
  const [counts, setCounts] = useState({})
  const [subs, setSubs] = useState([])
  const [token, setToken] = useState('')
  const [pool, setPool] = useState([])
  const [picked, setPicked] = useState([])
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [showPicker, setShowPicker] = useState(false)
  const [notes, setNotes] = useState([])
  const [newNote, setNewNote] = useState('')
  const [rating, setRating] = useState(null)
  const [shares, setShares] = useState({}) // candidate_id -> share row

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [s, p, n, r, sh] = await Promise.all([
        hrApi.recruitmentServices.submissions(request.id),
        hrApi.recruitmentServices.submittable(request.id),
        hrApi.recruitmentServices.notes(request.id),
        hrApi.recruitmentServices.rating(request.id),
        hrApi.recruitmentServices.resumeShares(request.id),
      ])
      const sd = unwrap(s) || {}
      setCounts(sd.counts || {}); setSubs(sd.submissions || []); setToken(sd.tracking_token || '')
      setPool(unwrap(p) || [])
      setNotes(unwrap(n) || [])
      setRating(unwrap(r) || null)
      const shareMap = {}; (unwrap(sh) || []).forEach(x => { shareMap[x.candidate_id] = x })
      setShares(shareMap)
    } catch (e) { showToast(e.response?.data?.message || 'Failed to load client data', 'error') }
    finally { setLoading(false) }
  }, [request.id, showToast])
  useEffect(() => { load() }, [load])

  const addNote = async () => {
    if (!newNote.trim()) return
    try { await hrApi.recruitmentServices.addNote(request.id, newNote.trim()); setNewNote(''); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed to add note', 'error') }
  }
  const removeNote = async (id) => {
    try { await hrApi.recruitmentServices.deleteNote(request.id, id); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed to delete note', 'error') }
  }
  const shareResume = async (candidateId) => {
    try { await hrApi.recruitmentServices.shareResume(request.id, candidateId); showToast('Resume shared with client'); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed to share resume', 'error') }
  }

  const copyTracking = () => {
    const url = `${window.location.origin}/client-tracking/${token}`
    Promise.resolve(navigator.clipboard?.writeText(url))
      .then(() => showToast('Client tracking link copied'))
      .catch(() => showToast('Could not copy — copy it manually', 'error'))
  }
  const toggle = (id) => setPicked(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id])
  const submit = async () => {
    if (!picked.length) return showToast('Select at least one candidate', 'error')
    setBusy(true)
    try {
      await hrApi.recruitmentServices.submitCandidates(request.id, picked, note)
      showToast('Candidate(s) submitted to client'); setPicked([]); setNote(''); setShowPicker(false)
      load(); onChanged?.()
    } catch (e) { showToast(e.response?.data?.message || 'Failed to submit', 'error') }
    finally { setBusy(false) }
  }
  const notify = async (event, labelText) => {
    if (event === 'project_completed' && !window.confirm('Mark this project complete and notify the client?')) return
    try {
      await hrApi.recruitmentServices.notifyClient(request.id, event)
      showToast(`Client notified: ${labelText}`); load(); onChanged?.()
    } catch (e) { showToast(e.response?.data?.message || 'Failed to notify', 'error') }
  }

  const COUNTS = [
    ['Applications', counts.applications, '#60a5fa'],
    ['Shortlisted', counts.shortlisted, '#f59e0b'],
    ['Interviews', counts.interviews, '#a78bfa'],
    ['Selected', counts.selected, '#10b981'],
    ['Delivered', counts.delivered, '#f472b6'],
  ]

  return (
    <Overlay onClose={onClose} width={1120}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 6 }}>
        <div>
          <p style={{ ...label, marginBottom: 2 }}>Client Collaboration · HR-{request.id}</p>
          <h2 style={{ color: 'var(--text-h)', margin: 0, fontSize: 18, fontWeight: 800 }}>{request.job_title}</h2>
          <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{request.external_company?.name || '—'}</p>
        </div>
        <button onClick={onClose} style={{ ...miniBtn, padding: 6 }}><X size={14} /></button>
      </div>

      {/* Tracking link */}
      <div style={{ display: 'flex', gap: 8, alignItems: 'center', margin: '10px 0 16px', flexWrap: 'wrap' }}>
        <button onClick={copyTracking} style={{ ...actBtn, color: '#22d3ee', background: 'rgba(34,211,238,0.12)' }}><Link2 size={13} /> Copy Client Tracking Link</button>
        <a href={`/client-tracking/${token}`} target="_blank" rel="noreferrer" style={{ ...actBtn, color: '#a78bfa', background: 'rgba(124,58,237,0.1)', textDecoration: 'none' }}><ExternalLink size={12} /> Open Portal</a>
      </div>

      {loading ? <HrLoading label="Loading client data…" /> : (
        <>
          {/* Funnel counts */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(96px,1fr))', gap: 8, marginBottom: 18 }}>
            {COUNTS.map(([l, v, c]) => (
              <div key={l} className="kpi-3d" style={{ padding: '12px 8px', textAlign: 'center' }}>
                <div style={{ fontSize: 20, fontWeight: 900, color: c }}>{v ?? 0}</div>
                <div style={{ fontSize: 9.5, color: 'var(--text-muted)', fontWeight: 700, marginTop: 2, textTransform: 'uppercase' }}>{l}</div>
              </div>
            ))}
          </div>

          {/* Notifications */}
          <p style={{ ...label, marginBottom: 8 }}>Notify Client</p>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 18 }}>
            <button onClick={() => notify('interview_scheduled', 'Interview scheduled')} style={{ ...miniBtn }}><Bell size={12} /> Interview Scheduled</button>
            <button onClick={() => notify('offer_released', 'Offer released')} style={{ ...miniBtn }}><Bell size={12} /> Offer Released</button>
            <button onClick={() => notify('project_completed', 'Project completed')} disabled={request.status === 'Completed'} style={{ ...miniBtn, color: '#22d3ee', opacity: request.status === 'Completed' ? 0.5 : 1 }}><CheckCircle2 size={12} /> {request.status === 'Completed' ? 'Project Completed' : 'Mark Project Complete'}</button>
          </div>

          {/* Submit candidates */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
            <p style={{ ...label, margin: 0 }}>Delivery History ({subs.length})</p>
            <button onClick={() => setShowPicker(v => !v)} style={{ ...actBtn, color: '#fff', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Send size={12} /> Submit Candidates</button>
          </div>

          {showPicker && (
            <div className="card-3d" style={{ padding: 14, marginBottom: 14 }}>
              {pool.length === 0 ? (
                <p style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>No candidates available to deliver yet. They appear here once the recruitment flow (Job Posting → Candidates) produces applicants for this request's Manpower Request.</p>
              ) : (
                <>
                  <div style={{ maxHeight: 220, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {pool.map(c => (
                      <label key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 10px', borderRadius: 8, cursor: 'pointer', background: picked.includes(c.id) ? 'rgba(124,58,237,0.12)' : 'var(--bg-input)', border: `1px solid ${picked.includes(c.id) ? 'rgba(124,58,237,0.4)' : 'var(--border)'}` }}>
                        <input type="checkbox" checked={picked.includes(c.id)} onChange={() => toggle(c.id)} />
                        <div style={{ flex: 1 }}>
                          <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 13 }}>{c.name}</span>
                          <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 8 }}>{c.stage}{c.experience_years ? ` · ${c.experience_years} yrs` : ''}{c.current_company ? ` · ${c.current_company}` : ''}</span>
                        </div>
                        {c.ai_score != null && <span style={{ fontSize: 11, fontWeight: 800, color: '#a78bfa' }}>AI {c.ai_score}</span>}
                      </label>
                    ))}
                  </div>
                  <input value={note} onChange={e => setNote(e.target.value)} placeholder="Note to client (optional)" style={{ ...inputStyle, marginTop: 10 }} />
                  <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 10 }}>
                    <button onClick={() => { setShowPicker(false); setPicked([]) }} style={ghostBtn}>Cancel</button>
                    <button onClick={submit} disabled={busy || !picked.length} style={primaryBtn}>{busy ? 'Submitting…' : `Submit ${picked.length || ''} to Client`}</button>
                  </div>
                </>
              )}
            </div>
          )}

          {/* Delivery history */}
          {subs.length === 0 ? (
            <HrEmpty icon={Users} title="No candidates delivered yet" hint="Submit shortlisted candidates to share them with the client." />
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {subs.map(s => {
                const st = SUB_STATUS[s.status] || {}
                const feedbackTrail = (s.audit_logs || []).filter(a => (a.action || '').startsWith('Client feedback'))
                return (
                  <div key={s.id} className="card-3d" style={{ padding: 12 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
                      <div>
                        <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 13.5 }}>{s.candidate?.name || `Candidate #${s.candidate_id}`}</span>
                        <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 8 }}>{s.candidate?.stage}{s.candidate?.experience_years ? ` · ${s.candidate.experience_years} yrs` : ''}</span>
                      </div>
                      <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: st.bg, color: st.c, height: 'fit-content' }}>{s.status}</span>
                    </div>
                    <p style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>
                      <Clock size={10} style={{ display: 'inline', verticalAlign: -1 }} /> Submitted {s.submitted_at ? new Date(s.submitted_at).toLocaleDateString() : '—'}{s.submitted_by?.name ? ` by ${s.submitted_by.name}` : ''}
                    </p>
                    {s.client_feedback && <p style={{ fontSize: 12, color: 'var(--text-h)', marginTop: 6, padding: '6px 10px', background: 'var(--bg-input)', borderRadius: 8 }}>💬 “{s.client_feedback}”</p>}
                    <div style={{ display: 'flex', gap: 8, marginTop: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                      {shares[s.candidate_id] ? (
                        <span style={{ ...miniBtn, color: '#10b981', cursor: 'default' }}><Check size={11} /> Resume shared{shares[s.candidate_id].download_count > 0 ? ` · ${shares[s.candidate_id].download_count} download(s)` : ''}</span>
                      ) : (
                        <button onClick={() => shareResume(s.candidate_id)} style={miniBtn}><FileText size={11} /> Share Resume</button>
                      )}
                      {feedbackTrail.length > 1 && <span style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{feedbackTrail.length} feedback events</span>}
                    </div>
                  </div>
                )
              })}
            </div>
          )}

          {/* Client rating */}
          {rating && (
            <div style={{ marginTop: 20 }}>
              <p style={{ ...label, marginBottom: 8 }}>Client Rating</p>
              <div className="card-3d" style={{ padding: 14 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
                  <Stars value={rating.overall} />
                  <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{rating.overall}/5 overall</span>
                </div>
                <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', fontSize: 11.5, color: 'var(--text-muted)' }}>
                  {[['Communication', rating.communication], ['Candidate Quality', rating.candidate_quality], ['Turnaround', rating.turnaround_time]].map(([l, v]) => v != null && (
                    <span key={l}>{l}: <b style={{ color: 'var(--text-h)' }}>{v}/5</b></span>
                  ))}
                </div>
                {rating.feedback && <p style={{ fontSize: 12, color: 'var(--text-h)', marginTop: 8, padding: '6px 10px', background: 'var(--bg-input)', borderRadius: 8 }}>“{rating.feedback}”</p>}
              </div>
            </div>
          )}

          {/* Internal recruiter notes */}
          <div style={{ marginTop: 20 }}>
            <p style={{ ...label, marginBottom: 8 }}><StickyNote size={12} style={{ display: 'inline', verticalAlign: -2, marginRight: 4 }} />Internal Notes <span style={{ textTransform: 'none', color: 'var(--text-muted)', fontWeight: 600 }}>· private to recruiters</span></p>
            <div style={{ display: 'flex', gap: 8, marginBottom: 10 }}>
              <input value={newNote} onChange={e => setNewNote(e.target.value)} onKeyDown={e => e.key === 'Enter' && addNote()} placeholder="Add a private note…" style={inputStyle} />
              <button onClick={addNote} style={{ ...actBtn, color: '#fff', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Add</button>
            </div>
            {notes.length === 0 ? (
              <p style={{ fontSize: 12, color: 'var(--text-muted)' }}>No internal notes yet.</p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                {notes.map(n => (
                  <div key={n.id} style={{ padding: '8px 12px', borderRadius: 8, background: 'var(--bg-input)', display: 'flex', justifyContent: 'space-between', gap: 10 }}>
                    <div>
                      <p style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{n.body}</p>
                      <p style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 2 }}>{n.user?.name || 'Recruiter'} · {n.created_at ? new Date(n.created_at).toLocaleString() : ''}</p>
                    </div>
                    <button onClick={() => removeNote(n.id)} style={{ ...miniBtn, padding: 5, color: '#f87171' }}><Trash2 size={11} /></button>
                  </div>
                ))}
              </div>
            )}
          </div>
        </>
      )}
    </Overlay>
  )
}

function Stars({ value = 0 }) {
  return (
    <span style={{ display: 'inline-flex', gap: 2 }}>
      {[1, 2, 3, 4, 5].map(i => <Star key={i} size={15} style={{ color: i <= Math.round(value) ? '#f59e0b' : 'var(--border)', fill: i <= Math.round(value) ? '#f59e0b' : 'transparent' }} />)}
    </span>
  )
}

const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '5px 10px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }
const actBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, border: 'none', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }
const ghostBtn = { padding: '9px 16px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }
const primaryBtn = { padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer' }
