import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate, NavLink, Routes, Route, Navigate } from 'react-router-dom'
import { ArrowLeft, Building2, CheckCircle2, ChevronDown, ChevronRight } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { VENDOR_NAV_GROUPS, VENDOR_NAV_ITEMS } from './vendorDetailNav'
import { TAB_ELEMENTS, ComingSoonTab } from './vendorDetailTabs'
import { VendorWorkspaceContext } from './vendorWorkspaceContext'

/**
 * Purchase Vendor Detail workspace — persistent left-sidebar layout for a single
 * PurchaseVendor (/app/purchase/vendors/:id/*). The sidebar stays fixed while the
 * nested route renders into <Outlet/>; each tab is deep-linkable and refresh-safe
 * because the active tab lives in the URL path. 100% Purchase-owned: loads
 * PurchaseVendor via purchaseApi only — never Vendor, never TPV.
 */
const STATUS_COLORS = { Active: '#10b981', Pending_Approval: '#f59e0b', Draft: '#6b7280', On_Hold: '#f59e0b', Rejected: '#ef4444', Blacklisted: '#991b1b', Inactive: '#6b7280' }

export default function PurchaseVendorDetailLayout() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [vendor, setVendor] = useState(null)
  const [onboarding, setOnboarding] = useState(null)
  const [loading, setLoading] = useState(true)
  const [collapsed, setCollapsed] = useState({})

  const load = useCallback(() => {
    setLoading(true)
    purchaseApi.vendors.get(id)
      .then((v) => {
        setVendor(v)
        return purchaseApi.onboarding.list({ purchase_vendor_id: id }).catch(() => [])
      })
      .then((list) => setOnboarding((Array.isArray(list) ? list : list?.data ?? [])[0] || null))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [id])

  useEffect(() => { load() }, [load])

  const activate = async () => { try { await purchaseApi.vendors.approve(id); load() } catch { /* noop */ } }
  const toggle = (title) => setCollapsed((c) => ({ ...c, [title]: !c[title] }))

  if (loading) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading vendor…</div>
  if (!vendor) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Purchase vendor not found.</div>

  const statusColor = STATUS_COLORS[vendor.status] || '#6b7280'

  return (
    <div style={{ padding: 20 }}>
      {/* Header */}
      <div className="card-3d" style={{ padding: 16, marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <button onClick={() => navigate('/app/purchase/vendors')} style={backBtn} title="Back to Vendors"><ArrowLeft size={16} /></button>
            <div style={{ width: 52, height: 52, borderRadius: 14, background: 'rgba(124,58,237,0.14)', border: '1px solid rgba(124,58,237,0.3)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <Building2 size={24} style={{ color: '#a78bfa' }} />
            </div>
            <div>
              <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: '.05em', color: '#a78bfa', textTransform: 'uppercase' }}>#{vendor.purchase_vendor_code}</div>
              <h1 style={{ fontSize: 20, fontWeight: 900, color: 'var(--text-h)', margin: '1px 0 4px' }}>{vendor.company_name}</h1>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <span style={{ ...pill, color: statusColor, borderColor: statusColor + '55' }}>{vendor.status_label || vendor.status}</span>
                {vendor.category && <span style={{ ...pill, color: 'var(--text-muted)' }}>{vendor.category}</span>}
                {onboarding && <span style={{ ...pill, color: '#7C3AED', borderColor: 'rgba(124,58,237,0.35)' }}>Onboarding: {onboarding.status_label || onboarding.status}</span>}
              </div>
            </div>
          </div>
          {/* Quick actions */}
          <div style={{ display: 'flex', gap: 8 }}>
            {vendor.status !== 'Active' && (
              <button onClick={activate} style={{ ...actBtn, color: '#10b981', borderColor: 'rgba(16,185,129,0.4)' }}><CheckCircle2 size={14} /> Activate</button>
            )}
          </div>
        </div>
      </div>

      {/* Two-pane: sidebar + content */}
      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
        <nav className="card-3d" style={{ width: 236, flexShrink: 0, position: 'sticky', top: 16, padding: 10, maxHeight: 'calc(100vh - 130px)', overflowY: 'auto' }}>
          {VENDOR_NAV_GROUPS.map((group) => {
            const isCollapsed = collapsed[group.title]
            return (
              <div key={group.title} style={{ marginBottom: 6 }}>
                <button onClick={() => toggle(group.title)} style={groupBtn}>
                  {isCollapsed ? <ChevronRight size={13} /> : <ChevronDown size={13} />}
                  {group.title}
                </button>
                {!isCollapsed && group.items.map((it) => (
                  <NavLink key={it.key} to={`/app/purchase/vendors/${id}/${it.key}`} style={({ isActive }) => ({ ...navItem, ...(isActive ? navItemActive : {}) })}>
                    <it.icon size={15} />
                    <span>{it.label}</span>
                  </NavLink>
                ))}
              </div>
            )
          })}
        </nav>

        <div style={{ flex: 1, minWidth: 0 }}>
          <VendorWorkspaceContext.Provider value={{ vendor, onboarding, reload: load }}>
            <Routes>
              <Route index element={<Navigate to={`/app/purchase/vendors/${id}/profile`} replace />} />
              {VENDOR_NAV_ITEMS.map((it) => (
                <Route key={it.key} path={it.key} element={TAB_ELEMENTS[it.key] || <ComingSoonTab label={it.label} />} />
              ))}
              <Route path="*" element={<Navigate to={`/app/purchase/vendors/${id}/profile`} replace />} />
            </Routes>
          </VendorWorkspaceContext.Provider>
        </div>
      </div>
    </div>
  )
}

const backBtn = { width: 38, height: 38, borderRadius: 10, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer' }
const actBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const pill = { fontSize: 11, fontWeight: 700, padding: '2px 9px', borderRadius: 999, border: '1px solid var(--border)', background: 'var(--bg-input)' }
const groupBtn = { display: 'flex', alignItems: 'center', gap: 6, width: '100%', padding: '8px 8px', background: 'none', border: 'none', color: 'var(--text-h)', fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '.04em', cursor: 'pointer' }
const navItem = { display: 'flex', alignItems: 'center', gap: 9, padding: '7px 10px 7px 14px', fontSize: 12.5, borderRadius: 8, marginBottom: 1, color: 'var(--text-muted)', fontWeight: 500, textDecoration: 'none', borderLeft: '2px solid transparent' }
const navItemActive = { color: '#a78bfa', background: 'rgba(124,58,237,0.12)', fontWeight: 700, borderLeft: '2px solid #7C3AED' }
