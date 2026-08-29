import PortalKb from '@/pages/vendor-portal/PortalKb'
import { purchasePortalApi } from '@/services/purchasePortalApi'

/** Purchase portal — Knowledge Base (tenant-published, read-only). */
export default function PurchaseMyKb() {
  return <PortalKb loadList={() => purchasePortalApi.kb.list()} loadArticle={(slug) => purchasePortalApi.kb.article(slug)} />
}
