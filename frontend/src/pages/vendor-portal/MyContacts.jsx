import PortalContacts from './PortalContacts'
import { portalApi } from '@/services/portalApi'

/** TPV portal — My Contacts. TPV contacts use a single `name` field. */
const FIELDS = [
  { key: 'name', label: 'Name', required: true },
  { key: 'designation', label: 'Designation' },
  { key: 'email', label: 'Email', type: 'email' },
  { key: 'phone', label: 'Phone' },
]

export default function MyContacts() {
  return <PortalContacts api={portalApi} fields={FIELDS} nameOf={c => c.name} />
}
