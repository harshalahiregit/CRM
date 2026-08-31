import { useState, useEffect } from 'react'

/**
 * Which sidebar accordion section is open — one answer, shared.
 *
 * AppShell renders TWO Sidebars: the off-canvas mobile drawer and the desktop
 * one. When each held its own state they could disagree — open Sales on the
 * desktop sidebar, then open the mobile drawer, and it still showed whatever it
 * was last told. Both also wrote to the same storage key, so which one won
 * depended on render order. Owning the state above them removes the question.
 *
 * Persisted so a refresh does not close what you opened. Per browser by nature:
 * this is a convenience, not a setting, and not worth a server round-trip.
 */
export const SIDEBAR_SECTION_KEY = 'sangoe_sidebar_section'

/**
 * The only ids that may be restored. A stored key outlives the thing it names —
 * rename or drop a module and the saved value points at a section that no
 * longer exists, leaving every header closed with nothing to explain why.
 * Validating on read degrades that to "all closed" instead.
 *
 * Keep in step with the section ids the Sidebar renders.
 */
export const SECTION_IDS = ['hr', 'sales', 'accounts', 'helpdesk', 'inventory', 'purchase', 'tpv']

export function readStoredSection() {
  try {
    const saved = localStorage.getItem(SIDEBAR_SECTION_KEY)
    return SECTION_IDS.includes(saved) ? saved : null
  } catch {
    // Private windows and blocked site data throw on access rather than
    // returning null. Start closed; the sidebar still works.
    return null
  }
}

/**
 * HR's inner groups — Recruitment and HR Records.
 *
 * These are NOT an accordion. The module level allows one open section because
 * the whole point there is to keep the sidebar short; inside a single open
 * module both groups can be open at once, and clicking one must leave the other
 * exactly as it was. Hence a set of ids rather than a single id.
 */
export const SIDEBAR_GROUPS_KEY = 'sangoe_sidebar_groups'
export const GROUP_IDS = ['recruitment', 'hr-records']

export function readStoredGroups() {
  try {
    const raw = JSON.parse(localStorage.getItem(SIDEBAR_GROUPS_KEY) || '[]')
    // Whatever comes back is untrusted: hand-edited, left over from an older
    // build, or not an array at all. Keep only ids that still name a group.
    return Array.isArray(raw) ? raw.filter(id => GROUP_IDS.includes(id)) : []
  } catch {
    return []
  }
}

export function useSidebarSection() {
  const [openSection, setOpenSection] = useState(readStoredSection)

  // `null` means all closed — both the starting state when nothing is stored,
  // and where clicking an open header returns you.
  const toggleSection = (id) => setOpenSection(cur => (cur === id ? null : id))

  useEffect(() => {
    try {
      if (openSection) localStorage.setItem(SIDEBAR_SECTION_KEY, openSection)
      else localStorage.removeItem(SIDEBAR_SECTION_KEY)
    } catch { /* not remembering is fine; crashing is not */ }
  }, [openSection])

  // Both groups start closed and open only on a click, each independently of
  // the other. Stored as a list because both may be open at the same time.
  const [openGroups, setOpenGroups] = useState(readStoredGroups)
  const toggleGroup = (id) =>
    setOpenGroups(cur => (cur.includes(id) ? cur.filter(g => g !== id) : [...cur, id]))
  const isGroupOpen = (id) => openGroups.includes(id)

  useEffect(() => {
    try {
      localStorage.setItem(SIDEBAR_GROUPS_KEY, JSON.stringify(openGroups))
    } catch { /* not remembering is fine; crashing is not */ }
  }, [openGroups])

  return { openSection, toggleSection, isGroupOpen, toggleGroup }
}
