/**
 * An employee's own advances.
 *
 * The other end of the same conversation the approvers are having, minus the
 * internal notes the server withholds. What this person can do at any moment
 * comes from `data.can` rather than being worked out from the status here — the
 * SangoeTrack app and the CRM must not disagree about whether something can
 * still be answered.
 *
 * The settlement is the part people forget, so it is not hidden behind a menu:
 * once an advance is disbursed, accounting for it is the primary action on the
 * card, and the outstanding banner at the top says what is still owed.
 */

import { useState, useEffect, useCallback } from 'react'
import { Wallet, Plus, Paperclip, X, Send, Check, AlertTriangle } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import RequestThread from '../components/RequestThread'
import useAttachmentOpener from '../components/useAttachmentOpener'

const inr = n =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 })
    .format(Number(n) || 0)

const MAX_FILES = 10
const MAX_MB    = 10
const ACCEPT    = '.pdf,.png,.jpg,.jpeg,.webp,.heic'

/** Same picker as expense claims — bills and quotes are the same kind of thing. */
function FilePicker({ files, setFiles, label = 'Attach documents' }) {
  const toast = useToast()

  const add = e => {
    const picked = Array.from(e.target.files || [])
    e.target.value = ''

    const tooBig = picked.filter(f => f.size > MAX_MB * 1024 * 1024)
    const ok     = picked.filter(f => f.size <= MAX_MB * 1024 * 1024)

    setFiles(prev => [...prev, ...ok].slice(0, MAX_FILES))

    if (tooBig.length) toast.error(`Too large (max ${MAX_MB}MB): ${tooBig.map(f => f.name).join(', ')}`)
    if (files.length + ok.length > MAX_FILES) toast.warning(`Only the first ${MAX_FILES} files were kept.`)
  }

  return (
    <div className="flex flex-col gap-1.5">
      <label className="rounded-lg text-[11px] font-semibold flex items-center gap-1.5 self-start cursor-pointer"
        style={{ padding: '6px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
        <Paperclip size={12} /> {label}
        <input type="file" multiple accept={ACCEPT} onChange={add} style={{ display: 'none' }} />
      </label>

      {!!files.length && (
        <div className="flex flex-wrap gap-1.5">
          {files.map((f, i) => (
            <span key={`${f.name}-${i}`} className="flex items-center gap-1.5 rounded-lg text-[11px]"
              style={{ padding: '4px 8px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
              {f.name}
              <button type="button" onClick={() => setFiles(prev => prev.filter((_, j) => j !== i))}
                style={{ color: 'var(--text-muted)' }} aria-label={`Remove ${f.name}`}><X size={11} /></button>
            </span>
          ))}
        </div>
      )}
      <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
        Images or PDFs, up to {MAX_FILES} files, {MAX_MB}MB each.
      </span>
    </div>
  )
}

export default function MyAdvances() {
  const toast = useToast()

  const [rows,    setRows]    = useState([])
  const [out,     setOut]     = useState(null)
  const [loading, setLoading] = useState(true)
  const [error,   setError]   = useState(null)
  const [busy,    setBusy]    = useState(false)

  const [creating, setCreating] = useState(false)
  const [form,     setForm]     = useState({ purpose: '', amount_requested: '', advance_type: '', category: '', project_site: '', required_date: '', expected_settlement_date: '' })
  const [newFiles, setNewFiles] = useState([])

  const [openId,     setOpenId]     = useState(null)
  const [detail,     setDetail]     = useState(null)
  const [replyBody,  setReplyBody]  = useState('')
  const [replyFiles, setReplyFiles] = useState([])

  // Settling: the figure and the bills go up together.
  const [settling,   setSettling]   = useState(false)
  const [spent,      setSpent]      = useState('')
  const [settleNote, setSettleNote] = useState('')
  const [bills,      setBills]      = useState([])

  const fetchFile = useCallback(a => hrApi.advances.me.file(openId, a), [openId])
  const files = useAttachmentOpener(fetchFile)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const [list, ledger] = await Promise.all([
        hrApi.advances.me.list(),
        hrApi.advances.me.outstanding().catch(() => null),
      ])
      setRows(list); setOut(ledger)
    } catch (e) {
      setError(e?.response?.status === 403
        ? (e?.response?.data?.message || 'Your login is not linked to an employee record. Contact HR.')
        : 'Could not load your advances.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const create = async () => {
    if (!form.purpose.trim()) return toast.error('What is the advance for?')
    if (!(Number(form.amount_requested) > 0)) return toast.error('How much do you need?')

    setBusy(true)
    try {
      await hrApi.advances.me.create({ ...form, purpose: form.purpose.trim() }, newFiles)
      toast.success('Advance requested. It goes to your manager first.')
      setCreating(false)
      setForm({ purpose: '', amount_requested: '', advance_type: '', category: '', project_site: '', required_date: '', expected_settlement_date: '' })
      setNewFiles([])
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That request could not be submitted.')
    } finally {
      setBusy(false)
    }
  }

  const openAdvance = async id => {
    setOpenId(id); setDetail(null); setReplyBody(''); setReplyFiles([])
    setSettling(false); setSpent(''); setSettleNote(''); setBills([])
    try { setDetail(await hrApi.advances.me.get(id)) }
    catch { toast.error('Could not open that advance.'); setOpenId(null) }
  }

  const after = async msg => {
    toast.success(msg)
    setDetail(await hrApi.advances.me.get(openId))
    load()
  }

  const run = async (fn, msg) => {
    setBusy(true)
    try { await fn(); await after(msg) }
    catch (e) { toast.error(e?.response?.data?.message || 'That did not go through.') }
    finally { setBusy(false) }
  }

  const reply = () => {
    if (!replyBody.trim()) return toast.error('Say something before sending.')
    run(async () => {
      await hrApi.advances.me.reply(openId, replyBody.trim(), replyFiles)
      setReplyBody(''); setReplyFiles([])
    }, 'Sent.')
  }

  const settle = () => {
    if (spent === '' || Number(spent) < 0) return toast.error('How much was actually spent?')
    run(async () => {
      await hrApi.advances.me.settle(openId, { actual_expense: Number(spent), notes: settleNote.trim() }, bills)
      setSettling(false); setSpent(''); setSettleNote(''); setBills([])
    }, 'Settlement submitted for review.')
  }

  const a   = detail?.advance
  const can = detail?.can || {}

  // What the settlement will come to, shown before it is sent — the arithmetic
  // is the part people get wrong, and finding out afterwards is no use.
  const disbursed = Number(a?.disbursed_amount || 0)
  const diff      = spent === '' ? null : Number(spent) - disbursed

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <Wallet size={18} /> My advances
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Money paid out before it is spent — and what you still have to account for.
          </p>
        </div>
        <button onClick={() => setCreating(v => !v)}
          className="rounded-lg text-xs font-bold flex items-center gap-1.5"
          style={{ padding: '8px 13px', background: 'var(--accent)', color: '#fff' }}>
          <Plus size={14} /> Request an advance
        </button>
      </div>

      {out?.open_count > 0 && (
        <div className="rounded-xl text-[11px] flex items-start gap-1.5"
          style={{ padding: 11, background: 'rgba(251,191,36,0.09)', border: '1px solid rgba(251,191,36,0.25)', color: '#fbbf24' }}>
          <AlertTriangle size={12} style={{ marginTop: 1, flexShrink: 0 }} />
          <span>
            You are carrying <b>{inr(out.outstanding_amount)}</b> across {out.open_count} advance
            {out.open_count === 1 ? '' : 's'} that still need settling
            {out.oldest_disbursed_at && `, the oldest from ${out.oldest_disbursed_at}`}.
          </span>
        </div>
      )}

      {creating && (
        <div className="rounded-2xl flex flex-col gap-3" style={{ padding: 18, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <Field label="What is it for">
            <textarea rows={2} value={form.purpose} onChange={e => setForm(f => ({ ...f, purpose: e.target.value }))}
              placeholder="e.g. Site visit to Pune — travel, hotel and local transport"
              className="w-full rounded-lg text-sm" style={inputStyle} />
          </Field>

          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))' }}>
            <Field label="Amount needed">
              <input type="number" step="0.01" min="0.01" value={form.amount_requested}
                onChange={e => setForm(f => ({ ...f, amount_requested: e.target.value }))}
                className="w-full rounded-lg text-sm" style={inputStyle} />
            </Field>
            <Field label="Type">
              <input value={form.advance_type} onChange={e => setForm(f => ({ ...f, advance_type: e.target.value }))}
                placeholder="travel, site, purchase…" className="w-full rounded-lg text-sm" style={inputStyle} />
            </Field>
            <Field label="Category">
              {/* Accepted by the server since day one and never asked for, so every
                  advance was filed uncategorised. Suggestions, not a closed list. */}
              <input list="advance-categories" value={form.category} maxLength={60}
                onChange={e => setForm(f => ({ ...f, category: e.target.value }))}
                placeholder="e.g. Travel, Site expense"
                className="w-full rounded-lg text-sm" style={inputStyle} />
              <datalist id="advance-categories">
                {['Travel', 'Site expense', 'Purchase', 'Event', 'Salary advance', 'Other']
                  .map(c => <option key={c} value={c} />)}
              </datalist>
            </Field>
            <Field label="Project / site">
              <input value={form.project_site} onChange={e => setForm(f => ({ ...f, project_site: e.target.value }))}
                className="w-full rounded-lg text-sm" style={inputStyle} />
            </Field>
            <Field label="Needed by">
              <input type="date" value={form.required_date}
                onChange={e => setForm(f => ({ ...f, required_date: e.target.value }))}
                className="w-full rounded-lg text-sm" style={inputStyle} />
            </Field>
            <Field label="Settle by">
              {/* The server refuses a settle-by before the needed-by date. */}
              <input type="date" min={form.required_date || undefined} value={form.expected_settlement_date}
                onChange={e => setForm(f => ({ ...f, expected_settlement_date: e.target.value }))}
                className="w-full rounded-lg text-sm" style={inputStyle} />
            </Field>
          </div>

          <FilePicker files={newFiles} setFiles={setNewFiles} label="Attach quotes or itineraries" />

          <div className="flex gap-2">
            <button onClick={create} disabled={busy}
              className="rounded-lg text-xs font-bold"
              style={{ padding: '9px 16px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
              {busy ? 'Submitting…' : 'Submit request'}
            </button>
            <button onClick={() => setCreating(false)}
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
        <HrEmpty icon={Wallet} title="No advances yet" hint="Need money up front for a trip or a site? Ask for it here." />
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map(r => (
            <button key={r.id} onClick={() => openAdvance(r.id)}
              className="rounded-xl text-left flex items-center gap-3 flex-wrap"
              style={{
                padding: '12px 14px', background: 'var(--bg-card)',
                // Held or waiting to be settled: both are on you.
                border: ['on_hold', 'disbursed'].includes(r.status)
                  ? '1px solid rgba(96,165,250,0.5)' : '1px solid var(--border)',
              }}>
              <div className="flex-1 min-w-[180px]">
                <p className="text-sm font-bold truncate" style={{ color: 'var(--text-h)' }}>{r.purpose}</p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  {r.reference}{r.required_date && ` · needed by ${r.required_date}`}
                </p>
              </div>
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                {inr(r.amount_approved ?? r.amount_requested)}
              </span>
              <span className="rounded-md text-[10px] font-bold uppercase tracking-wider"
                style={{ padding: '3px 8px', color: 'var(--text-muted)', background: 'var(--bg-input)' }}>
                {r.stage_label}
              </span>
            </button>
          ))}
        </div>
      )}

      {openId && (
        <div role="dialog" aria-modal="true" aria-label="Advance"
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: 'rgba(0,0,0,0.6)' }} onClick={() => setOpenId(null)}>
          <div onClick={e => e.stopPropagation()} className="rounded-2xl w-full max-w-2xl flex flex-col"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '88vh' }}>

            {!a ? <div className="p-8"><HrLoading /></div> : (
              <>
                <div className="flex items-start gap-3 p-5" style={{ borderBottom: '1px solid var(--border)' }}>
                  <div className="flex-1">
                    <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>{a.purpose}</h2>
                    <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                      {a.reference} · {a.stage_label}
                    </p>
                  </div>
                  <p className="text-lg font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                    {inr(a.amount_approved ?? a.amount_requested)}
                  </p>
                  <button onClick={() => setOpenId(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
                </div>

                <div className="p-5 overflow-y-auto flex flex-col gap-4">
                  {/* Everything the request carries. This pane showed a purpose and an
                      amount; type, category, site and both dates were captured and never
                      said back, so the employee could not check what they had filed. */}
                  <div className="grid gap-2 text-[11px]" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))', color: 'var(--text-muted)' }}>
                    {[['Type', a.advance_type], ['Category', a.category], ['Project / site', a.project_site],
                      ['Needed by', a.required_date], ['Settle by', a.expected_settlement_date],
                      ['Requested', inr(a.amount_requested)]].map(([k, v]) => v ? (
                        <div key={k}><span className="uppercase tracking-wider font-bold">{k}</span><br />
                          <span style={{ color: 'var(--text-p)' }}>{String(v)}</span></div>
                    ) : null)}
                  </div>

                  {/* What actually happened to the money — none of this was shown. */}
                  {a.disbursed_at && (
                    <div className="rounded-xl text-[11px]" style={{ padding: 10, background: 'rgba(52,211,153,0.08)', border: '1px solid rgba(52,211,153,0.25)', color: '#34d399' }}>
                      {inr(a.disbursed_amount)} paid by {String(a.disbursement_mode || '').replace('_', ' ')}
                      {a.disbursement_reference ? ` · ref ${a.disbursement_reference}` : ''}
                      {` · ${String(a.disbursed_at).slice(0, 10)}`}
                    </div>
                  )}

                  {/* Settlements, every attempt — a rejected one is kept on purpose. */}
                  {!!a.settlements?.length && (
                    <div className="flex flex-col gap-1.5">
                      <p className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Settlements</p>
                      {a.settlements.map(st => (
                        <div key={st.id} className="rounded-lg text-[11px]" style={{ padding: '8px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
                          <span className="font-bold">{inr(st.actual_expense)} spent</span> · {st.case_label}
                          <span className="ml-1" style={{ color: st.status === 'accepted' ? '#34d399' : st.status === 'rejected' ? '#f87171' : '#fbbf24' }}>({st.status})</span>
                          {st.review_remarks ? <span className="block mt-0.5" style={{ color: 'var(--text-muted)' }}>{st.review_remarks}</span> : null}
                        </div>
                      ))}
                    </div>
                  )}

                  {!!a.attachments?.length && (
                    <div className="flex flex-wrap gap-1.5">
                      {a.attachments.map(f => (
                        <button key={f.id} onClick={() => files.open(f)} disabled={files.opening === f.id}
                          className="rounded-lg text-[11px] font-semibold"
                          style={{ padding: '5px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
                          {files.opening === f.id ? 'Opening…' : f.name}
                        </button>
                      ))}
                    </div>
                  )}
                  {files.error && <p className="text-[11px]" style={{ color: '#f87171' }}>{files.error}</p>}

                  <RequestThread entries={detail.thread} onOpenFile={files.open}
                    emptyLabel="Nothing has happened on this advance yet." />
                </div>

                <div className="p-5 flex flex-col gap-3" style={{ borderTop: '1px solid var(--border)' }}>
                  {can.accept_proposal && (
                    <button onClick={() => run(() => hrApi.advances.me.accept(openId), 'Accepted. It continues through the remaining approvals.')}
                      disabled={busy}
                      className="rounded-lg text-xs font-bold flex items-center justify-center gap-1.5"
                      style={{ padding: '10px', background: 'rgba(52,211,153,0.15)', color: '#34d399', border: '1px solid rgba(52,211,153,0.35)' }}>
                      <Check size={14} /> Accept {inr(a.proposed_amount)} and carry on
                    </button>
                  )}

                  {can.settle && !settling && (
                    <button onClick={() => setSettling(true)}
                      className="rounded-lg text-xs font-bold"
                      style={{ padding: '10px', background: 'var(--accent)', color: '#fff' }}>
                      Account for what was spent
                    </button>
                  )}

                  {settling && (
                    <div className="flex flex-col gap-2.5">
                      <Field label={`Actually spent (advanced: ${inr(disbursed)})`}>
                        <input type="number" step="0.01" min="0" value={spent} onChange={e => setSpent(e.target.value)}
                          autoFocus className="w-full rounded-lg text-sm" style={inputStyle} />
                      </Field>

                      {diff !== null && Math.abs(diff) > 0.005 && (
                        <p className="text-[11px] font-semibold" style={{ color: diff > 0 ? '#fbbf24' : '#60a5fa' }}>
                          {diff > 0
                            ? `The company owes you ${inr(diff)}.`
                            : `${inr(-diff)} comes back.`}
                        </p>
                      )}

                      <Field label="Notes (optional)">
                        <textarea rows={2} value={settleNote} onChange={e => setSettleNote(e.target.value)}
                          className="w-full rounded-lg text-sm" style={inputStyle} />
                      </Field>

                      <FilePicker files={bills} setFiles={setBills} label="Attach bills" />

                      <div className="flex gap-2">
                        <button onClick={settle} disabled={busy}
                          className="rounded-lg text-xs font-bold"
                          style={{ padding: '9px 16px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
                          {busy ? 'Submitting…' : 'Submit settlement'}
                        </button>
                        <button onClick={() => setSettling(false)}
                          className="rounded-lg text-xs font-semibold"
                          style={{ padding: '9px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
                          Cancel
                        </button>
                      </div>
                    </div>
                  )}

                  {can.reply && !settling && (
                    <>
                      <textarea rows={2} value={replyBody} onChange={e => setReplyBody(e.target.value)}
                        placeholder="Reply to the approver…" className="w-full rounded-lg text-sm" style={inputStyle} />
                      <FilePicker files={replyFiles} setFiles={setReplyFiles} />
                      <div className="flex gap-2">
                        <button onClick={reply} disabled={busy}
                          className="rounded-lg text-xs font-bold flex items-center gap-1.5"
                          style={{ padding: '9px 16px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
                          <Send size={13} /> {busy ? 'Sending…' : 'Send'}
                        </button>
                        {can.cancel && (
                          <button onClick={() => run(() => hrApi.advances.me.cancel(openId), 'Request withdrawn.')}
                            disabled={busy} className="rounded-lg text-xs font-semibold"
                            style={{ padding: '9px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#f87171' }}>
                            Withdraw request
                          </button>
                        )}
                      </div>
                    </>
                  )}

                  {!can.reply && !can.settle && (
                    <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                      This advance is finished — there is nothing left to do on it.
                    </p>
                  )}
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

const inputStyle = {
  padding: '8px 11px', background: 'var(--bg-input)',
  border: '1px solid var(--border)', color: 'var(--text-p)',
}

function Field({ label, children }) {
  return (
    <label className="flex flex-col gap-1">
      <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{label}</span>
      {children}
    </label>
  )
}
