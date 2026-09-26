import { useState, useEffect, useCallback } from 'react'
import {
  ClipboardCheck, ShieldAlert, AlertTriangle, CheckCircle2, Circle,
  Loader2, RefreshCw, ListChecks, MessageSquarePlus, X,
} from 'lucide-react'
import Modal from '@/components/ui/Modal'
import { useToast } from '@/components/ui/Toast'
import { transportPretripApi } from '@/services/transportApi'
import { Chip } from './MasterFormFields'
import {
  pretripReadinessCfg, pretripResultCfg, PRETRIP_CATEGORY_ORDER, fmtDateTime,
} from '../constants'

/**
 * Pre-trip readiness panel on the trip detail page — SNG-TRN-010 step 8.
 *
 * Same design language as AllocationPanel, deliberately: a dispatcher looking at
 * allocation blockers and pre-trip blockers on the same page should not have to
 * learn two vocabularies. Same chips, same button styles, same "a refusal
 * explains itself in place" behaviour.
 *
 * ── UX §35: NEVER MERELY SHOW "BLOCKED" ──────────────────────────────────
 * §35 asks four things of a blocked state — why, what is missing, who must fix
 * it, what happens after. Three come straight from the server: every check
 * carries a `detail` written in BRWM §70's voice ("Order TO-2026-0042 is Draft —
 * it must be approved before the trip can dispatch"), and the readiness payload
 * carries `blocking_message`, the exact sentence the gate would refuse with.
 *
 * That last point is why the Pass button never has to be clicked to find out why
 * it cannot be: the reason is already on screen beside it.
 *
 * The fourth, "who must fix it", is NOT shown — the same recorded gap as the
 * allocation panel. An owner per requirement lives in CMP §12's Compliance
 * Requirement Master, which no ticket in the register builds.
 *
 * ── UX §36: WARNING IS NOT BLOCK ─────────────────────────────────────────
 * BRW-052 is the rule: "Critical failure: Dispatch blocked. Non-critical
 * warning: Continue with warning." So critical_fail is red and listed as a
 * blocker; fail and pass_warning are amber and never stop the gate. The chip
 * always spells the result out — UX §129, colour is not the only signal.
 *
 * ── WHY THE 18 UNREACHABLE CHECKS DO NOT APPEAR ──────────────────────────
 * There is nothing here to hide them. PretripCheckKey declares 23 checks but the
 * policy service refuses to enable any of the 18 that read data no column
 * stores, so they are never generated and never reach this component. The panel
 * renders exactly what the API returns.
 *
 * ── NO DISPATCH ──────────────────────────────────────────────────────────
 * Nothing here implies dispatch is possible. `pretrip_ok → dispatched` is
 * dispatch confirmation and belongs to no ticket in the register (D-18), so the
 * panel stops at "ready for dispatch" and offers no button to do it.
 */

const btn = {
  base: { padding: '8px 13px', borderRadius: 9, fontSize: 12.5, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer', border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-p)' },
  primary: { padding: '8px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer', border: '1px solid #7C3AED', background: '#7C3AED', color: '#fff' },
  go: { padding: '8px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer', border: '1px solid #059669', background: '#059669', color: '#fff' },
  disabled: { padding: '8px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'not-allowed', border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', opacity: 0.75 },
}

