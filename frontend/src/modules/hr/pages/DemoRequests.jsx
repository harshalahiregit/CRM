/**
 * Inbound demo enquiries.
 *
 * Everything SangoeTrack stores is here, plus an owner, a scheduled time, and
 * the enquirer's own message kept apart from staff notes — theirs has a single
 * `notes` field, so the two get written over each other.
 *
 * The message is shown but never editable. A record of somebody's own words
 * that staff can rewrite is not a record.
 */

import { useState, useEffect, useCallback } from 'react'
import { MessageSquare, Plus, X, RefreshCw, Search, Mail, Phone, Building2, Users } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'

const STATUS = {
  new:       { fg: '#fbbf24', bg: 'rgba(251,191,36,0.12)',  label: 'New' },
  contacted: { fg: '#60a5fa', bg: 'rgba(96,165,250,0.12)',  label: 'Contacted' },
  scheduled: { fg: '#a78bfa', bg: 'rgba(167,139,250,0.14)', label: 'Scheduled' },
  converted: { fg: '#34d399', bg: 'rgba(52,211,153,0.12)',  label: 'Converted' },
  declined:  { fg: '#f87171', bg: 'rgba(248,113,113,0.12)', label: 'Declined' },
}

const ORDER = ['new', 'contacted', 'scheduled', 'converted', 'declined']

const inputStyle = {
  padding: '8px 11px', background: 'var(--bg-input)',
  border: '1px solid var(--border)', color: 'var(--text-p)',
}

