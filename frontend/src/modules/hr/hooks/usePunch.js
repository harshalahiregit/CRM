import { useState, useCallback } from 'react'
import { useQuery } from '@tanstack/react-query'
import { hrApi } from '@/services/hrApi'
import { getLocation, buildNote } from '@/lib/punchEvidence'
import { useRefreshMyAttendanceToday } from './useMyAttendanceToday'

const POLICY_KEY = ['hr', 'settings', 'mine', 'punch-policy']

/** Defaults if the settings call fails: ask for location, do not demand a photo. */
const FALLBACK_POLICY = { selfie: false, location: true }

/**
 * One punch, wherever it was pressed.
 *
 * A shift can be started from the header pill, from the attendance card on the
 * main dashboard, from the same card on the HR dashboard, or from the
 * SangoeTrack phone app. They all move ONE row — hr_attendance is unique on
 * (tenant, employee, date) — so they had better mean the same thing.
 *
 * ── WHAT THIS FIXES ─────────────────────────────────────────────────────────
 * They did not. HeaderPunch read the workspace policy, asked for GPS, opened the
 * camera when a selfie was required and wrote a verification note. The card
 * called checkIn() with nothing at all. So the same person, on the same day,
 * left a fully evidenced punch or a bare one depending purely on which control
 * they happened to click — and a workspace that REQUIRED a selfie could be
 * walked past by using the dashboard instead of the header.
 *
 * That is the kind of difference nobody notices until an attendance record is
 * questioned and the evidence is missing for half the punches.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ────────────────────────────────────────
 * It does not own the camera. A selfie needs a dialog, and a dialog belongs to
 * the component that can render it — so `needsSelfie` is reported here and each
 * surface opens its own SelfieCapture. Everything that decides what gets SENT
 * lives in this file, which is the part that was drifting.
 */
export function usePunch({ enabled = true } = {}) {
  const refresh = useRefreshMyAttendanceToday()
  const [busy, setBusy] = useState(false)

  /*
   | What this workspace asks for on a web punch.
   |
   | Behind a query key rather than a per-component fetch, for the same reason
   | the attendance read is: the header and the dashboard card are both on screen
   | at once, and two copies of this hook meant two identical settings calls on
   | every dashboard load. That is the duplicate Dev 2 reported, and pulling the
   | policy up here would have quietly recreated it.
   |
   | Failing to read it must never disable punching, so the fallback stands:
   | location asked, selfie not.
   */
  const { data: policy = FALLBACK_POLICY } = useQuery({
    queryKey: POLICY_KEY,
    enabled,
    retry: false,
    staleTime: 10 * 60_000,   // a workspace setting, not a live value
    queryFn: async () => {
      const s = await hrApi.settings.mine()
      return {
        selfie: !! s?.web_punch_require_selfie,
        location: s?.web_punch_require_location !== false,
      }
    },
  })

  /**
   * Clock in or out with whatever the browser can prove.
   *
   * Location is asked for first because it is silent — the prompt is the
   * browser's own and most people have already answered it. A selfie needs a
   * dialog, so the caller opens one when `needsSelfie` says to; the dialog
   * itself always offers a way through without a photo.
   */
  const punch = useCallback(async (side, selfieBlob, selfieReason) => {
    const location = policy.location ? await getLocation() : null

    const evidence = {
      latitude: location?.ok ? location.latitude : undefined,
      longitude: location?.ok ? location.longitude : undefined,
      selfie: selfieBlob || undefined,
      verificationNote: buildNote({
        location, selfie: selfieBlob, selfieReason,
        requireSelfie: policy.selfie, requireLocation: policy.location,
      }),
    }

    const call = side === 'out' ? hrApi.attendance.me.checkOut : hrApi.attendance.me.checkIn

    setBusy(true)
    try {
      await call(evidence)
      // Every surface reads one query key, so this lands on all of them at once.
      await refresh()
      return { ok: true, message: side === 'out' ? 'Clocked out' : 'Clocked in' }
    } catch (e) {
      return { ok: false, message: e?.response?.data?.message || 'That did not work. Try again.' }
    } finally {
      setBusy(false)
    }
  }, [policy, refresh])

  /** Breaks carry no evidence — the shift is already open and proven. */
  const breakAction = useCallback(async (which) => {
    const call = which === 'end' ? hrApi.attendance.me.breakEnd : hrApi.attendance.me.breakStart

    setBusy(true)
    try {
      await call()
      await refresh()
      return { ok: true, message: which === 'end' ? 'Break ended' : 'Break started' }
    } catch (e) {
      return { ok: false, message: e?.response?.data?.message || 'That did not work. Try again.' }
    } finally {
      setBusy(false)
    }
  }, [refresh])

  return { punch, breakAction, busy, policy, needsSelfie: policy.selfie }
}
