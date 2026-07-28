import { useLocation } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { tpvApi } from '@/services/tpvApi'
import { purchaseApi } from '@/services/purchaseApi'
import { portalApi } from '@/services/portalApi'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import { canManageTpv, canApproveTpv } from './constants'

/**
 * Vendor-module context — generalises the old `isPortal ? portalApi : tpvApi`
 * boolean into a config so the SAME TPV vendor components render for TPV and for
 * Purchase (and their portals) by swapping only the data source + route base +
 * wording. Default resolves to the TPV config, so existing TPV routes are byte-
 * for-byte unchanged.
 *
 *   key            : 'tpv' | 'purchase'
 *   portal         : boolean (self-service portal vs internal admin)
 *   api            : the api client (tpvApi / purchaseApi / portalApi / purchasePortalApi)
 *   moduleName     : user-facing label ("Third Party Vendor" / "Purchase Vendor")
 *   engagement     : 'tpv' | 'purchase' (vendor tag written on create)
 *   listPath       : the vendor list route
 *   viewPath(id)   : the vendor detail route
 *   onboardingPath(id) : the onboarding wizard route
 *   kickoffNewPath(vid): the shared kickoff-create route pre-scoped to a vendor
 *   canManage/canApprove : permission helpers (role-based; unchanged)
 */
export function useVendorModule() {
  const { pathname } = useLocation()
  const { user } = useAuth()
  const role = user?.role

  // A Purchase Vendor holds a PurchaseVendor token, never a User session, so there
  // is no `role` to test — the portal is identified by its path alone.
  const isPurchasePortal = pathname.startsWith('/purchase-portal')
  const isTpvPortal      = pathname.startsWith('/vendor-portal') || role === 'third_party_vendor'
  const isPurchase       = pathname.startsWith('/app/purchase')

  if (isPurchasePortal) {
    return cfg({
      key: 'purchase', portal: true, api: purchasePortalApi, moduleName: 'Purchase Vendor',
      engagement: 'purchase', base: '/purchase-portal',
    })
  }
  if (isTpvPortal) {
    return cfg({
      key: 'tpv', portal: true, api: portalApi, moduleName: 'Vendor',
      engagement: 'tpv', base: '/vendor-portal',
    })
  }
  if (isPurchase) {
    return cfg({
      key: 'purchase', portal: false, api: purchaseApi, moduleName: 'Purchase Vendor',
      engagement: 'purchase', base: '/app/purchase',
      listPath: '/app/purchase/vendors',
      viewPath: (id) => `/app/purchase/vendors/${id}`,
      onboardingPath: (id) => `/app/purchase/onboarding/${id}`,
    })
  }
  // Default — TPV admin (unchanged behaviour).
  return cfg({
    key: 'tpv', portal: false, api: tpvApi, moduleName: 'Third Party Vendor',
    engagement: 'tpv', base: '/app/tpv', defaultVendorType: 'temporary',
    listPath: '/app/tpv/dashboard',
    viewPath: (id) => `/app/tpv/view/${id}`,
    onboardingPath: (id) => `/app/tpv/onboarding/${id}`,
  })
}

function cfg(c) {
  const base = c.base
  return {
    canManage: canManageTpv,
    canApprove: canApproveTpv,
    codePrefix: c.engagement === 'purchase' ? 'PUR' : 'TPV',
    defaultVendorType: c.defaultVendorType ?? 'standard',
    // Portal has no list/detail routes; onboarding lives at <base>/onboarding[/id].
    listPath: c.listPath ?? `${base}/onboarding`,
    viewPath: c.viewPath ?? ((id) => `${base}/onboarding/${id}`),
    onboardingPath: c.onboardingPath ?? ((id) => `${base}/onboarding/${id}`),
    // The onboarding LIST route (the wizard's "back" target) — always <base>/onboarding.
    onboardingListPath: `${base}/onboarding`,
    // Kickoff create is the shared page; pre-scope it to the vendor.
    kickoffNewPath: (vendorId) => `/app/tpv/kickoff/new?vendor=${vendorId}`,
    // Kickoff workspace (shared engine) — reached as a button, never a nav tab.
    kickoffListPath: '/app/tpv/kickoff',
    ...c,
  }
}
