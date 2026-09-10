import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, Trash2, Save, Loader2, AlertTriangle, ShieldCheck, ShieldAlert } from 'lucide-react'
import { transportDriverApi, transportCapabilityApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import DriverForm, { validateDriver } from '../components/DriverForm'
import DocumentsPanel from '../components/DocumentsPanel'
import { Chip } from '../components/MasterFormFields'
import {
  driverStatusCfg, driverAvailabilityCfg, complianceCfg,
  DRIVER_STATUS_TRANSITIONS, DRIVER_AVAILABILITY_TRANSITIONS,
  DRIVER_DOCUMENT_TYPES, fmtDateTime,
} from '../constants'

/**
 * Driver detail — SNG-TRN-004.
 *
 * Three states are shown, and they are genuinely three different facts:
 *   Record status  BO-009    — does this person drive for us at all
 *   Availability   §44       — are they free right now
 *   Compliance     CMP §23   — are their papers in order (DERIVED, never typed)
 *
 * Each of the first two has its own transition control, because moving one must
 * not silently move the other — a driver returning from leave is not thereby
 * reinstated from a block. The third has no control at all: it is computed from
 * the licence and the documents, so the way to change it is to fix the paperwork.
 *
 * UX §35: "Never merely show Blocked. Show: Why?" — the readiness panel carries
 * the reasons.
 */
export default function TransportDriverDetail() {
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
      const d = await transportDriverApi.get(id)
      setData(d); setForm(d?.driver ? { ...d.driver } : null)
    } catch (e) {
      setError(e?.message || 'Could not load this driver.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => { load() }, [load])
  useEffect(() => { transportCapabilityApi.get().then((c) => setGrants(c?.grants || {})).catch(() => {}) }, [])

  const save = async () => {
    const problem = validateDriver(form)
    if (problem) return toast.error(problem)
    setSaving(true)
    try {
      await transportDriverApi.update(id, form)
      toast.success('Driver updated.')
      load()
    } catch (e) {
      toast.error(e?.message || 'The driver could not be updated.')
    } finally {
      setSaving(false)
    }
  }

  const move = async (axis, to, label) => {
    try {
      await transportDriverApi.transition(id, axis, to)
      toast.success(`Driver ${label.toLowerCase()}.`)
      load()
    } catch (e) {
      toast.error(e?.message || 'That change was refused.')
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
      await transportDriverApi.remove(id, reason)
      toast.success('Driver deleted.')
      navigate('/app/transport/drivers')
    } catch (e) {
      toast.error(e?.message || 'The driver could not be deleted.')
    } finally {
      setDeleting(false)
    }
  }

  if (loading) return <div style={{ padding: 40, textAlign: 'center' }}><Loader2 size={20} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  if (error) return <div style={{ padding: 24, color: '#f87171', display: 'flex', gap: 8, alignItems: 'center' }}><AlertTriangle size={16} /> {error}</div>
  if (!data?.driver) return null

  const d = data.driver
  const elig = data.eligibility
  const statusMoves = DRIVER_STATUS_TRANSITIONS[d.status] || []
  const availMoves = DRIVER_AVAILABILITY_TRANSITIONS[d.availability] || []

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <button onClick={() => navigate('/app/transport/drivers')}
          style={{ padding: 7, borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', cursor: 'pointer', display: 'inline-flex' }}>
          <ArrowLeft size={15} />
        </button>
        <div style={{ flex: 1, minWidth: 200 }}>
          <p style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Driver</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 21, fontWeight: 900, margin: '2px 0 0' }}>{d.name}</h1>
        </div>
        <Chip cfg={driverStatusCfg(d.status)} size={12.5} />
        <Chip cfg={driverAvailabilityCfg(d.availability)} size={12.5} />
        <Chip cfg={complianceCfg(data.compliance_status)} size={12.5} />
        <button onClick={save} disabled={saving}
          style={{ padding: '8px 15px', borderRadius: 9, background: '#7C3AED', border: '1px solid #7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: saving ? 'wait' : 'pointer' }}>
          <Save size={13} /> {saving ? 'Saving…' : 'Save changes'}
        </button>
        {grants['transport.driver.delete'] && (
          <button onClick={remove} disabled={deleting} title="Delete this record — for one created in error"
            style={{ padding: '8px 13px', borderRadius: 9, background: 'rgba(248,113,113,0.12)', border: '1px solid rgba(248,113,113,0.35)', color: '#f87171', fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: deleting ? 'wait' : 'pointer' }}>
            <Trash2 size={13} /> {deleting ? 'Deleting…' : 'Delete'}
          </button>
        )}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }} className="tr-detail-grid">
        <div style={{ display: 'grid', gap: 16 }}>
          <div className="pr-glass" style={{ padding: 16, borderRadius: 12 }}>
            {form && <DriverForm value={form} onChange={setForm} mode="edit" />}
          </div>

          <DocumentsPanel
            documents={data.documents || []}
            types={DRIVER_DOCUMENT_TYPES}
            onFile={async (payload) => { await transportDriverApi.addDocument(id, payload); await load() }}
            onRenew={async (docId, payload) => { await transportDriverApi.renewDocument(id, docId, payload); await load() }}
          />
        </div>

        <div style={{ display: 'grid', gap: 16 }}>
          <div className="pr-glass" style={{ padding: 16, borderRadius: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
              {elig?.eligible ? <ShieldCheck size={15} style={{ color: '#34d399' }} /> : <ShieldAlert size={15} style={{ color: '#f87171' }} />}
              <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.03em' }}>Allocation readiness</h3>
            </div>
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

          <div className="pr-glass" style={{ padding: 16, borderRadius: 12 }}>
            <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 4px', textTransform: 'uppercase', letterSpacing: '.03em' }}>Record status</h3>
            <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 10px' }}>Whether this person drives for you at all.</p>
            <div style={{ display: 'grid', gap: 7 }}>
              {statusMoves.map((m) => (
                <button key={m.to} onClick={() => move('status', m.to, m.label)}
                  style={{ padding: '8px 12px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, cursor: 'pointer', textAlign: 'left' }}>
                  {m.label}
                </button>
              ))}
            </div>
          </div>

          <div className="pr-glass" style={{ padding: 16, borderRadius: 12 }}>
            <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 4px', textTransform: 'uppercase', letterSpacing: '.03em' }}>Availability</h3>
            <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 10px' }}>
              Whether they are free right now. Assigned and On trip are set by allocation and dispatch, not here.
            </p>
            <div style={{ display: 'grid', gap: 7 }}>
              {availMoves.length === 0
                ? <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>Set by allocation while the driver is on a trip.</p>
                : availMoves.map((m) => (
                  <button key={m.to} onClick={() => move('availability', m.to, m.label)}
                    style={{ padding: '8px 12px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, cursor: 'pointer', textAlign: 'left' }}>
                    {m.label}
                  </button>
                ))}
            </div>
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
                        {String(a.action || '').replace('transport.driver.', '').replace(/_/g, ' ')}
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
