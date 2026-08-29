import PortalKb from './PortalKb'
import { portalApi } from '@/services/portalApi'

/** TPV portal — Knowledge Base (my-work/kb). */
export default function MyKb() {
  return <PortalKb loadList={() => portalApi.myWork.kb()} loadArticle={(slug) => portalApi.myWork.kbArticle(slug)} />
}
