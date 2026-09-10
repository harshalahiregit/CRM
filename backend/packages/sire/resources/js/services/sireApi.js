/**
 * SIRE — every SIRE fetch call lives here (hard team convention: one api module
 * per module, all calls through the shared axios instance in lib/api.js).
 *
 * If src/services/sireApi.js already exists from an earlier SIRE slice, MERGE
 * these two methods into it rather than replacing the file.
 */

import { sireHostApi } from '../lib/sire/host';
const api = sireHostApi();

export const sireApi = {
  /**
   * Create a report. `context` is the SireContextCollector payload.
   * tenant_id and user_id are deliberately absent — the server takes both from
   * the authenticated token.
   */
  createReport: (payload) => api.post('/sire/reports', payload),

  /**
   * Attach evidence through the existing shared attachment engine. The subject
   * comes from the ROUTE, never the body, so a file cannot be retargeted by
   * editing the request.
   */
  uploadEvidence: (reportId, file) => {
    const form = new FormData();
    form.append('file', file);
    return api.post(`/sire/reports/${reportId}/attachments`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
  },

  // ---- register -----------------------------------------------------------
  listReports: (params) => api.get('/sire/reports', { params }),
  getReport: (id) => api.get(`/sire/reports/${id}`),

  // ---- engineering workflow ----------------------------------------------
  /**
   * ONE endpoint for every transition. The server's state machine decides what
   * is legal; the client sends an action name and the fields that action needs.
   * Twenty endpoints would be twenty places for the rules to drift.
   */
  transition: (id, action, payload = {}) =>
    api.post(`/sire/reports/${id}/transitions`, { action, ...payload }),

  /** Work recorded without moving the issue: notes, fix summary, acceptance. */
  recordAction: (id, action, payload = {}) =>
    api.post(`/sire/reports/${id}/actions`, { action, ...payload }),

  // ---- activity -----------------------------------------------------------
  /** System events (audit_logs) and user comments (notes), merged server-side. */
  getTimeline: (id) => api.get(`/sire/reports/${id}/timeline`),

  addComment: (id, body) => api.post(`/sire/reports/${id}/comments`, { body }),
  editComment: (id, noteId, body) => api.patch(`/sire/reports/${id}/comments/${noteId}`, { body }),
  deleteComment: (id, noteId) => api.delete(`/sire/reports/${id}/comments/${noteId}`),
  // NOTE: there is deliberately no editSystemEvent / deleteSystemEvent. System
  // events are immutable and no endpoint exists to change one.

  // ---- Phase 2: quality & governance --------------------------------------
  quality: (params) => api.get('/sire/quality', { params }),

  // root cause
  getRootCause:  (id) => api.get(`/sire/reports/${id}/root-cause`),
  saveRootCause: (id, payload) => api.post(`/sire/reports/${id}/root-cause`, payload),
  confirmRootCause: (id) => api.post(`/sire/reports/${id}/root-cause/confirm`),

  // relationships
  getRelations:     (id) => api.get(`/sire/reports/${id}/relations`),
  linkIssue:        (id, payload) => api.post(`/sire/reports/${id}/links`, payload),
  unlinkIssue:      (linkId) => api.delete(`/sire/links/${linkId}`),
  markRegression:   (id, payload) => api.post(`/sire/reports/${id}/regression`, payload),
  clearRegression:  (id, reason) => api.delete(`/sire/reports/${id}/regression`, { data: { reason } }),

  // recurrence
  listRecurrenceGroups: (params) => api.get('/sire/recurrence-groups', { params }),
  getRecurrenceGroup:   (id) => api.get(`/sire/recurrence-groups/${id}`),
  createRecurrenceGroup: (payload) => api.post('/sire/recurrence-groups', payload),
  addOccurrence:    (groupId, reportId) => api.post(`/sire/recurrence-groups/${groupId}/occurrences`, { report_id: reportId }),
  removeOccurrence: (groupId, reportId) => api.delete(`/sire/recurrence-groups/${groupId}/occurrences/${reportId}`),

  // releases
  listReleases:  (params) => api.get('/sire/releases', { params }),
  getRelease:    (id) => api.get(`/sire/releases/${id}`),
  createRelease: (payload) => api.post('/sire/releases', payload),
  // Shipping and rollback are GOVERNED transitions — the ungated endpoints were
  // removed in Phase 3 so there is only one path to "released", and it checks gates.
  releaseTransition: (id, payload) => api.post(`/sire/releases/${id}/transitions`, payload),

  // release governance
  releaseBoard:      (params) => api.get('/sire/release-board', { params }),
  releaseGovernance: (id) => api.get(`/sire/releases/${id}/governance`),
  evaluateGates:     (id) => api.post(`/sire/releases/${id}/gates/evaluate`),
  gateConfig:        () => api.get('/sire/release-gates'),
  overrideRelease:   (id, payload) => api.post(`/sire/releases/${id}/override`, payload),
  revokeOverride:    (overrideId, reason) => api.post(`/sire/release-overrides/${overrideId}/revoke`, { reason }),
  listOverrides:     (params) => api.get('/sire/release-overrides', { params }),

  // release notes — generate, submit, approve, publish
  generateReleaseNote:   (releaseId, audience) => api.post(`/sire/releases/${releaseId}/notes`, { audience }),
  getReleaseNote:        (id) => api.get(`/sire/release-notes/${id}`),
  regenerateReleaseNote: (id) => api.post(`/sire/release-notes/${id}/regenerate`),
  submitReleaseNote:     (id) => api.post(`/sire/release-notes/${id}/submit`),
  approveReleaseNote:    (id) => api.post(`/sire/release-notes/${id}/approve`),
  publishReleaseNote:    (id) => api.post(`/sire/release-notes/${id}/publish`),

  // knowledge base (Helpdesk's KB — SIRE has none of its own)
  listKbLinks:     (id) => api.get(`/sire/reports/${id}/kb-links`),
  linkKbArticle:   (id, payload) => api.post(`/sire/reports/${id}/kb-links`, payload),
  draftKbArticle:  (id, payload) => api.post(`/sire/reports/${id}/kb-article`, payload),
  unlinkKbArticle: (linkId) => api.delete(`/sire/kb-links/${linkId}`),

  // CAPA
  listCapa:      (params) => api.get('/sire/capa', { params }),
  createCapa:    (reportId, payload) => api.post(`/sire/reports/${reportId}/capa`, payload),
  createGroupCapa: (groupId, payload) => api.post(`/sire/recurrence-groups/${groupId}/capa`, payload),
  startCapa:     (id) => api.post(`/sire/capa/${id}/start`),
  completeCapa:  (id, note) => api.post(`/sire/capa/${id}/complete`, { completion_note: note }),
  verifyCapa:    (id, payload) => api.post(`/sire/capa/${id}/verify`, payload),

  // ---- AI foundation ------------------------------------------------------
  // Foundation only: /suggest returns `unavailable` because the only registered
  // provider declines everything. The wiring is real; the analysis is not built.
  aiStatus:        () => api.get('/sire/ai/status'),
  aiCapabilities:  () => api.get('/sire/ai/capabilities'),
  aiSuggestions:   (reportId, params) => api.get(`/sire/ai/reports/${reportId}/suggestions`, { params }),
  aiSuggest:       (reportId, capability) => api.post(`/sire/ai/reports/${reportId}/suggest`, { capability }),
  /** Records accept/reject/modify. Does NOT apply — the human applies. */
  aiDecide:        (suggestionId, payload) => api.post(`/sire/ai/suggestions/${suggestionId}/decide`, payload),
  /**
   * Classification + duplicate candidates, computed locally. Called AFTER an issue
   * exists — never during creation, so creation cannot be slowed or broken by it.
   */
  aiInsights:      (reportId, phase) => api.post(`/sire/ai/reports/${reportId}/insights`, null, { params: { phase } }),
  /** Release risk + a review of the release notes. Advisory; gates still decide. */
  aiReleaseInsights: (releaseId) => api.post(`/sire/ai/releases/${releaseId}/insights`),
  /** Management-level answers from this tenant's register. */
  aiEngineeringInsights: (params) => api.get('/sire/ai/engineering-insights', { params }),

  // ---- test cases (core — works with or without any AI) --------------------
  testCases:       (reportId, params) => api.get(`/sire/reports/${reportId}/test-cases`, { params }),
  /** Accepts generated definitions and hand-written ones alike. Never a result. */
  addTestCases:    (reportId, payload) => api.post(`/sire/reports/${reportId}/test-cases`, payload),
  updateTestCase:  (id, payload) => api.patch(`/sire/test-cases/${id}`, payload),
  removeTestCase:  (id, reason) => api.delete(`/sire/test-cases/${id}`, { data: { reason } }),
  /** The only path by which a test result is ever written. */
  recordTestResult: (id, payload) => api.post(`/sire/test-cases/${id}/result`, payload),
  resetTestResult: (id) => api.delete(`/sire/test-cases/${id}/result`),

  // ---- dashboard ----------------------------------------------------------
  /** Ten counts. Separate from the register so changing a page does not refetch them. */
  dashboardTiles: (params) => api.get('/sire/dashboard', { params }),

  /** The filtered, scoped list behind a tile. Same scopes as the tiles. */
  dashboardRegister: (params) => api.get('/sire/dashboard/register', { params }),

  /** Everything the filter bar needs, in one call, all tenant-scoped. */
  dashboardOptions: () => api.get('/sire/dashboard/options'),

  // ---- queues -------------------------------------------------------------
  myDevelopmentQueue: (params) => api.get('/sire/queues/development', { params }),
  myQaQueue: (params) => api.get('/sire/queues/qa', { params }),
};

export default sireApi;
