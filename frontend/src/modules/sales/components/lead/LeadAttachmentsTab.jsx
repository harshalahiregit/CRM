import { useState, useEffect, useRef, useCallback } from 'react'
import { Paperclip, Upload, Trash2, Download, FileText, Image as ImageIcon, Loader2 } from 'lucide-react'
import { leadEngagementApi } from '@/services/leadEngagementApi'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import { useToast } from '@/hooks/useToast'

const KB = 1024
const fmtSize = (b) => {
  if (!b) return '—'
  if (b < KB) return `${b} B`
  if (b < KB * KB) return `${(b / KB).toFixed(0)} KB`
  return `${(b / KB / KB).toFixed(1)} MB`
}
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

/**
 * Files attached to a lead — quotes received, spec sheets, signed scans.
 *
 * Supports drag-and-drop as well as the picker, since attaching is usually a
 * drag from a mail client. The server enforces the type allow-list and 20 MB cap;
 * the same limits are checked here only to fail fast with a clearer message.
 */
const MAX_BYTES = 20 * 1024 * 1024
const ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt,.png,.jpg,.jpeg,.gif,.webp,.zip'

export default function LeadAttachmentsTab({ leadId }) {
  const toast = useToast()
  const fileRef = useRef(null)
  const [rows, setRows] = useState(null)
  const [busy, setBusy] = useState(false)
  const [dragging, setDragging] = useState(false)
  const [confirmDel, setConfirmDel] = useState(null)

  const load = useCallback(() => {
    leadEngagementApi.attachments.list(leadId)
      .then(d => setRows(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => { toast.error(e.message); setRows([]) })
  }, [leadId])
  useEffect(() => { load() }, [load])

  const upload = async (files) => {
    const list = Array.from(files || [])
    if (!list.length) return
    setBusy(true)
    try {
      // Sequential, so one rejected file doesn't abort the rest and the error
      // names the file it belongs to.
      for (const file of list) {
        if (file.size > MAX_BYTES) { toast.error(`"${file.name}" is over 20 MB`); continue }
        await leadEngagementApi.attachments.upload(leadId, file)
      }
      toast.success(list.length > 1 ? `${list.length} files uploaded` : 'File uploaded')
      load()
    } catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }

  const remove = async () => {
    try {
      await leadEngagementApi.attachments.remove(leadId, confirmDel.id)
      toast.success('Attachment deleted'); setConfirmDel(null); load()
    } catch (e) { toast.error(e.message) }
  }

  const isImage = (m) => (m || '').startsWith('image/')

  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <div className="flex items-center justify-between mb-4">
        <h3 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <Paperclip size={14} style={{ color: 'var(--accent)' }} /> Attachments
          {!!rows?.length && <span className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>{rows.length}</span>}
        </h3>
        <button onClick={() => fileRef.current?.click()} disabled={busy}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold disabled:opacity-60"
          style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)', border: '1px solid var(--border)' }}>
          {busy ? <Loader2 size={12} className="animate-spin" /> : <Upload size={12} />} Upload
        </button>
        <input ref={fileRef} type="file" multiple accept={ACCEPT} className="hidden"
          onChange={e => { upload(e.target.files); e.target.value = '' }} />
      </div>

      {/* Drop zone — attaching is normally a drag out of an email client. */}
      <div
        onDragOver={e => { e.preventDefault(); setDragging(true) }}
        onDragLeave={() => setDragging(false)}
        onDrop={e => { e.preventDefault(); setDragging(false); upload(e.dataTransfer.files) }}
        className="rounded-xl text-center py-5 mb-4 transition-colors"
        style={{
          border: `1px dashed ${dragging ? 'var(--accent)' : 'var(--border)'}`,
          background: dragging ? 'rgba(124,58,237,0.06)' : 'var(--bg-input)',
        }}>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Drop files here, or use Upload. Max 20 MB each.
        </p>
      </div>

      {rows === null ? (
        <div className="skeleton h-16 rounded-xl" style={{ background: 'var(--border)' }} />
      ) : !rows.length ? (
        <EmptyState icon={Paperclip} title="No attachments" description="Files added to this lead will be listed here." />
      ) : (
        <div className="space-y-2">
          {rows.map(a => (
            <div key={a.id} className="flex items-center gap-3 px-3 py-2 rounded-xl"
              style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
              <div className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                style={{ background: 'rgba(124,58,237,0.1)' }}>
                {isImage(a.mime_type)
                  ? <ImageIcon size={14} style={{ color: '#a78bfa' }} />
                  : <FileText size={14} style={{ color: '#a78bfa' }} />}
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-xs font-bold truncate" style={{ color: 'var(--text-h)' }}>{a.file_name}</p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  {fmtSize(a.file_size)} · {a.uploader?.name || 'Staff'} · {fmtDate(a.created_at)}
                </p>
              </div>
              {a.url && (
                <a href={a.url} target="_blank" rel="noreferrer" download title="Download"
                  className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
                  style={{ border: '1px solid var(--border)' }}>
                  <Download size={12} style={{ color: 'var(--text-muted)' }} />
                </a>
              )}
              <button onClick={() => setConfirmDel(a)} title="Delete" className="flex-shrink-0">
                <Trash2 size={13} style={{ color: '#f87171' }} />
              </button>
            </div>
          ))}
        </div>
      )}

      {confirmDel && (
        <ConfirmDialog
          title="Delete this attachment?"
          message={`"${confirmDel.file_name}" will be permanently removed.`}
          confirmLabel="Delete" tone="danger"
          onCancel={() => setConfirmDel(null)} onConfirm={remove}
        />
      )}
    </div>
  )
}
