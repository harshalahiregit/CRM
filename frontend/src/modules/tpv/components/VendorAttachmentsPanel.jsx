import { useState, useEffect, useCallback, useRef } from 'react'
import {
  Folder, FolderPlus, File as FileIcon, FileText, FileImage, FileSpreadsheet, FileArchive,
  Upload, Download, Trash2, Pencil, ChevronRight, Home, Loader2, HardDrive, Cloud,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { openGoogleDrivePicker, googleDriveConfigured, googleDriveFullyConfigured } from '@/lib/googleDrivePicker'
import { openOneDrivePicker, oneDriveConfigured } from '@/lib/oneDrivePicker'
import { Overlay, ModalFooter, Field, TextInput } from '@/components/ui/kit3d'

/**
 * The vendor's file area — folders, uploads, and imports from Google Drive and
 * OneDrive.
 *
 * Distinct from the Documents tab, which is the statutory CHECKLIST: a fixed set
 * of named types the vendor supplies and an admin approves. This is the opposite —
 * free-form files in folders the team makes up as it goes, with no checklist and
 * no approval step.
 *
 * Drive and OneDrive are import SOURCES, not storage. Both pickers hand back real
 * File objects and those go through the same upload endpoint as a local file, so
 * the bytes are always on our disk and a file cannot vanish because someone
 * reorganised their personal cloud. The origin is recorded on the row.
 */

const ICON_BY_EXT = {
  pdf: FileText, doc: FileText, docx: FileText, txt: FileText, rtf: FileText,
  xls: FileSpreadsheet, xlsx: FileSpreadsheet, csv: FileSpreadsheet,
  png: FileImage, jpg: FileImage, jpeg: FileImage, gif: FileImage, webp: FileImage, svg: FileImage,
  zip: FileArchive, rar: FileArchive, '7z': FileArchive, tar: FileArchive, gz: FileArchive,
}
const iconFor = (name) => ICON_BY_EXT[String(name).split('.').pop()?.toLowerCase()] || FileIcon

const SOURCE_LABEL = { google_drive: 'Google Drive', onedrive: 'OneDrive', upload: null }

const fmtDate = (d) => (d ? new Date(d).toLocaleDateString() : '—')

/**
 * @param api  The vendor api group to read/write through — `tpvApi.vendors` by
 *             default, `purchaseApi.vendors` from the Purchase workspace. Both
 *             expose the same attachments shape because both sit on the same
 *             polymorphic tables; only the route prefix and role gate differ.
 */
export function VendorAttachments({ vendorId, manage, api = tpvApi.vendors }) {
  const [tree, setTree] = useState(null)
  const [folderId, setFolderId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState('')          // status line while importing/uploading
  const [creating, setCreating] = useState(false)
  const [renaming, setRenaming] = useState(null) // { kind:'file'|'folder', row }
  const [dragging, setDragging] = useState(false)
  const fileInput = useRef(null)

  const load = useCallback(() => {
    setLoading(true); setError(null)

    api.attachments.browse(vendorId, folderId)
      .then(setTree)
      .catch(e => setError(e?.response?.data?.message || 'Could not load files.'))
      .finally(() => setLoading(false))
  }, [vendorId, folderId])

  useEffect(() => { load() }, [load])

  /** One upload path for local files, Drive and OneDrive alike. */
  const uploadAll = async (files, source = 'upload') => {
    const list = Array.from(files || [])
    if (!list.length) return

    for (let i = 0; i < list.length; i++) {
      setBusy(`Uploading ${i + 1} of ${list.length}…`)
      try {
        await api.attachments.upload(vendorId, list[i], { folderId, source })
      } catch (e) {
        alert(`${list[i].name}: ${e?.response?.data?.message || 'upload failed'}`)
      }
    }
    setBusy(''); load()
  }

  const importFrom = async (provider) => {
    setBusy(provider === 'onedrive' ? 'Opening OneDrive…' : 'Opening Google Drive…')
    try {
      const files = provider === 'onedrive' ? await openOneDrivePicker() : await openGoogleDrivePicker()
      await uploadAll(files, provider === 'onedrive' ? 'onedrive' : 'google_drive')
    } catch (e) {
      setBusy('')
      alert(e?.message || 'Could not import the files.')
    }
  }

  const download = async (f) => {
    try {
      const blob = await api.attachments.download(vendorId, f.id)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url; a.download = f.name
      document.body.appendChild(a); a.click(); a.remove()
      URL.revokeObjectURL(url)
    } catch { alert('Could not download that file.') }
  }

  const removeFile = async (f) => {
    if (!window.confirm(`Delete “${f.name}”? This cannot be undone.`)) return
    try { await api.attachments.remove(vendorId, f.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not delete the file.') }
  }

  const removeFolder = async (d) => {
    const inside = (d.children_count || 0) + (d.files_count || 0)
    const warn = inside
      ? `“${d.name}” contains ${inside} item${inside === 1 ? '' : 's'}. Delete the folder and everything in it?`
      : `Delete the folder “${d.name}”?`
    if (!window.confirm(warn)) return

    try { await api.attachments.removeFolder(vendorId, d.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not delete the folder.') }
  }

  const onDrop = (e) => {
    e.preventDefault(); setDragging(false)
    if (manage) uploadAll(e.dataTransfer.files)
  }

  const folders = tree?.folders ?? []
  const files = tree?.files ?? []
  const crumbs = tree?.breadcrumbs ?? []
  const empty = !loading && !folders.length && !files.length

  return (
    <div
      onDragOver={e => { e.preventDefault(); if (manage) setDragging(true) }}
      onDragLeave={() => setDragging(false)}
      onDrop={onDrop}
      style={{
        borderRadius: 14, outline: dragging ? '2px dashed #7C3AED' : 'none',
        outlineOffset: 4, transition: 'outline-color .15s',
      }}
    >
      {creating && (
        <NameModal
          title="New Folder" label="Folder name" confirmLabel="Create Folder"
          onClose={() => setCreating(false)}
          onSave={async (name) => {
            await api.attachments.createFolder(vendorId, name, folderId)
            setCreating(false); load()
          }}
        />
      )}
      {renaming && (
        <NameModal
          title={renaming.kind === 'folder' ? 'Rename Folder' : 'Rename File'}
          label="Name" confirmLabel="Save" initial={renaming.row.name}
          onClose={() => setRenaming(null)}
          onSave={async (name) => {
            renaming.kind === 'folder'
              ? await api.attachments.renameFolder(vendorId, renaming.row.id, name)
              : await api.attachments.rename(vendorId, renaming.row.id, name)
            setRenaming(null); load()
          }}
        />
      )}

      {/* Toolbar — breadcrumb on the left, the ways in on the right */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
        <Breadcrumbs crumbs={crumbs} onGo={setFolderId} />

        <div style={{ marginLeft: 'auto', display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {manage && (
            <>
              <input ref={fileInput} type="file" multiple hidden
                onChange={e => { uploadAll(e.target.files); e.target.value = '' }} />

              <Btn onClick={() => setCreating(true)} icon={FolderPlus}>New Folder</Btn>
              <Btn onClick={() => fileInput.current?.click()} icon={Upload} primary>Upload</Btn>

              {/* Import sources. Enabled as soon as sign-in is possible — the
                  client id alone reaches the provider's login screen, and the
                  remaining setup is reported at the step that needs it. Greyed
                  with a reason when nothing is configured, rather than hidden:
                  "where is Drive?" is a worse question than "why is it off?". */}
              <Btn
                onClick={() => importFrom('google_drive')} icon={HardDrive}
                disabled={!googleDriveConfigured}
                title={
                  !googleDriveConfigured ? 'Set VITE_GOOGLE_CLIENT_ID to enable Google sign-in'
                    : !googleDriveFullyConfigured ? 'Sign-in works; set VITE_GOOGLE_API_KEY to finish enabling the picker'
                    : 'Import from Google Drive'
                }
              >Google Drive</Btn>

              <Btn
                onClick={() => importFrom('onedrive')} icon={Cloud}
                disabled={!oneDriveConfigured}
                title={oneDriveConfigured ? 'Import from OneDrive' : 'Set VITE_MS_CLIENT_ID to enable OneDrive sign-in'}
              >OneDrive</Btn>
            </>
          )}
        </div>
      </div>

      {busy && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12, fontSize: 12.5, color: 'var(--text-muted)' }}>
          <Loader2 size={13} className="rfq-spin" /> {busy}
        </div>
      )}
      {error && (
        <div style={{ padding: '9px 12px', borderRadius: 10, marginBottom: 12, fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>
          {error}
        </div>
      )}

      <div className="pr-glass" style={{ borderRadius: 14, overflow: 'hidden' }}>
        {loading ? (
          <Row muted><Loader2 size={14} className="rfq-spin" /> Loading…</Row>
        ) : empty ? (
          <div style={{ padding: '44px 24px', textAlign: 'center' }}>
            <Folder size={26} strokeWidth={1.7} style={{ color: 'var(--text-muted)', marginBottom: 10 }} />
            <p style={{ margin: 0, fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)' }}>
              {crumbs.length ? 'This folder is empty' : 'No files yet'}
            </p>
            <p style={{ margin: '6px auto 0', maxWidth: 420, fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.6 }}>
              {manage
                ? 'Drag files here, or use Upload, Google Drive or OneDrive. Folders keep it organised.'
                : 'Nothing has been filed here yet.'}
            </p>
          </div>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr>
                {['Name', 'Source', 'Size', 'Added by', 'Added', ''].map((h, i) => (
                  <th key={h} style={{
                    padding: '9px 14px', textAlign: i === 2 ? 'right' : 'left', whiteSpace: 'nowrap',
                    fontSize: 10, fontWeight: 700, letterSpacing: '0.05em', textTransform: 'uppercase',
                    color: 'var(--text-muted)', borderBottom: '1px solid var(--border)',
                  }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {/* Folders first, the way every file manager orders them. */}
              {folders.map(d => (
                <tr key={`d${d.id}`} onClick={() => setFolderId(d.id)}
                  style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                  <td style={{ padding: '11px 14px' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 9, fontWeight: 700, color: 'var(--text-h)' }}>
                      <Folder size={15} style={{ color: '#f59e0b' }} /> {d.name}
                    </span>
                  </td>
                  <td colSpan={3} style={{ padding: '11px 14px', color: 'var(--text-muted)', fontSize: 12 }}>
                    {(d.children_count || 0) + (d.files_count || 0)} item
                    {((d.children_count || 0) + (d.files_count || 0)) === 1 ? '' : 's'}
                  </td>
                  <td style={{ padding: '11px 14px', color: 'var(--text-muted)' }}>{fmtDate(d.created_at)}</td>
                  <td style={{ padding: '11px 14px', textAlign: 'right' }} onClick={e => e.stopPropagation()}>
                    {manage && (
                      <span style={{ display: 'inline-flex', gap: 6 }}>
                        <IconBtn onClick={() => setRenaming({ kind: 'folder', row: d })} icon={Pencil} title="Rename" />
                        <IconBtn onClick={() => removeFolder(d)} icon={Trash2} title="Delete" danger />
                      </span>
                    )}
                  </td>
                </tr>
              ))}

              {files.map(f => {
                const Icon = iconFor(f.name)
                return (
                  <tr key={`f${f.id}`} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '11px 14px' }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 9, color: 'var(--text-h)' }}>
                        <Icon size={15} style={{ color: '#94a3b8' }} /> {f.name}
                      </span>
                    </td>
                    <td style={{ padding: '11px 14px', color: 'var(--text-muted)', fontSize: 12 }}>
                      {SOURCE_LABEL[f.source] || '—'}
                    </td>
                    <td style={{ padding: '11px 14px', textAlign: 'right', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>
                      {f.size_label}
                    </td>
                    <td style={{ padding: '11px 14px', color: 'var(--text-muted)' }}>{f.uploader?.name || '—'}</td>
                    <td style={{ padding: '11px 14px', color: 'var(--text-muted)' }}>{fmtDate(f.created_at)}</td>
                    <td style={{ padding: '11px 14px', textAlign: 'right' }}>
                      <span style={{ display: 'inline-flex', gap: 6 }}>
                        <IconBtn onClick={() => download(f)} icon={Download} title="Download" />
                        {manage && <IconBtn onClick={() => setRenaming({ kind: 'file', row: f })} icon={Pencil} title="Rename" />}
                        {manage && <IconBtn onClick={() => removeFile(f)} icon={Trash2} title="Delete" danger />}
                      </span>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

function Breadcrumbs({ crumbs, onGo }) {
  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4, flexWrap: 'wrap', fontSize: 12.5 }}>
      <button type="button" onClick={() => onGo(null)}
        style={{ display: 'inline-flex', alignItems: 'center', gap: 5, border: 'none', background: 'none', cursor: 'pointer', color: crumbs.length ? 'var(--text-muted)' : 'var(--text-h)', fontWeight: 700, fontSize: 12.5, padding: 0 }}>
        <Home size={13} /> Files
      </button>
      {crumbs.map((c, i) => (
        <span key={c.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
          <ChevronRight size={12} style={{ color: 'var(--text-muted)' }} />
          <button type="button" onClick={() => onGo(c.id)}
            style={{ border: 'none', background: 'none', cursor: 'pointer', padding: 0, fontSize: 12.5, fontWeight: 700, color: i === crumbs.length - 1 ? 'var(--text-h)' : 'var(--text-muted)' }}>
            {c.name}
          </button>
        </span>
      ))}
    </div>
  )
}

function NameModal({ title, label, confirmLabel, initial = '', onClose, onSave }) {
  const [name, setName] = useState(initial)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const save = async () => {
    if (!name.trim()) { setErr('A name is required.'); return }
    setBusy(true); setErr('')
    try { await onSave(name.trim()) }
    catch (e) { setErr(e?.response?.data?.message || 'Could not save.'); setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={480}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 14px', fontSize: 17, fontWeight: 800 }}>{title}</h2>
      {err && (
        <div style={{ padding: '9px 12px', borderRadius: 10, marginBottom: 12, fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>
          {err}
        </div>
      )}
      <Field label={label}>
        <TextInput value={name} onChange={e => setName(e.target.value)} placeholder="e.g. Insurance 2026" autoFocus />
      </Field>
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} disabled={!name.trim()} confirmLabel={confirmLabel} />
    </Overlay>
  )
}

const Btn = ({ onClick, icon: Icon, children, primary, disabled, title }) => (
  <button type="button" onClick={onClick} disabled={disabled} title={title}
    style={{
      display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10,
      border: primary ? 'none' : '1px solid var(--border)',
      background: primary ? 'linear-gradient(135deg,#7C3AED,#6d28d9)' : 'var(--bg-card)',
      color: primary ? '#fff' : 'var(--text-h)', fontWeight: 700, fontSize: 12.5,
      cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.45 : 1,
    }}>
    <Icon size={14} /> {children}
  </button>
)

const IconBtn = ({ onClick, icon: Icon, title, danger }) => (
  <button type="button" onClick={onClick} title={title}
    style={{
      border: '1px solid var(--border)', background: 'var(--bg-card)',
      color: danger ? '#ef4444' : 'var(--text-muted)',
      borderRadius: 8, padding: '4px 7px', cursor: 'pointer',
    }}>
    <Icon size={13} />
  </button>
)

const Row = ({ children, muted }) => (
  <div style={{ padding: '16px 18px', display: 'flex', alignItems: 'center', gap: 8, color: muted ? 'var(--text-muted)' : 'var(--text-h)', fontSize: 13 }}>
    {children}
  </div>
)
