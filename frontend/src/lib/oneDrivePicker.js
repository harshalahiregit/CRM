// OneDrive / SharePoint picker — lets a user pick files from their OneDrive and
// hands them back as real File objects, so the caller uploads them through the
// normal multipart endpoint (same as a local file, same as Google Drive).
//
// Deliberately the same contract as lib/googleDrivePicker.js: pick → download the
// bytes → return File[]. The CRM stores the bytes; OneDrive is a SOURCE, never a
// storage backend. A link into someone's personal OneDrive would break the moment
// they move, rename or unshare the file — a document store cannot rest on that.
//
// Needs one env var:
//   VITE_MS_CLIENT_ID — Azure AD app (client) id, with OneDrive File Picker
//                       enabled and this origin listed as a SPA redirect URI.
// Without it, openOneDrivePicker() rejects with a clear, actionable error.

const CLIENT_ID = import.meta.env.VITE_MS_CLIENT_ID

export const oneDriveConfigured = Boolean(CLIENT_ID)

/** Inject a <script> once and resolve when it has loaded. */
function loadScript(src) {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) return resolve()
    const s = document.createElement('script')
    s.src = src; s.async = true; s.defer = true
    s.onload = () => resolve()
    s.onerror = () => reject(new Error(`Failed to load ${src}`))
    document.head.appendChild(s)
  })
}

let sdkReady = null
function loadSdk() {
  if (sdkReady) return sdkReady
  sdkReady = loadScript('https://js.live.net/v7.2/OneDrive.js')
  return sdkReady
}

/**
 * Open the OneDrive picker. Resolves with an array of File objects for the chosen
 * files, or [] if the user cancels.
 *
 * `action: 'download'` asks the SDK for pre-authenticated download URLs, so the
 * bytes can be fetched without us holding a Graph token or wiring a server-side
 * OAuth flow. That keeps this purely client-side, exactly like the Drive picker.
 */
export async function openOneDrivePicker() {
  if (!oneDriveConfigured) {
    throw new Error('OneDrive isn’t configured. Set VITE_MS_CLIENT_ID to enable it.')
  }
  await loadSdk()

  const picked = await new Promise((resolve, reject) => {
    window.OneDrive.open({
      clientId: CLIENT_ID,
      action: 'download',
      multiSelect: true,
      advanced: {
        redirectUri: window.location.origin,
        // Files only — folders are created in the CRM's own tree, not imported.
        filter: 'all',
      },
      success: (res) => resolve(res?.value || []),
      cancel: () => resolve([]),
      error: (e) => reject(new Error(e?.message || 'OneDrive picker failed.')),
    })
  })

  const files = []
  for (const item of picked) {
    // The SDK returns the download URL under a Graph-namespaced key; older
    // responses use @content.downloadUrl. Accept either rather than assume.
    const url = item['@microsoft.graph.downloadUrl'] || item['@content.downloadUrl']
    if (!url) continue

    const res = await fetch(url)
    if (!res.ok) continue

    const blob = await res.blob()
    files.push(new File([blob], item.name || `onedrive-file-${item.id}`, {
      type: item.file?.mimeType || blob.type,
    }))
  }

  return files
}
