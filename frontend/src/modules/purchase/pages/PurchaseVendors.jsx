import { useState, useEffect, useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { Building2, Plus, RefreshCw, CheckCircle2, Eye, CalendarDays, Pencil } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import PurchaseVendorForm, { validatePurchaseVendor } from '@/modules/purchase/components/PurchaseVendorForm'
import PurchaseRegistrationBadge from '@/modules/purchase/components/PurchaseRegistrationBadge'
import TemporaryVendorValidityBadge from '@/modules/purchase/components/TemporaryVendorValidityBadge'
import { PV_DEFAULTS } from '@/modules/purchase/components/purchaseVendorFormConstants'
import TableToolbar from '@/components/ui/TableToolbar'

/**
 * Purchase Vendors — the admin master list for the Purchase-owned vendor entity
 * (/api/purchase/vendors). Independent of the shared Vendor and of TPV.
 */
const STATUS_COLORS = { Active: '#10b981', Pending_Approval: '#f59e0b', Draft: '#6b7280', On_Hold: '#f59e0b', Rejected: '#ef4444', Blacklisted: '#991b1b', Inactive: '#6b7280' }

export default function PurchaseVendors() {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [stats, setStats] = useState({ total: 0, active: 0, pending: 0, draft: 0 })
  const [loading, setLoading] = useState(true)
  const [q, setQ] = useState('')
  const [modal, setModal] = useState(null)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')
  const [editLoadingId, setEditLoadingId] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    Promise.all([purchaseApi.vendors.list({ search: q }), purchaseApi.vendors.stats()])
      .then(([list, s]) => { setRows(Array.isArray(list) ? list : (list?.data ?? [])); setStats(s || {}) })
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [q])

  useEffect(() => { load() }, [load])

  // One modal for both create and edit — an existing id is what tells them
  // apart. The form component already has an `edit` mode; the page simply never
  // opened it, so a vendor could be created and then never corrected.
  const isEdit = Boolean(modal?.id)

  const save = async () => {
    const invalid = validatePurchaseVendor(modal)
    if (invalid) { setErr(invalid); return }
    setSaving(true); setErr('')
    try {
      if (isEdit) await purchaseApi.vendors.update(modal.id, modal)
      else await purchaseApi.vendors.create(modal)
      setModal(null); load()
    } catch (e) {
      const errors = e?.response?.data?.errors
      setErr(errors ? Object.values(errors).flat()[0]
        : (e?.response?.data?.message || `Could not ${isEdit ? 'update' : 'create'} vendor.`))
    } finally { setSaving(false) }
  }

  const activate = async (id) => { try { await purchaseApi.vendors.approve(id); load() } catch { /* noop */ } }

  /**
   * Open the edit form on the FULL record, not the list row.
   *
   * The list is a summary — it omits notes, bank details, return policy,
   * payment terms and the address block. Seeding the form from a row would
   * show those fields blank for a vendor that has them, which reads as "this
   * vendor has no address" and invites someone to retype one.
   *
   * Falls back to the row if the fetch fails, so a flaky request degrades to a
   * partial form rather than no form at all.
   */
  const openEdit = async (v) => {
    setErr('')
    setEditLoadingId(v.id)
    try {
      const full = await purchaseApi.vendors.get(v.id)
      setModal({ ...PV_DEFAULTS, ...v, ...(full?.data ?? full ?? {}) })
    } catch {
      setModal({ ...PV_DEFAULTS, ...v })
    } finally {
      setEditLoadingId(null)
    }
  }

  /**
   * Column definitions drive the header AND every export, so the table on
   * screen and the file that comes out can never describe different columns.
   * `export` overrides the cell for the ones rendered as a badge — a CSV of
   * React elements is useless.
   */
  const columns = useMemo(() => [
    { key: 'purchase_vendor_code', label: 'Code' },
    { key: 'company_name',         label: 'Company' },
    { key: 'email',                label: 'Email' },
    { key: 'registration_type',    label: 'Type',     export: v => v.registration_type_label || v.registration_type || '' },
    { key: 'validity',             label: 'Remaining Validity', export: v => v.validity_countdown?.label || '' },
    { key: 'status',               label: 'Status',   export: v => v.status_label || v.status || '' },
    // Not on screen — the table has no room — but the single most useful
    // column in a spreadsheet, so the export carries it.
    { key: 'category',             label: 'Category', export: v => v.category || '' },
    { key: 'phone',                label: 'Phone',    export: v => v.phone || '' },
  ], [])

  return (
    <div style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Building2 size={22} style={{ color: '#7C3AED' }} />
          <h1 style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Purchase Vendors</h1>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} style={{ ...btn }}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => navigate('/app/purchase/kickoff')} style={{ ...btn }}><CalendarDays size={14} /> Kickoff Meetings</button>
          <button onClick={() => { setErr(''); setModal({ company_name: '', email: '', ...PV_DEFAULTS }) }} style={{ ...btn, background: '#7C3AED', color: '#fff', border: 'none' }}><Plus size={14} /> New Vendor</button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: 12, marginBottom: 16 }}>
        {/* Permanent + Temporary sum to Total: both derive from registration_type,
            so no vendor is counted twice or missed. */}
        {[['Total', stats.total], ['Permanent', stats.permanent], ['Temporary', stats.temporary], ['Active', stats.active], ['Pending', stats.pending], ['Draft', stats.draft]].map(([k, v]) => (
          <div key={k} className="card-3d" style={{ padding: 14 }}>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', fontWeight: 700 }}>{k}</div>
            <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--text-h)' }}>{v ?? 0}</div>
          </div>
        ))}
      </div>

      {/* Search + CSV / Excel / Copy / Print, from the same component TPV uses.
          Exports carry the rows currently loaded, which is the whole filtered
          set — the list is not server-paginated. */}
      <TableToolbar
        search={q}
        setSearch={setQ}
        placeholder="Search vendors…"
        columns={columns}
        rows={rows}
        filename="purchase-vendors"
        title="Purchase Vendors"
      />

      <div className="card-3d" style={{ overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ background: 'var(--bg-input)' }}>
              {['Code', 'Company', 'Email', 'Type', 'Remaining Validity', 'Status', ''].map((h) => <th key={h} style={th}>{h}</th>)}
            </tr>
          </thead>
          <tbody>
            {loading ? <tr><td colSpan={6} style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)' }}>Loading…</td></tr>
              : rows.length === 0 ? <tr><td colSpan={6} style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)' }}>No purchase vendors yet.</td></tr>
              : rows.map((v) => (
                <tr key={v.id} style={{ borderTop: '1px solid var(--border)' }}>
                  <td style={td}>{v.purchase_vendor_code}</td>
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>{v.company_name}</td>
                  <td style={td}>{v.email || '—'}</td>
                  <td style={td}><PurchaseRegistrationBadge type={v.registration_type} label={v.registration_type_label} /></td>
                  <td style={td}><TemporaryVendorValidityBadge countdown={v.validity_countdown} compact /></td>
                  <td style={td}><span style={{ fontSize: 11, fontWeight: 700, color: STATUS_COLORS[v.status] || '#6b7280' }}>{v.status_label || v.status}</span></td>
                  <td style={{ ...td, textAlign: 'right', whiteSpace: 'nowrap' }}>
                    {v.status !== 'Active' && <button onClick={() => activate(v.id)} style={{ ...miniBtn, color: '#10b981' }}><CheckCircle2 size={13} /> Activate</button>}
                    <button onClick={() => openEdit(v)} disabled={editLoadingId === v.id} style={miniBtn}>
                      <Pencil size={13} /> {editLoadingId === v.id ? 'Opening…' : 'Edit'}
                    </button>
                    <button onClick={() => navigate(`/app/purchase/vendors/${v.id}`)} style={miniBtn}><Eye size={13} /> View</button>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {modal && (
        <div style={overlay}>
          <div className="card-3d" style={{ padding: 0, width: 720, maxWidth: '95vw', maxHeight: '92vh', display: 'flex', flexDirection: 'column' }} onClick={(e) => e.stopPropagation()}>
            <div style={{ padding: '18px 20px', borderBottom: '1px solid var(--border)' }}>
              <h2 style={{ fontSize: 17, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>
                {isEdit ? `Edit · ${modal.company_name}` : 'New Purchase Vendor'}
              </h2>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
                {isEdit
                  ? `Vendor Code ${modal.purchase_vendor_code || '—'} · assigned on creation and never changes.`
                  : 'Vendor Code is auto-generated on save.'}
              </div>
            </div>
            <div style={{ padding: 20, overflowY: 'auto' }}>
              <PurchaseVendorForm value={modal} onChange={setModal} mode={isEdit ? 'edit' : 'create'} />
            </div>
            <div style={{ padding: '14px 20px', borderTop: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
              <span style={{ color: '#ef4444', fontSize: 12 }}>{err}</span>
              <div style={{ display: 'flex', gap: 8 }}>
                <button onClick={() => setModal(null)} style={btn}>Cancel</button>
                <button onClick={save} disabled={saving || !modal.company_name} style={{ ...btn, background: '#7C3AED', color: '#fff', border: 'none' }}>
                  {saving ? 'Saving…' : (isEdit ? 'Save Changes' : 'Create Vendor')}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '4px 8px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700, marginLeft: 6 }
const th = { textAlign: 'left', padding: '10px 12px', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em', color: 'var(--text-muted)', fontWeight: 700 }
const td = { padding: '10px 12px', color: 'var(--text-muted)' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 60 }
