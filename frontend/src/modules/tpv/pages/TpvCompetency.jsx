import { useState, useEffect, useCallback } from 'react'
import { GraduationCap, RefreshCw, ChevronDown, ChevronRight, Plus, Trash2, Award, BookOpen } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §15 — Competency & Training. Roster of workers with competency +
// training records; per-worker management. The Skill Matrix (Worker × Activity ×
// Competency × Validity) is exposed per work package on the Work Packages page.
const STATUS_TONE = { Valid: '#10b981', Expiring: '#f59e0b', Expired: '#ef4444', Failed: '#ef4444' }

export default function TpvCompetency() {
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [cats, setCats] = useState([])
  const [types, setTypes] = useState([])
  const [expanded, setExpanded] = useState(null)

  const load = useCallback(() => {
    tpvApi.competency.roster().then(d => { setLoadError(null);
      setRows(d?.data ?? [])
      if (d?.categories) setCats(d.categories)
      if (d?.training_types) setTypes(d.training_types)
    }).catch(e => { setRows([]); setLoadError(e) })
  }, [])
  useEffect(() => { load() }, [load])

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>WORKFORCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Competency &amp; Training</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Qualifications, licences and training per worker — no competency, no authorization.</p>
        </div>
        <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['', 'Worker', 'Vendor', 'Competencies', 'Trainings', 'Expiring', 'Status'].map((h, i) => <th key={i} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={7} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>No workers yet.</td></tr>
                : rows.map(w => (
                  <WorkerRow key={w.id} w={w} cats={cats} types={types}
                    expanded={expanded === w.id} onToggle={() => setExpanded(expanded === w.id ? null : w.id)} onChanged={load} />
                ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function WorkerRow({ w, cats, types, expanded, onToggle, onChanged }) {
  const expiring = w.expiring_competencies_count ?? 0
  return (
    <>
      <tr style={{ borderTop: '1px solid var(--border)' }}>
        <td style={{ padding: '10px 14px' }}><button onClick={onToggle} style={iconBtn}>{expanded ? <ChevronDown size={15} /> : <ChevronRight size={15} />}</button></td>
        <td style={{ padding: '10px 14px' }}>
          <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{w.name}</div>
          <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{w.worker_code}</div>
        </td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{w.vendor?.company_name || '—'}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{w.competencies_count ?? 0}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{w.trainings_count ?? 0}</td>
        <td style={{ padding: '10px 14px' }}>{expiring > 0 ? <span style={{ color: '#f59e0b', fontWeight: 700 }}>{expiring}</span> : <span style={{ color: 'var(--text-muted)' }}>0</span>}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{String(w.status || '').replace(/_/g, ' ')}</td>
      </tr>
      {expanded && (
        <tr><td colSpan={7} style={{ padding: '0 14px 14px', background: 'var(--bg-input,rgba(124,58,237,0.03))' }}>
          <WorkerDetail workerId={w.id} cats={cats} types={types} onChanged={onChanged} />
        </td></tr>
      )}
    </>
  )
}

function WorkerDetail({ workerId, cats, types, onChanged }) {
  const [detail, setDetail] = useState(null)
  const load = useCallback(() => { tpvApi.competency.worker(workerId).then(setDetail).catch(() => setDetail({ competencies: [], trainings: [] })) }, [workerId])
  useEffect(load, [load])

  const [newC, setNewC] = useState({ name: '', category: 'Skill', valid_until: '' })
  const [newT, setNewT] = useState({ training_type: types[0] || 'Site_Induction', valid_until: '', score: '' })

  const addC = async () => {
    if (!newC.name.trim()) return
    await tpvApi.competency.addCompetency(workerId, { ...newC, valid_until: newC.valid_until || null })
    setNewC({ name: '', category: 'Skill', valid_until: '' }); load(); onChanged?.()
  }
  const delC = async (id) => { await tpvApi.competency.deleteCompetency(id); load(); onChanged?.() }
  const addT = async () => {
    const payload = { ...newT, valid_until: newT.valid_until || null, score: newT.score || null }
    await tpvApi.competency.addTraining(workerId, payload)
    setNewT({ training_type: types[0] || 'Site_Induction', valid_until: '', score: '' }); load(); onChanged?.()
  }
  const delT = async (id) => { await tpvApi.competency.deleteTraining(id); load(); onChanged?.() }

  if (!detail) return <div style={{ padding: 12, color: 'var(--text-muted)', fontSize: 12.5 }}>Loading…</div>

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, paddingTop: 12 }}>
      <div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 8 }}><Award size={14} style={{ color: '#a78bfa' }} /><strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>Competencies</strong></div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 10 }}>
          {(detail.competencies || []).length === 0 && <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>None recorded.</p>}
          {(detail.competencies || []).map(c => (
            <div key={c.id} style={rowBox}>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)' }}>{c.name} <span style={{ fontWeight: 400, color: 'var(--text-muted)', fontSize: 11 }}>· {String(c.category).replace(/_/g, ' ')}</span></div>
                {c.valid_until && <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>valid to {new Date(c.valid_until).toLocaleDateString()}</div>}
              </div>
              <Pill status={c.status} />
              <button onClick={() => delC(c.id)} style={iconBtn}><Trash2 size={13} /></button>
            </div>
          ))}
        </div>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          <input value={newC.name} onChange={e => setNewC(p => ({ ...p, name: e.target.value }))} placeholder="Competency" style={{ ...inp, flex: 2, minWidth: 120 }} />
          <select value={newC.category} onChange={e => setNewC(p => ({ ...p, category: e.target.value }))} style={{ ...inp, flex: 1, minWidth: 90 }}>
            {cats.map(c => <option key={c} value={c}>{c.replace(/_/g, ' ')}</option>)}
          </select>
          <input type="date" value={newC.valid_until} onChange={e => setNewC(p => ({ ...p, valid_until: e.target.value }))} style={{ ...inp, flex: 1, minWidth: 120 }} />
          <button onClick={addC} style={{ ...btnPrimary, padding: '7px 12px' }}><Plus size={14} /></button>
        </div>
      </div>
      <div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 8 }}><BookOpen size={14} style={{ color: '#a78bfa' }} /><strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>Training</strong></div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 10 }}>
          {(detail.trainings || []).length === 0 && <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>None recorded.</p>}
          {(detail.trainings || []).map(t => (
            <div key={t.id} style={rowBox}>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)' }}>{String(t.training_type).replace(/_/g, ' ')}{t.score != null ? ` · ${t.score}%` : ''}</div>
                {t.valid_until && <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>valid to {new Date(t.valid_until).toLocaleDateString()}</div>}
              </div>
              <Pill status={t.status} />
              <button onClick={() => delT(t.id)} style={iconBtn}><Trash2 size={13} /></button>
            </div>
          ))}
        </div>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          <select value={newT.training_type} onChange={e => setNewT(p => ({ ...p, training_type: e.target.value }))} style={{ ...inp, flex: 2, minWidth: 120 }}>
            {types.map(t => <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>)}
          </select>
          <input type="number" value={newT.score} onChange={e => setNewT(p => ({ ...p, score: e.target.value }))} placeholder="Score" style={{ ...inp, width: 70 }} />
          <input type="date" value={newT.valid_until} onChange={e => setNewT(p => ({ ...p, valid_until: e.target.value }))} style={{ ...inp, flex: 1, minWidth: 120 }} />
          <button onClick={addT} style={{ ...btnPrimary, padding: '7px 12px' }}><Plus size={14} /></button>
        </div>
      </div>
    </div>
  )
}

function Pill({ status }) {
  const tone = STATUS_TONE[status] || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '2px 8px', borderRadius: 999, background: `${tone}1f`, color: tone, fontSize: 10.5, fontWeight: 700 }}>{status}</span>
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const rowBox = { display: 'flex', alignItems: 'center', gap: 8, padding: '7px 10px', borderRadius: 9, background: 'var(--bg-card)', border: '1px solid var(--border)' }
const inp = { padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
