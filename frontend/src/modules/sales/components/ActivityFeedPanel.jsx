import { useApiQuery } from '@/hooks/useApiQuery'
import { activityApi } from '@/services/activityApi'
import ActivityTimeline from './ActivityTimeline'

/**
 * Reusable unified-activity panel. Fetches a subject's cross-entity timeline
 * from /sales/activities and renders it via the shared ActivityTimeline.
 * Maps the backend event shape → the timeline's {type,label,detail,date}.
 *
 *   <ActivityFeedPanel subjectType="lead" subjectId={lead.id} />
 */
export default function ActivityFeedPanel({ subjectType, subjectId, title = 'Activity' }) {
  const enabled = !!subjectType && !!subjectId
  const { data, isLoading, error } = useApiQuery(
    ['activity', subjectType, subjectId],
    () => activityApi.feed(subjectType, subjectId),
    { enabled },
  )

  const events = (data || []).map(e => ({
    type: e.type,
    label: e.description,
    detail: e.performed_by ? `by ${e.performed_by}` : null,
    date: e.date,
  }))

  return (
    <div className="card-3d">
      <p className="label-caps mb-3">{title}</p>
      {isLoading && <p className="text-xs text-center py-6" style={{ color: 'var(--text-muted)' }}>Loading activity…</p>}
      {error && <p className="text-xs text-center py-6" style={{ color: '#f87171' }}>{error.message}</p>}
      {!isLoading && !error && <ActivityTimeline events={events} />}
    </div>
  )
}
