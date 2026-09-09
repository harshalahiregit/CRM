import api from '@/lib/api'

/**
 * The Contract module's client.
 *
 * Named contractModuleApi, not contractApi: `contractApi.js` already belongs to
 * the Sales module's own contract feature, which this module deliberately leaves
 * alone. Two clients with the same name is how a screen ends up talking to the
 * wrong tables.
 */
export const contractModuleApi = {
  /* ── Dashboard ─────────────────────────────────────────────── */
  list:  (params = {}) => api.get('/contracts', { params }).then(r => r.data),
  stats: ()            => api.get('/contracts/stats').then(r => r.data),
  get:   (id)          => api.get(`/contracts/${id}`).then(r => r.data),

  /* ── Create & edit ─────────────────────────────────────────── */
  create: (data)     => api.post('/contracts', data).then(r => r.data),
  update: (id, data) => api.put(`/contracts/${id}`, data).then(r => r.data),
  remove: (id)       => api.delete(`/contracts/${id}`).then(r => r.data),

  setStatus: (id, status) => api.patch(`/contracts/${id}/status`, { status }).then(r => r.data),
  renew:     (id, data)   => api.post(`/contracts/${id}/renew`, data).then(r => r.data),

  /* ── Signing (our side) ────────────────────────────────────── */
  sign: (id, sig) => api.post(`/contracts/${id}/sign`, sig).then(r => r.data),

  /**
   * E-mail the contract to the other party, through the tenant's own SMTP.
   *
   * Sends the PDF and the signing link together, and marks the contract Sent
   * only once the server has actually accepted it.
   */
  send: (id, data) => api.post(`/contracts/${id}/send`, data).then(r => r.data),

  /** The link to send the counterparty. The only place the token is disclosed. */
  signingLink: (id) => api.get(`/contracts/${id}/signing-link`).then(r => r.data),

  comment: (id, body) => api.post(`/contracts/${id}/comments`, { body }).then(r => r.data),

  /**
   * Open the PDF in a new tab.
   *
   * It has to be FETCHED, not linked. This route is behind Sanctum, and a plain
   * <a href> cannot carry the Authorization header — the browser navigates
   * without it, the API answers 401, and the new tab shows a blank page with no
   * error anywhere. That is exactly what happened when this was a URL builder.
   *
   * The window is opened BEFORE the await: a popup blocker rejects window.open
   * that is not the direct result of a click, and awaiting first breaks that
   * chain. It is opened blank and pointed at the blob once it arrives.
   */
  openPdf: async (id) => {
    const tab = window.open('', '_blank')
    try {
      const res = await api.get(`/contracts/${id}/pdf`, { responseType: 'blob' })
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
      if (tab) tab.location = url
      else window.location.assign(url)   // popup blocked: use this tab instead
      // Revoked late: revoking immediately can pull the document out from under
      // a viewer that has not finished reading it.
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch (e) {
      tab?.close()
      throw e
    }
  },

  /* ── Pickers ───────────────────────────────────────────────── */
  categories:     ()     => api.get('/contracts/categories').then(r => r.data),
  createCategory: (data) => api.post('/contracts/categories', data).then(r => r.data),
  parties:        ()     => api.get('/contracts/parties').then(r => r.data),
}

/**
 * A customer's or vendor's OWN contracts, inside their portal.
 *
 * The path is `agreements`, not `contracts`: the client and purchase portals
 * already serve /contracts from their own older tables, and Laravel matches the
 * first registration — mounting these at /contracts would have returned 200
 * while quietly answering with the other module's list.
 *
 * `base` differs per portal because each one authenticates a different identity;
 * the server reads the party from that session, never from the URL, so there is
 * no id here to get wrong.
 */
export function partyContractApi(base) {
  return {
    // The list carries each contract IN FULL — terms, signatures, thread and the
    // party's own pdf/signing links. There is no detail endpoint on purpose: the
    // client portal forbids any id in its URLs (ClientPortalTest scans the route
    // table for it), and all three portals share this shape rather than one of
    // them quietly differing.
    list:    ()         => api.get(`${base}/agreements`).then(r => r.data?.data ?? r.data),
    // The contract is named in the body, checked against the caller's own party.
    comment: (id, body) => api.post(`${base}/agreements/comment`,
                                    { contract_id: id, body }).then(r => r.data),
  }
}

/** The three portals. */
export const vendorContractApi   = partyContractApi('/portal')
export const clientContractApi   = partyContractApi('/portal/client')
export const purchaseContractApi = partyContractApi('/portal/purchase')

/** Admin side: the contracts on one customer's or vendor's record. */
export const contractsForParty = (type, id) =>
  api.get(`/contracts/for/${type}/${id}`).then(r => r.data?.data ?? r.data)

/**
 * The counterparty's client — no login, the token is the authority.
 *
 * Separate object because these calls must NOT carry the staff session: they run
 * on a public page a customer opens from an e-mail link.
 */
export const publicContractApi = {
  get:     (token)       => api.get(`/public/contracts/${token}`).then(r => r.data),
  sign:    (token, sig)  => api.post(`/public/contracts/${token}/sign`, sig).then(r => r.data),
  comment: (token, body, name) =>
    api.post(`/public/contracts/${token}/comments`, { body, name }).then(r => r.data),
  verify:  (token)       => api.get(`/public/contracts/${token}/verify`).then(r => r.data),
  pdfUrl:  (token)       => `${api.defaults.baseURL}/public/contracts/${token}/pdf`,
}

export default contractModuleApi
