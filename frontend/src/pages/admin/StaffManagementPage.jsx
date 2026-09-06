import { useState, useEffect, useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Plus, Search, MoreVertical, Edit, Trash2, Power, UserCheck, UserX, Shield, RefreshCw } from 'lucide-react'
import api from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import StaffModal from '@/components/admin/StaffModal'
import RolesModal from '@/components/admin/RolesModal'
import DeleteConfirmModal from '@/components/admin/DeleteConfirmModal'

export default function StaffManagementPage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()

  // Every module reads its assignable-people list from a React Query cache
  // (Tasks ['task-staff'], Projects ['project-staff'], Helpdesk ['helpdesk-agents'],
  // etc.). This page fetches with plain axios, so after any staff change those
  // caches stay stale for up to 5 min — a newly added user wouldn't appear in
  // assignee/member/agent pickers until then. Invalidate them on every staff
  // mutation so the whole app reflects the directory immediately. Predicate-based
  // so it also covers any future staff/user/agent list without hard-coding keys.
  const invalidateDirectory = useCallback(() => {
    queryClient.invalidateQueries({
      predicate: (q) => {
        const k = q.queryKey?.[0]
        return typeof k === 'string' && /staff|agent|member|assignee|users?\b/i.test(k)
      },
    })
  }, [queryClient])
  const [stats, setStats]       = useState({ total_staff: 0, active_staff: 0, inactive_staff: 0 })
  const [staff, setStaff]       = useState([])
  const [loading, setLoading]   = useState(true)
  const [search, setSearch]     = useState('')
  const [designationFilter, setDesignationFilter] = useState('')
  const [statusFilter, setStatusFilter]           = useState('')
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })

  // Modal states
  const [showStaffModal,  setShowStaffModal]  = useState(false)
  const [showRolesModal, setShowRolesModal] = useState(false)
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [selectedStaff,   setSelectedStaff]   = useState(null)
  const [actionMenuOpen,  setActionMenuOpen]  = useState(null)

  // Meta dropdowns
  const [designations, setDesignations] = useState([])
  const [departments,  setDepartments]  = useState([])

  // Toast
  const [toast, setToast] = useState(null)
  const showToast = (msg, type = 'success') => {
    setToast({ msg, type })
    setTimeout(() => setToast(null), 3500)
  }

  // ── Data Fetching ─────────────────────────────────────────────────────────
  const fetchStats = useCallback(async () => {
    try {
      const res = await api.get('/admin/staff/stats')
      if (res.data?.data) setStats(res.data.data)
    } catch {}
  }, [])

  const fetchDesignations = useCallback(async () => {
    try {
      const res = await api.get('/admin/staff/designations')
      if (res.data?.data) setDesignations(res.data.data)
    } catch {}
  }, [])

  const fetchDepartments = useCallback(async () => {
    try {
      const res = await api.get('/admin/staff/departments')
      if (res.data?.data) setDepartments(res.data.data)
    } catch {}
  }, [])

  const fetchStaff = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.get('/admin/staff', {
        params: {
          search,
          designation: designationFilter,
          status:      statusFilter,
          per_page:    pagination.per_page,
          page:        pagination.current_page,
        },
      })
      setStaff(res.data.data.staff)
      setPagination(res.data.data.pagination)
    } catch {
      showToast('Failed to load staff list', 'error')
    } finally {
      setLoading(false)
    }
  }, [search, designationFilter, statusFilter, pagination.current_page, pagination.per_page])

  useEffect(() => {
    if (!user) return
    if (user?.role !== 'admin') { window.location.href = '/app/dashboard'; return }
    fetchStats(); fetchDesignations(); fetchDepartments()
  }, [user])

  useEffect(() => {
    if (user?.role === 'admin') fetchStaff()
  }, [search, designationFilter, statusFilter, pagination.current_page])

  // ── Actions ───────────────────────────────────────────────────────────────
  const handleToggleStatus = async (member) => {
    try {
      await api.patch(`/admin/staff/${member.id}/toggle-status`, {})
      showToast(`${member.name}'s status updated`)
      fetchStaff(); fetchStats(); invalidateDirectory()
    } catch {
      showToast('Failed to update status', 'error')
    }
    setActionMenuOpen(null)
  }

  const handleDelete = async () => {
    try {
      await api.delete(`/admin/staff/${selectedStaff.id}`)
      showToast(`${selectedStaff.name} deleted`)
      fetchStaff(); fetchStats(); invalidateDirectory()
      setShowDeleteModal(false); setSelectedStaff(null)
    } catch {
      showToast('Failed to delete staff member', 'error')
    }
  }

  const getRelativeTime = (d) => {
    if (!d) return 'Never'
    const diff = Math.floor((new Date() - new Date(d)) / 1000)
    if (diff < 60)   return 'Just now'
    if (diff < 3600) return `${Math.floor(diff/60)}m ago`
    if (diff < 86400)return `${Math.floor(diff/3600)}h ago`
    const days = Math.floor(diff/86400)
    return days > 7 ? new Date(d).toLocaleDateString() : `${days}d ago`
  }

  const formatRole = (r) => (r || 'N/A').split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')

  return (
    <div className="p-6 space-y-6" style={{ minHeight: '100vh' }} onClick={() => setActionMenuOpen(null)}>

      {/* ── Toast ─────────────────────────────────────────── */}
      {toast && (
        <div
          className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{
            background: toast.type === 'success'
              ? 'linear-gradient(135deg,#10b981,#059669)'
              : 'linear-gradient(135deg,#f87171,#ef4444)',
            animation: 'slideIn .3s ease',
          }}
        >
          {toast.msg}
        </div>
      )}

      {/* ── Header ────────────────────────────────────────── */}
      <div className="flex items-center justify-between flex-wrap gap-4">
        <div>
          <p className="label-caps mb-1">Admin Panel</p>
          <h1 className="text-3xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            Staff <span className="text-gradient">Management</span>
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
            Manage internal team members, roles, and permissions
          </p>
        </div>
        {/* Roles were seeded and assignable but never editable — the API shipped
            without a screen, which removes the point of roles being records. */}
        <button
          onClick={() => setShowRolesModal(true)}
          className="px-4 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2"
          style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'var(--text-p)' }}
        >
          <Shield size={16} /> Roles
        </button>
        <button
          onClick={() => { setSelectedStaff(null); setShowStaffModal(true) }}
          className="px-5 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 text-white"
          style={{ background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}
        >
          <Plus size={17} /> Add Staff Member
        </button>
      </div>

      {/* ── Stats ─────────────────────────────────────────── */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {[
          { title: 'Total Staff',         value: stats.total_staff,    icon: <UserCheck size={20}/>, color: '#7C3AED' },
          { title: 'Active',              value: stats.active_staff,   icon: <UserCheck size={20}/>, color: '#10b981' },
          { title: 'Inactive / Suspended',value: stats.inactive_staff, icon: <UserX size={20}/>,     color: '#ef4444' },
        ].map(({ title, value, icon, color }) => (
          <div key={title} className="kpi-3d">
            <div className="flex items-center justify-between mb-3">
              <span className="text-sm font-medium" style={{ color: 'var(--text-muted)' }}>{title}</span>
              <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ background: `${color}20`, color }}>
                {icon}
              </div>
            </div>
            <div className="text-3xl font-black" style={{ color: 'var(--text-h)' }}>{value}</div>
          </div>
        ))}
      </div>

      {/* ── Filters ───────────────────────────────────────── */}
      <div className="flex flex-col md:flex-row gap-3 items-center">
        <div className="flex-1 relative">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }}/>
          <input
            type="text"
            placeholder="Search by name or email…"
            value={search}
            onChange={e => { setSearch(e.target.value); setPagination(p => ({...p, current_page:1})) }}
            className="w-full pl-9 pr-4 py-2.5 rounded-xl text-sm outline-none"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
          />
        </div>
        {[
          {
            value: designationFilter, onChange: v => setDesignationFilter(v),
            options: [{ value:'', label:'All Roles' }, ...designations.map(d => ({ value:d.value, label:d.label }))],
          },
          {
            value: statusFilter, onChange: v => setStatusFilter(v),
            options: [
              { value:'', label:'All Status' },
              { value:'active', label:'Active' },
              { value:'inactive', label:'Inactive' },
              { value:'suspended', label:'Suspended' },
            ],
          },
        ].map((sel, i) => (
          <select
            key={i}
            value={sel.value}
            onChange={e => sel.onChange(e.target.value)}
            className="px-4 py-2.5 rounded-xl text-sm outline-none"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
          >
            {sel.options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        ))}
        <button
          onClick={() => fetchStaff()}
          className="p-2.5 rounded-xl"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}
          title="Refresh"
        >
          <RefreshCw size={16} style={{ color: 'var(--text-muted)' }}/>
        </button>
      </div>

      {/* ── Table ─────────────────────────────────────────── */}
      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              <tr>
                {['Staff Member','Role / Designation','Departments','Profile Group','Status','Last Active','Actions'].map(h => (
                  <th key={h} className="px-5 py-3.5 text-left text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="px-6 py-16 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
                  <div className="flex items-center justify-center gap-3">
                    <div className="w-5 h-5 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"/>
                    Loading staff…
                  </div>
                </td></tr>
              ) : staff.length === 0 ? (
                <tr><td colSpan={7} className="px-6 py-16 text-center">
                  <div className="flex flex-col items-center gap-3">
                    <div className="w-14 h-14 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.08)' }}>
                      <UserCheck size={24} style={{ color: '#7C3AED' }}/>
                    </div>
                    <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>No staff members found</p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Try adjusting your filters or add a new staff member</p>
                  </div>
                </td></tr>
              ) : staff.map((member, idx) => {
                const meta = member.meta || {}
                const depts = Array.isArray(meta.member_departments) ? meta.member_departments : []
                return (
                  <tr
                    key={member.id}
                    style={{ borderBottom: idx !== staff.length - 1 ? '1px solid var(--border)' : 'none' }}
                  >
                    {/* Staff Info */}
                    <td className="px-5 py-4">
                      <div className="flex items-center gap-3">
                        <div
                          className="w-10 h-10 rounded-xl flex items-center justify-center font-bold text-sm flex-shrink-0"
                          style={{ background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff' }}
                        >
                          {member.name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                          <div className="flex items-center gap-1.5">
                            <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{member.name}</span>
                            {/* Admins appear in this list now, so the list has to
                                say which they are — "who are the admins" should be
                                answerable from the screen, not the database. */}
                            {member.role === 'admin' && (
                              <span className="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase flex items-center gap-1"
                                style={{ background:'rgba(16,185,129,0.12)', color:'#10b981', border:'1px solid rgba(16,185,129,0.2)' }}>
                                <Shield size={9}/> ADMIN
                              </span>
                            )}
                            {meta.is_moderator && (
                              <span className="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase flex items-center gap-1"
                                style={{ background:'rgba(245,158,11,0.12)', color:'#f59e0b', border:'1px solid rgba(245,158,11,0.2)' }}>
                                <Shield size={9}/> MOD
                              </span>
                            )}
                          </div>
                          <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{member.email}</div>
                          {member.phone && <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{member.phone}</div>}
                        </div>
                      </div>
                    </td>

                    {/* Role */}
                    <td className="px-5 py-4">
                      <div>
                        <div className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{formatRole(member.internal_role)}</div>
                        {member.designation && (
                          <div className="text-xs" style={{ color: 'var(--text-muted)' }}>{member.designation}</div>
                        )}
                      </div>
                    </td>

                    {/* Departments */}
                    <td className="px-5 py-4">
                      <div className="flex flex-wrap gap-1 max-w-[160px]">
                        {depts.length > 0
                          ? depts.slice(0, 3).map(d => (
                              <span key={d} className="px-2 py-0.5 rounded-lg text-[10px] font-semibold"
                                style={{ background:'rgba(124,58,237,0.08)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.15)' }}>
                                {d}
                              </span>
                            ))
                          : <span className="text-xs" style={{ color:'var(--text-muted)' }}>—</span>
                        }
                        {depts.length > 3 && (
                          <span className="px-2 py-0.5 rounded-lg text-[10px] font-semibold"
                            style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
                            +{depts.length - 3}
                          </span>
                        )}
                      </div>
                    </td>

                    {/* Profile Group */}
                    <td className="px-5 py-4">
                      <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {meta.profile_group || member.department || '—'}
                      </span>
                    </td>

                    {/* Status */}
                    <td className="px-5 py-4"><StatusBadge status={member.status}/></td>

                    {/* Last Active */}
                    <td className="px-5 py-4">
                      <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {getRelativeTime(member.updated_at)}
                      </span>
                    </td>

                    {/* Actions */}
                    <td className="px-5 py-4">
                      <div className="flex items-center justify-center relative" onClick={e => e.stopPropagation()}>
                        <button
                          onClick={() => setActionMenuOpen(actionMenuOpen === member.id ? null : member.id)}
                          className="w-8 h-8 rounded-lg flex items-center justify-center transition-all"
                          style={{ background: actionMenuOpen === member.id ? 'var(--bg-input)' : 'transparent', border: '1px solid transparent' }}
                        >
                          <MoreVertical size={16} style={{ color: 'var(--text-muted)' }}/>
                        </button>

                        {actionMenuOpen === member.id && (
                          <div
                            className="absolute right-0 top-10 rounded-xl overflow-hidden z-50 shadow-2xl min-w-[180px]"
                            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}
                          >
                            {[
                              {
                                icon: <Edit size={14}/>, label: 'Edit',
                                onClick: () => { setSelectedStaff(member); setShowStaffModal(true); setActionMenuOpen(null) },
                                color: 'var(--text-h)',
                              },
                              {
                                icon: <Power size={14}/>, label: member.status === 'active' ? 'Deactivate' : 'Activate',
                                onClick: () => handleToggleStatus(member),
                                color: member.status === 'active' ? '#f59e0b' : '#10b981',
                              },
                              {
                                icon: <Trash2 size={14}/>, label: 'Delete',
                                onClick: () => { setSelectedStaff(member); setShowDeleteModal(true); setActionMenuOpen(null) },
                                color: '#ef4444',
                              },
                            ].map(({ icon, label, onClick, color }, i, arr) => (
                              <button
                                key={label}
                                onClick={onClick}
                                className="w-full px-4 py-3 text-left text-sm flex items-center gap-3 transition-all"
                                style={{
                                  color,
                                  background: 'transparent',
                                  borderBottom: i < arr.length - 1 ? '1px solid var(--border)' : 'none',
                                }}
                                onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                              >
                                {icon} {label}
                              </button>
                            ))}
                          </div>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {pagination.last_page > 1 && (
          <div className="px-6 py-4 flex items-center justify-between" style={{ borderTop: '1px solid var(--border)' }}>
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Showing {((pagination.current_page-1)*pagination.per_page)+1}–
              {Math.min(pagination.current_page*pagination.per_page, pagination.total)} of {pagination.total}
            </span>
            <div className="flex gap-2">
              {[
                { label:'← Prev', disabled: pagination.current_page === 1, page: pagination.current_page - 1 },
                { label:'Next →', disabled: pagination.current_page === pagination.last_page, page: pagination.current_page + 1 },
              ].map(({ label, disabled, page }) => (
                <button
                  key={label}
                  disabled={disabled}
                  onClick={() => setPagination(p => ({...p, current_page: page}))}
                  className="px-4 py-2 rounded-xl text-xs font-bold disabled:opacity-40"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* ── Modals ────────────────────────────────────────── */}
      {showRolesModal && (
        <RolesModal
          onClose={() => setShowRolesModal(false)}
          onChanged={() => { fetchDesignations(); fetchStaff() }}
        />
      )}

      {showStaffModal && (
        <StaffModal
          staff={selectedStaff}
          departments={departments}
          onClose={() => { setShowStaffModal(false); setSelectedStaff(null) }}
          onSuccess={() => {
            fetchStaff(); fetchStats(); invalidateDirectory()
            setShowStaffModal(false); setSelectedStaff(null)
            showToast(selectedStaff ? 'Staff member updated!' : 'Staff member created!')
          }}
        />
      )}

      {showDeleteModal && (
        <DeleteConfirmModal
          title="Delete Staff Member"
          message={`Are you sure you want to delete ${selectedStaff?.name}? This action cannot be undone.`}
          onConfirm={handleDelete}
          onCancel={() => { setShowDeleteModal(false); setSelectedStaff(null) }}
        />
      )}
    </div>
  )
}

function StatusBadge({ status }) {
  const map = {
    active:    { bg:'rgba(16,185,129,0.12)',  border:'rgba(16,185,129,0.25)',  text:'#10b981', dot:'#10b981' },
    inactive:  { bg:'rgba(107,114,128,0.12)', border:'rgba(107,114,128,0.25)', text:'#9ca3af', dot:'#6b7280' },
    suspended: { bg:'rgba(239,68,68,0.12)',   border:'rgba(239,68,68,0.25)',   text:'#f87171', dot:'#ef4444' },
  }
  const s = map[status] || map.inactive
  return (
    <span
      className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-bold uppercase"
      style={{ background:s.bg, border:`1px solid ${s.border}`, color:s.text }}
    >
      <span className="w-1.5 h-1.5 rounded-full" style={{ background:s.dot }}/>
      {status}
    </span>
  )
}
