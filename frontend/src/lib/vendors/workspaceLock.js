/**
 * What a vendor's workspace shows before that vendor is onboarded.
 *
 * Both admin vendor workspaces — Purchase's and TPV's — open with the same
 * forty-odd sections regardless of who the vendor is. On a vendor who has not
 * finished onboarding that is forty sections of nothing: Purchase Orders for a
 * company you cannot raise an order against, Gate Log for workers who have not
 * been registered, Renewal for a contract that does not exist. Every one of
 * them loads, queries, and shows an empty list, and the person looking at the
 * screen cannot tell "there is nothing here yet" from "this is broken" — or
 * find, among forty entries, the four that are the actual next step.
 *
 * So until the vendor is onboarded the workspace shows only the sections that
 * step needs, and says in one line what the rest are waiting for. It is not a
 * permission: an admin who wants a locked section can approve the onboarding,
 * which is the thing they were going to have to do anyway.
 *
 * One module, two callers, on purpose. These two workspaces are the same job
 * against two vendor masters and have drifted apart over vocabulary alone
 * often enough that vendorDetailNav.jsx opens with a warning about it.
 */

/** The onboarding status that means "done". */
export const ONBOARDING_APPROVED = 'Approved'

/** The vendor status that means the same thing from the other direction. */
export const VENDOR_ACTIVE = 'Active'

/**
 * The sections that still mean something for a vendor mid-onboarding, by the
 * question each one answers for the person deciding:
 *
 *   overview   — where the onboarding stands, and the approve/hold/reject panel
 *   profile    — is this company who they say they are
 *   contacts   — who do we talk to, and step 1 of the onboarding itself
 *   meetings   — the kickoff, which is step 2
 *   documents  — what have they actually uploaded, and is it acceptable
 *
 * THE LIST IS DERIVED, not chosen: it is exactly the sections the seven
 * onboarding steps send somebody to. Meetings was missing, which made step 2
 * the only step on the strip that could not be opened — the kickoff is held
 * before the vendor is approved, so locking it until approval asked for the
 * meeting after the thing it unlocks.
 *
 * Both key styles are listed because the two workspaces address their tabs
 * differently: Purchase by a URL segment ('purchase-orders'), TPV by a slugged
 * label ('purchase-order'). Matching is done on the slug of whatever is passed,
 * so either spelling resolves.
 */
export const PRE_ONBOARDING_SECTIONS = [
  'overview', 'profile', 'contact', 'contacts', 'documents', 'meeting', 'meetings',
]

/**
 * And the same rule from the vendor's own side of the glass.
 *
 * A vendor part-way through onboarding was shown the whole portal — My Items,
 * Purchase Orders, Debit Notes, Payments, PTW, Packages, Shipping. None of it
 * can contain anything: there is no order to a company that is not approved
 * yet, no permit for workers not yet registered. So the vendor's first
 * impression of the system was thirty empty screens surrounding the two that
 * mattered, and support calls asking which one they were supposed to fill in.
 *
 * Two sections survive, and they are the whole of the next step:
 *   dashboard   — where they are, and what is still outstanding
 *   onboarding  — the wizard itself, steps 1 to 6
 *
 * Profile and Documents are deliberately NOT here even though an admin sees
 * them: for a vendor they are steps 2 and 3 *inside* the wizard, and listing
 * them beside it offers two doors into one room.
 */
export const PRE_ONBOARDING_PORTAL_SECTIONS = ['dashboard', 'onboarding']

/** Is this portal section available to the vendor yet? */
export function isPortalSectionUnlocked(key, unlocked) {
  return unlocked || PRE_ONBOARDING_PORTAL_SECTIONS.includes(slug(key))
}

const slug = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')

/**
 * Is this vendor's workspace fully unlocked?
 *
 * An APPROVED onboarding, or nothing. There is no second way in.
 *
 * This used to read
 *
 *     if (vendor.status === VENDOR_ACTIVE) return true
 *
 * on the reasoning that either answer means the same thing. They do not. The
 * two disagree constantly in real data: a vendor can be set Active — by hand,
 * by an older import, by an admin activating before the wizard was finished —
 * while its onboarding still sits In_Progress at step 1. That check unlocked
 * every one of those, which is why both portals opened with everything visible
 * for a vendor that had not onboarded at all.
 *
 * A missing onboarding record is not a pass either. "No wizard was ever
 * started" is the least onboarded a vendor can be, and both portals create the
 * record the moment the vendor opens Onboarding — so refusing here strands
 * nobody, it just makes them start.
 *
 * The same rule is enforced server-side by EnsureVendorOnboardingComplete; this
 * decides what is worth rendering, that decides what is allowed.
 */
export function isWorkspaceUnlocked(vendor, onboarding) {
  if (!vendor) return false

  return onboarding?.status === ONBOARDING_APPROVED
}

/** Is this one section available yet? */
export function isSectionUnlocked(key, unlocked) {
  return unlocked || PRE_ONBOARDING_SECTIONS.includes(slug(key))
}

/**
 * The nav, with the locked sections removed.
 *
 * @param groups  [{ items: [...] }] in either workspace's shape
 * @param unlocked  the answer from isWorkspaceUnlocked
 * @param keyOf  how to read a section key out of one item
 * @returns { groups, hidden } — hidden is the count, for the line that explains
 *          where they went. A workspace that silently drops forty entries and
 *          says nothing is the same confusion in the other direction.
 */
export function lockNav(groups, unlocked, keyOf = (it) => it) {
  if (unlocked) return { groups, hidden: 0 }

  let hidden = 0
  const kept = groups
    .map(g => {
      const items = g.items.filter(it => {
        const ok = isSectionUnlocked(keyOf(it), false)
        if (!ok) hidden += 1

        return ok
      })

      return { ...g, items }
    })
    .filter(g => g.items.length > 0)

  return { groups: kept, hidden }
}

/** What to tell somebody about the sections that are not there. */
export function lockNotice(vendor, onboarding, hidden) {
  if (!hidden) return null

  const state = onboarding?.status_label || String(onboarding?.status || '').replace(/_/g, ' ')

  return {
    count: hidden,
    // The next thing that has to happen, named — not "complete onboarding",
    // which is the thing they are already looking at.
    reason: onboarding
      ? `Onboarding is ${state || 'in progress'}.`
      : 'This vendor has not started onboarding.',
    unlocks: `${hidden} more ${hidden === 1 ? 'section' : 'sections'} unlock once the vendor is approved.`,
  }
}
