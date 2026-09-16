import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Plus, RefreshCw, Search, Truck, AlertTriangle, Loader2, ChevronRight } from 'lucide-react'
import { transportVehicleApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import Modal from '@/components/ui/Modal'
import VehicleForm, { emptyVehicle, validateVehicle } from '../components/VehicleForm'
import { FilterChip, Chip } from '../components/MasterFormFields'
import { VEHICLE_STATUS_LABEL, vehicleStatusCfg, fmtDate } from '../constants'

/**
 * Vehicle master list — SNG-TRN-003.
 *
 * Same structure as TransportOrders: chips that filter this list, a search box,
 * a table whose rows open the record. Chips cover all 13 FLEET §7 states so one
 * never silently disappears when its count drops to zero.
 *
 * useState/useEffect, matching the rest of the CRM.
 */
export default function TransportVehicles() {
  const navigate = useNavigate()
  const toast = useToast()

  const [rows, setRows] = useState([])
  const [counts, setCounts] = useState({})
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(emptyVehicle())
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const [page, c] = await Promise.all([
        transportVehicleApi.list({ status: status || undefined, search: search || undefined }),
        transportVehicleApi.statusCounts(),
      ])
      setRows(Array.isArray(page?.data) ? page.data : [])
      setCounts(c || {})
    } catch (e) {
      setError(e?.message || 'Could not load vehicles.')
    } finally {
      setLoading(false)
    }
  }, [status, search])

  useEffect(() => { load() }, [load])

  const submit = async () => {
    const problem = validateVehicle(form)
    if (problem) return toast.error(problem)
    setSaving(true)
    try {
      const created = await transportVehicleApi.create(form)
      toast.success(`Vehicle ${created?.registration_number ?? ''} added.`)
      setOpen(false); setForm(emptyVehicle())
      load()
    } catch (e) {
      toast.error(e?.message || 'The vehicle could not be added.')
    } finally {
      setSaving(false)
    }
  }

  const total = Object.values(counts).reduce((a, b) => a + Number(b || 0), 0)

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Transport</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Vehicles</h1>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '4px 0 0' }}>
            The fleet master. A vehicle must be Available, compliant and free before it can be allocated to a trip. Open a row to edit or delete it.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} disabled={loading}
            style={{ padding: '8px 13px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
            <RefreshCw size={13} className={loading ? 'animate-spin' : ''} /> Refresh
          </button>
          <button onClick={() => { setForm(emptyVehicle()); setOpen(true) }}
            style={{ padding: '8px 14px', borderRadius: 9, background: '#7C3AED', border: '1px solid #7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
            <Plus size={14} /> Add Vehicle
          </button>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <FilterChip active={status === ''} onClick={() => setStatus('')} label="All" count={total} />
        {Object.entries(VEHICLE_STATUS_LABEL).map(([key, label]) => (
          <FilterChip key={key} active={status === key} onClick={() => setStatus(key)}
            label={label} count={counts[key] || 0} cfg={vehicleStatusCfg(key)} />
        ))}
      </div>

      <div style={{ position: 'relative', maxWidth: 420 }}>
        <Search size={14} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--text-muted)' }} />
        <input value={search} onChange={(e) => setSearch(e.target.value)}
          placeholder="Registration, fleet number or chassis…"
          style={{ width: '100%', padding: '9px 12px 9px 32px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 9, color: 'var(--text-h)', fontSize: 13, outline: 'none' }} />
      </div>

      <div className="pr-glass" style={{ borderRadius: 12, overflow: 'hidden' }}>
        {error && (
          <div style={{ padding: 16, display: 'flex', gap: 8, alignItems: 'center', color: '#f87171', fontSize: 13 }}>
            <AlertTriangle size={15} /> {error}
          </div>
        )}
        {loading && !error && (
          <div style={{ padding: 28, textAlign: 'center', color: 'var(--text-muted)' }}>
            <Loader2 size={18} className="animate-spin" style={{ margin: '0 auto' }} />
          </div>
        )}
        {!loading && !error && rows.length === 0 && (
          <div style={{ padding: 34, textAlign: 'center' }}>
            <Truck size={26} style={{ color: 'var(--text-muted)', margin: '0 auto 8px' }} />
            <p style={{ fontSize: 13.5, color: 'var(--text-h)', fontWeight: 700, margin: 0 }}>No vehicles yet</p>
            <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '4px 0 0' }}>Add your first vehicle to start allocating trips.</p>
          </div>
        )}
        {!loading && !error && rows.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 760 }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--border)' }}>
                  {['Registration', 'Type', 'Capacity', 'Ownership', 'Branch', 'Status', ''].map((h, i) => (
                    <th key={h || i} style={{ textAlign: 'left', padding: '11px 14px', fontSize: 10.5, fontWeight: 800, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} onClick={() => navigate(`/app/transport/vehicles/${r.id}`)}
                    style={{ borderBottom: '1px solid var(--border)', cursor: 'pointer' }}
                    onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--bg-input)')}
                    onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}>
                    <td style={{ padding: '11px 14px' }}>
                      <p style={{ margin: 0, fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{r.registration_number}</p>
                      {r.fleet_number && <p style={{ margin: '2px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>{r.fleet_number}</p>}
                    </td>
                    <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-p)' }}>{r.vehicle_type || '—'}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-p)' }}>{r.capacity_tonnes ? `${Number(r.capacity_tonnes)} t` : '—'}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-p)', textTransform: 'capitalize' }}>{r.ownership_type || '—'}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-p)' }}>{r.branch || '—'}</td>
                    <td style={{ padding: '11px 14px' }}><Chip cfg={vehicleStatusCfg(r.status)} /></td>
                    <td style={{ padding: '11px 14px', textAlign: 'right' }} title="Open to edit">
                      <ChevronRight size={15} style={{ color: 'var(--text-muted)' }} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal open={open} onClose={() => setOpen(false)} title="Add a vehicle" size="lg">
        <div style={{ display: 'grid', gap: 16 }}>
          <VehicleForm value={form} onChange={setForm} mode="create" />
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <button onClick={() => setOpen(false)} style={{ padding: '9px 15px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>Cancel</button>
            <button onClick={submit} disabled={saving}
              style={{ padding: '9px 17px', borderRadius: 9, background: '#7C3AED', border: '1px solid #7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800, cursor: saving ? 'wait' : 'pointer' }}>
              {saving ? 'Saving…' : 'Add vehicle'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
