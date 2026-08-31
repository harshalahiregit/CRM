/**
 * Salary advances — the deepest workflow SangoeTrack has.
 *
 * An advance moves through several hands: requested, approved by a manager, then
 * by accounts, then disbursed with a payment reference, and finally settled by
 * the employee and reviewed. Which action this screen offers depends on where
 * the request has reached, so the status is shown as a stage rather than a word.
 *
 * Two things carry real money and are treated accordingly:
 *
 *   Approving for less   — the approve call doubles as approve-with-modification.
 *                          Entering an amount grants that instead of what was
 *                          asked for, and the difference is spelled out before
 *                          it is sent.
 *   Disbursing           — a bank transfer or cheque without a reference cannot
 *                          be reconciled later, so the reference is required for
 *                          everything except cash.
 *
 * Settlements are on this page rather than their own because they are the tail
 * of the same story, and an approver reviewing advances is the person who cares
 * that one came back.
 */

import { useState, useEffect, useCallback } from 'react'
import { Banknote, AlertTriangle } from 'lucide-react'
import { sangoeTrackApi, trackErrorMessage } from '@/services/sangoeTrackApi'
import { useToast } from '@/hooks/useToast'
import useTrackApprovals from './useTrackApprovals'
import useTrackHistory from './useTrackHistory'
import {
  TrackHeader, TrackList, TrackCard, FieldGrid, Field, DecisionBar,
  QueueTabs, HistoryFilters, HistoryPager, Outcome, ExportButton,
} from './TrackShell'

const inr = n =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 })
    .format(Number(n) || 0)

// Their status strings, said the way a person would.
const STAGE = {
  pending:           { label: 'Awaiting approval',   fg: '#fbbf24' },
  manager_approved:  { label: 'Manager approved',    fg: '#60a5fa' },
  accounts_approved: { label: 'Accounts approved',   fg: '#818cf8' },
  approved:          { label: 'Ready to disburse',   fg: '#34d399' },
}

/* ── disbursement ────────────────────────────────────────────────────── */

