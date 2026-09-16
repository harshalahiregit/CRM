import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Plus, RefreshCw, Search, UserRound, AlertTriangle, Loader2, ChevronRight } from 'lucide-react'
import { transportDriverApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import Modal from '@/components/ui/Modal'
import DriverForm, { emptyDriver, validateDriver } from '../components/DriverForm'
import { FilterChip, Chip } from '../components/MasterFormFields'
import {
  DRIVER_STATUS_LABEL, driverStatusCfg,
  DRIVER_AVAILABILITY_LABEL, driverAvailabilityCfg,
  expiryCfg, fmtDate,
} from '../constants'

/**
 * Driver master list — SNG-TRN-004.
 *
 * A driver carries TWO independent state axes (BO-009 lifecycle, STOS-DB §44
 * availability), so the list filters on both and shows both. Collapsing them
 * into one chip row would make "Blocked" ambiguous between an administrative bar
 * and an operational state — the exact distinction DriverStatus exists to keep.
 *
 * The licence column shows its expiry state directly, because an expired licence
 * is the single most common reason a driver cannot be allocated (BR-P0-004).
 */
export default function TransportDrivers() {
  const navigate = useNavigate()
  const toast = useToast()

  const [rows, setRows] = useState([])
  const [counts, setCounts] = useState({ status: {}, availability: {} })
  const [status, setStatus] = useState('')
  const [availability, setAvailability] = useState('')
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(emptyDriver())
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const [page, c] = await Promise.all([
        transportDriverApi.list({
          status: status || undefined,
          availability: availability || undefined,
          search: search || undefined,
        }),
        transportDriverApi.statusCounts(),
      ])
      setRows(Array.isArray(page?.data) ? page.data : [])
      setCounts(c || { status: {}, availability: {} })
    } catch (e) {
      setError(e?.message || 'Could not load drivers.')
    } finally {
      setLoading(false)
    }
  }, [status, availability, search])

  useEffect(() => { load() }, [load])

  const submit = async () => {
    const problem = validateDriver(form)
    if (problem) return toast.error(problem)
    setSaving(true)
    try {
      const created = await transportDriverApi.create(form)
      toast.success(`Driver ${created?.name ?? ''} added.`)
      setOpen(false); setForm(emptyDriver())
      load()
    } catch (e) {
      toast.error(e?.message || 'The driver could not be added.')
    } finally {
      setSaving(false)
    }
  }

  const totalStatus = Object.values(counts.status || {}).reduce((a, b) => a + Number(b || 0), 0)

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Transport</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Drivers</h1>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '4px 0 0' }}>
            The driver master. A driver must be active, available and hold a valid licence before they can be allocated. Open a row to edit or delete them.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} disabled={loading}
            style={{ padding: '8px 13px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
            <RefreshCw size={13} className={loading ? 'animate-spin' : ''} /> Refresh
          </button>
          <button onClick={() => { setForm(emptyDriver()); setOpen(true) }}
            style={{ padding: '8px 14px', borderRadius: 9, background: '#7C3AED', border: '1px solid #7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
            <Plus size={14} /> Add Driver
          </button>
        </div>
      </div>

      {/* Two axes, two chip rows — see the component docblock. */}
      <div>
        <p style={{ fontSize: 10.5, fontWeight: 800, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-muted)', margin: '0 0 6px' }}>Record status</p>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <FilterChip active={status === ''} onClick={() => setStatus('')} label="All" count={totalStatus} />
          {Object.entries(DRIVER_STATUS_LABEL).map(([k, label]) => (
            <FilterChip key={k} active={status === k} onClick={() => setStatus(k)} label={label}
              count={counts.status?.[k] || 0} cfg={driverStatusCfg(k)} />
          ))}
        </div>
      </div>
      <div>
        <p style={{ fontSize: 10.5, fontWeight: 800, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-muted)', margin: '0 0 6px' }}>Availability</p>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <FilterChip active={availability === ''} onClick={() => setAvailability('')} label="Any" count={totalStatus} />
          {Object.entries(DRIVER_AVAILABILITY_LABEL).map(([k, label]) => (
            <FilterChip key={k} active={availability === k} onClick={() => setAvailability(k)} label={label}
              count={counts.availability?.[k] || 0} cfg={driverAvailabilityCfg(k)} />
          ))}
        </div>
      </div>

      <div style={{ position: 'relative', maxWidth: 420 }}>
        <Search size={14} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--text-muted)' }} />
        <input value={search} onChange={(e) => setSearch(e.target.value)}
          placeholder="Name, driver code, mobile or licence…"
          style={{ width: '100%', padding: '9px 12px 9px 32px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 9, color: 'var(--text-h)', fontSize: 13, outline: 'none' }} />
      </div>

      <div className="pr-glass" style={{ borderRadius: 12, overflow: 'hidden' }}>
        {error && <div style={{ padding: 16, display: 'flex', gap: 8, alignItems: 'center', color: '#f87171', fontSize: 13 }}><AlertTriangle size={15} /> {error}</div>}
        {loading && !error && <div style={{ padding: 28, textAlign: 'center' }}><Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>}
        {!loading && !error && rows.length === 0 && (
          <div style={{ padding: 34, textAlign: 'center' }}>
            <UserRound size={26} style={{ color: 'var(--text-muted)', margin: '0 auto 8px' }} />
            <p style={{ fontSize: 13.5, color: 'var(--text-h)', fontWeight: 700, margin: 0 }}>No drivers yet</p>
            <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '4px 0 0' }}>Add your first driver to start allocating trips.</p>
          </div>
        )}
        {!loading && !error && rows.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 820 }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--border)' }}>
                  {['Driver', 'Mobile', 'Licence', 'Licence expiry', 'Record', 'Availability', ''].map((h, i) => (
                    <th key={h || i} style={{ textAlign: 'left', padding: '11px 14px', fontSize: 10.5, fontWeight: 800, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => {
                  const lic = r.licence_number ? expiryCfg(r.licence_valid_until) : { label: 'No licence', color: '#f87171', bg: 'rgba(248,113,113,0.16)' }
                  return (
                    <tr key={r.id} onClick={() => navigate(`/app/transport/drivers/${r.id}`)}
                      style={{ borderBottom: '1px solid var(--border)', cursor: 'pointer' }}
                      onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--bg-input)')}
                      onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}>
                      <td style={{ padding: '11px 14px' }}>
                        <p style={{ margin: 0, fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{r.name}</p>
                        {r.driver_code && <p style={{ margin: '2px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>{r.driver_code}</p>}
                      </td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-p)' }}>{r.mobile || '—'}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-p)' }}>
                        {r.licence_number || '—'}{r.licence_class ? ` · ${r.licence_class}` : ''}
                      </td>
                      <td style={{ padding: '11px 14px' }}>
                        <Chip cfg={lic} />
                        {r.licence_valid_until && <p style={{ margin: '3px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>{fmtDate(r.licence_valid_until)}</p>}
                      </td>
                      <td style={{ padding: '11px 14px' }}><Chip cfg={driverStatusCfg(r.status)} /></td>
                      <td style={{ padding: '11px 14px' }}><Chip cfg={driverAvailabilityCfg(r.availability)} /></td>
                      <td style={{ padding: '11px 14px', textAlign: 'right' }} title="Open to edit">
                        <ChevronRight size={15} style={{ color: 'var(--text-muted)' }} />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal open={open} onClose={() => setOpen(false)} title="Add a driver" size="lg">
        <div style={{ display: 'grid', gap: 16 }}>
          <DriverForm value={form} onChange={setForm} mode="create" />
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <button onClick={() => setOpen(false)} style={{ padding: '9px 15px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>Cancel</button>
            <button onClick={submit} disabled={saving}
              style={{ padding: '9px 17px', borderRadius: 9, background: '#7C3AED', border: '1px solid #7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800, cursor: saving ? 'wait' : 'pointer' }}>
              {saving ? 'Saving…' : 'Add driver'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
