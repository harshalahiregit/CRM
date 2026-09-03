/**
 * The company holiday calendar — the CRM's own, not SangoeTrack's.
 *
 * The only holiday screen that existed wrote to track.sangoe.in, so nothing
 * could be entered into the CRM at all: hr_holidays sat empty and the attendance
 * app had nothing to show. This is the native one.
 *
 * Every field here reaches the phone. The TYPE picks the badge and the colour on
 * the app's calendar, and "Applies to" decides who sees the day at all — a
 * holiday scoped to one department is not sent to anybody else. Getting either
 * wrong shows people a day off they do not get, so both are spelled out on the
 * form rather than hidden behind a default.
 *
 * There is deliberately no delete. A holiday that has already shifted somebody's
 * leave calculation should be switched off, not erased — the API offers exactly
 * that and nothing more.
 */

import { useState, useEffect, useCallback, useMemo } from 'react'
import { PartyPopper, Plus, Pencil, Search, Power } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useToast } from '@/hooks/useToast'
import LoadError from '@/components/ui/LoadError'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

// Mirrors HrHoliday::TYPES. The colour is the one the app's calendar draws.
const TYPES = [
  { key: 'National', color: '#7C3AED' },
  { key: 'Festival', color: '#ec4899' },
  { key: 'Company',  color: '#0ea5e9' },
  { key: 'Optional', color: '#f59e0b' },
]
const TYPE_C = Object.fromEntries(TYPES.map(t => [t.key, t.color]))

// Mirrors HrHoliday::SCOPES.
const SCOPES = ['Organization', 'Department', 'Designation']

const EMPTY = {
  title: '', description: '', holiday_date: '',
  holiday_type: 'National', applicable_for: 'Organization',
  department_id: '', designation_id: '',
  is_optional: false, is_active: true,
}

const fmt = d => {
  if (!d) return '—'
  const iso = String(d).slice(0, 10)
  return new Date(iso + 'T00:00:00').toLocaleDateString('en-IN', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
  })
}

