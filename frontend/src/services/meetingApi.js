/**
 * meetingApi — online meeting link generation & retrieval.
 *
 * Wraps the two new KickoffMeetingLinkController endpoints:
 *   POST /kickoff/meetings/{id}/generate-link
 *   GET  /kickoff/meetings/{id}/link
 *
 * Also handles the tenant default-platform preference stored in
 * tenant.settings.default_meeting_platform (via a dedicated settings endpoint).
 */
import api from '@/lib/api'

export const meetingApi = {
  /**
   * Generate (or regenerate) an online meeting link for a kickoff meeting.
   *
   * @param {number|string} meetingId  — kickoff meeting ID
   * @param {string|null}   platform   — 'google_meet' | 'zoom' | 'teams' | 'stub' | null (uses env default)
   * @returns {{ meeting: object, link: { platform, link, id, passcode, host_link } }}
   */
  generateLink: (meetingId, platform = null) =>
    api.post(`/kickoff/meetings/${meetingId}/generate-link`, { platform }).then(r => r.data),

  /**
   * Return the stored online-meeting link data for an existing meeting.
   *
   * @param {number|string} meetingId
   * @returns {{ platform, link, id, passcode, host_link }}
   */
  getLink: (meetingId) =>
    api.get(`/kickoff/meetings/${meetingId}/link`).then(r => r.data),

  /**
   * Fetch the tenant's default meeting platform preference.
   * Returns null gracefully if the endpoint is not yet configured.
   *
   * @returns {{ platform: string, available_platforms: string[] } | null}
   */
  getDefaultPlatform: () =>
    api.get('/settings/meeting-platform').then(r => r.data).catch(() => null),

  /**
   * Save the tenant's default meeting platform preference.
   *
   * @param {string} platform  — 'google_meet' | 'zoom' | 'teams' | 'stub'
   */
  savePlatformSetting: (platform) =>
    api.put('/settings/meeting-platform', { platform }).then(r => r.data),
}

export default meetingApi
