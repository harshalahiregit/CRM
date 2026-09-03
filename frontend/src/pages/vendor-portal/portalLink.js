/**
 * Resolve a notification's deep-link for a VENDOR PORTAL surface.
 *
 * Notifications are created generically (a task assigned to any user links to
 * the internal `/app/tasks/{id}`), but a vendor cannot open the `/app` shell —
 * the route guard blocks their role and bounces them to their dashboard, so the
 * link appears to "do nothing". Here we remap a known `/app/*` link to the
 * equivalent page inside THIS portal (its My-Work lists). Links that are already
 * portal links (e.g. /vendor-portal/onboarding) — and anything unrecognised —
 * pass through unchanged.
 *
 * @param {string|null} link  the stored notification link
 * @param {string} base       '/vendor-portal' | '/purchase-portal'
 */
export function portalLink(link, base) {
  if (!link) return null
  if (link === base || link.startsWith(base + '/')) return link

  if (link.startsWith('/app/')) {
    if (link.startsWith('/app/tasks'))    return `${base}/tasks`
    if (link.startsWith('/app/projects')) return `${base}/projects`
    if (link.startsWith('/app/helpdesk/tickets') || link.startsWith('/app/tickets')) return `${base}/tickets`
    // An /app section the portal has no mirror for — land them somewhere valid
    // rather than a blocked route.
    return `${base}/dashboard`
  }

  return link
}

/** The portal base ('/vendor-portal' | '/purchase-portal') for a pathname. */
export function portalBase(pathname) {
  return '/' + (pathname.split('/')[1] || 'vendor-portal')
}
