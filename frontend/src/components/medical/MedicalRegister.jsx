import { useCallback, useEffect, useMemo, useState } from 'react'
import { HeartPulse, RefreshCw, Upload, FileSpreadsheet, History } from 'lucide-react'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'
import { medicalApi } from '@/services/medicalApi'
import MedicalDetailDrawer from './MedicalDetailDrawer'
import { SingleCertificateModal, BulkCertificateModal } from './ExternalCertificateModals'
import { S, Stat, FitnessPill, QcPill, HealthScore, humanise } from './MedicalBits'

/**
 * The Medical Fitness register and the quality team's queue.
 *
 * One component, both registers: `module` picks TPV or Purchase, and the two
 * APIs are route-for-route identical by design, so parity here is structural
 * rather than something to remember to maintain.
 *
 * The screen is built around the reviewer's actual question — "what is waiting
 * on me?" — so the summary tiles are filters, and Awaiting review is the first
 * one for a reason.
 */
export default function MedicalRegister({ module = 'tpv', accent = '#a78bfa' }) {
  const [payload, setPayload] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [filters, setFilters] = useState({ qc_status: '', fitness_status: '', expiry: '', origin: '' })

  const [openId, setOpenId] = useState(null)
  const [showSingle, setShowSingle] = useState(false)
  const [showBulk, setShowBulk] = useState(false)
  const [batches, setBatches] = useState(null)

  const load = useCallback(() => {
    const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
    medicalApi.admin.list(module, params)
      .then(d => { setLoadError(null); setPayload(d) })
      .catch(e => { setPayload({ data: [] }); setLoadError(e) })
  }, [module, filters])

  useEffect(() => { load() }, [load])

  const rows     = payload?.data ?? null
  const summary  = payload?.summary
  const qc       = payload?.qc
  const statuses = payload?.statuses ?? []

  // The register's rows carry their worker; the upload form needs the roster,
  // and this is the one place both are already in hand.
  const workers = useMemo(() => {
    const seen = new Map()
    ;(rows ?? []).forEach(r => {
      const w = r.worker
      if (w && !seen.has(w.id)) seen.set(w.id, { id: w.id, name: w.name || w.full_name, worker_code: w.worker_code })
    })
    return [...seen.values()]
  }, [rows])

  const source = {
    get:         (id) => medicalApi.admin.get(module, id),
    decide:      (id, data) => medicalApi.admin.decide(module, id, data),
    comment:     (id, data) => medicalApi.admin.comment(module, id, data),
    certificate: (id) => medicalApi.admin.certificate(module, id),
    document:    (id) => medicalApi.admin.document(module, id),
  }

  const toggle = (key, value) => setFilters(f => ({ ...f, [key]: f[key] === value ? '' : value }))

  const openBatches = async () => {
    setBatches(await medicalApi.admin.batches(module))
  }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>

      <header style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: accent, margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>WORKFORCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', display: 'flex', alignItems: 'center', gap: 8 }}>
            <HeartPulse size={20} /> Medical Fitness
          </h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>
            Examinations, the quality check, and the conversation with the vendor. A certificate is not clearance until it is approved.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button onClick={() => setShowSingle(true)} style={S.btn}><Upload size={14} /> External certificate</button>
          <button onClick={() => setShowBulk(true)} style={S.btn}><FileSpreadsheet size={14} /> Bulk upload</button>
          <button onClick={openBatches} style={S.btn}><History size={14} /> Imports</button>
          <button onClick={load} style={S.btn}><RefreshCw size={14} /> Refresh</button>
        </div>
      </header>

      {summary && (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
          <Stat label="Awaiting review" value={summary.awaiting_review} tone="#6366f1"
                onClick={() => toggle('qc_status', 'Pending')} active={filters.qc_status === 'Pending'} />
          <Stat label="On hold" value={summary.on_hold} tone="#f59e0b"
                onClick={() => toggle('qc_status', 'Hold')} active={filters.qc_status === 'Hold'} />
          <Stat label="Rejected" value={summary.rejected} tone="#ef4444"
                onClick={() => toggle('qc_status', 'Rejected')} active={filters.qc_status === 'Rejected'} />
          <Stat label="Approved" value={summary.approved} tone="#10b981"
                onClick={() => toggle('qc_status', 'Approved')} active={filters.qc_status === 'Approved'} />
          <Stat label="Expired" value={summary.expired} tone="#ef4444"
                onClick={() => toggle('expiry', 'expired')} active={filters.expiry === 'expired'} />
          <Stat label="Records" value={summary.total} tone="#7C3AED" />
        </div>
      )}

      <div style={{ display: 'flex', gap: 10, marginBottom: 12, flexWrap: 'wrap' }}>
        <select value={filters.fitness_status} onChange={e => setFilters(f => ({ ...f, fitness_status: e.target.value }))} style={S.select}>
          <option value="">All outcomes</option>
          {statuses.map(s => <option key={s} value={s}>{humanise(s)}</option>)}
        </select>
        <select value={filters.qc_status} onChange={e => setFilters(f => ({ ...f, qc_status: e.target.value }))} style={S.select}>
          <option value="">Any review state</option>
          {(qc?.statuses ?? []).map(s => <option key={s} value={s}>{humanise(s)}</option>)}
        </select>
        <select value={filters.origin} onChange={e => setFilters(f => ({ ...f, origin: e.target.value }))} style={S.select}>
          <option value="">Any source</option>
          {Object.entries(qc?.origins ?? {}).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select value={filters.expiry} onChange={e => setFilters(f => ({ ...f, expiry: e.target.value }))} style={S.select}>
          <option value="">Any currency</option>
          <option value="expiring">Expiring (≤30d)</option>
          <option value="expired">Expired</option>
        </select>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Certificate', 'Worker', 'Vendor', 'Outcome', 'Review', 'Score', 'Valid until', 'Source'].map(h => (
                  <th key={h} style={S.th}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loadError ? (
                <tr><td colSpan={8} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
              ) : rows === null ? (
                <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>No medical records match.</td></tr>
              ) : rows.map(m => (
                <tr
                  key={m.id}
                  onClick={() => setOpenId(m.id)}
                  style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}
                >
                  <td style={{ ...S.td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {m.certificate_no || '—'}
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>
                      {m.exam_date}{m.is_reexam ? ` · re-exam #${m.attempt_no}` : ''}
                    </div>
                  </td>
                  <td style={{ ...S.td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {m.worker?.name || m.worker?.full_name || '—'}
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{m.worker?.worker_code}</div>
                  </td>
                  <td style={{ ...S.td, color: 'var(--text-muted)' }}>{m.worker?.vendor?.company_name || '—'}</td>
                  <td style={S.td}><FitnessPill status={m.fitness_status} /></td>
                  <td style={S.td}><QcPill status={m.qc_status} /></td>
                  <td style={S.td}><HealthScore score={m.health_score} band={m.health_band} /></td>
                  <td style={{ ...S.td, color: m.is_expired ? '#ef4444' : 'var(--text-muted)', fontWeight: m.is_expired ? 700 : 500 }}>
                    {m.valid_until || m.expiry_date || '—'}{m.is_expired ? ' · expired' : ''}
                  </td>
                  <td style={{ ...S.td, color: 'var(--text-muted)', fontSize: 12 }}>{m.origin_label || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Past imports — where a rejected row is traced back to its file. */}
      {batches && (
        <div className="pr-glass" style={{ marginTop: 14, padding: 14, borderRadius: 14 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
            <h3 style={{ margin: 0, fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Recent imports</h3>
            <button onClick={() => setBatches(null)} style={{ ...S.btn, padding: '4px 10px' }}>Hide</button>
          </div>
          {batches.length === 0 ? (
            <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>No sheets have been imported yet.</p>
          ) : (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
              <tbody>
                {batches.map(b => (
                  <tr key={b.id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '7px 8px', fontWeight: 600 }}>{b.file_name}</td>
                    <td style={{ padding: '7px 8px', color: 'var(--text-muted)' }}>{b.vendor?.company_name || '—'}</td>
                    <td style={{ padding: '7px 8px', color: '#10b981', fontWeight: 700 }}>{b.created_count} in</td>
                    <td style={{ padding: '7px 8px', color: b.failed_count ? '#ef4444' : 'var(--text-muted)', fontWeight: 700 }}>{b.failed_count} rejected</td>
                    <td style={{ padding: '7px 8px', color: 'var(--text-muted)' }}>{b.created_at ? new Date(b.created_at).toLocaleString() : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      <MedicalDetailDrawer
        open={!!openId}
        medicalId={openId}
        onClose={() => setOpenId(null)}
        role="reviewer"
        source={source}
        vocabulary={qc}
        onChanged={load}
      />

      <SingleCertificateModal
        open={showSingle}
        onClose={() => setShowSingle(false)}
        workers={workers}
        onSubmit={(workerId, data) => medicalApi.admin.storeExternal(module, workerId, data).then(r => { load(); return r })}
      />

      <BulkCertificateModal
        open={showBulk}
        onClose={() => { setShowBulk(false); load() }}
        template={null}
        onDownloadTemplate={(format) => medicalApi.admin.template(module, format)}
        onSubmit={(data) => medicalApi.admin.bulkUpload(module, data).then(r => { load(); return r })}
      />
    </div>
  )
}
