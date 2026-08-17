import { useState, useEffect, useCallback } from 'react'
import { StickyNote, Pencil, Trash2 } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { SectionTable } from './VendorSectionTable'
import {
  Overlay, ModalFooter, Field, TextInput, inputStyle,
} from '@/components/ui/kit3d'

/**
 * Internal notes about this vendor.
 *
 * Backed by the shared polymorphic `notes` table (notable_type/notable_id) rather
 * than a vendor-specific store, so the same engine serves any subject that needs
 * notes later — the shape `reminders` already uses in this codebase.
 *
 * Staff-only, like the rest of the vendor detail: the vendor never sees these.
 */

const fmt = (d) => (d ? new Date(d).toLocaleString(undefined, {
  day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
}) : '—')

/** Notes are stored as sanitised HTML; the list shows a flattened preview. */
const preview = (html, max = 90) => {
  const text = String(html || '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/gi, ' ').replace(/\s+/g, ' ').trim()
  if (! text) return '—'
  return text.length > max ? `${text.slice(0, max)}…` : text
}

/**
 * @param api  The vendor api group to read/write through — `tpvApi.vendors` by
 *             default, `purchaseApi.vendors` from the Purchase workspace. Both
 *             expose the same notes shape because both sit on the same
 *             polymorphic table; only the route prefix and role gate differ.
 */
export function VendorNotes({ vendorId, vendorName, manage, api = tpvApi.vendors }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [editing, setEditing] = useState(null)   // note object, or 'new'

  const load = useCallback(() => {
    setLoading(true); setError(null)

    api.notes.list(vendorId)
      .then(d => setRows(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => setError(e?.response?.data?.message || 'Could not load notes.'))
      .finally(() => setLoading(false))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  const remove = async (n) => {
    if (!window.confirm(`Delete the note “${n.title}”?`)) return
    try { await api.notes.remove(vendorId, n.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not delete the note.') }
  }

  return (
    <>
      {editing && (
        <NoteModal
          vendorId={vendorId} vendorName={vendorName} api={api}
          note={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); load() }}
        />
      )}
      <SectionTable
        icon={StickyNote} title="Notes" loading={loading} error={error} rows={rows}
        hint="Internal notes about this vendor. Not visible in the vendor portal."
        onAdd={manage ? () => setEditing('new') : undefined} addLabel="Add Note"
        searchFields={['title', 'content']}
        onRowClick={manage ? (n) => setEditing(n) : undefined}
        columns={[
          { key: 'title', label: 'Note', render: n => <span style={{ fontWeight: 700 }}>{n.title}</span> },
          { key: 'content', label: 'Preview', render: n => <span style={{ color: 'var(--text-muted)' }}>{preview(n.content)}</span> },
          { key: 'creator', label: 'Added by', render: n => n.creator?.name || '—' },
          { key: 'created_at', label: 'Added', render: n => fmt(n.created_at) },
          {
            key: 'actions', label: '',
            render: n => (
              <span style={{ display: 'inline-flex', gap: 6 }} onClick={e => e.stopPropagation()}>
                {manage && (
                  <button type="button" onClick={() => setEditing(n)} title="Edit"
                    style={{ border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', borderRadius: 8, padding: '4px 7px', cursor: 'pointer' }}>
                    <Pencil size={13} />
                  </button>
                )}
                {manage && (
                  <button type="button" onClick={() => remove(n)} title="Delete"
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

function NoteModal({ vendorId, vendorName, note, onClose, onSaved, api }) {
  const editing = Boolean(note)
  const [f, setF] = useState({ title: note?.title || '', content: note?.content || '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.title.trim()) { setErr('A title is required.'); return }

    setBusy(true); setErr('')
    try {
      editing
        ? await api.notes.update(vendorId, note.id, f)
        : await api.notes.create(vendorId, f)
      onSaved()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save the note.')
    } finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={620}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        {editing ? 'Edit Note' : 'New Note'}
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        Internal note for <strong style={{ color: 'var(--text-h)' }}>{vendorName}</strong>.
      </p>

      {err && (
        <div style={{ padding: '9px 12px', borderRadius: 10, marginBottom: 12, fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>
          {err}
        </div>
      )}

      <Field label="Title"><TextInput value={f.title} onChange={set('title')} placeholder="e.g. Site manager prefers morning deliveries" /></Field>

      <Field label="Note" full>
        <textarea value={f.content} onChange={set('content')} rows={6} placeholder="Write the note…"
          style={{ ...inputStyle, resize: 'vertical' }} />
      </Field>

      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} disabled={!f.title.trim()}
        confirmLabel={editing ? 'Save Note' : 'Add Note'} />
    </Overlay>
  )
}
