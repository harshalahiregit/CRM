import { purchaseApi } from './purchaseApi'

const k = purchaseApi.kickoff

/**
 * Translate the shared meeting form's body into what Purchase's endpoint takes.
 *
 * The shared engine attaches a meeting to any allowlisted subject, so it sends
 * `subject_type` + `subject_id`. Purchase meetings are always about a Purchase
 * vendor and the endpoint REQUIRES `purchase_vendor_id` — posting the shared
 * shape unchanged is a 422 every time, which is what made this the one piece
 * that could not simply be renamed.
 *
 * Keys the shared form sends that Purchase has no column for (`subject_ids`,
 * `client_id`, `project_id`, `work_package`, `planned_date`) are dropped here
 * rather than posted and ignored, so the request says what it means.
 */
function toPurchaseMeeting(payload = {}) {
  const {
    subject_type, subject_id, subject_ids,
    client_id, project_id, work_package, planned_date, location_detail,
    attendees,
    ...rest
  } = payload

  return {
    ...rest,
    // The one required field, taken from whichever subject the form picked.
    purchase_vendor_id: subject_id ? Number(subject_id) : undefined,
    // Shared calls the roster "attendees"; Purchase calls it "participants",
    // and its contact link is a purchase_contact_id.
    participants: (attendees || []).map(({ vendor_contact_id, ...p }) => ({
      ...p,
      purchase_contact_id: vendor_contact_id || undefined,
    })),
    // Purchase has no separate location_detail column; fold it into location
    // so the text the user typed is not silently discarded.
    location: [rest.location, location_detail].filter(Boolean).join(' — ') || undefined,
  }
}

/**
 * Purchase meeting engine, presented under the SHARED engine's method names.
 *
 * The shared meeting screens (KickoffMeetings / KickoffMeetingCreate /
 * KickoffMeetingDetail — about 4,700 lines between them) are written against
 * `kickoffApi`. Purchase already implements nearly all of the same operations,
 * just under different names and a different nesting: the shared page asks for
 * `convertIssue(...)`, Purchase calls it `issues.convert(...)`; the shared page
 * says `schedule(...)`, Purchase says `create(...)`.
 *
 * This is that translation, and nothing else. It exists so the two modules run
 * ONE copy of the meeting UI instead of two that drift — the alternative was
 * duplicating those 4,700 lines and maintaining both.
 *
 * Everything here is a rename or a re-nesting of a call Purchase already had,
 * except where a comment says otherwise.
 */
export const purchaseKickoffApi = {
  /* ── Meetings ─────────────────────────────────────────────── */
  list:      k.list,
  stats:     k.stats,
  dashboard: k.dashboard,
  get:       k.get,
  // Shared calls it "schedule"; Purchase's endpoint is a plain create. Both
  // take the SAME body from the form, so both go through one translator.
  schedule:  (payload) => k.create(toPurchaseMeeting(payload)),
  update:    (id, payload) => k.update(id, toPurchaseMeeting(payload)),
  delete:    k.remove,
  transition: k.transition,
  // Shared: markAttendance. Purchase: attendance.
  markAttendance: k.attendance,
  remind:    k.remind,
  publish:   k.publish,
  history:   k.history,
  carryForward: k.carryForward,
  previousSummary: k.previousSummary,

  /* ── Minutes ──────────────────────────────────────────────── */
  uploadMom:   k.uploadMom,
  generateMom: k.generateMom,
  momBlob:     k.momBlob,
  momSubmit:   k.momSubmit,
  momDecide:   k.momDecide,
  momRevise:   k.momRevise,

  /* ── Documents ────────────────────────────────────────────── */
  documents:       k.documents,
  uploadDocuments: k.uploadDocuments,
  deleteDocument:  k.deleteDocument,
  documentBlob:    k.documentBlob,

  /* ── Agenda ───────────────────────────────────────────────── */
  agenda: k.agenda,

  /* ── Actions ──────────────────────────────────────────────────
   *
   * Three signature mismatches live here. The shared client passes ONE object
   * and builds the multipart body itself; Purchase's client takes the evidence
   * File as a separate fourth argument, and takes plain strings where the
   * shared one takes objects. Passing the shared shapes straight through would
   * not throw — it would post `{status: {status: 'Closed'}}` and quietly fail
   * validation — so each is unpacked explicitly.
   */
  progressAction: (meetingId, itemId, data = {}) => {
    // Shared puts the evidence File inside `data`; Purchase wants it separately.
    const { evidence, ...rest } = data
    return k.actions.progress(meetingId, itemId, rest, evidence)
  },
  actionEvidenceBlob: (meetingId, itemId) => k.actions.evidenceBlob(meetingId, itemId),
  pushActionTask:     (meetingId, itemId) => k.actions.pushTask(meetingId, itemId),

  /* ── Issues ───────────────────────────────────────────────── */
  // Shared sends {status}; Purchase's client takes the bare string and wraps it.
  progressIssue: (meetingId, issueId, data) =>
    k.issues.progress(meetingId, issueId, typeof data === 'string' ? data : data?.status),
  // Same again for {target}.
  convertIssue: (meetingId, issueId, data) =>
    k.issues.convert(meetingId, issueId, typeof data === 'string' ? data : data?.target),
  // The shared UI offers each escalation as its own button. Purchase takes one
  // endpoint with a `target`, so these four are the same call with the target
  // filled in — not four endpoints.
  convertIssueNcr:      (meetingId, issueId) => k.issues.convert(meetingId, issueId, 'ncr'),
  convertIssueCapa:     (meetingId, issueId) => k.issues.convert(meetingId, issueId, 'capa'),
  convertIssueApproval: (meetingId, issueId) => k.issues.convert(meetingId, issueId, 'approval'),
  convertIssueTask:     (meetingId, issueId) => k.issues.convert(meetingId, issueId, 'task'),

  /* ── Decisions ────────────────────────────────────────────── */
  decisions: k.decisions,

  /* ── Registers + pickers ──────────────────────────────────── */
  registers:    k.registers,
  staff:        k.staff,
  vendors:      k.vendors,
  vendorStatus: k.vendorStatus,
  meetingTypes: k.meetingTypes,
  // Subjects the shared engine supports and Purchase does not. The endpoints
  // answer with an empty list rather than 404, so the shared form's pickers
  // render empty instead of erroring on a screen that is working correctly.
  projects:     k.projects,
  customers:    k.customers,

  /* ── Meeting-type settings ────────────────────────────────── */
  typeSettings: k.typeSettings,
  createType:   k.createType,
  updateType:   k.updateType,
  deleteType:   k.deleteType,

  /* ── AI ───────────────────────────────────────────────────── */
  aiSuggestAgenda: k.aiSuggestAgenda,
  aiSummary:       k.aiSummary,

  /* ── Not built on the Purchase side ───────────────────────────
   *
   * invite / distribution need a purchase_meeting_distributions table (the
   * shared one is keyed on kickoff_meeting_id and kickoff_attendee_id, so it
   * cannot simply be pointed at Purchase meetings). They REJECT rather than
   * resolve, so a caller sees a real failure instead of a button that silently
   * does nothing and looks like it worked.
   */
  invite: () => Promise.reject(
    new Error('Invitations are not available for Purchase meetings yet.')
  ),
  distribution: () => Promise.reject(
    new Error('The distribution list is not available for Purchase meetings yet.')
  ),
}
