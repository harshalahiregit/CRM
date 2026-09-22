import { useState, useEffect } from 'react'
import { X, ChevronRight, Search, Building2, User, KeyRound, ArrowLeft, Loader2, Check } from 'lucide-react'
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
 *
 * Several employees can be ticked and sent in one go. That matters more here
 * than in a plain list: getting to this screen is two stages, so one pick per
 * person meant walking vendor → employee again for every name on the crew.
 * onPick is called once per employee, so the caller is unchanged either way.
 *
 * Closes on the X or Cancel only — never on a backdrop click, which two stages
 * and several ticks in would throw the whole walk away.
 */
export default function VendorEmployeeCascadePicker({ open, onClose, onPick, accent = '#7C3AED', excludeIds = [] }) {
  const [vendors, setVendors] = useState([])
  const [vendor, setVendor] = useState(null)      // chosen vendor record
  const [employees, setEmployees] = useState([])
  const [loadingV, setLoadingV] = useState(false)
  const [loadingE, setLoadingE] = useState(false)
  const [granting, setGranting] = useState(null)
  const [picked, setPicked] = useState([])        // employees ticked, not yet sent
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')

  useEffect(() => {
    if (!open) return
    setVendor(null); setEmployees([]); setQ(''); setPicked([]); setErr('')
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

  const isPicked = (emp) => picked.some(p => p.id === emp.id)

  const toggleEmployee = (emp) => {
    setErr('')
    // Someone with no email cannot be given a login, so they can never become an
    // assignee. Said at the moment of ticking rather than on confirm, where it
    // would be one refusal standing for a whole batch.
    if (!emp.user_id && !emp.email) {
      setErr(`${emp.name} has no email address — add one on the vendor's Contacts tab before assigning them.`)
      return
    }
    setPicked(p => p.some(x => x.id === emp.id) ? p.filter(x => x.id !== emp.id) : [...p, emp])
  }

  /*
   * Send everyone ticked.
   *
   * Some of them have no login yet, and an assignee has to be a users.id — so
   * those are provisioned first, one at a time because grant-access is a write
   * per employee. Each success is handed to the caller as it lands rather than
   * at the end: if the fourth one fails, the first three are still assigned and
   * the message names only the one that did not.
   */
  const confirm = async () => {
    if (!picked.length) return
    setErr('')
    const failed = []

    for (const emp of picked) {
      if (emp.user_id) { onPick({ user_id: emp.user_id, name: emp.name }); continue }

      setGranting(emp.id)
      try {
        const updated = await tpvApi.employees.grantAccess(vendor.id, emp.id)
        if (!updated?.user_id) throw new Error('No login returned')
        onPick({ user_id: updated.user_id, name: emp.name })
      } catch (e) {
        failed.push(emp.name)
      } finally {
        setGranting(null)
      }
    }

    if (failed.length) {
      setErr(`Could not enable a login for ${failed.join(', ')}. The others were assigned.`)
      setPicked(p => p.filter(x => failed.includes(x.name)))
      return
    }

    onClose()
  }

  if (!open) return null

  const ql = q.trim().toLowerCase()
  const vList = ql ? vendors.filter(v => (v.company_name || v.name || '').toLowerCase().includes(ql) || (v.vendor_code || '').toLowerCase().includes(ql)) : vendors
  const eList = employees
    // Somebody already on the task is not a choice. A ticked-but-unsent employee
    // stays in the list — that is where their tick is drawn.
    .filter(e => !excludeIds.includes(e.user_id))
    .filter(e => !ql || (e.name || '').toLowerCase().includes(ql) || (e.designation || '').toLowerCase().includes(ql))

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 70, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '10vh 16px 16px', backdropFilter: 'blur(2px)' }}>
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
        <div style={{ overflowY: 'auto', padding: 8, flex: 1, minHeight: 0 }}>
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
              <button key={e.id} onClick={() => toggleEmployee(e)} disabled={granting === e.id}
                style={{ ...rowStyle, opacity: granting === e.id ? 0.6 : 1,
                  background: isPicked(e) ? `${accent}1f` : 'none' }}>
                <span style={{ width: 16, height: 16, borderRadius: 4, flexShrink: 0,
                  display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                  border: `1.5px solid ${isPicked(e) ? accent : 'var(--border)'}`,
                  background: isPicked(e) ? accent : 'transparent' }}>
                  {isPicked(e) && <Check size={11} strokeWidth={3} style={{ color: '#fff' }} />}
                </span>
                <span style={{ ...iconWrap, background: `${accent}18` }}><User size={15} style={{ color: accent }} /></span>
                <span style={{ flex: 1, minWidth: 0, textAlign: 'left' }}>
                  <span style={rowTitle}>{e.name}</span>
                  <span style={rowSub}>{[e.designation, e.email].filter(Boolean).join(' · ') || 'No details'}</span>
                </span>
                {e.user_id
                  ? <span style={{ fontSize: 10.5, fontWeight: 700, color: '#10b981', flexShrink: 0 }}>Assignable</span>
                  : <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 10.5, fontWeight: 700, color: accent, flexShrink: 0 }}><KeyRound size={11} /> {granting === e.id ? 'Enabling…' : 'Needs a login'}</span>}
              </button>
            ))
          )}
        </div>

        {/* Footer — only once there are employees to tick. On the vendor stage
            there is nothing to confirm, and a dead Assign button there reads as
            something being broken. */}
        {vendor && (
          <div style={{ borderTop: '1px solid var(--border)', padding: '10px 14px' }}>
            {err && (
              <p style={{ margin: '0 0 8px', fontSize: 11.5, color: 'var(--color-danger-500)', lineHeight: 1.45 }}>{err}</p>
            )}
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <span style={{ flex: 1, fontSize: 11, color: 'var(--text-muted)' }}>
                {picked.length
                  ? `${picked.length} selected${picked.some(p => !p.user_id) ? ' · a login is created for anyone who has none' : ''}`
                  : 'Tick everyone from this vendor who is on the task'}
              </span>
              <button onClick={onClose} style={footBtn}>Cancel</button>
              <button onClick={confirm} disabled={!picked.length || granting !== null}
                style={{ ...footBtn,
                  background: picked.length ? accent : 'var(--bg-input)',
                  color: picked.length ? '#fff' : 'var(--text-muted)',
                  cursor: picked.length ? 'pointer' : 'not-allowed' }}>
                {granting !== null ? 'Assigning…' : picked.length ? `Assign ${picked.length}` : 'Assign'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

const footBtn = { fontSize: 12, fontWeight: 700, padding: '6px 12px', borderRadius: 9,
  background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-body)', cursor: 'pointer' }

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
