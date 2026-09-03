import { useState, useEffect, useCallback } from 'react'

/**
 * One polling notification feed for a vendor portal, shared by the header bell
 * AND the on-screen toaster so there is a single request every `intervalMs`.
 * `api` is the portal's notifications service ({ list, markRead, markAllRead }).
 */
export function useNotificationFeed(api, intervalMs = 30000) {
  const [items, setItems] = useState([])
  const [unread, setUnread] = useState(0)
  const [loaded, setLoaded] = useState(false)

  const refetch = useCallback(() => {
    if (!api?.list) return
    api.list()
      .then(d => { setItems(d?.items ?? []); setUnread(d?.unread_count ?? 0); setLoaded(true) })
      .catch(() => {})
  }, [api])

  useEffect(() => {
    if (!api?.list) return
    refetch()
    const t = setInterval(refetch, intervalMs)
    return () => clearInterval(t)
  }, [api, refetch, intervalMs])

  const markRead = useCallback(async (id) => {
    try { await api.markRead(id) } catch { /* best-effort */ }
    refetch()
  }, [api, refetch])

  const markAllRead = useCallback(async () => {
    try { await api.markAllRead() } catch { /* best-effort */ }
    refetch()
  }, [api, refetch])

  return { items, unread_count: unread, loaded, refetch, markRead, markAllRead }
}
