import { useLocation } from 'react-router-dom'
import { kickoffApi } from '@/services/kickoffApi'
import { purchaseKickoffApi } from '@/services/purchaseKickoffApi'
import { meetingModuleFor } from './meetingModules'

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
 * WHICH module a path belongs to, and every link out of it, now comes from
 * meetingModules.js. This hook used to carry its own copy of that decision and
 * the API layer carried another, and the two drifted -- see the note in that
 * file. All this adds is the API client, which is the one thing a path table
 * has no business importing.
 *
 *   key   : 'shared' | 'purchase' | 'meetings'
 *   api   : the meeting api client
 *   base  : route base for links back into the module
 *   label : user-facing module name
 */
export function useMeetingModule() {
  const { pathname } = useLocation()
  const mod = meetingModuleFor(pathname)

  return {
    key: mod.key,
    // The adapter for Purchase, not purchaseApi.kickoff directly -- it presents
    // Purchase's engine under the shared method names and reconciles three
    // argument shapes that differ between the two clients.
    api: mod.key === 'purchase' ? purchaseKickoffApi : kickoffApi,
    base: mod.base,
    label: mod.label,
    hasProjects: mod.hasProjects,
    listPath: mod.list,
    newPath: mod.create,
    meetingPath: mod.detail,
    editPath: mod.edit,
    registersPath: mod.registers,
  }
}