const when = ts => (ts ? new Date(ts).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')

function Pill({ status }) {
  const s = STATUS[status] || { fg: 'var(--text-muted)', bg: 'var(--bg-input)', label: status }
  return (
    <span className="rounded-md text-[10px] font-bold uppercase tracking-wider"
      style={{ padding: '3px 8px', color: s.fg, background: s.bg }}>{s.label}</span>
  )
}

const NEW = { name: '', company_name: '', email: '', phone: '', address: '', num_employees: '', message: '' }

export default function DemoRequests() {
  const toast = useToast()

  const [rows,    setRows]    = useState([])
  const [loading, setLoading] = useState(true)
  const [error,   setError]   = useState(null)
  const [busy,    setBusy]    = useState(false)

  const [statusF, setStatusF] = useState('')
  const [search,  setSearch]  = useState('')

  const [creating, setCreating] = useState(false)
  const [form,     setForm]     = useState(NEW)

  const [open, setOpen] = useState(null)   // the record being worked on
  const [edit, setEdit] = useState({})

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      setRows(await hrApi.demoRequests.list({
        status: statusF || undefined,
        search: search.trim() || undefined,
      }))
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not load demo requests.')
    } finally {
      setLoading(false)
    }
  }, [statusF, search])

  useEffect(() => {
    // Debounced so typing in the search box does not fire a request per keystroke.
    const id = setTimeout(load, 250)
    return () => clearTimeout(id)
  }, [load])

  const create = async () => {
    if (!form.name.trim()) return toast.error('Who is asking?')

    setBusy(true)
    try {
      await hrApi.demoRequests.create({
        ...form,
        num_employees: form.num_employees === '' ? null : Number(form.num_employees),
      })
      toast.success('Demo request logged.')
      setCreating(false); setForm(NEW)
      load()
    } catch (e) {
      const errs = e?.response?.data?.errors
      toast.error(errs ? Object.values(errs).flat()[0] : 'That could not be logged.')
    } finally {
      setBusy(false)
    }
  }

  const openRow = r => {
    setOpen(r)
    setEdit({
      status: r.status || 'new',
      notes: r.notes || '',
      demo_at: r.demo_at ? String(r.demo_at).slice(0, 16).replace(' ', 'T') : '',
      phone: r.phone || '', email: r.email || '',
      company_name: r.company_name || '', address: r.address || '',
      num_employees: r.num_employees ?? '',
    })
  }

  const save = async () => {
    setBusy(true)
    try {
      await hrApi.demoRequests.update(open.id, {
        ...edit,
        num_employees: edit.num_employees === '' ? null : Number(edit.num_employees),
        demo_at: edit.demo_at || null,
      })
      toast.success('Updated.')
      setOpen(null)
      load()
    } catch (e) {
      const errs = e?.response?.data?.errors
      toast.error(errs ? Object.values(errs).flat()[0] : 'That could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <MessageSquare size={18} /> Demo requests
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            People who asked to see the product, and what happened next.
          </p>
        </div>
        <div className="flex gap-2">
          <button onClick={load} disabled={loading}
            className="rounded-lg text-xs font-semibold flex items-center gap-1.5"
            style={{ padding: '7px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
            <RefreshCw size={13} className={loading ? 'animate-spin' : ''} /> Refresh
          </button>
          <button onClick={() => setCreating(v => !v)}
            className="rounded-lg text-xs font-bold flex items-center gap-1.5"
            style={{ padding: '8px 13px', background: 'var(--accent)', color: '#fff' }}>
            <Plus size={14} /> Log a request
          </button>
        </div>
      </div>

      <div className="flex items-center gap-2 flex-wrap">
        <div className="flex items-center gap-1.5 rounded-lg" style={{ padding: '0 9px', background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <Search size={13} style={{ color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)}
            placeholder="Name, company, email or phone"
            className="text-xs bg-transparent" style={{ padding: '7px 0', width: 210, color: 'var(--text-p)', border: 'none', outline: 'none' }} />
        </div>
        <button onClick={() => setStatusF('')}
          className="rounded-lg text-xs font-semibold"
          style={{ padding: '6px 12px', background: !statusF ? 'var(--accent)' : 'var(--bg-input)', color: !statusF ? '#fff' : 'var(--text-p)', border: '1px solid var(--border)' }}>
          All
        </button>
        {ORDER.map(s => (
          <button key={s} onClick={() => setStatusF(s)}
            className="rounded-lg text-xs font-semibold"
            style={{ padding: '6px 12px', background: statusF === s ? 'var(--accent)' : 'var(--bg-input)', color: statusF === s ? '#fff' : 'var(--text-p)', border: '1px solid var(--border)' }}>
            {STATUS[s].label}
          </button>
        ))}
      </div>

      {creating && (
        <div className="rounded-2xl flex flex-col gap-3" style={{ padding: 18, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))' }}>
            {[['name', 'Name *'], ['company_name', 'Company'], ['email', 'Email'],
              ['phone', 'Phone'], ['num_employees', 'Employees'], ['address', 'Address']].map(([k, label]) => (
              <label key={k} className="flex flex-col gap-1">
                <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{label}</span>
                <input type={k === 'num_employees' ? 'number' : k === 'email' ? 'email' : 'text'}
                  min={k === 'num_employees' ? '0' : undefined}
                  value={form[k]} onChange={e => setForm(f => ({ ...f, [k]: e.target.value }))}
                  className="rounded-lg text-sm w-full" style={inputStyle} />
              </label>
            ))}
          </div>
          <label className="flex flex-col gap-1">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>What they asked for</span>
            <textarea rows={2} value={form.message} onChange={e => setForm(f => ({ ...f, message: e.target.value }))}
              className="rounded-lg text-sm w-full" style={inputStyle} />
          </label>
          <div className="flex gap-2">
            <button onClick={create} disabled={busy}
              className="rounded-lg text-xs font-bold"
              style={{ padding: '9px 16px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
              {busy ? 'Saving…' : 'Log request'}
            </button>
            <button onClick={() => { setCreating(false); setForm(NEW) }}
              className="rounded-lg text-xs font-semibold"
              style={{ padding: '9px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
              Cancel
            </button>
          </div>
        </div>
      )}

      {loading ? <HrLoading /> : error ? (
        <div className="rounded-xl text-xs" style={{ padding: 14, background: 'rgba(248,113,113,0.1)', color: '#f87171' }}>{error}</div>
      ) : !rows.length ? (
        <HrEmpty icon={MessageSquare} title="No demo requests" hint="Nothing matches these filters." />
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map(r => (
            <button key={r.id} onClick={() => openRow(r)}
              className="rounded-xl text-left flex items-center gap-3 flex-wrap"
              style={{ padding: '12px 14px', background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div className="flex-1 min-w-[200px]">
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
                  {r.name}
                  {r.company_name && <span className="font-normal text-[11px] ml-1.5" style={{ color: 'var(--text-muted)' }}>{r.company_name}</span>}
                </p>
                <p className="text-[11px] flex flex-wrap gap-x-3" style={{ color: 'var(--text-muted)' }}>
                  {r.email && <span><Mail size={9} style={{ display: 'inline' }} /> {r.email}</span>}
                  {r.phone && <span><Phone size={9} style={{ display: 'inline' }} /> {r.phone}</span>}
                  {r.num_employees != null && <span><Users size={9} style={{ display: 'inline' }} /> {r.num_employees}</span>}
                  <span>{when(r.created_at)}</span>
                </p>
              </div>
              {r.assignee?.name && (
                <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.assignee.name}</span>
              )}
              {!r.tenant_id && (
                <span className="text-[10px] font-bold" style={{ color: '#fbbf24' }}>Unclaimed</span>
              )}
              <Pill status={r.status} />
            </button>
          ))}
        </div>
      )}

      {open && (
        <div role="dialog" aria-modal="true" aria-label="Demo request"
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: 'rgba(0,0,0,0.6)' }} onClick={() => setOpen(null)}>
          <div onClick={e => e.stopPropagation()} className="rounded-2xl w-full max-w-xl flex flex-col"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '88vh' }}>

            <div className="flex items-start gap-3 p-5" style={{ borderBottom: '1px solid var(--border)' }}>
              <div className="flex-1">
                <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>{open.name}</h2>
                <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  <Building2 size={10} style={{ display: 'inline' }} /> {open.company_name || 'No company given'} · asked {when(open.created_at)}
                  {open.source ? ` · via ${open.source}` : ''}
                </p>
              </div>
              <Pill status={open.status} />
              <button onClick={() => setOpen(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>

            <div className="p-5 overflow-y-auto flex flex-col gap-3">
              {/* Their own words. Read-only on purpose. */}
              {open.message && (
                <div className="rounded-xl" style={{ padding: '10px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  <p className="text-[10px] font-bold uppercase tracking-wider mb-1" style={{ color: 'var(--text-muted)' }}>
                    What they asked for
                  </p>
                  <p className="text-xs whitespace-pre-wrap" style={{ color: 'var(--text-p)' }}>{open.message}</p>
                </div>
              )}

              <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))' }}>
                {[['company_name', 'Company', 'text'], ['email', 'Email', 'email'], ['phone', 'Phone', 'text'],
                  ['num_employees', 'Employees', 'number'], ['address', 'Address', 'text']].map(([k, label, t]) => (
                  <label key={k} className="flex flex-col gap-1">
                    <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{label}</span>
                    <input type={t} min={t === 'number' ? '0' : undefined} value={edit[k] ?? ''}
                      onChange={e => setEdit(p => ({ ...p, [k]: e.target.value }))}
                      className="rounded-lg text-sm w-full" style={inputStyle} />
                  </label>
                ))}

                <label className="flex flex-col gap-1">
                  <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Status</span>
                  <select value={edit.status} onChange={e => setEdit(p => ({ ...p, status: e.target.value }))}
                    className="rounded-lg text-sm w-full" style={inputStyle}>
                    {ORDER.map(s => <option key={s} value={s}>{STATUS[s].label}</option>)}
                  </select>
                </label>

                <label className="flex flex-col gap-1">
                  <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Demo at</span>
                  <input type="datetime-local" value={edit.demo_at}
                    onChange={e => setEdit(p => ({ ...p, demo_at: e.target.value }))}
                    className="rounded-lg text-sm w-full" style={inputStyle} />
                </label>
              </div>

              <label className="flex flex-col gap-1">
                <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                  Notes — what was said, what happens next
                </span>
                <textarea rows={3} value={edit.notes} onChange={e => setEdit(p => ({ ...p, notes: e.target.value }))}
                  className="rounded-lg text-sm w-full" style={inputStyle} />
              </label>
            </div>

            <div className="p-5 flex gap-2" style={{ borderTop: '1px solid var(--border)' }}>
              <button onClick={() => setOpen(null)}
                className="rounded-lg text-xs font-semibold flex-1"
                style={{ padding: '9px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
                Close
              </button>
              <button onClick={save} disabled={busy}
                className="rounded-lg text-xs font-bold flex-1"
                style={{ padding: '9px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
                {busy ? 'Saving…' : 'Save'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
