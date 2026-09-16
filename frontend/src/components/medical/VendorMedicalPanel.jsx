import { useCallback, useEffect, useState } from 'react'
import { HeartPulse, Upload, FileSpreadsheet, RefreshCw, AlertTriangle } from 'lucide-react'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'
import { medicalApi } from '@/services/medicalApi'
import MedicalDetailDrawer from './MedicalDetailDrawer'
import { SingleCertificateModal, BulkCertificateModal } from './ExternalCertificateModals'
import { S, Stat, FitnessPill, QcPill, ClearancePill, HealthScore } from './MedicalBits'

/**
 * The vendor's side of the Medical module.
 *
 * A vendor's real question is not "what certificates do I have" but "which of my
 * workers still cannot go to site, and what do I have to do about it" — so the
 * blocked roster leads, and the certificate list sits under it.
 *
 * `base` selects the portal: '/portal' for TPV, '/portal/purchase' for Purchase.
 */
export default function VendorMedicalPanel({ base = '/portal', title = 'Medical' }) {
  const [payload, setPayload] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [openId, setOpenId] = useState(null)
  const [showSingle, setShowSingle] = useState(false)
  const [showBulk, setShowBulk] = useState(false)
  const [presetWorker, setPresetWorker] = useState(null)

  const load = useCallback(() => {
    medicalApi.portal.list(base)
      .then(d => { setLoadError(null); setPayload(d) })
      .catch(e => { setPayload({ data: [], workers: [] }); setLoadError(e) })
  }, [base])

  useEffect(() => { load() }, [load])

  const rows     = payload?.data ?? null
  const workers  = payload?.workers ?? []
  const summary  = payload?.summary
  const template = payload?.template

  const blocked = workers.filter(w => !w.clearance?.cleared)

  const source = {
    get:         (id) => medicalApi.portal.get(base, id),
    comment:     (id, data) => medicalApi.portal.comment(base, id, data),
    resubmit:    (id, data) => medicalApi.portal.resubmit(base, id, data),
    certificate: (id) => medicalApi.portal.certificate(base, id),
    document:    (id) => medicalApi.portal.document(base, id),
  }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>

      <header style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <HeartPulse size={20} /> {title}
          </h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>
            Upload the certificates your own doctor issued, and follow what the quality team says about them.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button onClick={() => { setPresetWorker(null); setShowSingle(true) }} style={S.btn}><Upload size={14} /> Upload certificate</button>
          <button onClick={() => setShowBulk(true)} style={S.btn}><FileSpreadsheet size={14} /> Upload a sheet</button>
          <button onClick={load} style={S.btn}><RefreshCw size={14} /> Refresh</button>
        </div>
      </header>

      {summary && (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
          <Stat label="Awaiting review" value={summary.awaiting_review} tone="#6366f1" />
          <Stat label="Needs your reply" value={summary.on_hold} tone="#f59e0b" />
          <Stat label="Rejected" value={summary.rejected} tone="#ef4444" />
          <Stat label="Approved" value={summary.approved} tone="#10b981" />
          <Stat label="Workers blocked" value={summary.blocked_workers} tone="#ef4444" />
        </div>
      )}

      {/* The prerequisite, said plainly: these workers cannot start safety
          induction until their medical clears. */}
      {blocked.length > 0 && (
        <section className="pr-glass" style={{ padding: 14, borderRadius: 14, marginBottom: 14, borderLeft: '3px solid #f59e0b' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
            <AlertTriangle size={16} color="#f59e0b" />
            <h3 style={{ margin: 0, fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>
              {blocked.length} worker{blocked.length > 1 ? 's' : ''} cannot start safety induction yet
            </h3>
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {blocked.map(w => (
              <div key={w.id} style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <strong style={{ fontSize: 13, color: 'var(--text-h)', minWidth: 140 }}>{w.name}</strong>
                <span style={{ fontSize: 11.5, color: 'var(--text-muted)', minWidth: 90 }}>{w.worker_code}</span>
                <ClearancePill clearance={w.clearance} />
                <span style={{ fontSize: 12, color: 'var(--text-muted)', flex: 1, minWidth: 180 }}>{w.clearance?.message}</span>
                {w.clearance?.status === 'missing' && (
                  <button
                    onClick={() => { setPresetWorker(w.id); setShowSingle(true) }}
                    style={{ ...S.btn, padding: '5px 12px', fontSize: 12 }}
                  >
                    <Upload size={12} /> Upload
                  </button>
                )}
              </div>
            ))}
          </div>
        </section>
      )}

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Certificate', 'Worker', 'Outcome', 'Review', 'Score', 'Valid until'].map(h => <th key={h} style={S.th}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? (
                <tr><td colSpan={6} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
              ) : rows === null ? (
                <tr><td colSpan={6} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={6} style={{ padding: 18, color: 'var(--text-muted)' }}>No certificates uploaded yet.</td></tr>
              ) : rows.map(m => (
                <tr key={m.id} onClick={() => setOpenId(m.id)} style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                  <td style={{ ...S.td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {m.certificate_no || '—'}
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{m.exam_date}</div>
                  </td>
                  <td style={{ ...S.td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {m.worker?.name || m.worker?.full_name || '—'}
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{m.worker?.worker_code}</div>
                  </td>
                  <td style={S.td}><FitnessPill status={m.fitness_status} /></td>
                  <td style={S.td}>
                    <QcPill status={m.qc_status} />
                    {m.qc_status === 'Hold' && (
                      <div style={{ fontSize: 11, color: '#f59e0b', fontWeight: 700, marginTop: 2 }}>Reply needed</div>
                    )}
                  </td>
                  <td style={S.td}><HealthScore score={m.health_score} band={m.health_band} /></td>
                  <td style={{ ...S.td, color: m.is_expired ? '#ef4444' : 'var(--text-muted)' }}>
                    {m.valid_until || m.expiry_date || '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <MedicalDetailDrawer
        open={!!openId}
        medicalId={openId}
        onClose={() => setOpenId(null)}
        role="vendor"
        source={source}
        onChanged={load}
      />

      <SingleCertificateModal
        open={showSingle}
        onClose={() => { setShowSingle(false); setPresetWorker(null) }}
        workers={workers}
        presetWorkerId={presetWorker}
        onSubmit={(workerId, data) => medicalApi.portal.store(base, workerId, data).then(r => { load(); return r })}
      />

      <BulkCertificateModal
        open={showBulk}
        onClose={() => { setShowBulk(false); load() }}
        template={template}
        onDownloadTemplate={(format) => medicalApi.portal.template(base, format)}
        onSubmit={(data) => medicalApi.portal.bulkUpload(base, data).then(r => { load(); return r })}
      />
    </div>
  )
}
