import { useState } from 'react'
import { LifeBuoy } from 'lucide-react'
import RaiseTicketModal from '@/modules/helpdesk/components/RaiseTicketModal'

/**
 * A drop-in "Raise Ticket" button for any module. It owns its open state and
 * renders the shared RaiseTicketModal, so a module gets the full helpdesk flow
 * (acknowledgement email, ticket-manager notifications, standard visibility)
 * with a single line: <RaiseTicketButton source="hr" />.
 *
 * `source` stamps the ticket's origin module so the ticket grid badges where it
 * came from. Everything else (project/customer linking, requester fields) is
 * handled by the modal.
 */
export default function RaiseTicketButton({ source, label = 'Raise Ticket', className = '', style, onCreated }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className={`flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-bold transition-opacity hover:opacity-90 ${className}`}
        style={{ background: 'var(--color-support-500)', color: '#fff', ...style }}
      >
        <LifeBuoy size={15} /> {label}
      </button>
      <RaiseTicketModal open={open} onClose={() => setOpen(false)} source={source} onCreated={onCreated} />
    </>
  )
}
