/**
 * An employee's own expense claims.
 *
 * The counterpart of the admin queue, and deliberately the same conversation
 * seen from the other end: the thread here is the thread there, minus the
 * internal notes the server withholds. What an employee can do at any moment is
 * read off `data.can` rather than worked out from the status in this file — the
 * app and the CRM must not disagree about whether a claim can still be answered.
 *
 * Several files, including PDFs, on both the first submission and every reply.
 * A held claim is usually held because the paperwork was wrong, so replying
 * without being able to attach the right paperwork would leave the hold
 * unclearable.
 */

import { useState, useEffect, useCallback } from 'react'
import { Receipt, Plus, Paperclip, X, Send, Check } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import RequestThread from '../components/RequestThread'
import useAttachmentOpener from '../components/useAttachmentOpener'

const inr = n =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 })
    .format(Number(n) || 0)

const STATUS = {
  pending:  { fg: '#fbbf24', bg: 'rgba(251,191,36,0.12)', label: 'Waiting on a decision' },
  on_hold:  { fg: '#60a5fa', bg: 'rgba(96,165,250,0.12)', label: 'Needs your reply' },
  approved: { fg: '#34d399', bg: 'rgba(52,211,153,0.12)', label: 'Approved' },
  declined: { fg: '#f87171', bg: 'rgba(248,113,113,0.12)', label: 'Declined' },
}

/** Matches the server's rules, so an oversized or odd file is caught before upload. */
const MAX_FILES = 10
const MAX_MB    = 10
const ACCEPT    = '.pdf,.png,.jpg,.jpeg,.webp,.heic'

function Pill({ status }) {
  const s = STATUS[status] || { fg: 'var(--text-muted)', bg: 'var(--bg-input)', label: status }
  return (
    <span className="rounded-md text-[10px] font-bold uppercase tracking-wider"
      style={{ padding: '3px 8px', color: s.fg, background: s.bg }}>{s.label}</span>
  )
}