export default function Holidays() {
  const toast = useToast()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(null)
  const [depts, setDepts] = useState([])
  const [desigs, setDesigs] = useState([])
  const [search, setSearch] = useState('')
  const [typeF, setTypeF] = useState('All')
  const [year, setYear] = useState(new Date().getFullYear())
  const [modal, setModal] = useState(null)
  const [saving, setSaving] = useState(false)
  const [confirm, setConfirm] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    hrApi.leave.holidays.list({ year })
      .then(r => { setRows(r?.data ?? r ?? []); setLoadError(null) })
      .catch(e => setLoadError(e))
      .finally(() => setLoading(false))
  }, [year])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    hrApi.organization.departments.list().then(r => setDepts(r?.data ?? r ?? [])).catch(() => {})
    hrApi.organization.designations.list().then(r => setDesigs(r?.data ?? r ?? [])).catch(() => {})
  }, [])

  const visible = useMemo(() => {
    const q = search.trim().toLowerCase()
    return rows.filter(r =>
      (typeF === 'All' || r.holiday_type === typeF) &&
      (!q || r.title?.toLowerCase().includes(q))
    )
  }, [rows, search, typeF])

  const save = async () => {
    const f = modal.form
    if (!f.title.trim() || !f.holiday_date) {
      return toast.error('A title and a date are required.')
    }

    // Only send the target that the chosen scope actually uses; leaving a stale
    // department id on an organisation-wide holiday is how it quietly narrows.
    const payload = {
      ...f,
      department_id:  f.applicable_for === 'Department'  ? Number(f.department_id) || null : null,
      designation_id: f.applicable_for === 'Designation' ? Number(f.designation_id) || null : null,
      // Choosing the Optional type IS marking it optional — the app reads one
      // flag, so the two cannot be allowed to disagree.
      is_optional: f.holiday_type === 'Optional' ? true : !!f.is_optional,
    }

    setSaving(true)
    try {
      modal.editing
        ? await hrApi.leave.holidays.update(modal.editing, payload)
        : await hrApi.leave.holidays.create(payload)
      toast.success(`Holiday ${modal.editing ? 'updated' : 'added'}`)
      setModal(null)
      load()
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not save the holiday')
    } finally { setSaving(false) }
  }

  const toggle = async (r) => {
    try {
      await hrApi.leave.holidays.setStatus(r.id, !r.is_active)
      toast.success(r.is_active ? 'Holiday switched off' : 'Holiday switched on')
      load()
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not change the status')
    } finally { setConfirm(null) }
  }

  const scopeLabel = r => {
    if (r.applicable_for === 'Department')  return `Dept · ${r.department_name || '—'}`
    if (r.applicable_for === 'Designation') return `Role · ${r.designation_name || '—'}`
    return 'Everyone'
  }

  const years = useMemo(() => {
    const y = new Date().getFullYear()
    return [y - 1, y, y + 1, y + 2]
  }, [])

  const f = modal?.form
  const setF = (k, v) => setModal(m => ({ ...m, form: { ...m.form, [k]: v } }))

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>Holidays</h1>
        <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
          The company calendar. Everything here appears in the attendance app —
          the type sets its colour, and “Applies to” decides who sees it.
        </p>
      </div>

      <div className="card-3d" style={{ padding: '16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]">
            <label className="label">Search</label>
            <Search size={14} className="absolute left-3 top-[34px]" style={{ color: 'var(--text-muted)' }} />
            <input className="input-3d pl-9 text-sm" placeholder="Holiday name…"
              value={search} onChange={e => setSearch(e.target.value)} />
          </div>
          <div className="min-w-[150px]">
            <label className="label">Type</label>
            <select className="input-3d text-sm" value={typeF} onChange={e => setTypeF(e.target.value)}>
              {['All', ...TYPES.map(t => t.key)].map(t => <option key={t}>{t}</option>)}
            </select>
          </div>
          <div className="min-w-[110px]">
            <label className="label">Year</label>
            <select className="input-3d text-sm" value={year} onChange={e => setYear(Number(e.target.value))}>
              {years.map(y => <option key={y} value={y}>{y}</option>)}
            </select>
          </div>
          <button onClick={() => setModal({ editing: null, form: { ...EMPTY } })}
            className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto"
            style={{ background: GRAD }}>
            <Plus size={15} /> Add Holiday
          </button>
        </div>
      </div>

      {loadError && <LoadError error={loadError} onRetry={load} />}

      {loading ? <HrLoading label="Loading holidays…" />
        : visible.length === 0
          ? <HrEmpty icon={PartyPopper} title="No holidays yet"
              hint="Add the year's holidays here — the attendance app reads this calendar." />
          : (
            <div className="card-3d overflow-x-auto" style={{ padding: '6px' }}>
              <table className="w-full text-sm" style={{ minWidth: 820 }}>
                <thead>
                  <tr style={{ borderBottom: '1px solid var(--border)' }}>
                    {['Holiday', 'Date', 'Type', 'Applies to', 'Status', 'Actions'].map(h => (
                      <th key={h} className={`text-left px-3 py-3 label-caps ${h === 'Actions' ? 'text-right' : ''}`}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {visible.map(r => (
                    <tr key={r.id} style={{ borderBottom: '1px solid var(--border)', opacity: r.is_active ? 1 : 0.55 }}>
                      <td className="px-3 py-2.5">
                        <span className="inline-flex items-center gap-1.5">
                          <span className="w-2.5 h-2.5 rounded-full" style={{ background: TYPE_C[r.holiday_type] || '#7C3AED' }} />
                          <span className="font-bold" style={{ color: 'var(--text-h)' }}>{r.title}</span>
                        </span>
                        {r.description && <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{r.description}</div>}
                      </td>
                      <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)' }}>{fmt(r.holiday_date)}</td>
                      <td className="px-3 py-2.5">
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                          style={{ background: `${TYPE_C[r.holiday_type] || '#7C3AED'}1f`, color: TYPE_C[r.holiday_type] || '#7C3AED' }}>
                          {r.holiday_type}{r.is_optional && r.holiday_type !== 'Optional' ? ' · Optional' : ''}
                        </span>
                      </td>
                      <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)' }}>{scopeLabel(r)}</td>
                      <td className="px-3 py-2.5">
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                          style={{ background: r.is_active ? 'rgba(16,185,129,0.12)' : 'rgba(148,163,184,0.15)', color: r.is_active ? '#10b981' : '#94a3b8' }}>
                          {r.is_active ? 'Active' : 'Off'}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 text-right whitespace-nowrap">
                        <button onClick={() => setModal({
                          editing: r.id,
                          form: {
                            title: r.title ?? '', description: r.description ?? '',
                            holiday_date: String(r.holiday_date ?? '').slice(0, 10),
                            holiday_type: r.holiday_type ?? 'National',
                            applicable_for: r.applicable_for ?? 'Organization',
                            department_id: r.department_id ?? '', designation_id: r.designation_id ?? '',
                            is_optional: !!r.is_optional, is_active: !!r.is_active,
                          },
                        })} className="p-1.5 rounded-lg" title="Edit"><Pencil size={14} style={{ color: '#a78bfa' }} /></button>
                        <button onClick={() => setConfirm(r)} className="p-1.5 rounded-lg ml-1"
                          title={r.is_active ? 'Switch off' : 'Switch on'}>
                          <Power size={14} style={{ color: r.is_active ? '#f87171' : '#10b981' }} />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

      {modal && (
        <div className="drawer-backdrop" onClick={() => !saving && setModal(null)}>
          <div className="card-3d" onClick={e => e.stopPropagation()}
            style={{ maxWidth: 560, margin: '6vh auto', padding: 22, position: 'relative' }}>
            <h2 className="text-xl font-black mb-1" style={{ color: 'var(--text-h)' }}>
              {modal.editing ? 'Edit holiday' : 'Add holiday'}
            </h2>
            <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
              This changes the calendar for everyone it applies to, in the CRM and in the app.
            </p>

            <div className="grid grid-cols-2 gap-3">
              <div className="col-span-2">
                <label className="label">Holiday name *</label>
                <input className="input-3d text-sm" value={f.title} onChange={e => setF('title', e.target.value)}
                  placeholder="Independence Day" />
              </div>
              <div>
                <label className="label">Date *</label>
                <input type="date" className="input-3d text-sm" value={f.holiday_date}
                  onChange={e => setF('holiday_date', e.target.value)} />
              </div>
              <div>
                <label className="label">Type</label>
                <select className="input-3d text-sm" value={f.holiday_type}
                  onChange={e => setF('holiday_type', e.target.value)}>
                  {TYPES.map(t => <option key={t.key} value={t.key}>{t.key}</option>)}
                </select>
              </div>
              <div className={f.applicable_for === 'Organization' ? 'col-span-2' : ''}>
                <label className="label">Applies to</label>
                <select className="input-3d text-sm" value={f.applicable_for}
                  onChange={e => setF('applicable_for', e.target.value)}>
                  {SCOPES.map(s => <option key={s} value={s}>{s === 'Organization' ? 'Everyone' : s}</option>)}
                </select>
              </div>
              {f.applicable_for === 'Department' && (
                <div>
                  <label className="label">Department</label>
                  <select className="input-3d text-sm" value={f.department_id}
                    onChange={e => setF('department_id', e.target.value)}>
                    <option value="">Select…</option>
                    {depts.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                  </select>
                </div>
              )}
              {f.applicable_for === 'Designation' && (
                <div>
                  <label className="label">Designation</label>
                  <select className="input-3d text-sm" value={f.designation_id}
                    onChange={e => setF('designation_id', e.target.value)}>
                    <option value="">Select…</option>
                    {desigs.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                  </select>
                </div>
              )}
              <div className="col-span-2">
                <label className="label">Description</label>
                <textarea className="input-3d text-sm" rows={2} value={f.description}
                  onChange={e => setF('description', e.target.value)}
                  placeholder="Shown on the app's calendar" />
              </div>
              <label className="col-span-2 flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
                <input type="checkbox" checked={f.holiday_type === 'Optional' || f.is_optional}
                  disabled={f.holiday_type === 'Optional'}
                  onChange={e => setF('is_optional', e.target.checked)} />
                Optional — staff may choose to work this day
                {f.holiday_type === 'Optional' && <span className="text-xs">(implied by the type)</span>}
              </label>
            </div>

            <div className="flex gap-2 justify-end mt-5">
              <button onClick={() => setModal(null)} disabled={saving}
                className="px-4 py-2.5 rounded-xl text-sm font-bold"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                Cancel
              </button>
              <button onClick={save} disabled={saving}
                className="px-5 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD }}>
                {saving ? 'Saving…' : modal.editing ? 'Save changes' : 'Add holiday'}
              </button>
            </div>
          </div>
        </div>
      )}

      {confirm && (
        <ConfirmDialog
          title={confirm.is_active ? 'Switch this holiday off?' : 'Switch this holiday on?'}
          message={confirm.is_active
            ? `“${confirm.title}” will stop appearing in the app and stop counting as a holiday. It is kept, not deleted.`
            : `“${confirm.title}” will appear in the app again and count as a holiday.`}
          confirmLabel={confirm.is_active ? 'Switch off' : 'Switch on'}
          tone={confirm.is_active ? 'danger' : 'primary'}
          onConfirm={() => toggle(confirm)}
          onCancel={() => setConfirm(null)}
        />
      )}
    </div>
  )
}
