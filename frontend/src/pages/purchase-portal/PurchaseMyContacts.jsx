import PortalContacts from '@/pages/vendor-portal/PortalContacts'
import { purchasePortalApi } from '@/services/purchasePortalApi'

/** Purchase portal — My Contacts. Purchase contacts use first/last name + more. */
const FIELDS = [
  { key: 'first_name', label: 'First Name', required: true },
  { key: 'last_name', label: 'Last Name' },
  { key: 'designation', label: 'Designation' },
  { key: 'department', label: 'Department' },
  { key: 'email', label: 'Email', type: 'email', required: true },
  { key: 'phone', label: 'Phone', required: true },
  { key: 'mobile', label: 'Mobile' },
]

export default function PurchaseMyContacts() {
  return <PortalContacts api={purchasePortalApi} fields={FIELDS} nameOf={c => [c.first_name, c.last_name].filter(Boolean).join(' ')} />
}
