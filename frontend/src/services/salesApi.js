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

export const salesApi = {
  dashboard: dashboardApi.sales,
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

  // Kept for dropdowns that need a flat client list
  // Replace with a real /contacts endpoint when that module is built
  clients: [],
}

export default salesApi
