// Cloud file-import providers, in display order. Google Drive and OneDrive reuse
// the app's existing pickers (lib/googleDrivePicker, lib/oneDrivePicker) so there
// is exactly one implementation of each; pCloud is added here (it has no drop-in
// widget, so it sets browse:true and exposes authorize/listFolder/downloadFile,
// which the shared <PcloudPicker> drives). Each entry is the common shape
// { id, label, configured, Icon, pick? , browse?, ... }. Adding a provider = one
// import + one array entry; the UI and "Not configured" handling key off this list.
import { GoogleDriveIcon, OneDriveIcon, PcloudIcon } from './icons'
import { openGoogleDrivePicker, googleDriveConfigured } from '@/lib/googleDrivePicker'
import { openOneDrivePicker, oneDriveConfigured } from '@/lib/oneDrivePicker'
import pcloud from './pcloud'

export const CLOUD_PROVIDERS = [
  { id: 'google', label: 'Google Drive', configured: googleDriveConfigured, pick: openGoogleDrivePicker, Icon: GoogleDriveIcon },
  { id: 'onedrive', label: 'OneDrive', configured: oneDriveConfigured, pick: openOneDrivePicker, Icon: OneDriveIcon },
  { ...pcloud, Icon: PcloudIcon },
]

/** True if at least one provider has its client id/keys configured. */
export const anyCloudConfigured = CLOUD_PROVIDERS.some((p) => p.configured)
