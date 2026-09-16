/**
 * Who may see SIRE at all, in the browser.
 *
 * SIRE is the INTERNAL engineering track. The server already enforces this --
 * every /api/sire route answers 403 to anyone else -- but enforcement alone left
 * a customer looking at an "Issues & Quality" section in their sidebar and a
 * Report Issue button, both of which opened a blank screen. Nothing leaked, but
 * it advertised an internal tool to the wrong audience and read as broken.
 *
 * Kept deliberately in ONE place so the sidebar, the route guard and the global
 * button cannot drift apart and re-open that hole.
 *
 * These are the host roles mapped to ADMIN and INTERNAL_USER in
 * backend config/sire-host.php. If that mapping changes, change it here too --
 * the server stays the authority, this only decides what is worth rendering.
 */
export const SIRE_ROLES = ['admin', 'staff'];

export const canUseSire = (user) => SIRE_ROLES.includes(user?.role);

export default canUseSire;
