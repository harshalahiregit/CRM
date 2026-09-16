/**
 * SIRE — every SIRE fetch call lives here (hard team convention: one api module
 * per module, all calls through the shared axios instance in lib/api.js).
 *
 * If src/services/sireApi.js already exists from an earlier SIRE slice, MERGE
 * these two methods into it rather than replacing the file.
 */

import { sireHostApi } from '../lib/sire/host';
const api = sireHostApi();

/**
 * Map a SIRE list envelope onto the paginator shape the tables read.
 *
 * The API answers {data:[...], meta:{page, per_page, total, pages}}. IssueTable
 * -- like every other table in this CRM -- reads a Laravel paginator:
 * page.data, page.current_page, page.last_page. Nothing lined up.
 *
 * Screens were unwrapping with `r.data?.data ?? r.data`, which yields the bare
 * ARRAY, so page.data came back undefined and rows was always []. Every register
 * rendered "No issues match these filters" while the tile directly above it
 * counted those very issues. Mapped here, once, so a screen cannot half-read it.
 */
/**
 * Flatten the issue-detail envelope onto the shape the detail screen reads.
 *
 * The API answers {data:{report:{...}, sla, available_transitions}}, but
 * IssueDetailPage reads issue.title, issue.report_number, issue.status and
 * issue.context directly -- while ALSO reading issue.available_transitions from
 * the top level. Half of it lined up, which is what made the bug so odd to look
 * at: the transition buttons rendered correctly above a header showing "-".
 *
 * Spreading report up and keeping its siblings satisfies both.
 */
/**
 * Map the attachment descriptors onto the field names EvidencePanel reads.
 *
 * Not one field lined up: the API sends {id, name, size, mime, url} and the panel
 * reads mime_type, original_name, size_label and download_url. So a screenshot
 * that uploaded perfectly rendered as "No screenshots or files attached".
 *
 * download_url is built here rather than taken from the descriptor: the disk is
 * private, and the url it reports points at /storage/..., which has no symlink
 * and would be an unauthorized path if it did.
 */
const sizeLabel = (bytes) => {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
};

/**
 * How an attachment is named in a URL: the FILENAME only, never the id.
 *
 * An id here is a storage path (sire/{tenant}/Report/{id}/file.jpg). Put one in
 * a URL segment and each slash encodes as %2F, which the production web server
 * (Plesk, Apache behind nginx) answers with its own 404 before the request
 * reaches Laravel -- so evidence that had uploaded perfectly showed
 * "Could not load" on live, while local dev passes %2F through and looked fine.
 *
 * The endpoint resolves the name against the report's own attachment listing,
 * so the directory comes from the route and nothing is lost by dropping it.
 */
const attachmentRef = (id) => encodeURIComponent(String(id ?? '').split('/').pop());

export const toAttachments = (response, reportId) => {
  const body = response?.data?.data ?? response?.data ?? [];
  const rows = Array.isArray(body) ? body : [];

  return rows.map((a) => ({
    ...a,
    mime_type: a.mime ?? a.mime_type ?? '',
    original_name: a.name ?? a.original_name ?? 'attachment',
    size_label: sizeLabel(a.size),
    download_url: `/api/sire/reports/${reportId}/attachments/${attachmentRef(a.id)}`,
  }));
};

export const toIssue = (response) => {
  const body = response?.data?.data ?? response?.data ?? {};
  const { report, ...rest } = body;

  return { ...(report ?? {}), ...rest };
};

export const toPage = (response) => {
  const body = response?.data ?? {};
  const rows = Array.isArray(body?.data) ? body.data : Array.isArray(body) ? body : [];
  const meta = body?.meta ?? {};

  return {
    data: rows,
    current_page: meta.page ?? 1,
    last_page: meta.pages ?? 1,
    total: meta.total ?? rows.length,
    per_page: meta.per_page ?? (rows.length || 25),
  };
};

export const sireApi = {
  /**
   * Create a report. `context` is the SireContextCollector payload.
   * tenant_id and user_id are deliberately absent — the server takes both from
   * the authenticated token.
   */
  createReport: (payload) => api.post('/sire/reports', payload),

  // Categories, severities and priorities for the Report Issue form. Its own
  // endpoint rather than /sire/dashboard/options, which carries filter-bar
  // rosters the form has no use for -- the button is on every screen and has to
  // open instantly.
  reportOptions: () => api.get('/sire/report-options'),

  // The host's own Customer Directory, read through SireCustomerProvider. SIRE
  // never writes to a customer record -- it names one on a defect so the
  // register can answer "which customers are hitting this".
  searchCustomers: (q = '') => api.get('/sire/customers', { params: { q } }),
  setCustomer:     (reportId, customerId) =>
    api.put(`/sire/reports/${reportId}/customer`, { customer_id: customerId }),

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

  /** Evidence already on an issue. Nothing fetched these before, which is the
   *  other half of why an uploaded screenshot never appeared. */
  listAttachments: (reportId) => api.get(`/sire/reports/${reportId}/attachments`),

  /**
   * One file, as a blob.
   *
   * The route is behind auth:sanctum and this app authenticates with a Bearer
   * token, which a plain <img src> cannot send -- so the bytes come through the
   * shared client and are shown from an object URL instead.
   */
  attachmentBlob: (reportId, id) =>
    api.get(`/sire/reports/${reportId}/attachments/${attachmentRef(id)}`, { responseType: 'blob' }),
  // ---- register -----------------------------------------------------------
  /**
   * The register. Note this is dashboard/register, NOT `GET /sire/reports` --
   * that route does not exist, and this method used to call it and 404.
   */
  listReports: (params) => api.get('/sire/dashboard/register', { params }),
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
  /** Recompute a group's derived statistics. Every recurrence number is derived,
   *  never typed -- this is how you force a refresh after editing occurrences. */
  recomputeRecurrence: (id) => api.post(`/sire/recurrence-groups/${id}/recompute`),

  // releases
  listReleases:  (params) => api.get('/sire/releases', { params }),
  getRelease:    (id) => api.get(`/sire/releases/${id}`),
  createRelease: (payload) => api.post('/sire/releases', payload),
  /** Rename / re-date / re-type a release. Route existed, nothing called it. */
  updateRelease: (id, payload) => api.patch(`/sire/releases/${id}`, payload),
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
  /** Edit note content before it is submitted for approval. */
  updateReleaseNote: (id, payload) => api.patch(`/sire/release-notes/${id}`, payload),
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
  /** Cancel an action. The route existed; no method called it, so a CAPA could be
   *  started, completed and verified but never abandoned. */
  cancelCapa:    (id, payload) => api.post(`/sire/capa/${id}/cancel`, payload),

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
