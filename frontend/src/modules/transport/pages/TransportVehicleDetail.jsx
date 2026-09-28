import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, Trash2, Truck, Save, Loader2, AlertTriangle, ShieldCheck, ShieldAlert } from 'lucide-react'
import { transportVehicleApi, transportCapabilityApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import VehicleForm, { validateVehicle } from '../components/VehicleForm'
import DocumentsPanel from '../components/DocumentsPanel'
import { Chip } from '../components/MasterFormFields'
import { vehicleStatusCfg, VEHICLE_TRANSITIONS, VEHICLE_DOCUMENT_TYPES, fmtDateTime } from '../constants'

/**
 * Vehicle detail — SNG-TRN-003.
 *
 * Layout mirrors TransportOrderDetail: a 1.6fr/1fr grid, record on the left,
 * state and history on the right.
 *
 * The eligibility panel exists because FLEET §16 draws a distinction the UI must
 * not blur — "Available" means the asset is FREE, "Eligible" means it MEETS
 * REQUIREMENTS. A vehicle can be Available and still not allocatable, so the
 * status chip and the eligibility verdict are shown side by side rather than
 * collapsed into one signal. UX §35: never merely show Blocked — show why.
 */
export default function TransportVehicleDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()

  const [data, setData] = useState(null)
  const [form, setForm] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  // What this user may actually do. Fetched rather than guessed, so a
  // destructive button is never shown to someone the API would refuse.
  const [grants, setGrants] = useState({})
  const [deleting, setDeleting] = useState(false)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const d = await transportVehicleApi.get(id)
      setData(d); setForm(d?.vehicle ? { ...d.vehicle } : null)
    } catch (e) {
      setError(e?.message || 'Could not load this vehicle.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => { load() }, [load])
  useEffect(() => { transportCapabilityApi.get().then((c) => setGrants(c?.grants || {})).catch(() => {}) }, [])

  const save = async () => {
    const problem = validateVehicle(form)
    if (problem) return toast.error(problem)
    setSaving(true)
    try {
      await transportVehicleApi.update(id, form)
      toast.success('Vehicle updated.')
      load()
    } catch (e) {
      toast.error(e?.message || 'The vehicle could not be updated.')
    } finally {
      setSaving(false)
    }
  }

  const move = async (to, label) => {
    try {
      await transportVehicleApi.transition(id, to)
      toast.success(`Vehicle ${label.toLowerCase()}.`)
      load()
    } catch (e) {
      toast.error(e?.message || 'That status change was refused.')
    }
  }

  /**
   * Deleting a master record is deliberately harder than editing one.
   *
   * FLEET §7 gives Retire and Sold as a vehicle's normal end, and Step 5's
   * conventions keep transactional history intact — so this exists for a record
   * created in error, not for taking a resource out of service. Owner/Admin only,
   * and the button is absent entirely for anyone else.
   */
  const remove = async () => {
    const reason = window.prompt('Deleting is for a record created in error. Deactivating or retiring is usually what you want.\n\nType a reason to confirm:')
    if (!reason) return
    setDeleting(true)
    try {
      await transportVehicleApi.remove(id, reason)
      toast.success('Vehicle deleted.')
      navigate('/app/transport/vehicles')
    } catch (e) {
      toast.error(e?.message || 'The vehicle could not be deleted.')
    } finally {
      setDeleting(false)
    }
  }

  if (loading) return <div style={{ padding: 40, textAlign: 'center' }}><Loader2 size={20} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  if (error) return <div style={{ padding: 24, color: '#f87171', display: 'flex', gap: 8, alignItems: 'center' }}><AlertTriangle size={16} /> {error}</div>
  if (!data?.vehicle) return null

  const v = data.vehicle
  const elig = data.eligibility
  const moves = VEHICLE_TRANSITIONS[v.status] || []

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <button onClick={() => navigate('/app/transport/vehicles')}
          style={{ padding: 7, borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', cursor: 'pointer', display: 'inline-flex' }}>
          <ArrowLeft size={15} />
        </button>
        <div style={{ flex: 1, minWidth: 200 }}>
          <p style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Vehicle</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 21, fontWeight: 900, margin: '2px 0 0' }}>{v.registration_number}</h1>
        </div>
        <Chip cfg={vehicleStatusCfg(v.status)} size={12.5} />
        <button onClick={save} disabled={saving}
          style={{ padding: '8px 15px', borderRadius: 9, background: '#7C3AED', border: '1px solid #7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: saving ? 'wait' : 'pointer' }}>
          <Save size={13} /> {saving ? 'Saving…' : 'Save changes'}
        </button>
        {grants['transport.vehicle.delete'] && (
          <button onClick={remove} disabled={deleting} title="Delete this record — for one created in error"
            style={{ padding: '8px 13px', borderRadius: 9, background: 'rgba(248,113,113,0.12)', border: '1px solid rgba(248,113,113,0.35)', color: '#f87171', fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: deleting ? 'wait' : 'pointer' }}>
            <Trash2 size={13} /> {deleting ? 'Deleting…' : 'Delete'}
          </button>
        )}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }} className="tr-detail-grid">
        <div style={{ display: 'grid', gap: 16 }}>
          <div className="pr-glass" style={{ padding: 16, borderRadius: 12 }}>
            {form && <VehicleForm value={form} onChange={setForm} mode="edit" />}
          </div>

          <DocumentsPanel
            documents={data.documents || []}
            types={VEHICLE_DOCUMENT_TYPES}
            onFile={async (payload) => { await transportVehicleApi.addDocument(id, payload); await load() }}
            onRenew={async (docId, payload) => { await transportVehicleApi.renewDocument(id, docId, payload); await load() }}
          />
        </div>

        <div style={{ display: 'grid', gap: 16 }}>
          {/* FLEET §16 — Available is not the same as Eligible. */}
          <div className="pr-glass" style={{ padding: 16, borderRadius: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
              {elig?.eligible ? <ShieldCheck size={15} style={{ color: '#34d399' }} /> : <ShieldAlert size={15} style={{ color: '#f87171' }} />}
              <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.03em' }}>Allocation readiness</h3>
            </div>
            <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 10px' }}>
              Available means the vehicle is free. Eligible means it meets the requirements to be allocated.
            </p>
            <div style={{ display: 'grid', gap: 7 }}>
              {(elig?.checks || []).map((c) => (
                <div key={c.key} style={{ display: 'flex', gap: 8, alignItems: 'flex-start' }}>
                  <span style={{ marginTop: 2, width: 8, height: 8, borderRadius: 999, flexShrink: 0, background: c.passed ? '#34d399' : (c.required ? '#f87171' : '#fbbf24') }} />
                  <div>
                    <p style={{ margin: 0, fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                      {c.label}{!c.required && <span style={{ fontWeight: 600, color: 'var(--text-muted)' }}> · advisory</span>}
                    </p>
                    <p style={{ margin: '1px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>{c.detail}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* FLEET §8 — status is a business event, so these are buttons, not a dropdown. */}
          <div className="pr-glass" style={{ padding: 16, borderRadius: 12 }}>
            <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 4px', textTransform: 'uppercase', letterSpacing: '.03em' }}>Status</h3>
            <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 10px' }}>
              Status is driven by business events, not typed. Allocation and dispatch set the rest.
            </p>
            {moves.length === 0
              ? <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>No further change is available from {vehicleStatusCfg(v.status).label}.</p>
              : (
                <div style={{ display: 'grid', gap: 7 }}>
                  {moves.map((m) => (
                    <button key={m.to} onClick={() => move(m.to, m.label)}
                      style={{ padding: '8px 12px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, cursor: 'pointer', textAlign: 'left' }}>
                      {m.label}
                    </button>
                  ))}
                </div>
              )}
          </div>

          <div className="pr-glass" style={{ padding: 16, borderRadius: 12 }}>
            <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 10px', textTransform: 'uppercase', letterSpacing: '.03em' }}>History</h3>
            {(data.audit || []).length === 0
              ? <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>Nothing recorded yet.</p>
              : (
                <div style={{ display: 'grid', gap: 9 }}>
                  {(data.audit || []).map((a) => (
                    <div key={a.id}>
                      <p style={{ margin: 0, fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                        {String(a.action || '').replace('transport.vehicle.', '').replace(/_/g, ' ')}
                      </p>
                      <p style={{ margin: '1px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>
                        {a.actor_name || 'System'} · {fmtDateTime(a.occurred_at)}
                      </p>
                    </div>
                  ))}
                </div>
              )}
          </div>
        </div>
      </div>
    </div>
  )
}
