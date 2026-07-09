import { useQuery } from '@tanstack/react-query'

/**
 * Generic fetch-with-cache hook for ad-hoc endpoints that don't
 * warrant their own resource-specific hook file.
 *
 *   const { data, isLoading, error } = useApiQuery(['items', id], () => itemApi.get(id))
 */
export function useApiQuery(queryKey, queryFn, options = {}) {
  return useQuery({ queryKey, queryFn, ...options })
}
