import { useLocation } from 'react-router-dom'
import { kickoffApi } from '@/services/kickoffApi'
import { purchaseKickoffApi } from '@/services/purchaseKickoffApi'

/**
 * Meeting-module context — the same idea as useVendorModule(), for the meeting
 * engine.
 *
 * There are two meeting engines on separate tables: the shared one
 * (kickoff_meetings + kickoff_mom_items + meeting_issues, reached at /kickoff)
 * and Purchase's (purchase_kickoff_meetings + purchase_mom_*, reached at
 * /purchase/kickoff). They hold DIFFERENT companies with unrelated ids, so
 * which one a page talks to has to follow the route, not a default.
 *
 * The two APIs expose the same method names with the same parameters, and the
 * two modules' status vocabularies were verified identical
 * (Open/In_Progress/Resolved/Closed/Reopened/Cancelled for issues;
 * Open/In_Progress/Pending_Verification/Closed/Reopened/Cancelled for actions),
 * so a page written against one renders the other unchanged.
 *
 *   key   : 'shared' | 'purchase'
 *   api   : the meeting api client
 *   base  : route base for links back into the module
 *   label : user-facing module name
 */
export function useMeetingModule() {
  const { pathname } = useLocation()

  if (pathname.startsWith('/app/purchase')) {
    return {
      key: 'purchase',
      // The adapter, not purchaseApi.kickoff directly — it presents Purchase's
      // engine under the shared method names and reconciles three argument
      // shapes that differ between the two clients.
      api: purchaseKickoffApi,
      base: '/app/purchase',
      label: 'Purchase',
      // Purchase meetings are scoped to a vendor and carry no project link, so
      // the project filter is hidden rather than shown permanently empty.
      hasProjects: false,
      listPath: '/app/purchase/kickoff',
      newPath: '/app/purchase/kickoff/new',
      meetingPath: (id) => `/app/purchase/kickoff/${id}`,
      editPath: (id) => `/app/purchase/kickoff/${id}/edit`,
    }
  }

  // The company-wide module. Same engine, same screens, no vendor module in the
  // way -- an HR catch-up has no business being scheduled from inside TPV.
  // Returned before the TPV default so /app/meetings keeps its own links; without
  // this branch every link on the page threw the user back to /app/tpv/kickoff.
  if (pathname.startsWith('/app/meetings')) {
    return {
      key: 'meetings',
      api: kickoffApi,
      base: '/app',
      label: 'Meetings',
      hasProjects: true,
      listPath: '/app/meetings',
      newPath: '/app/meetings/new',
      meetingPath: (id) => `/app/meetings/${id}`,
      editPath: (id) => `/app/meetings/${id}/edit`,
    }
  }

  return {
    key: 'shared',
    api: kickoffApi,
    base: '/app/tpv',
    label: 'Meetings',
    hasProjects: true,
    listPath: '/app/tpv/kickoff',
    newPath: '/app/tpv/kickoff/new',
    meetingPath: (id) => `/app/tpv/kickoff/${id}`,
    editPath: (id) => `/app/tpv/kickoff/${id}/edit`,
  }
}
