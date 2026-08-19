import { useState, useEffect } from 'react'
import { X, ChevronRight, Search, Building2, User, KeyRound, ArrowLeft, Loader2 } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'

/**
 * Two-stage assignee cascade for TPV vendors (enhancement #9): pick a vendor
 * record → see ONLY that vendor's employees → assign one. Selecting an employee
 * that has no login yet provisions one on the fly (grant-access), so the returned
 * value is always a users.id ready to drop into task/project assignees.
 *
 * onPick({ user_id, name }) — called with the employee's login user id + display
 * name. The caller adds user_id to assignee_ids; the name lets it render the chip
 * immediately even before the staff/vendor list refetches.
 */
export default function VendorEmployeeCascadePicker({ open, onClose, onPick, accent = '#7C3AED', excludeIds = [] }) {
  const [vendors, setVendors] = useState([])
  const [vendor, setVendor] = useState(null)      // chosen vendor record
  const [employees, setEmployees] = useState([])
  const [loadingV, setLoadingV] = useState(false)
  const [loadingE, setLoadingE] = useState(false)
  const [granting, setGranting] = useState(null)
  const [q, setQ] = useState('')

  useEffect(() => {
    if (!open) return
    setVendor(null); setEmployees([]); setQ('')
    setLoadingV(true)
    tpvApi.vendors.list()
      .then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : []))
      .catch(() => setVendors([]))
      .finally(() => setLoadingV(false))
  }, [open])

  const chooseVendor = (v) => {
    setVendor(v); setQ(''); setLoadingE(true)
    tpvApi.employees.list(v.id)
      .then(rows => setEmployees(Array.isArray(rows) ? rows : []))
      .catch(() => setEmployees([]))
      .finally(() => setLoadingE(false))
  }

  const chooseEmployee = async (emp) => {
    // Already has a login → assign directly. Otherwise provision one first.
    if (emp.user_id) { onPick({ user_id: emp.user_id, name: emp.name }); onClose(); return }
    if (!emp.email) { alert('This employee has no email — add one on the vendor Contacts tab before assigning.'); return }
    setGranting(emp.id)
    try {
      const updated = await tpvApi.employees.grantAccess(vendor.id, emp.id)
      const uid = updated?.user_id
      if (!uid) throw new Error('No login returned')
      onPick({ user_id: uid, name: emp.name }); onClose()
    } catch (e) {
      alert(e?.response?.data?.message || 'Could not enable a login for this employee.')
    } finally {
      setGranting(null)
    }
  }

  if (!open) return null

  const ql = q.trim().toLowerCase()
  const vList = ql ? vendors.filter(v => (v.company_name || v.name || '').toLowerCase().includes(ql) || (v.vendor_code || '').toLowerCase().includes(ql)) : vendors
  const eList = employees
    .filter(e => !excludeIds.includes(e.user_id))
    .filter(e => !ql || (e.name || '').toLowerCase().includes(ql) || (e.designation || '').toLowerCase().includes(ql))

  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 70, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '10vh 16px 16px', backdropFilter: 'blur(2px)' }}>
      <div onClick={e => e.stopPropagation()} style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, width: '100%', maxWidth: 460, maxHeight: '70vh', display: 'flex', flexDirection: 'column', overflow: 'hidden', boxShadow: '0 20px 60px rgba(0,0,0,0.4)' }}>
        {/* Header */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 16px', borderBottom: '1px solid var(--border)' }}>
          {vendor && (
            <button onClick={() => { setVendor(null); setEmployees([]); setQ('') }} title="Back to vendors"
              style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex', padding: 2 }}><ArrowLeft size={18} /></button>
          )}
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{vendor ? vendor.company_name || vendor.name : 'Assign a third-party vendor'}</div>
            <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{vendor ? 'Pick an employee to assign' : 'First choose the vendor, then its employee'}</div>
          </div>
          <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex', padding: 2 }}><X size={18} /></button>
        </div>

        {/* Search */}
        <div style={{ padding: '10px 16px', borderBottom: '1px solid var(--border)', position: 'relative' }}>
          <Search size={14} style={{ position: 'absolute', left: 26, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input autoFocus value={q} onChange={e => setQ(e.target.value)} placeholder={vendor ? 'Search employees…' : 'Search vendors…'}
            style={{ width: '100%', padding: '8px 10px 8px 30px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13 }} />
        </div>

        {/* Body */}
        <div style={{ overflowY: 'auto', padding: 8 }}>
          {!vendor ? (
            loadingV ? <Empty icon={Loader2} spin text="Loading vendors…" />
            : vList.length === 0 ? <Empty icon={Building2} text="No third-party vendors found." />
            : vList.map(v => (
              <button key={v.id} onClick={() => chooseVendor(v)} style={rowStyle}>
                <span style={{ ...iconWrap, background: `${accent}18` }}><Building2 size={15} style={{ color: accent }} /></span>
                <span style={{ flex: 1, minWidth: 0, textAlign: 'left' }}>
                  <span style={rowTitle}>{v.company_name || v.name}</span>
                  <span style={rowSub}>{[v.vendor_code, v.status].filter(Boolean).join(' · ')}</span>
                </span>
                <ChevronRight size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              </button>
            ))
          ) : (
            loadingE ? <Empty icon={Loader2} spin text="Loading employees…" />
            : eList.length === 0 ? <Empty icon={User} text="No employees for this vendor yet. Add them on the vendor's Contacts tab." />
            : eList.map(e => (
              <button key={e.id} onClick={() => chooseEmployee(e)} disabled={granting === e.id} style={{ ...rowStyle, opacity: granting === e.id ? 0.6 : 1 }}>
                <span style={{ ...iconWrap, background: `${accent}18` }}><User size={15} style={{ color: accent }} /></span>
                <span style={{ flex: 1, minWidth: 0, textAlign: 'left' }}>
                  <span style={rowTitle}>{e.name}</span>
                  <span style={rowSub}>{[e.designation, e.email].filter(Boolean).join(' · ') || 'No details'}</span>
                </span>
                {e.user_id
                  ? <span style={{ fontSize: 10.5, fontWeight: 700, color: '#10b981', flexShrink: 0 }}>Assignable</span>
                  : <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 10.5, fontWeight: 700, color: accent, flexShrink: 0 }}><KeyRound size={11} /> {granting === e.id ? 'Enabling…' : 'Grant + assign'}</span>}
              </button>
            ))
          )}
        </div>
      </div>
    </div>
  )
}

const rowStyle = { display: 'flex', alignItems: 'center', gap: 11, width: '100%', padding: '9px 11px', borderRadius: 10, border: 'none', background: 'none', cursor: 'pointer', color: 'var(--text-h)' }
const iconWrap = { width: 30, height: 30, borderRadius: 9, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }
const rowTitle = { display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }
const rowSub = { display: 'block', fontSize: 11, color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }

function Empty({ icon: Icon, text, spin }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '28px 16px', color: 'var(--text-muted)', fontSize: 12.5, textAlign: 'center' }}>
      <Icon size={20} className={spin ? 'rfq-spin' : undefined} />
      {text}
    </div>
  )
}
