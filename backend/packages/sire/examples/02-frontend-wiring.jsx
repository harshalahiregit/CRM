/*
 * ============================================================================
 * ADAPT TO CRM — the three additive edits
 * ============================================================================
 */

// ---------------------------------------------------------------------------
// 1. <HOST_API_CLIENT>  (report: src/lib/api.js)
//    ONE LINE, first in the existing error branch.
// ---------------------------------------------------------------------------
import { recordFailedRequest } from './sire/requestLog';

api.interceptors.response.use(
  (response) => response,
  (error) => {
    // Metadata only — method, path, status, timestamp, correlation ref. Never a
    // body, never a header, never a query string. Never throws, never alters the
    // rejection. First statement so a failure is still recorded when the
    // session-failure path redirects.
    recordFailedRequest(error);

    if (isSessionFailure(error)) { clearAuth(); window.location.assign('/login'); }
    return Promise.reject(error);
  },
);

// ---------------------------------------------------------------------------
// 2. <HOST_APP_ROOT>  (report: src/App.jsx)
//    Inside AuthProvider (user known) and inside the Router (navigation live).
// ---------------------------------------------------------------------------
import { SireContextProvider } from './context/SireContextProvider';

<AuthProvider>
  <BrowserRouter>
    <SireContextProvider>
      <AppRoutes />
    </SireContextProvider>
  </BrowserRouter>
</AuthProvider>;

// ---------------------------------------------------------------------------
// 3. <HOST_AUTHENTICATED_LAYOUT>  (report: components/layout/Layout.jsx)
//    ONE LINE. This is what puts Report Issue on every CRM screen.
//    In the layout, not App.jsx, so it stays off login and public portals.
// ---------------------------------------------------------------------------
import ReportIssueRoot from '../sire/ReportIssueRoot';

<main>{children}</main>
<ReportIssueRoot />;

// ---------------------------------------------------------------------------
// OPTIONAL — a screen the route map cannot describe.
// Only where the route genuinely cannot tell: a wizard whose step lives in
// component state, a console whose entity is not in the URL. If the route
// already identifies the screen, this is redundant.
// ---------------------------------------------------------------------------
import { SireContext } from '../../context/SireContextProvider';

<SireContext
  module="sales"
  section="leads"
  screen="lead-import-wizard"
  entityType="lead"
  entityId={lead.id}
/>;
