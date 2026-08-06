// Ad-hoc meeting links for the composer's "Meeting" button (owner: Shivam).
// POST a platform, get back a real link to drop into the message.
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

const unwrap = (r) => r.data?.data ?? r.data

export const meetingLinkApi = {
  // platform: 'google_meet' | 'zoom' | 'jitsi'
  create: (platform, title) => api.post('/meeting-links', { platform, title }).then(unwrap).catch(handleErr),
}

export default meetingLinkApi
