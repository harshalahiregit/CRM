import { useState, useEffect, useCallback } from 'react'
import { BellRing, CheckCircle2, Trash2 } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { SectionTable } from './VendorSectionTable'
import {
  Overlay, ModalFooter, Field, TextInput, SelectInput, labelStyle, inputStyle,
} from '@/components/ui/kit3d'

/**
 * Follow-ups against this vendor.
 *
 * The `reminders` table is polymorphic (remindable_type/remindable_id), so this is
 * the EXISTING reminder engine pointed at a vendor — not a TPV-side reminder
 * store. Same table, same complete/delete rules Sales uses.
 *
 * It reads /tpv/vendors/{id}/reminders rather than /sales/reminders because that
 * Sales group carries no role gate; the vendor-scoped routes sit behind
 * role:admin,staff like the rest of this screen.
 */

const TYPES = [
  ['call', 'Call'], ['meeting', 'Meeting'], ['email', 'Email'],
  ['whatsapp', 'WhatsApp'], ['sms', 'SMS'], ['visit', 'Visit'],
]
const PRIORITIES = [['low', 'Low'], ['medium', 'Medium'], ['high', 'High']]
const PRIORITY_COLOR = { low: '#94a3b8', medium: '#f59e0b', high: '#ef4444' }

const fmt = (d) => (d ? new Date(d).toLocaleString(undefined, {
  day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
}) : '—')

/** Local midnight — an overdue check must not turn on the clock, only the date. */
const isOverdue = (r) => !r.completed_at && r.due_at && new Date(r.due_at) < new Date()

function Pill({ tone, children }) {
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 5, padding: '2px 9px', borderRadius: 20,
      fontSize: 11, fontWeight: 700, textTransform: 'capitalize',
      color: tone, background: `color-mix(in srgb, ${tone} 12%, transparent)`,
    }}>{children}</span>
  )
}

/**
 * @param api  The vendor api group to read/write through — `tpvApi.vendors` by
 *             default, `purchaseApi.vendors` from the Purchase workspace. Both
 *             expose the same reminders shape because both sit on the same
 *             polymorphic table; only the route prefix and role gate differ.
 */
export function VendorReminders({ vendorId, vendorName, manage, api = tpvApi.vendors }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [adding, setAdding] = useState(false)

  const load = useCallback(() => {
    setLoading(true); setError(null)

    api.reminders.list(vendorId)
      .then(d => setRows(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => setError(e?.response?.data?.message || 'Could not load reminders.'))
      .finally(() => setLoading(false))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  const complete = async (r) => {
    if (!window.confirm(`Mark “${r.title}” as done?`)) return
    try { await api.reminders.complete(vendorId, r.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not complete the reminder.') }
  }

  const remove = async (r) => {
    if (!window.confirm(`Delete “${r.title}”?`)) return
    try { await api.reminders.remove(vendorId, r.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not delete the reminder.') }
  }

  return (
    <>
      {adding && (
        <ReminderModal
          vendorId={vendorId} vendorName={vendorName} api={api}
          onClose={() => setAdding(false)}
          onSaved={() => { setAdding(false); load() }}
        />
      )}
      <SectionTable
        icon={BellRing} title="Reminders" loading={loading} error={error} rows={rows}
        hint="Follow-ups against this vendor. Open ones are listed first."
        onAdd={manage ? () => setAdding(true) : undefined} addLabel="Add Reminder"
        searchFields={['title', 'type', 'notes']}
        columns={[
          {
            key: 'title', label: 'Reminder',
            render: r => (
              <span style={{ fontWeight: 700, textDecoration: r.completed_at ? 'line-through' : 'none', opacity: r.completed_at ? 0.6 : 1 }}>
                {r.title}
              </span>
            ),
          },
          { key: 'type', label: 'Type', render: r => <span style={{ textTransform: 'capitalize' }}>{r.type}</span> },
          {
            key: 'priority', label: 'Priority',
            render: r => <Pill tone={PRIORITY_COLOR[r.priority] || '#94a3b8'}>{r.priority || 'medium'}</Pill>,
          },
          {
            key: 'due_at', label: 'Due',
            render: r => (
              <span style={{ color: isOverdue(r) ? '#ef4444' : 'var(--text-muted)', fontWeight: isOverdue(r) ? 700 : 400 }}>
                {fmt(r.due_at)}{isOverdue(r) ? ' · overdue' : ''}
              </span>
            ),
          },
          { key: 'assignee', label: 'Assigned', render: r => r.assignee?.name || '—' },
          {
            key: 'status', label: 'Status',
            render: r => r.completed_at
              ? <Pill tone="#0ca30c">Done</Pill>
              : <Pill tone="#f59e0b">Open</Pill>,
          },
          {
            key: 'actions', label: '',
            render: r => (
              <span style={{ display: 'inline-flex', gap: 6 }} onClick={e => e.stopPropagation()}>
                {manage && !r.completed_at && (
                  <button type="button" onClick={() => complete(r)} title="Mark done"
                    style={{ border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#0ca30c', borderRadius: 8, padding: '4px 7px', cursor: 'pointer' }}>
                    <CheckCircle2 size={13} />
                  </button>
                )}
                {manage && (
                  <button type="button" onClick={() => remove(r)} title="Delete"
                    style={{ border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#ef4444', borderRadius: 8, padding: '4px 7px', cursor: 'pointer' }}>
                    <Trash2 size={13} />
                  </button>
                )}
              </span>
            ),
          },
        ]}
      />
    </>
  )
}

/** Create form. Mirrors the reminder fields Sales exposes, minus the subject. */
function ReminderModal({ vendorId, vendorName, onClose, onSaved, api }) {
  const [f, setF] = useState({ type: 'call', title: '', due_at: '', priority: 'medium', notes: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.title.trim()) { setErr('A title is required.'); return }
    if (!f.due_at) { setErr('A due date is required.'); return }

    setBusy(true); setErr('')
    try {
      await api.reminders.create(vendorId, f)
      onSaved()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save the reminder.')
    } finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={620}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>New Reminder</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        Follow-up for <strong style={{ color: 'var(--text-h)' }}>{vendorName}</strong>.
      </p>

      {err && (
        <div style={{ padding: '9px 12px', borderRadius: 10, marginBottom: 12, fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>
          {err}
        </div>
      )}

      <Field label="Title"><TextInput value={f.title} onChange={set('title')} placeholder="e.g. Chase renewed insurance certificate" /></Field>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Type">
          <SelectInput value={f.type} onChange={set('type')} pairs options={TYPES} />
        </Field>
        <Field label="Priority">
          <SelectInput value={f.priority} onChange={set('priority')} pairs options={PRIORITIES} />
        </Field>
      </div>

      <Field label="Due">
        <input type="datetime-local" value={f.due_at} onChange={set('due_at')} style={inputStyle} />
      </Field>

      <Field label="Notes" full>
        <textarea value={f.notes} onChange={set('notes')} rows={3} placeholder="Optional detail…"
          style={{ ...inputStyle, resize: 'vertical' }} />
      </Field>

      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} disabled={!f.title.trim() || !f.due_at} confirmLabel="Add Reminder" />
    </Overlay>
  )
}
