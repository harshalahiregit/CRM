import { useQuery } from '@tanstack/react-query'
import { statusApi } from '@/services/statusApi'
import { TASK_STATUS } from '@/services/taskApi'
import { PROJECT_STATUS } from '@/services/projectApi'

/**
 * Tenant-configured statuses for 'task' | 'project', shaped like the old
 * hardcoded TASK_STATUS/PROJECT_STATUS maps so every dropdown can consume them
 * the same way. Falls back to the hardcoded map while the request is in flight,
 * so nothing renders blank on first paint.
 *
 * Returns { map, list, isLoading } where map is { key: {label, color, ...} }.
 */
const FALLBACK = { task: TASK_STATUS, project: PROJECT_STATUS }

export function useStatuses(type) {
  const { data, isLoading } = useQuery({
    queryKey: ['statuses', type],
    queryFn: () => statusApi.list(type),
    staleTime: 5 * 60 * 1000,   // statuses change rarely — don't refetch on every mount
  })

  if (!data) {
    const map = FALLBACK[type] || {}
    return {
      isLoading,
      map,
      list: Object.entries(map).map(([key, m]) => ({ key, name: m.label, color: m.color })),
    }
  }

  const map = {}
  for (const s of data) {
    map[s.key] = { label: s.name, color: s.color, is_closed_status: s.is_closed_status, hidden_for: s.hidden_for }
  }
  return { isLoading, map, list: data }
}

/** Options for a <Select>, honouring hidden_for against the current role. */
export function statusOptions(list, role) {
  return list
    .filter(s => !(s.hidden_for || []).includes(role))
    .map(s => ({ value: s.key, label: s.name ?? s.label, dot: s.color }))
}
