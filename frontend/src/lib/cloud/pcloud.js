// pCloud adapter. Unlike Google/OneDrive, pCloud ships no drop-in picker widget,
// so this adapter exposes the lower-level pieces — authorize(), listFolder(),
// downloadFile() — and sets `browse: true`. The shared <CloudImport> component
// renders a small built-in file browser on top of these, giving pCloud the same
// "pick files, attach them" UX as the other two providers.
//
// Needs:
//   VITE_PCLOUD_CLIENT_ID  — pCloud app id, with /cloud-oauth.html registered as
//                            the redirect URI. Uses the OAuth 2.0 implicit flow.

import { openOAuthPopup, callbackUrl } from './oauthPopup'

const CLIENT_ID = import.meta.env.VITE_PCLOUD_CLIENT_ID

export const configured = Boolean(CLIENT_ID)

// pCloud splits accounts across two data regions; the authorize response tells us
// which API host to talk to (locationid 2 = EU, anything else = US/global).
function apiHost(locationid) {
  return String(locationid) === '2' ? 'eapi.pcloud.com' : 'api.pcloud.com'
}

/** Pop the pCloud consent window and return { token, host } for later calls. */
export async function authorize() {
  if (!configured) {
    throw new Error('pCloud isn’t configured. Set VITE_PCLOUD_CLIENT_ID to enable it.')
  }
  const url =
    'https://my.pcloud.com/oauth2/authorize' +
    `?client_id=${encodeURIComponent(CLIENT_ID)}` +
    '&response_type=token' +
    `&redirect_uri=${encodeURIComponent(callbackUrl())}`
  const params = await openOAuthPopup(url)
  return { token: params.access_token, host: apiHost(params.locationid) }
}

/** List one folder. folderId 0 is the account root. */
export async function listFolder({ token, host }, folderId = 0) {
  const res = await fetch(`https://${host}/listfolder?folderid=${folderId}&access_token=${encodeURIComponent(token)}`)
  const data = await res.json()
  if (data.result !== 0) throw new Error(data.error || 'Could not read that pCloud folder.')
  const contents = data.metadata?.contents || []
  return contents.map((it) => ({
    id: it.isfolder ? it.folderid : it.fileid,
    name: it.name,
    isFolder: Boolean(it.isfolder),
    size: it.size || 0,
    contentType: it.contenttype || '',
  }))
}

/** Resolve a file's real download link and fetch it, returning a File object. */
export async function downloadFile({ token, host }, file) {
  const linkRes = await fetch(`https://${host}/getfilelink?fileid=${file.id}&access_token=${encodeURIComponent(token)}`)
  const link = await linkRes.json()
  if (link.result !== 0 || !link.hosts?.length) throw new Error(link.error || 'Could not fetch that pCloud file.')
  const res = await fetch(`https://${link.hosts[0]}${link.path}`)
  const blob = await res.blob()
  return new File([blob], file.name || `pcloud-file-${file.id}`, { type: file.contentType || blob.type })
}

export default { id: 'pcloud', label: 'pCloud', configured, browse: true, authorize, listFolder, downloadFile }
