import PortalContracts from '../PortalContracts'
import { clientContractApi } from '@/services/contractModuleApi'

/**
 * The Client portal's contracts page.
 *
 * A three-line wrapper on purpose: the page is shared, and only the api client
 * differs because each portal authenticates a different identity. The party is
 * resolved from that session server-side, so nothing here names an id.
 */
export default function ClientContractsPage() {
  return <PortalContracts api={clientContractApi} />
}
