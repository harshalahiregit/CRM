/**
 * Who may see STOS at all, in the browser.
 *
 * The server is the authority — every /api/stos route answers 403 to anyone
 * else — but enforcement alone would leave a customer looking at a "Transport"
 * section in their sidebar whose every link opens a blank screen. Nothing
 * leaks, but it advertises an internal tool to the wrong audience and reads as
 * broken.
 *
 * Kept in ONE place so the sidebar and the route guard cannot drift apart.
 * Mirrors role:admin,staff on routes/stos.php — change both together.
 */
export const STOS_ROLES = ['admin', 'staff']

export const canUseStos = (user) => STOS_ROLES.includes(user?.role)

export default canUseStos