function DisburseForm({ advance, onDone }) {
  const [open, setOpen]   = useState(false)
  const [mode, setMode]   = useState('bank_transfer')
  const [ref, setRef]     = useState('')
  const [busy, setBusy]   = useState(false)
  const toast = useToast()

  const needsRef = mode !== 'cash'

  async function submit() {
    setBusy(true)
    try {
      await sangoeTrackApi.advances.disburse(advance.id, mode, ref.trim() || null)
      toast.success(`${inr(advance.amount)} marked as disbursed`)
      setOpen(false)
      setRef('')
      onDone?.()
    } catch (err) {
      toast.error(trackErrorMessage(err, 'Could not record the disbursement.'))
    } finally {
      setBusy(false)
    }
  }

  if (!open) {
    return (
      <button onClick={() => setOpen(true)}
        className="rounded-lg text-xs font-bold flex items-center gap-1.5"
        style={{ padding: '7px 14px', background: 'rgba(5,150,105,0.14)', color: '#34d399' }}>
        <Banknote size={13} /> Record disbursement
      </button>
    )
  }

  return (
    <div className="flex flex-col gap-2.5 rounded-lg p-3" style={{ background: 'var(--bg-input)' }}>
      <div className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>
        Paying {inr(advance.amount)} to {advance.employee_name}
      </div>

      <div className="flex flex-wrap gap-1.5">
        {[['upi', 'UPI'], ['bank_transfer', 'Bank transfer'], ['cheque', 'Cheque'], ['cash', 'Cash']].map(([value, label]) => (
          <button key={value} onClick={() => setMode(value)} aria-pressed={mode === value}
            className="rounded-lg text-xs font-semibold"
            style={{
              padding: '6px 12px',
              background: mode === value ? 'rgba(124,58,237,0.16)' : 'var(--bg-card)',
              border: `1px solid ${mode === value ? '#7C3AED' : 'var(--border)'}`,
              color: mode === value ? '#a78bfa' : 'var(--text-muted)',
            }}>
            {label}
          </button>
        ))}
      </div>

      {needsRef && (
        <input
          value={ref}
          onChange={e => setRef(e.target.value)}
          autoFocus
          maxLength={100}
          placeholder={
            mode === 'cheque' ? 'Cheque number'
            : mode === 'upi'  ? 'UPI reference'
            : 'UTR / transaction reference'
          }
          className="rounded-lg text-sm px-2.5 py-2"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
        />
      )}

      <div className="flex gap-2">
        <button onClick={submit} disabled={busy || (needsRef && !ref.trim())}
          className="rounded-lg text-xs font-bold disabled:opacity-50"
          style={{ padding: '7px 14px', background: '#059669', color: '#fff' }}>
          {busy ? 'Recording…' : 'Confirm disbursement'}
        </button>
        <button onClick={() => setOpen(false)} disabled={busy}
          className="rounded-lg text-xs font-semibold disabled:opacity-50"
          style={{ padding: '7px 14px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          Cancel
        </button>
      </div>
    </div>
  )
}

/* ── approve for less ────────────────────────────────────────────────── */

function AmountOverride({ requested, value, onChange }) {
  const changed = value !== '' && Number(value) !== Number(requested)
  return (
    <div className="flex flex-wrap items-center gap-2">
      <label className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>
        Approve a different amount
      </label>
      <input
        type="number" min="0" inputMode="decimal"
        value={value}
        onChange={e => onChange(e.target.value)}
        placeholder={String(requested)}
        className="rounded-lg text-sm px-2.5 py-1.5"
        style={{ width: 130, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
      />
      {changed && (
        <span className="text-[11px] font-semibold" style={{ color: '#fbbf24' }}>
          {Number(value) < Number(requested)
            ? `${inr(Number(requested) - Number(value))} less than requested`
            : `${inr(Number(value) - Number(requested))} more than requested`}
        </span>
      )}
    </div>
  )
}

/* ── settlements ─────────────────────────────────────────────────────── */

function Settlements({ rows, loading, error, reload }) {
  if (!loading && !error && !rows.length) return null

  return (
    <section className="flex flex-col gap-2.5">
      <h2 className="text-sm font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
        Awaiting settlement review
      </h2>
      <TrackList loading={loading} error={error} rows={rows} onRetry={reload} noun="settlements">
        {rows.map(s => {
          // They classify each settlement and send the sentence to show for it,
          // so the arithmetic does not have to be re-derived — or re-derived
          // differently — on this side.
          const owed = s.settlement_case === 'more_spent'
          const back = s.settlement_case === 'less_spent'
          return (
            <TrackCard key={s.id} who={s.employee_name} when={s.submitted_on}>
              {s.case_label && (
                <span className="rounded-md text-[11px] font-bold px-2 py-1 self-start"
                  style={{
                    color: owed ? '#fbbf24' : back ? '#60a5fa' : '#34d399',
                    background: 'var(--bg-input)',
                  }}>
                  {s.case_label}
                </span>
              )}

              <FieldGrid>
                <Field label="Advance"   value={s.advance_id} />
                <Field label="Disbursed" value={inr(s.disbursed_amount)} />
                <Field label="Actually spent" value={inr(s.actual_expense)} />
                {back && <Field label="To return"   value={inr(Math.abs(s.balance_return))} tone="#60a5fa" />}
                {owed && <Field label="Company owes" value={inr(s.extra_due)} tone="#fbbf24" />}
                <Field label="Type"    value={s.advance_type} />
                <Field label="Purpose" value={s.purpose} />
                <Field label="Their notes" value={s.notes} wide />
              </FieldGrid>

              {Array.isArray(s.bills) && s.bills.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                  {s.bills.map((bill, i) => (
                    <a key={i} href={typeof bill === 'string' ? bill : bill?.url}
                      target="_blank" rel="noopener noreferrer"
                      className="rounded-lg text-[11px] font-semibold px-2.5 py-1.5"
                      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
                      Bill {i + 1}
                    </a>
                  ))}
                </div>
              )}

              <DecisionBar
                approveLabel="Accept"
                onDecide={(status, remark) => sangoeTrackApi.advances.reviewSettlement(s.id, status, remark)}
                onDone={reload}
              />
            </TrackCard>
          )
        })}
      </TrackList>
    </section>
  )
}

/* ── page ────────────────────────────────────────────────────────────── */

const CSV = [
  { key: 'employee_name',    label: 'Employee' },
  { key: 'advance_id',       label: 'Advance ID' },
  { key: 'advance_type',     label: 'Type' },
  { key: 'category',         label: 'Category' },
  { key: 'department',       label: 'Department' },
  { key: 'project_site',     label: 'Project / site' },
  { key: 'purpose',          label: 'Purpose' },
  { key: 'amount_requested', label: 'Requested' },
  { key: 'amount_approved',  label: 'Approved' },
  { key: 'status',           label: 'Status' },
  { key: 'required_date',    label: 'Needed by' },
  { key: 'expected_settlement_date', label: 'Settle by' },
  { key: 'attachment',       label: 'Attachment URL' },
  { key: 'submitted_on',     label: 'Submitted on' },
]

export default function TrackAdvances() {
  const [tab, setTab] = useState('pending')
  const { rows, loading, error, reload } = useTrackApprovals('advances')
  const past = useTrackHistory('advances')
  const [amounts, setAmounts] = useState({})

  const [settlements, setSettlements] = useState([])
  const [sLoading, setSLoading]       = useState(true)
  const [sError, setSError]           = useState(null)

  const loadSettlements = useCallback(async () => {
    setSLoading(true)
    setSError(null)
    try {
      const res = await sangoeTrackApi.approvals.settlements()
      setSettlements(Array.isArray(res?.data) ? res.data : [])
    } catch (err) {
      setSError(err)
    } finally {
      setSLoading(false)
    }
  }, [])

  useEffect(() => { loadSettlements() }, [loadSettlements])

  function refreshAll() {
    reload()
    loadSettlements()
  }

  return (
    <div className="p-5 md:p-7 flex flex-col gap-6">
      <TrackHeader
        title="Advances"
        subtitle="Requests, approvals and disbursement. Money leaves the company here."
        onRefresh={tab === 'pending' ? refreshAll : past.reload}
        loading={tab === 'pending' ? (loading || sLoading) : past.loading}
      />

      <QueueTabs tab={tab} onChange={setTab} pendingCount={rows.length} />

      {tab === 'history' && (
        <>
          {/* Advances move through a longer chain than approved/rejected, so the
              filter uses their vocabulary rather than the generic one. */}
          <HistoryFilters {...past} setFilter={past.setFilter} clear={past.clear} statuses="advance">
            <ExportButton
              filename={`advances-${past.filters.from || "all"}-to-${past.filters.to || "now"}`}
              rows={past.rows} columns={CSV} total={past.meta?.total} />
          </HistoryFilters>

          <TrackList loading={past.loading} error={past.error} rows={past.rows} onRetry={past.reload} noun="advances">
            {past.rows.map(a => (
              <TrackCard key={a.id} who={a.employee_name} when={a.submitted_on}>
                <div className="flex flex-wrap items-center gap-2">
                  <Outcome status={a.status} />
                  {a.advance_id && (
                    <span className="text-[11px]" style={{ color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>
                      {a.advance_id}
                    </span>
                  )}
                </div>
                <FieldGrid>
                  <Field label="Requested" value={inr(a.amount_requested)} />
                  {a.amount_approved != null && Number(a.amount_approved) !== Number(a.amount_requested) && (
                    <Field label="Approved for" value={inr(a.amount_approved)} tone="#fbbf24" />
                  )}
                  <Field label="Type"       value={a.advance_type} />
                  <Field label="Category"   value={a.category} />
                  <Field label="Department" value={a.department} />
                  <Field label="Needed by"  value={a.required_date} />
                  <Field label="Purpose"    value={a.purpose} wide />
                </FieldGrid>
                {a.attachment && (
                  <a href={a.attachment} target="_blank" rel="noopener noreferrer"
                    className="rounded-lg text-xs font-semibold self-start"
                    style={{ padding: '6px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
                    View attachment
                  </a>
                )}
              </TrackCard>
            ))}
          </TrackList>

          <HistoryPager meta={past.meta} page={past.page} setPage={past.setPage} noun="advances" />
        </>
      )}

      {tab === 'pending' && <TrackList loading={loading} error={error} rows={rows} onRetry={reload} noun="advance requests">
        {rows.map(a => {
          const stage = STAGE[a.status] ?? { label: a.status, fg: 'var(--text-muted)' }
          const override = amounts[a.id] ?? ''
          const reduced = a.amount_approved != null && Number(a.amount_approved) !== Number(a.amount_requested)

          return (
            <TrackCard key={a.id} who={a.employee_name} when={a.applied_on}>
              <div className="flex flex-wrap items-center gap-2">
                <span className="rounded-md text-[11px] font-bold px-2 py-1"
                  style={{ color: stage.fg, background: 'var(--bg-input)' }}>
                  {stage.label}
                </span>
                {a.advance_id && (
                  <span className="text-[11px]" style={{ color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>
                    {a.advance_id}
                  </span>
                )}
              </div>

              <FieldGrid>
                <Field label="Requested" value={inr(a.amount_requested)} />
                {/* Only shown once it differs — otherwise it is the same number twice. */}
                {reduced && <Field label="Approved for" value={inr(a.amount_approved)} tone="#fbbf24" />}
                <Field label="Type"        value={a.advance_type} />
                <Field label="Category"    value={a.category} />
                <Field label="Department"  value={a.department} />
                <Field label="Project / site" value={a.project_site} />
                <Field label="Needed by"   value={a.required_date} />
                <Field label="Settle by"   value={a.expected_settlement_date} />
                <Field label="Purpose"     value={a.purpose} wide />
              </FieldGrid>

              {a.awaiting_disbursement ? (
                <DisburseForm advance={a} onDone={refreshAll} />
              ) : (
                <div className="flex flex-col gap-2.5">
                  <AmountOverride
                    requested={a.amount_requested}
                    value={override}
                    onChange={v => setAmounts(prev => ({ ...prev, [a.id]: v }))}
                  />
                  <DecisionBar
                    onDecide={(status, remark) =>
                      sangoeTrackApi.advances.decide(
                        a.id, status, remark,
                        // Only send an amount when approving with a real change.
                        status === 'approved' && override !== '' ? Number(override) : undefined
                      )
                    }
                    onDone={refreshAll}
                  />
                </div>
              )}
            </TrackCard>
          )
        })}
      </TrackList>}

      {tab === 'pending' && (
        <Settlements rows={settlements} loading={sLoading} error={sError} reload={loadSettlements} />
      )}

      {/* Two things this screen still cannot do. Both are real, so both are said. */}
      <p className="text-[11px] flex items-start gap-1.5" style={{ color: 'var(--text-muted)' }}>
        <AlertTriangle size={12} style={{ marginTop: 2, flexShrink: 0 }} />
        SangoeTrack approves advances through three stages — manager, accounts, then director.
        This screen approves without choosing a stage, and has no per-employee ledger, so an
        employee's existing outstanding balance is not shown before a new advance is granted.
      </p>
    </div>
  )
}
