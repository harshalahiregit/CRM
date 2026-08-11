import api from '@/lib/api'
// Sales & Revenue — aggregates the per-resource API modules below.
// Kept for backward compatibility with existing `salesApi.xxx.yyy()` call
// sites; new code should import the per-resource modules directly
// (leadApi, proposalApi, estimateApi, invoiceApi, creditNoteApi,
// deliveryNoteApi, itemApi, dashboardApi, leadSettingsApi).

import { leadApi } from '@/services/leadApi'
import { leadSettingsApi } from '@/services/leadSettingsApi'
import { proposalApi } from '@/services/proposalApi'
import { estimateApi } from '@/services/estimateApi'
import { invoiceApi, paymentApi } from '@/services/invoiceApi'
import { creditNoteApi } from '@/services/creditNoteApi'
import { deliveryNoteApi } from '@/services/deliveryNoteApi'
import { itemApi } from '@/services/itemApi'
import { dashboardApi } from '@/services/dashboardApi'
import { paymentLinkApi } from '@/services/paymentLinkApi'
import { retainerInvoiceApi } from '@/services/retainerInvoiceApi'
import { proposalTemplateApi } from '@/services/proposalTemplateApi'
import { activityApi } from '@/services/activityApi'

export const salesApi = {
  dashboard: dashboardApi.sales,
  activities: activityApi,
  proposals: proposalApi,
  estimates: estimateApi,
  invoices: invoiceApi,
  creditNotes: creditNoteApi,
  deliveryNotes: deliveryNoteApi,
  payments: paymentApi,
  items: itemApi,
  leads: leadApi,
  leadStatuses: leadSettingsApi.statuses,
  leadSources: leadSettingsApi.sources,
  leadGoals: leadSettingsApi.goals,
  leadQuestionnaires: leadSettingsApi.questionnaires,
  paymentLinks: paymentLinkApi,
  retainerInvoices: retainerInvoiceApi,
  proposalTemplates: proposalTemplateApi,
}

// NOTE: client dropdowns now use the real Customer module via
// `useClientOptions()` / `fetchClientOptions()` from '@/services/customerApi'.
// The old hardcoded `salesApi.clients: []` stub has been removed.


/**
 * Download any sales list as CSV/XLSX.
 *
 * Goes through axios with responseType blob so the Bearer token is sent — a bare
 * <a href> would hit the API unauthenticated and download the login error.
 *
 * type: invoices | estimates | proposals | credit-notes | delivery-notes |
 *       payments | contracts | leads
 */
export const exportSalesList = async (type, params = {}) => {
  const res = await api.get(`/sales/export/${type}`, { params, responseType: 'blob' })
  const cd = res.headers?.['content-disposition'] || ''
  const name = cd.match(/filename="?([^";]+)"?/)?.[1]
    || `${type}_${new Date().toISOString().slice(0, 10)}.${params.format === 'xlsx' ? 'xlsx' : 'csv'}`
  const url = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = url; a.download = name; document.body.appendChild(a); a.click()
  a.remove(); URL.revokeObjectURL(url)
}

export default salesApi
