/**
 * One slice of SangoeTrack's approval queue.
 *
 * All four queues — leaves, corrections, reimbursements, advances — arrive from
 * a single endpoint, so each screen fetches the lot and keeps its own slice.
 * That is one request per screen rather than four, and it means a decision made
 * on one screen is reflected everywhere on the next load without any shared
 * cache to go stale.
 *
 * PENDING ONLY. SangoeTrack has no endpoint that returns past decisions, so once
 * something is approved it leaves this queue for good. Screens must say that
 * out loud — an empty list otherwise reads as "nothing ever happened here"
 * rather than "nothing is waiting on you".
 */

import { useState, useEffect, useCallback } from 'react'
import { sangoeTrackApi, trackErrorMessage } from '@/services/sangoeTrackApi'

/** @param slice 'leaves' | 'raises' | 'reimbursements' | 'advances' */
export default function useTrackApprovals(slice) {
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await sangoeTrackApi.approvals.pending()
      const list = res?.data?.[slice]
      setRows(Array.isArray(list) ? list : [])
    } catch (err) {
      setError(trackErrorMessage(err, 'Could not reach SangoeTrack.'))
    } finally {
      setLoading(false)
    }
  }, [slice])

  useEffect(() => { load() }, [load])

  return { rows, loading, error, reload: load }
}
