import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, Building2, User, Phone, MapPin, Loader2, ShieldCheck } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

const TABS = ['Overview', 'Profile', 'Contact']

export default function TpvVendorDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [v, setV] = useState(null)
  const [loading, setLoad] = useState(true)
  const [tab, setTab] = useState('Overview')

  const load = useCallback(() => {
    setLoad(true)
    tpvApi.vendors.get(id).then(r => { setV(r?.data ?? r); setLoad(false) }).catch(() => setLoad(false))
  }, [id])
  useEffect(() => { load() }, [load])

  if (loading) return <div style={wrap}><style>{KIT3D_STYLE}</style><Loader2 size={22} className="rfq-spin" style={{ color: '#a78bfa' }} /></div>
  if (!v) return <div style={wrap}><style>{KIT3D_STYLE}</style><p style={{ color: 'var(--text-muted)' }}>Vendor not found.</p></div>

  const active = v.status === 'Active'
  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18, flexWrap: 'wrap' }}>
        <button onClick={() => navigate('/app/tpv/dashboard')} style={backBtn}><ArrowLeft size={16} /></button>
        <div style={{ width: 54, height: 54, borderRadius: 16, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', border: '1px solid rgba(124,58,237,0.3)' }}>
          <Building2 size={24} style={{ color: '#a78bfa' }} />
        </div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800 }}>{v.vendor_code || `TPV-${v.id}`}</p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>{v.company_name}</h1>
            <span style={{ padding: '4px 11px', borderRadius: 999, background: active ? 'rgba(16,185,129,0.14)' : 'rgba(239,68,68,0.12)', color: active ? '#10b981' : '#ef4444', fontSize: 11.5, fontWeight: 800 }}>{active ? 'Active' : 'Inactive'}</span>
          </div>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>{v.user?.name ? `Login: ${v.user.name} · ` : ''}{v.email || 'No email'}</p>
        </div>
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 6, marginBottom: 16, borderBottom: '1px solid var(--border)' }}>
        {TABS.map(t => (
          <button key={t} onClick={() => setTab(t)} style={{
            padding: '9px 16px', fontSize: 13, fontWeight: 700, cursor: 'pointer', background: 'none', border: 'none',
            color: tab === t ? '#a78bfa' : 'var(--text-muted)', borderBottom: `2px solid ${tab === t ? '#7C3AED' : 'transparent'}`, marginBottom: -1,
          }}>{t}</button>
        ))}
      </div>

      {tab === 'Overview' && (
        <Card icon={ShieldCheck} title="Overview">
          <Grid rows={[
            ['Company', v.company_name],
            ['Vendor Code', v.vendor_code],
            ['Status', active ? 'Active' : 'Inactive'],
            ['Type', v.vendor_type],
            ['Portal Login', v.user?.name || '—'],
            ['Login Status', v.user?.status || '—'],
            ['Engagements', (v.engagements || []).join(', ') || '—'],
            ['Created', v.created_at ? new Date(v.created_at).toLocaleDateString() : '—'],
          ]} />
        </Card>
      )}
      {tab === 'Profile' && (
        <Card icon={User} title="Profile">
          <Grid rows={[
            ['Legal Name', v.legal_name],
            ['GST Number', v.gst_number],
            ['PAN Number', v.pan_number],
            ['Registration No.', v.registration_number],
            ['Category', v.category],
            ['Website', v.website],
            ['Account Manager', v.account_manager?.name],
          ]} />
        </Card>
      )}
      {tab === 'Contact' && (
        <Card icon={Phone} title="Contact & Address">
          <Grid rows={[
            ['Email', v.email],
            ['Phone', v.phone],
            ['Address', v.address],
            ['City', v.city],
            ['State', v.state],
            ['Country', v.country],
            ['Pincode', v.pincode],
          ]} />
          {(v.contacts || []).length > 0 && (
            <div style={{ marginTop: 14 }}>
              <p style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', color: '#a78bfa', margin: '0 0 8px' }}>Contact Persons</p>
              {v.contacts.map(c => (
                <div key={c.id} style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
                  <MapPin size={14} style={{ color: 'var(--text-muted)' }} />
                  <span style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{c.name}</span>
                  {c.designation && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>· {c.designation}</span>}
                  {c.email && <span style={{ fontSize: 11.5, color: 'var(--text-muted)', marginLeft: 'auto' }}>{c.email}</span>}
                  {c.phone && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{c.phone}</span>}
                </div>
              ))}
            </div>
          )}
        </Card>
      )}
    </div>
  )
}

function Card({ icon: Icon, title, children }) {
  return (
    <div className="pr-glass" style={{ padding: 20, borderRadius: 16, maxWidth: 760 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
        <Icon size={16} style={{ color: '#a78bfa' }} />
        <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{title}</span>
      </div>
      {children}
    </div>
  )
}

function Grid({ rows }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 24px' }}>
      {rows.map(([k, val]) => (
        <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 7 }}>
          <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
          <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, textAlign: 'right' }}>{val || '—'}</span>
        </div>
      ))}
    </div>
  )
}

const wrap = { padding: 24, minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg-global)' }
const backBtn = { width: 38, height: 38, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', flexShrink: 0 }
