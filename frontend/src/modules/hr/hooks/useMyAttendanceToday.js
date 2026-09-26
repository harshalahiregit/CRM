import { useQuery, useQueryClient } from '@tanstack/react-query'
import { hrApi } from '@/services/hrApi'

const KEY = ['hr', 'attendance', 'me', 'today']

/**
 * Today's own attendance, fetched once and shared.
 *
 * Two widgets read this: HeaderPunch, which sits in Header.jsx and is therefore
 * on every page, and MyAttendanceCard on the dashboard. Each had its own
 * useState + useEffect calling hrApi.attendance.me.today() directly, so the
 * dashboard made the request twice, and every navigation made it again. For the
 * many logins with no employee record that meant a 403 in the browser console on
 * every page load — harmless, reported by Dev 2, and pure noise.
 *
 * One query key fixes all of it:
 *   · both widgets share a single in-flight request and a single cache entry
 *   · a 403 is cached like any other answer, so it is asked once per session
 *     rather than on every route change
 *   · retry is off — "you have no employee record" is not a transient failure
 *     and retrying it just triples the console noise
 *
 * It also fixes something nobody reported: a punch from the header now refreshes
 * the dashboard card, because they are reading the same cache rather than two
 * private copies that could disagree until the page was reloaded.
 *
 * ── THE 403 IS A STATE, NOT AN ERROR ─────────────────────────────────────────
 * Until the identity backfill runs, most logins have no HR employee record. Both
 * widgets already treated that as a quiet state; this keeps that contract and
 * hands it back as `unlinked` so neither has to inspect an axios error itself.
 */
export function useMyAttendanceToday({ enabled = true } = {}) {
  const q = useQuery({
    queryKey: KEY,
    enabled,
    retry: false,

    /*
     | ── THE SAME SHIFT, WHEREVER IT WAS PUNCHED ─────────────────────────────
     |
     | A punch can arrive from three places: the header pill, the attendance card
     | on either dashboard, or the SangoeTrack phone app. All of them move ONE
     | row — hr_attendance is unique on (tenant, employee, date) — so a browser
     | showing yesterday's idea of that row is showing something that is simply
     | not true any more.
     |
     | The header and both dashboards share this query key, so a punch from any
     | of them updates the others the moment it lands. The phone cannot do that;
     | it writes straight to the API and the browser is never told. These three
     | settings are what close that gap, and they override the app-wide defaults
     | in main.jsx (staleTime 5 minutes, refetchOnWindowFocus false) which are
     | right for a customer list and wrong for a running shift.
     |
     | There are no websockets here — BROADCAST_CONNECTION is `log` and the
     | frontend has no Echo client — so this is as close to live as the stack
     | honestly gets. It is one small GET; the cost is not the concern, showing a
     | stale Clock-in button to somebody already clocked in is.
     */
    staleTime: 15_000,

    // Punched on the phone, then switched back to the browser: the answer is
    // correct by the time the tab is looked at.
    refetchOnWindowFocus: true,

    // And without switching tabs, for a dashboard left open on a second screen.
    // react-query pauses this while the tab is hidden, which is why there is no
    // refetchIntervalInBackground here — a background tab polling attendance is
    // work nobody asked for.
    refetchInterval: 30_000,

    queryFn: async () => {
      const res = await hrApi.attendance.me.today()
      return res.data
    },
  })

  const status = q.error?.response?.status
  const unlinked = status === 403

  return {
    data: q.data ?? null,
    loading: q.isLoading,
    unlinked,
    // A real failure only. The 403 is reported through `unlinked` instead, so a
    // caller cannot accidentally render "Could not load" for a normal state.
    error: q.isError && ! unlinked
      ? (q.error?.response?.data?.message || 'Could not load your attendance.')
      : null,
    // 403 carries its own sentence — "ask HR to link your login" — which is more
    // use to the reader than anything this layer could invent.
    unlinkedMessage: unlinked ? (q.error?.response?.data?.message || null) : null,
  }
}

/** Refresh both widgets after a punch. */
export function useRefreshMyAttendanceToday() {
  const client = useQueryClient()

  return () => client.invalidateQueries({ queryKey: KEY })
}