/** A file picker that shows what is attached and lets one be taken back off. */
function FilePicker({ files, setFiles }) {
  const toast = useToast()

  const add = e => {
    const picked = Array.from(e.target.files || [])
    e.target.value = ''   // so the same file can be picked again after removal

    const tooBig = picked.filter(f => f.size > MAX_MB * 1024 * 1024)
    const ok = picked.filter(f => f.size <= MAX_MB * 1024 * 1024)

    setFiles(prev => [...prev, ...ok].slice(0, MAX_FILES))

    // Named, not counted — "one file was too large" leaves you guessing which.
    if (tooBig.length) {
      toast.error(`Too large (max ${MAX_MB}MB): ${tooBig.map(f => f.name).join(', ')}`)
    }
    if (files.length + ok.length > MAX_FILES) {
      toast.warning(`Only the first ${MAX_FILES} files were kept.`)
    }
  }

  return (
    <div className="flex flex-col gap-1.5">
      <label className="rounded-lg text-[11px] font-semibold flex items-center gap-1.5 self-start cursor-pointer"
        style={{ padding: '6px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
        <Paperclip size={12} /> Attach receipts
        <input type="file" multiple accept={ACCEPT} onChange={add} style={{ display: 'none' }} />
      </label>

      {!!files.length && (
        <div className="flex flex-wrap gap-1.5">
          {files.map((f, i) => (
            <span key={`${f.name}-${i}`} className="flex items-center gap-1.5 rounded-lg text-[11px]"
              style={{ padding: '4px 8px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
              {f.name}
              <button type="button" onClick={() => setFiles(prev => prev.filter((_, j) => j !== i))}
                style={{ color: 'var(--text-muted)' }} aria-label={`Remove ${f.name}`}>
                <X size={11} />
              </button>
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

export default function MyReimbursements() {
  const toast = useToast()

  const [rows,    setRows]    = useState([])
  const [loading, setLoading] = useState(true)
  const [error,   setError]   = useState(null)
  const [busy,    setBusy]    = useState(false)

  const [creating, setCreating] = useState(false)
  const [form,     setForm]     = useState({ title: '', description: '', expense_date: '', amount_claimed: '' })
  const [newFiles, setNewFiles] = useState([])

  const [openId,    setOpenId]    = useState(null)
  const [detail,    setDetail]    = useState(null)
  const [replyBody, setReplyBody] = useState('')
  const [replyFiles,setReplyFiles]= useState([])

  const fetchFile = useCallback(
    attachmentId => hrApi.reimbursements.me.file(openId, attachmentId), [openId])
  const files = useAttachmentOpener(fetchFile)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      setRows(await hrApi.reimbursements.me.list())
    } catch (e) {
      // The linked-employee refusal is worth saying plainly — it is an HR task,
      // not something the person can fix by retrying.
      setError(e?.response?.status === 403
        ? (e?.response?.data?.message || 'Your login is not linked to an employee record. Contact HR.')
        : 'Could not load your claims.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const create = async () => {
    if (!form.title.trim())      return toast.error('What was the expense for?')
    if (!form.expense_date)      return toast.error('When was it spent?')
    if (!(Number(form.amount_claimed) > 0)) return toast.error('How much are you claiming?')

    setBusy(true)
    try {
      await hrApi.reimbursements.me.create({ ...form, title: form.title.trim() }, newFiles)
      toast.success('Claim submitted.')
      setCreating(false)
      setForm({ title: '', description: '', expense_date: '', amount_claimed: '' })
      setNewFiles([])
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That claim could not be submitted.')
    } finally {
      setBusy(false)
    }
  }

  const openClaim = async id => {
    setOpenId(id); setDetail(null); setReplyBody(''); setReplyFiles([])
    try {
      setDetail(await hrApi.reimbursements.me.get(id))
    } catch {
      toast.error('Could not open that claim.')
      setOpenId(null)
    }
  }

  const reply = async () => {
    if (!replyBody.trim()) return toast.error('Say something before sending.')
    setBusy(true)
    try {
      await hrApi.reimbursements.me.reply(openId, replyBody.trim(), replyFiles)
      toast.success('Sent.')
      setReplyBody(''); setReplyFiles([])
      setDetail(await hrApi.reimbursements.me.get(openId))
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That could not be sent.')
    } finally {
      setBusy(false)
    }
  }

  const accept = async () => {
    setBusy(true)
    try {
      await hrApi.reimbursements.me.accept(openId)
      toast.success('Accepted. The claim has been approved at that amount.')
      setDetail(await hrApi.reimbursements.me.get(openId))
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That could not be accepted.')
    } finally {
      setBusy(false)
    }
  }

  const claim = detail?.claim
  const can   = detail?.can || {}

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <Receipt size={18} /> My reimbursements
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Expenses you have claimed back, and anything the approver has asked you.
          </p>
        </div>
        <button onClick={() => setCreating(v => !v)}
          className="rounded-lg text-xs font-bold flex items-center gap-1.5"
          style={{ padding: '8px 13px', background: 'var(--accent)', color: '#fff' }}>
          <Plus size={14} /> New claim
        </button>
      </div>

      {creating && (
        <div className="rounded-2xl flex flex-col gap-3" style={{ padding: 18, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(190px,1fr))' }}>
            <Field label="What was it for">
              <input value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
                placeholder="e.g. Client dinner" className="w-full rounded-lg text-sm"
                style={inputStyle} />
            </Field>
            <Field label="Amount">
              <input type="number" step="0.01" min="0.01" value={form.amount_claimed}
                onChange={e => setForm(f => ({ ...f, amount_claimed: e.target.value }))}
                className="w-full rounded-lg text-sm" style={inputStyle} />
            </Field>
            <Field label="Spent on">
              {/* The server refuses a future date; the picker refuses it first. */}
              <input type="date" max={new Date().toISOString().slice(0, 10)} value={form.expense_date}
                onChange={e => setForm(f => ({ ...f, expense_date: e.target.value }))}
                className="w-full rounded-lg text-sm" style={inputStyle} />
            </Field>
          </div>

          <Field label="Anything the approver should know (optional)">
            <textarea rows={2} value={form.description}
              onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
              className="w-full rounded-lg text-sm" style={inputStyle} />
          </Field>

          <FilePicker files={newFiles} setFiles={setNewFiles} />

          <div className="flex gap-2">
            <button onClick={create} disabled={busy}
              className="rounded-lg text-xs font-bold"
              style={{ padding: '9px 16px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
              {busy ? 'Submitting…' : 'Submit claim'}
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
        <div className="rounded-xl text-xs" style={{ padding: 14, background: 'rgba(248,113,113,0.1)', color: '#f87171' }}>
          {error}
        </div>
      ) : !rows.length ? (
        <HrEmpty icon={Receipt} title="No claims yet" hint="Spent something on the company's behalf? Claim it back here." />
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map(r => (
            <button key={r.id} onClick={() => openClaim(r.id)}
              className="rounded-xl text-left flex items-center gap-3 flex-wrap"
              style={{
                padding: '12px 14px', background: 'var(--bg-card)',
                // A claim waiting on you should be the one you notice.
                border: r.status === 'on_hold' ? '1px solid rgba(96,165,250,0.5)' : '1px solid var(--border)',
              }}>
              <div className="flex-1 min-w-[180px]">
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{r.title}</p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  spent {r.expense_date}
                  {!!r.attachments_count && ` · ${r.attachments_count} file${r.attachments_count === 1 ? '' : 's'}`}
                </p>
              </div>
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                {inr(r.amount_approved ?? r.amount_claimed)}
              </span>
              <Pill status={r.status} />
            </button>
          ))}
        </div>
      )}

      {openId && (
        <div role="dialog" aria-modal="true" aria-label="Claim"
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: 'rgba(0,0,0,0.6)' }} onClick={() => setOpenId(null)}>
          <div onClick={e => e.stopPropagation()} className="rounded-2xl w-full max-w-2xl flex flex-col"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '88vh' }}>

            {!claim ? <div className="p-8"><HrLoading /></div> : (
              <>
                <div className="flex items-start gap-3 p-5" style={{ borderBottom: '1px solid var(--border)' }}>
                  <div className="flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>{claim.title}</h2>
                      <Pill status={claim.status} />
                    </div>
                    <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>spent {claim.expense_date}</p>
                  </div>
                  <p className="text-lg font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                    {inr(claim.amount_approved ?? claim.amount_claimed)}
                  </p>
                  <button onClick={() => setOpenId(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
                </div>

                <div className="p-5 overflow-y-auto flex flex-col gap-4">
                  {!!claim.attachments?.length && (
                    <div className="flex flex-wrap gap-1.5">
                      {claim.attachments.map(a => (
                        <button key={a.id} onClick={() => files.open(a)} disabled={files.opening === a.id}
                          className="rounded-lg text-[11px] font-semibold"
                          style={{ padding: '5px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
                          {files.opening === a.id ? 'Opening…' : a.name}
                        </button>
                      ))}
                    </div>
                  )}
                  {files.error && <p className="text-[11px]" style={{ color: '#f87171' }}>{files.error}</p>}

                  <RequestThread entries={detail.thread} onOpenFile={files.open}
                    emptyLabel="Nothing has happened on this claim yet." />
                </div>

                <div className="p-5 flex flex-col gap-3" style={{ borderTop: '1px solid var(--border)' }}>
                  {can.accept_proposal && (
                    <button onClick={accept} disabled={busy}
                      className="rounded-lg text-xs font-bold flex items-center justify-center gap-1.5"
                      style={{ padding: '10px', background: 'rgba(52,211,153,0.15)', color: '#34d399', border: '1px solid rgba(52,211,153,0.35)' }}>
                      <Check size={14} /> Accept {inr(claim.proposed_amount)} and close this
                    </button>
                  )}

                  {can.reply ? (
                    <>
                      <textarea rows={2} value={replyBody} onChange={e => setReplyBody(e.target.value)}
                        placeholder="Reply to the approver…" className="w-full rounded-lg text-sm" style={inputStyle} />
                      <FilePicker files={replyFiles} setFiles={setReplyFiles} />
                      <button onClick={reply} disabled={busy}
                        className="rounded-lg text-xs font-bold flex items-center justify-center gap-1.5 self-start"
                        style={{ padding: '9px 16px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
                        <Send size={13} /> {busy ? 'Sending…' : 'Send'}
                      </button>
                    </>
                  ) : (
                    <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                      This claim has been decided, so it can no longer be replied to.
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
