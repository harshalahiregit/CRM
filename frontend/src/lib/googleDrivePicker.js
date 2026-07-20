// Google Drive Picker — lets a user pick files from their Drive and hands them
// back as real File objects, so the caller can upload them through the normal
// multipart endpoint (same as a local file). Needs two env vars:
//   VITE_GOOGLE_CLIENT_ID  — OAuth 2.0 Web client id
//   VITE_GOOGLE_API_KEY    — API key with the Picker API enabled
// Without them, openGoogleDrivePicker() rejects with a clear, actionable error.

const CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID
const API_KEY = import.meta.env.VITE_GOOGLE_API_KEY
const SCOPE = 'https://www.googleapis.com/auth/drive.readonly'

export const googleDriveConfigured = Boolean(CLIENT_ID && API_KEY)

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

let pickerReady = null
function loadPicker() {
  if (pickerReady) return pickerReady
  pickerReady = (async () => {
    await loadScript('https://apis.google.com/js/api.js')
    await new Promise((res) => window.gapi.load('picker', res))
    await loadScript('https://accounts.google.com/gsi/client')
  })()
  return pickerReady
}

/** Get an OAuth access token via Google Identity Services (popup consent). */
function getAccessToken() {
  return new Promise((resolve, reject) => {
    const client = window.google.accounts.oauth2.initTokenClient({
      client_id: CLIENT_ID,
      scope: SCOPE,
      callback: (resp) => resp.error ? reject(new Error(resp.error)) : resolve(resp.access_token),
    })
    client.requestAccessToken({ prompt: '' })
  })
}

/**
 * Open the Drive picker. Resolves with an array of File objects for the chosen
 * files (downloaded via the Drive API using the granted token), or [] if the
 * user cancels.
 */
export async function openGoogleDrivePicker() {
  if (!googleDriveConfigured) {
    throw new Error('Google Drive isn’t configured. Set VITE_GOOGLE_CLIENT_ID and VITE_GOOGLE_API_KEY to enable it.')
  }
  await loadPicker()
  const token = await getAccessToken()

  const picked = await new Promise((resolve, reject) => {
    try {
      const view = new window.google.picker.DocsView().setIncludeFolders(false).setSelectFolderEnabled(false)
      const picker = new window.google.picker.PickerBuilder()
        .enableFeature(window.google.picker.Feature.MULTISELECT_ENABLED)
        .setOAuthToken(token)
        .setDeveloperKey(API_KEY)
        .addView(view)
        .setCallback((data) => {
          const a = window.google.picker.Action
          if (data.action === a.PICKED) resolve(data.docs || [])
          else if (data.action === a.CANCEL) resolve([])
        })
        .build()
      picker.setVisible(true)
    } catch (e) { reject(e) }
  })

  // Download each picked doc's bytes and wrap as a File so it uploads like a local file.
  const files = []
  for (const doc of picked) {
    const res = await fetch(`https://www.googleapis.com/drive/v3/files/${doc.id}?alt=media`, {
      headers: { Authorization: `Bearer ${token}` },
    })
    if (!res.ok) continue
    const blob = await res.blob()
    files.push(new File([blob], doc.name || `drive-file-${doc.id}`, { type: doc.mimeType || blob.type }))
  }
  return files
}
