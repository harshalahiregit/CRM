import { useState } from 'react'
import { Trash2 } from 'lucide-react'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

/**
 * Trash-icon button that asks for confirmation (via the shared ConfirmDialog)
 * before firing onConfirm. Used for the customer profile sub-resource deletes.
 */
export default function ConfirmIconButton({ onConfirm, title = 'Delete?', message = 'This action cannot be undone.' }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button onClick={() => setOpen(true)} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)] transition-colors" title="Delete">
        <Trash2 size={13} style={{ color: '#f87171' }} />
      </button>
      {open && (
        <ConfirmDialog
          title={title}
          message={message}
          confirmLabel="Delete"
          onConfirm={() => { setOpen(false); onConfirm() }}
          onCancel={() => setOpen(false)}
        />
      )}
    </>
  )
}