/** One check row. Completed and outstanding are two different signals. */
function CheckRow({ check, canPerform, onConfirm, busyId }) {
  const cfg = pretripResultCfg(check.result)
  const blocking = check.blocks
  const warning = check.warning
  const done = check.completed
  const busy = busyId === check.id

  return (
    <div style={{
      border: `1px solid ${blocking ? 'rgba(248,113,113,0.35)' : 'var(--border)'}`,
      borderRadius: 10, padding: '10px 12px',
      background: blocking ? 'rgba(248,113,113,0.06)' : done ? 'var(--bg-input)' : 'transparent',
    }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, flexWrap: 'wrap' }}>
        {/* Confirmed vs outstanding, as a shape and not only a colour. */}
        {done
          ? <CheckCircle2 size={15} style={{ color: '#34d399', marginTop: 2, flexShrink: 0 }} />
          : <Circle size={15} style={{ color: 'var(--text-muted)', marginTop: 2, flexShrink: 0 }} />}

        <div style={{ flex: 1, minWidth: 180 }}>
          <p style={{ margin: 0, fontSize: 13, fontWeight: 700, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap' }}>
            {check.label}
            {/* BRW-052 — whether a failure of this check blocks is policy, and
                the row says which policy it was generated under. */}
            {check.critical
              ? <span style={{ fontSize: 10, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: '#f87171' }}>Must pass</span>
              : <span style={{ fontSize: 10, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>Advisory</span>}
          </p>

          <p style={{ margin: '3px 0 0', fontSize: 11.5, color: blocking ? '#f87171' : warning ? '#fbbf24' : 'var(--text-muted)' }}>
            {check.detail || '—'}
          </p>

          {/* A remark survives invalidation deliberately — what a person wrote is
              theirs, not ours to discard. But an unconfirmed row's remark was
              written about a crew that has since changed, so it is labelled
              rather than shown as if it still stood. */}
          {check.remarks && (
            <p style={{ margin: '5px 0 0', fontSize: 11.5, color: 'var(--text-p)', display: 'flex', gap: 6, alignItems: 'flex-start' }}>
              <MessageSquarePlus size={12} style={{ marginTop: 2, flexShrink: 0, color: 'var(--text-muted)' }} />
              <span>
                {!done && (
                  <span style={{ color: 'var(--text-muted)', fontWeight: 700 }}>Earlier note — </span>
                )}
                <span style={{ fontStyle: 'italic' }}>{check.remarks}</span>
              </span>
            </p>
          )}

          {done && (
            <p style={{ margin: '4px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>
              Confirmed {fmtDateTime(check.completed_at)}
            </p>
          )}
        </div>

        <Chip cfg={cfg} />

        {/* Only offered when the user may perform, and only while outstanding.
            Confirming never changes the result and never clears a block. */}
        {canPerform && !done && check.result !== 'pending' && (
          <button onClick={() => onConfirm(check)} disabled={busy} style={{ ...btn.base, padding: '6px 11px' }}>
            {busy ? <Loader2 size={12} className="animate-spin" /> : <CheckCircle2 size={12} />} Confirm
          </button>
        )}
        {canPerform && !done && check.result === 'pending' && (
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Refresh to evaluate</span>
        )}
      </div>
    </div>
  )
}


/**
 * A blocker or a warning, as a sentence — D-150.
 *
 * ONE shape: `{code, why, owner}`. What this screen reads is PRE-TRIP
 * readiness (`TripPretripCheck::blockersOf/warningsOf`), which was still
 * plain strings — and D-147's version turned a string into EMPTY: `r?.owner`
 * undefined fell through to `r?.why ?? ''`. Both now emit the same shape as
 * `EligibilityVerdict`; `owner` is null because no pre-trip check names a desk.
 * No string branch: PretripSchemaPolicyTest holds the backend to the shape.
 */
const reason = (r) => (r?.owner ? `${r.why} (${r.owner})` : (r?.why ?? ''))

export default function PretripPanel({ trip, canPerform, onChanged }) {
  const toast = useToast()

  const [readiness, setReadiness] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [busyId, setBusyId] = useState(null)
  const [refusal, setRefusal] = useState(null)
  const [confirming, setConfirming] = useState(null)   // the check being remarked
  const [remarks, setRemarks] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setReadiness(await transportPretripApi.readiness(trip.id))
    } catch (e) {
      toast.error(e?.message || 'Could not load pre-trip readiness.')
    } finally {
      setLoading(false)
    }
  }, [trip.id])

  useEffect(() => { load() }, [load])

  const generate = async () => {
    setBusy(true); setRefusal(null)
    try {
      const res = await transportPretripApi.generate(trip.id)
      if (res.ok) {
        setReadiness(res.readiness)
        toast.success('Pre-trip checklist refreshed.')
      } else {
        setRefusal(res.message)
        if (res.readiness) setReadiness(res.readiness)
        toast.error(res.message)
      }
    } catch (e) {
      toast.error(e?.message || 'The checklist could not be built.')
    } finally {
      setBusy(false)
    }
  }

  const confirm = async (check, note) => {
    setBusyId(check.id); setRefusal(null)
    try {
      const res = await transportPretripApi.complete(trip.id, check.id, note || null)
      if (res.ok) {
        setReadiness({ ...res, check: undefined, audit: undefined })
        setConfirming(null); setRemarks('')
        toast.success(`${check.label} confirmed.`)
      } else {
        setRefusal(res.message)
        if (res.readiness) setReadiness(res.readiness)
        toast.error(res.message)
      }
    } catch (e) {
      toast.error(e?.message || 'That check could not be confirmed.')
    } finally {
      setBusyId(null)
    }
  }

  const pass = async () => {
    setBusy(true); setRefusal(null)
    try {
      const res = await transportPretripApi.pass(trip.id)
      if (res.ok) {
        toast.success('Pre-trip checks passed — the trip is ready for dispatch.')
        onChanged?.()
        load()
      } else {
        // BRW-048/OPS §30 — a block is a verdict, shown in place with its reason.
        setRefusal(res.message)
        if (res.readiness) setReadiness(res.readiness)
        toast.error(res.message)
      }
    } catch (e) {
      toast.error(e?.message || 'The trip could not pass pre-trip checks.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return <div style={{ padding: 22, textAlign: 'center' }}><Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  }

  const status = readiness?.status || 'not_started'
  const checks = readiness?.checks || []
  const isReady = !!readiness?.ready
  // "Has this trip already moved past pre-trip", NOT "is it exactly at
  // pretrip_ok". Written as the narrower test when pretrip_ok was the last
  // reachable state; once dispatch was wired, a dispatched trip stopped
  // matching it and was offered an enabled "Pass pre-trip" button the API
  // would refuse — UX §150's exact complaint. Any state at or beyond the gate
  // counts, so the next state added does not reintroduce this.
  const alreadyPassed = ['pretrip_ok', 'dispatched'].includes(trip.status)

  // OPS §28's category order, with anything unrecognised kept at the end rather
  // than dropped — a check the UI cannot place is the one worth showing.
  const groups = []
  const seen = new Set()
  for (const cat of PRETRIP_CATEGORY_ORDER) {
    const rows = checks.filter((c) => c.category === cat)
    if (rows.length) { groups.push({ cat, label: rows[0].category_label || cat, rows }); seen.add(cat) }
  }
  for (const c of checks) {
    if (!seen.has(c.category)) {
      seen.add(c.category)
      groups.push({ cat: c.category, label: c.category_label || c.category, rows: checks.filter((r) => r.category === c.category) })
    }
  }

  return (
    <>
      {/* Header: how this step stands, and how far through the list we are.
          "Readiness" was our word for it — the reader wants "Checks". The trip's
          own status chip has gone: it is on the page header and the tracker, and
          a third copy inside a step panel only invited the question of which one
          to believe. */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginTop: 12 }}>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Checks</span>
        <Chip cfg={pretripReadinessCfg(status)} />
        {checks.length > 0 && (
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
            {readiness.completed} of {readiness.total} checks confirmed
          </span>
        )}
      </div>

      {/* UX §35 — the reason lives next to the action, so nobody has to click to
          find out why they cannot proceed. */}
      {!isReady && readiness?.blocking_message && !alreadyPassed && (
        <div style={{
          marginTop: 12, padding: '10px 12px', borderRadius: 9, fontSize: 12.5, display: 'flex', gap: 8,
          background: status === 'blocked' ? 'rgba(248,113,113,0.12)' : 'rgba(251,191,36,0.10)',
          border: `1px solid ${status === 'blocked' ? 'rgba(248,113,113,0.35)' : 'rgba(251,191,36,0.30)'}`,
          color: status === 'blocked' ? '#f87171' : '#fbbf24',
        }}>
          <ShieldAlert size={15} style={{ flexShrink: 0, marginTop: 1 }} /> {readiness.blocking_message}
        </div>
      )}

      {refusal && (
        <div style={{ marginTop: 10, padding: '10px 12px', borderRadius: 9, background: 'rgba(248,113,113,0.12)', border: '1px solid rgba(248,113,113,0.35)', color: '#f87171', fontSize: 12.5, display: 'flex', gap: 8 }}>
          <AlertTriangle size={15} style={{ flexShrink: 0, marginTop: 1 }} /> {refusal}
        </div>
      )}

      {alreadyPassed && (
        <div style={{ marginTop: 12, padding: '10px 12px', borderRadius: 9, background: 'rgba(52,211,153,0.10)', border: '1px solid rgba(52,211,153,0.30)', color: '#34d399', fontSize: 12.5, display: 'flex', gap: 8 }}>
          <CheckCircle2 size={15} style={{ flexShrink: 0, marginTop: 1 }} />
          {trip.status === 'dispatched'
            ? 'These checks passed and the trip has been dispatched. Below is what was confirmed before it left.'
            : 'All checks passed. Dispatch re-checks these before the trip leaves.'}
        </div>
      )}

      {/* Advisory caveats — §36, never rendered as blockers. */}
      {(readiness?.warnings || []).length > 0 && (
        <div style={{ marginTop: 10 }}>
          {readiness.warnings.map((w, i) => (
            <p key={i} style={{ margin: '0 0 4px', fontSize: 11.5, color: '#fbbf24' }}>⚠ {reason(w)}</p>
          ))}
        </div>
      )}

      {/* Empty state — OPS §29's NOT_STARTED, and the way out of it. */}
      {checks.length === 0 && (
        <div style={{ marginTop: 14, padding: '22px 16px', textAlign: 'center', border: '1px dashed var(--border)', borderRadius: 10 }}>
          <ListChecks size={22} style={{ color: 'var(--text-muted)', margin: '0 auto 8px' }} />
          <p style={{ margin: 0, fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>No checklist yet</p>
          <p style={{ margin: '4px 0 12px', fontSize: 12, color: 'var(--text-muted)' }}>
            The readiness checklist is built from your pre-trip policy and this trip's crew.
          </p>
          {canPerform && readiness?.generatable && (
            <button onClick={generate} disabled={busy} style={{ ...btn.primary, margin: '0 auto' }}>
              {busy ? <Loader2 size={13} className="animate-spin" /> : <ClipboardCheck size={13} />} Generate checklist
            </button>
          )}
          {canPerform && !readiness?.generatable && (
            <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)' }}>
              A checklist can be built once the trip is approved or allocated.
            </p>
          )}
        </div>
      )}

      {/* The checklist, grouped as STOS-OPS §28 groups it. */}
      {groups.map((g) => (
        <div key={g.cat} style={{ marginTop: 14 }}>
          <p style={{ margin: '0 0 7px', fontSize: 10.5, fontWeight: 800, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
            {g.label}
          </p>
          <div style={{ display: 'grid', gap: 8 }}>
            {g.rows.map((c) => (
              <CheckRow key={c.id} check={c} canPerform={canPerform && !alreadyPassed}
                onConfirm={(chk) => { setConfirming(chk); setRemarks('') }} busyId={busyId} />
            ))}
          </div>
        </div>
      ))}

      {/* Actions. UX §150 hides what the user may not do; the Pass button is
          DISABLED rather than hidden when the checklist is not ready, because
          that is a state the user can act on and the reason is on screen. */}
      {canPerform && checks.length > 0 && !alreadyPassed && (
        <div style={{ marginTop: 16, display: 'flex', gap: 9, alignItems: 'center', flexWrap: 'wrap' }}>
          <button onClick={generate} disabled={busy} style={btn.base}>
            <RefreshCw size={13} className={busy ? 'animate-spin' : ''} /> Refresh checks
          </button>

          {isReady ? (
            <button onClick={pass} disabled={busy} style={btn.go}>
              {busy ? <Loader2 size={13} className="animate-spin" /> : <ClipboardCheck size={13} />} Pass pre-trip
            </button>
          ) : (
            <button disabled title={readiness?.blocking_message || 'Not ready'} style={btn.disabled}>
              <ClipboardCheck size={13} /> Pass pre-trip
            </button>
          )}
        </div>
      )}

      {!canPerform && (
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '12px 0 0' }}>
          You do not have permission to run pre-trip checks on a trip.
        </p>
      )}

      {/* Confirming one check. A remark is optional; a result cannot be entered
          at all — that would be an override, and override is P1. */}
      <Modal open={!!confirming} onClose={() => !busyId && setConfirming(null)} style={{ maxWidth: 460, width: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)' }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 900, color: 'var(--text-h)' }}>Confirm check</h2>
          <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{confirming?.label}</p>
        </div>
        <div style={{ padding: 22, display: 'grid', gap: 12 }}>
          <div style={{ padding: '10px 12px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 5 }}>
              <Chip cfg={pretripResultCfg(confirming?.result)} />
              {confirming?.blocks && <span style={{ fontSize: 11.5, color: '#f87171', fontWeight: 700 }}>Blocks dispatch</span>}
            </div>
            <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)' }}>{confirming?.detail}</p>
          </div>

          {confirming?.blocks && (
            <p style={{ margin: 0, fontSize: 12, color: '#fbbf24' }}>
              Confirming records that you have seen this. It does not clear the block —
              resolve what it reports, then refresh the checks.
            </p>
          )}

          <div>
            <label className="label" style={{ display: 'block', marginBottom: 4 }}>Remarks (optional)</label>
            <textarea rows={3} value={remarks} onChange={(e) => setRemarks(e.target.value)}
              placeholder="What you checked, and anything worth recording."
              style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 9, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box', resize: 'vertical' }} />
          </div>
        </div>
        <div style={{ padding: '14px 22px', borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: 9 }}>
          <button onClick={() => setConfirming(null)} disabled={!!busyId} style={btn.base}>
            <X size={13} /> Cancel
          </button>
          <button onClick={() => confirm(confirming, remarks)} disabled={!!busyId} style={btn.primary}>
            {busyId ? <Loader2 size={13} className="animate-spin" /> : <CheckCircle2 size={13} />} Confirm check
          </button>
        </div>
      </Modal>
    </>
  )
}
