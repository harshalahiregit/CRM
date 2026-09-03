import { useNavigate, useLocation } from 'react-router-dom'
import NotificationToaster from '@/components/notifications/NotificationToaster'
import { portalLink, portalBase } from './portalLink'

/**
 * On-screen notification pop-ups for a vendor portal. Driven by the shared feed
 * (see useNotificationFeed) so it does not poll separately from the bell.
 */
export default function PortalNotificationToaster({ feed }) {
  const navigate = useNavigate()
  const location = useLocation()
  if (!feed) return null

  const base = portalBase(location.pathname)
  const onView = (n) => { feed.markRead(n.id); const to = portalLink(n.link, base); if (to) navigate(to) }
  const onDismiss = (n) => { feed.markRead(n.id) }

  return (
    <NotificationToaster
      items={feed.items}
      loaded={feed.loaded}
      scope="portal"
      onView={onView}
      onDismiss={onDismiss}
    />
  )
}
