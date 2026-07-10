import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { Search, MapPin, Briefcase, Users, ArrowRight, Building2 } from 'lucide-react'
import { careersApi } from '@/services/careersApi'

const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : ''

export default function CareerPortal() {
  const { slug } = useParams()
  const navigate = useNavigate()
  const [tenant, setTenant] = useState(null)
  const [jobs, setJobs] = useState([])
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [search, setSearch] = useState('')
  const [dept, setDept] = useState('All')
  const [type, setType] = useState('All')

  const accent = tenant?.branding_color || '#2563EB'

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [t, j] = await Promise.all([careersApi.tenant(slug), careersApi.jobs(slug)])
      setTenant(t); setJobs(Array.isArray(j) ? j : [])
    } catch (e) { setNotFound(true) }
    finally { setLoading(false) }
  }, [slug])
  useEffect(() => { load() }, [load])

  const departments = ['All', ...Array.from(new Set(jobs.map(j => j.department).filter(Boolean)))]
  const types = ['All', ...Array.from(new Set(jobs.map(j => j.job_type).filter(Boolean)))]
  const filtered = jobs.filter(j => {
    const q = search.toLowerCase()
    const mq = !q || j.title?.toLowerCase().includes(q) || j.department?.toLowerCase().includes(q) || j.location?.toLowerCase().includes(q)
    return mq && (dept === 'All' || j.department === dept) && (type === 'All' || j.job_type === type)
  })

  if (notFound) return <Centered><h1 style={{ fontSize: 24, fontWeight: 800, color: '#0f172a' }}>Career portal not found</h1><p style={{ color: '#64748b' }}>This company's careers page isn't available.</p></Centered>

  return (
    <div style={{ minHeight: '100vh', background: '#f8fafc', color: '#0f172a', fontFamily: 'system-ui, -apple-system, sans-serif' }}>
      {/* Top bar */}
      <header style={{ background: '#fff', borderBottom: '1px solid #e2e8f0', position: 'sticky', top: 0, zIndex: 10 }}>
        <div style={{ maxWidth: 1080, margin: '0 auto', padding: '16px 24px', display: 'flex', alignItems: 'center', gap: 12 }}>
          <span style={{ width: 38, height: 38, borderRadius: 10, background: accent, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 16 }}>
            {(tenant?.name || slug || 'C').charAt(0).toUpperCase()}
          </span>
          <div>
            <div style={{ fontWeight: 800, fontSize: 16 }}>{tenant?.name || '—'}</div>
            <div style={{ fontSize: 12, color: '#64748b' }}>Careers</div>
          </div>
        </div>
      </header>

      {/* Hero + search */}
      <section style={{ maxWidth: 1080, margin: '0 auto', padding: '40px 24px 20px' }}>
        <h1 style={{ fontSize: 32, fontWeight: 900, letterSpacing: '-0.02em', margin: 0 }}>Open Positions</h1>
        <p style={{ color: '#64748b', margin: '8px 0 24px', fontSize: 15 }}>Join {tenant?.name || 'our team'} — explore roles and apply in minutes.</p>

        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          <div style={{ position: 'relative', flex: 1, minWidth: 240 }}>
            <Search size={16} style={{ position: 'absolute', left: 14, top: '50%', transform: 'translateY(-50%)', color: '#94a3b8' }} />
            <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search job title, department or location…"
              style={{ width: '100%', padding: '12px 14px 12px 40px', borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 14, outline: 'none', background: '#fff', boxSizing: 'border-box' }} />
          </div>
          <select value={dept} onChange={e => setDept(e.target.value)} style={selStyle}>{departments.map(d => <option key={d}>{d === 'All' ? 'All Departments' : d}</option>)}</select>
          <select value={type} onChange={e => setType(e.target.value)} style={selStyle}>{types.map(t => <option key={t}>{t === 'All' ? 'All Types' : t}</option>)}</select>
        </div>
      </section>

      {/* Jobs */}
      <section style={{ maxWidth: 1080, margin: '0 auto', padding: '8px 24px 60px' }}>
        {loading ? (
          <div style={{ textAlign: 'center', padding: 60, color: '#94a3b8' }}>Loading open positions…</div>
        ) : filtered.length === 0 ? (
          <div style={{ textAlign: 'center', padding: 60, color: '#94a3b8', background: '#fff', borderRadius: 14, border: '1px solid #e2e8f0' }}>
            <Briefcase size={40} style={{ marginBottom: 12, opacity: 0.4 }} /><p>No open positions right now. Check back soon!</p>
          </div>
        ) : (
          <>
            <div style={{ fontSize: 13, color: '#64748b', marginBottom: 14 }}>{filtered.length} open position{filtered.length > 1 ? 's' : ''}</div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(320px,1fr))', gap: 16 }}>
              {filtered.map(job => (
                <div key={job.id} onClick={() => navigate(`/careers/${slug}/jobs/${job.id}`)}
                  style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 14, padding: 20, cursor: 'pointer', transition: 'box-shadow .15s, transform .15s', display: 'flex', flexDirection: 'column', gap: 12 }}
                  onMouseEnter={e => { e.currentTarget.style.boxShadow = '0 8px 24px rgba(2,6,23,0.08)'; e.currentTarget.style.transform = 'translateY(-2px)' }}
                  onMouseLeave={e => { e.currentTarget.style.boxShadow = 'none'; e.currentTarget.style.transform = 'none' }}>
                  <div>
                    <div style={{ fontWeight: 800, fontSize: 16.5 }}>{job.title}</div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 8, flexWrap: 'wrap' }}>
                      <Chip icon={Building2}>{job.department}</Chip>
                      <Chip icon={MapPin}>{job.location}</Chip>
                    </div>
                  </div>
                  <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <Tag accent={accent}>{job.job_type}</Tag>
                    {job.number_of_openings > 1 && <Tag>{job.number_of_openings} openings</Tag>}
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 'auto', paddingTop: 8, borderTop: '1px solid #f1f5f9' }}>
                    <span style={{ fontSize: 12, color: '#94a3b8' }}>Posted {fmtDate(job.published_at)}</span>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 13, fontWeight: 700, color: accent }}>View &amp; Apply <ArrowRight size={14} /></span>
                  </div>
                </div>
              ))}
            </div>
          </>
        )}
      </section>

      <footer style={{ borderTop: '1px solid #e2e8f0', background: '#fff' }}>
        <div style={{ maxWidth: 1080, margin: '0 auto', padding: '20px 24px', fontSize: 12.5, color: '#94a3b8' }}>
          © {new Date().getFullYear()} {tenant?.name || ''} · Careers
        </div>
      </footer>
    </div>
  )
}

const selStyle = { padding: '12px 14px', borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 14, background: '#fff', cursor: 'pointer', outline: 'none', color: '#0f172a' }
const Chip = ({ icon: Icon, children }) => <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12.5, color: '#475569' }}><Icon size={13} /> {children}</span>
const Tag = ({ accent, children }) => <span style={{ padding: '3px 10px', borderRadius: 8, fontSize: 12, fontWeight: 600, background: accent ? `${accent}15` : '#f1f5f9', color: accent || '#475569' }}>{children}</span>
const Centered = ({ children }) => <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 8, background: '#f8fafc', textAlign: 'center', padding: 24 }}>{children}</div>
