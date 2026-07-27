import { createContext, useContext } from 'react'

/**
 * Shares the loaded PurchaseVendor (+ onboarding + reload) from the detail layout
 * down to every tab, so tabs never refetch the vendor. Purchase-owned; carries no
 * TPV / shared-Vendor state.
 */
export const VendorWorkspaceContext = createContext(null)

export const useVendorWorkspace = () => useContext(VendorWorkspaceContext)
