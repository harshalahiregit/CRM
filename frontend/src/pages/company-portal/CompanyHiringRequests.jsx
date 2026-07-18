import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, Search, LayoutGrid, List as ListIcon, Eye, Pencil, Send, X,
  ChevronLeft, ChevronRight, Briefcase,
} from 'lucide-react'
import { companyPortalApi } from '@/services/companyPortalApi'

const unwrap = r => r?.data ?? r
const STATUS = {
  Draft:        { c: '#94a3b8', bg: 'rgba(148,163,184,0.14)' },
  Submitted:    { c: '#3b82f6', bg: 'rgba(59,130,246,0.12)' },
  Under_Review: { c: '#f59e0b', bg: 'rgba(245,158,11,0.12)' },
  Approved:     { c: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  Rejected:     { c: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  Converted:    { c: '#a78bfa', bg: 'rgba(124,58,237,0.14)' },
  Completed:    { c: '#22d3ee', bg: 'rgba(34,211,238,0.14)' },
}
const label = s => (s || '').replace(/_/g, ' ')
const money = (a, b) => (!a && !b) ? '—' : `₹${a ? (a / 100000).toFixed(1) : '0'}–${b ? (b / 100000).toFixed(1) : '0'}L`
const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const lbl = { display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
const EMP = ['Full-time', 'Part-time', 'Contract', 'Internship']
const MODES = ['Onsite', 'Remote', 'Hybrid']
const PRI = ['Low', 'Medium', 'High', 'Critical']

export default function CompanyHiringRequests() {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ total: 0, current_page: 1, last_page: 1 })
  const [loading, setLoading] = useState(true)
  const [view, setView] = useState('card')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('All')
  const [sort, setSort] = useState('created_at')
  const [page, setPage] = useState(1)
  const [modal, setModal] = useState(null) // {form, editingId}
  const [busy, setBusy] = useState(false)
  const [toast, setToast] = useState(null)
  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const r = unwrap(await companyPortalApi.requests({ search, status, sort, dir: 'desc', page, per_page: 12 }))
      setRows(r.data || []); setMeta({ total: r.total, current_page: r.current_page, last_page: r.last_page })
    } catch (e) { showToast(e.response?.data?.message || 'Failed to load', 'error') }
    finally { setLoading(false) }
  }, [search, status, sort, page])
  useEffect(() => { load() }, [load])
  useEffect(() => { setPage(1) }, [search, status, sort])

  const openCreate = () => setModal({ form: { number_of_positions: 1, employment_type: 'Full-time', priority: 'Medium' } })
  const openEdit = (r) => setModal({ editingId: r.id, form: { ...r, required_skills: Array.isArray(r.required_skills) ? r.required_skills.join(', ') : (r.required_skills || '') } })

  const save = async () => {
    const f = modal.form
    if (!f.job_title?.trim()) return showToast('Job title is required', 'error')
    if (!f.department?.trim()) return showToast('Department is required', 'error')
    if (f.salary_min && f.salary_max && Number(f.salary_min) > Number(f.salary_max)) return showToast('Salary Max cannot be less than Min', 'error')
    setBusy(true)
    try {
      const payload = {
        ...f,
        number_of_positions: Number(f.number_of_positions) || 1,
        salary_min: f.salary_min === '' ? null : (f.salary_min ?? null),
        salary_max: f.salary_max === '' ? null : (f.salary_max ?? null),
        required_skills: typeof f.required_skills === 'string'
          ? f.required_skills.split(',').map(s => s.trim()).filter(Boolean) : (f.required_skills || []),
      }
      if (modal.editingId) await companyPortalApi.updateRequest(modal.editingId, payload)
      else await companyPortalApi.createRequest(payload)
      setModal(null); showToast(modal.editingId ? 'Draft updated' : 'Draft created'); load()
    } catch (e) { showToast(e.response?.data?.message || 'Failed to save', 'error') }
    finally { setBusy(false) }
  }

  const submit = async (r) => {
    if (!window.confirm(`Submit "${r.job_title}" for HR review? You won't be able to edit it after.`)) return
    try { await companyPortalApi.submitRequest(r.id); showToast('Submitted for review'); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed to submit', 'error') }
  }

  const setF = k => e => setModal(m => ({ ...m, form: { ...m.form, [k]: e.target.value } }))

  return (
    <div>
      {toast && <div style={{ position: 'fixed', top: 20, right: 20, zIndex: 9999, padding: '12px 20px', borderRadius: 14, fontSize: 13, fontWeight: 700, color: '#fff', background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p style={{ ...lbl, marginBottom: 2 }}>Company Portal</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Hiring Requests</h1>
        </div>
        <button onClick={openCreate} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> New Request</button>
      </div>

      {/* Toolbar */}
      <div className="card-3d" style={{ padding: '12px 16px', marginBottom: 16, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
        <div style={{ position: 'relative', flex: 1, minWidth: 180 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search by title or department…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={status} onChange={e => setStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.keys(STATUS).map(s => <option key={s} value={s}>{label(s)}</option>)}
        </select>
        <select value={sort} onChange={e => setSort(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="created_at">Newest</option>
          <option value="job_title">Title</option>
          <option value="priority">Priority</option>
          <option value="number_of_positions">Positions</option>
          <option value="status">Status</option>
        </select>
        <div style={{ display: 'flex', gap: 4 }}>
          {[['card', LayoutGrid], ['list', ListIcon]].map(([v, Icon]) => (
            <button key={v} onClick={() => setView(v)} style={{ padding: 8, borderRadius: 8, cursor: 'pointer', border: '1px solid var(--border)', background: view === v ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)', color: view === v ? '#a78bfa' : 'var(--text-muted)' }}><Icon size={16} /></button>
          ))}
        </div>
      </div>

      {loading ? <div style={{ color: 'var(--text-muted)', padding: 40, textAlign: 'center' }}>Loading…</div> : (
        rows.length === 0 ? (
          <div className="card-3d" style={{ padding: 40, textAlign: 'center', color: 'var(--text-muted)' }}>
            <Briefcase size={28} style={{ opacity: 0.5 }} /><p style={{ marginTop: 8, fontWeight: 700, color: 'var(--text-h)' }}>No hiring requests yet</p>
            <p style={{ fontSize: 13 }}>Create your first request to start hiring.</p>
          </div>
        ) : view === 'card' ? (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(320px,1fr))', gap: 12 }}>
            {rows.map(r => <Card key={r.id} r={r} onView={() => navigate(`/company-portal/hiring-requests/${r.id}`)} onEdit={() => openEdit(r)} onSubmit={() => submit(r)} />)}
          </div>
        ) : (
          <div className="card-3d" style={{ padding: 0, overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
              <thead><tr style={{ background: 'var(--bg-input)' }}>
                {['Req', 'Position', 'Department', 'Business Unit', 'Pos.', 'Priority', 'Status', 'Recruiter', 'Created', ''].map(h => (
                  <th key={h} style={{ textAlign: 'left', padding: '10px 12px', fontWeight: 700, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>
                ))}
              </tr></thead>
              <tbody>
                {rows.map(r => {
                  const st = STATUS[r.status] || {}
                  return (
                    <tr key={r.id} style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }} onClick={() => navigate(`/company-portal/hiring-requests/${r.id}`)}>
                      <td style={{ padding: '10px 12px', color: '#a78bfa', fontWeight: 700 }}>HR-{r.id}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-h)', fontWeight: 700 }}>{r.job_title}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{r.department || '—'}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{r.business_unit || '—'}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-h)' }}>{r.number_of_positions}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{r.priority}</td>
                      <td style={{ padding: '10px 12px' }}><span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: st.bg, color: st.c }}>{label(r.status)}</span></td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{r.assigned_recruiter?.name || '—'}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{r.created_at ? new Date(r.created_at).toLocaleDateString() : ''}</td>
                      <td style={{ padding: '10px 12px' }} onClick={e => e.stopPropagation()}>
                        {r.status === 'Draft' && <button onClick={() => submit(r)} style={{ ...miniBtn, color: '#10b981' }}><Send size={11} /></button>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )
      )}

      {/* Pagination */}
      {meta.last_page > 1 && (
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 12, marginTop: 18 }}>
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} style={{ ...miniBtn, opacity: page <= 1 ? 0.4 : 1 }}><ChevronLeft size={14} /></button>
          <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Page {meta.current_page} of {meta.last_page} · {meta.total} total</span>
          <button disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)} style={{ ...miniBtn, opacity: page >= meta.last_page ? 0.4 : 1 }}><ChevronRight size={14} /></button>
        </div>
      )}

      {/* Create / edit modal */}
      {modal && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.72)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }} onClick={e => e.target === e.currentTarget && setModal(null)}>
          <div className="card-3d" style={{ width: '100%', maxWidth: 720, maxHeight: '90vh', overflowY: 'auto', padding: 24 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
              <h2 style={{ color: 'var(--text-h)', margin: 0, fontSize: 17, fontWeight: 800 }}>{modal.editingId ? 'Edit Draft Request' : 'New Hiring Request'}</h2>
              <button onClick={() => setModal(null)} style={{ ...miniBtn, padding: 6 }}><X size={14} /></button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))', gap: 14 }}>
              <F l="Job Title *" full><input value={modal.form.job_title || ''} onChange={setF('job_title')} style={inputStyle} placeholder="e.g. Backend Engineer" /></F>
              <F l="Department *"><input value={modal.form.department || ''} onChange={setF('department')} style={inputStyle} /></F>
              <F l="Business Unit"><input value={modal.form.business_unit || ''} onChange={setF('business_unit')} style={inputStyle} /></F>
              <F l="Employment Type"><select value={modal.form.employment_type || 'Full-time'} onChange={setF('employment_type')} style={{ ...inputStyle, cursor: 'pointer' }}>{EMP.map(t => <option key={t}>{t}</option>)}</select></F>
              <F l="Work Mode"><select value={modal.form.work_mode || ''} onChange={setF('work_mode')} style={{ ...inputStyle, cursor: 'pointer' }}><option value="">—</option>{MODES.map(t => <option key={t}>{t}</option>)}</select></F>
              <F l="Vacancies"><input type="number" min="1" value={modal.form.number_of_positions || 1} onChange={setF('number_of_positions')} style={inputStyle} /></F>
              <F l="Priority"><select value={modal.form.priority || 'Medium'} onChange={setF('priority')} style={{ ...inputStyle, cursor: 'pointer' }}>{PRI.map(t => <option key={t}>{t}</option>)}</select></F>
              <F l="Experience"><input value={modal.form.experience_required || ''} onChange={setF('experience_required')} style={inputStyle} placeholder="e.g. 3-5 years" /></F>
              <F l="Target Joining Date"><input type="date" value={modal.form.target_joining_date || ''} onChange={setF('target_joining_date')} style={inputStyle} /></F>
              <F l="Location"><input value={modal.form.location || ''} onChange={setF('location')} style={inputStyle} /></F>
              <F l="Salary Min"><input type="number" value={modal.form.salary_min || ''} onChange={setF('salary_min')} style={inputStyle} /></F>
              <F l="Salary Max"><input type="number" value={modal.form.salary_max || ''} onChange={setF('salary_max')} style={inputStyle} /></F>
              <F l="Education"><input value={modal.form.education || ''} onChange={setF('education')} style={inputStyle} /></F>
              <F l="Required Skills (comma-separated)" full><input value={modal.form.required_skills || ''} onChange={setF('required_skills')} style={inputStyle} placeholder="e.g. PHP, Laravel" /></F>
              <F l="Job Description" full><textarea rows={3} value={modal.form.job_description || ''} onChange={setF('job_description')} style={{ ...inputStyle, resize: 'vertical' }} /></F>
            </div>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 18 }}>
              <button onClick={() => setModal(null)} style={{ ...miniBtn, padding: '9px 16px' }}>Cancel</button>
              <button onClick={save} disabled={busy} style={{ padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer', opacity: busy ? 0.7 : 1 }}>{busy ? 'Saving…' : (modal.editingId ? 'Save Draft' : 'Create Draft')}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function Card({ r, onView, onEdit, onSubmit }) {
  const st = STATUS[r.status] || {}
  return (
    <div className="card-3d" style={{ padding: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>HR-{r.id}</span>
            <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 14.5 }}>{r.job_title}</span>
          </div>
          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>{r.department || '—'}{r.business_unit ? ` · ${r.business_unit}` : ''}</p>
        </div>
        <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, height: 'fit-content', background: st.bg, color: st.c }}>{label(r.status)}</span>
      </div>
      <div style={{ display: 'flex', gap: 14, marginTop: 10, fontSize: 11.5, color: 'var(--text-muted)', flexWrap: 'wrap' }}>
        <span>{r.number_of_positions} position(s)</span>
        <span>{r.priority}</span>
        <span>{money(r.salary_min, r.salary_max)}</span>
      </div>
      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 6 }}>
        Recruiter: {r.assigned_recruiter?.name || 'Unassigned'} · {r.submissions_count ?? 0} candidate(s)
      </p>
      <div style={{ display: 'flex', gap: 6, marginTop: 12, flexWrap: 'wrap' }}>
        <button onClick={onView} style={miniBtn}><Eye size={11} /> Open</button>
        {r.status === 'Draft' && <button onClick={onEdit} style={miniBtn}><Pencil size={11} /> Edit</button>}
        {r.status === 'Draft' && <button onClick={onSubmit} style={{ ...miniBtn, color: '#10b981' }}><Send size={11} /> Submit</button>}
      </div>
    </div>
  )
}

const F = ({ l, children, full }) => <div style={{ gridColumn: full ? '1 / -1' : 'auto' }}><label style={lbl}>{l}</label>{children}</div>
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }
