import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { notificationApi } from '@/services/notificationApi'
import NotificationToaster from './NotificationToaster'

/**
 * Main-app (staff/admin) notification pop-ups. Shares the React Query cache with
 * the header bell (same ['notifications'] key) so there is a single 30s poll for
 * both. Mount once in the app chrome.
 */
export default function AppNotificationToaster() {
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { data } = useQuery({
    queryKey: ['notifications'],
    queryFn: notificationApi.list,
    refetchInterval: 30000,
    refetchOnWindowFocus: true,
  })

  const markRead = useMutation({
    mutationFn: (id) => notificationApi.markRead(id),
    onSettled: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const onView = (n) => { markRead.mutate(n.id); if (n.link) navigate(n.link) }
  const onDismiss = (n) => { markRead.mutate(n.id) }

  return (
    <NotificationToaster
      items={data?.items || []}
      loaded={data !== undefined}
      scope="app"
      onView={onView}
      onDismiss={onDismiss}
    />
  )
}
