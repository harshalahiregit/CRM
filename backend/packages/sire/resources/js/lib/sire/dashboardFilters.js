/**
 * SIRE dashboard — the filter contract, shared by the UI and (mirrored) the API.
 *
 * This is DATA. The frontend builds query params from it; the backend allowlists
 * against the same key set. A filter that is not here does not exist: an unknown
 * query parameter is ignored rather than passed to the query builder.
 *
 * On `tenant`: the brief asks for a tenant filter, and it is defined here for
 * completeness — but in this CRM a user belongs to exactly ONE tenant, there is no
 * super-admin and no cross-tenant read path. So the control is only rendered when
 * the API reports more than one accessible tenant, which today is never. The
 * backend still validates it (a mismatch is a 404, not a silent ignore) so that
 * the day someone builds cross-tenant access, this filter fails closed instead of
 * quietly widening.
 */

export const FILTER_KEYS = [
  'tenant_id', 'module', 'type', 'severity_id', 'priority',
  'status', 'assignee_id', 'date_from', 'date_to',
];

export const FILTERS = {
  tenant_id:   { label: 'Tenant',     type: 'select', optionsKey: 'tenants',    hiddenWhenSingle: true },
  module:      { label: 'Module',     type: 'select', optionsKey: 'modules' },
  type:        { label: 'Issue type', type: 'select', optionsKey: 'types' },
  severity_id: { label: 'Severity',   type: 'select', optionsKey: 'severities' },
  priority:    { label: 'Priority',   type: 'select', optionsKey: 'priorities' },
  status:      { label: 'Status',     type: 'multi',  optionsKey: 'statuses' },
  assignee_id: { label: 'Assignee',   type: 'select', optionsKey: 'assignees' },
  date_from:   { label: 'From',       type: 'date' },
  date_to:     { label: 'To',         type: 'date' },
};

/** The ten dashboard tiles, in display order. `scope` is what the API understands. */
export const TILES = [
  { key: 'open',              label: 'Open issues',      scope: 'open',              tone: 'slate' },
  { key: 'critical',          label: 'Critical',         scope: 'critical',          tone: 'red' },
  { key: 'high_priority',     label: 'High priority',    scope: 'high_priority',     tone: 'orange' },
  { key: 'overdue',           label: 'Overdue',          scope: 'overdue',           tone: 'red' },
  { key: 'sla_breached',      label: 'SLA breached',     scope: 'sla_breached',      tone: 'red' },
  { key: 'mine',              label: 'My issues',        scope: 'mine',              tone: 'blue' },
  { key: 'awaiting_qa',       label: 'Awaiting QA',      scope: 'awaiting_qa',       tone: 'violet' },
  { key: 'qa_failed',         label: 'QA failed',        scope: 'qa_failed',         tone: 'red' },
  { key: 'reopened',          label: 'Reopened',         scope: 'reopened',          tone: 'orange' },
  { key: 'recently_resolved', label: 'Recently resolved', scope: 'recently_resolved', tone: 'green' },
];

/**
 * Build query params from a filter state.
 *
 * Drops unknown keys, empty strings, nulls and empty arrays — an empty filter must
 * not become `status=` in the URL, because a blank value is not the same question
 * as "no filter" and the backend should never have to guess which was meant.
 */
export function toQueryParams(filters = {}, scope = null) {
  const params = {};

  for (const key of FILTER_KEYS) {
    const value = filters[key];
    if (value === undefined || value === null || value === '') continue;
    if (Array.isArray(value)) {
      if (value.length === 0) continue;
      params[key] = value.join(',');
      continue;
    }
    params[key] = value;
  }

  if (scope) params.scope = scope;

  return params;
}

/** Count of filters the user has actually applied, for the "clear" affordance. */
export function activeFilterCount(filters = {}) {
  return Object.keys(toQueryParams(filters)).length;
}
