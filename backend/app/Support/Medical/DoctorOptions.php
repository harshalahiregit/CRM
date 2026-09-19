<?php

namespace App\Support\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use Illuminate\Support\Collection;

/**
 * The internal doctors somebody may choose when recording a medical.
 *
 * ── Why this exists ─────────────────────────────────────────────────────
 * Every doctor on every medical record was typed by hand. Six separate
 * free-text boxes across the TPV and Purchase wizards, the two vendor-detail
 * quick-adds and the bulk CSV, none of them connected to the doctor directory
 * the admin had already filled in. So the same in-house doctor was "Dr Sharma",
 * "Dr. A Sharma" and "sharma" on three records, the licence number was retyped
 * (or mistyped) each time, and `doctor_user_id` — the column that makes a
 * record point at a real person — was left NULL by everything except the
 * doctor's own portal.
 *
 * An internal doctor is an employee we already hold a full profile for. Asking
 * anyone to retype that is asking them to introduce errors.
 *
 * ── A reader, not shared medical logic ──────────────────────────────────
 * TPV and Purchase stay separate registers with their own tables and
 * controllers. What they share is the DIRECTORY: medical_doctor_profiles is one
 * table with a `modules` list saying which side each doctor serves. So this is
 * one query shape taking the caller's module, the same arrangement
 * Support\Task\VendorTaskLink uses. Neither side writes into the other's tables.
 *
 * ── The snapshot is taken here, never sent by the client ────────────────
 * A picked doctor's licence and council are copied from the profile on the
 * server. If the browser were trusted to send them, a licence number on a
 * medical certificate would be whatever the page posted — and that certificate
 * is a legal document. The client sends an id; everything else is looked up.
 */
final class DoctorOptions
{
    /** The modules a caller may ask for; anything else gets the unfiltered list. */
    public const MODULES = ['tpv', 'purchase'];

    /**
     * Doctors this tenant may pick, newest-name-first, as plain option rows.
     *
     * Only ACTIVE profiles: a deactivated doctor keeps their name on the
     * certificates they already signed, but must not be attached to a new one.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function forTenant(int $tenantId, ?string $module = null): Collection
    {
        return MedicalDoctorProfile::forTenant($tenantId)
            ->where('is_active', true)
            ->with('user:id,name,email,status')
            ->get()
            // `modules` is a json list and an empty one means "serves both", so
            // this is decided in PHP rather than as a fragile JSON query.
            ->filter(fn (MedicalDoctorProfile $p) => $module === null || $p->servesModule($module))
            // A suspended login is not a doctor who can be examining anybody.
            ->filter(fn (MedicalDoctorProfile $p) => ($p->user?->status ?? 'active') === 'active')
            ->sortBy(fn (MedicalDoctorProfile $p) => mb_strtolower((string) $p->user?->name))
            ->map(fn (MedicalDoctorProfile $p) => [
                'user_id'       => (int) $p->user_id,
                'name'          => $p->user?->name,
                'license_no'    => $p->license_no,
                'council'       => $p->council,
                'qualification' => $p->qualification,
                'designation'   => $p->designation,
                'clinic_name'   => $p->clinic_name,
                // A doctor with no licence may examine but may not issue, so the
                // picker can say so rather than letting someone find out at the
                // point of printing a certificate.
                'is_signable'   => $p->isSignable(),
            ])
            ->values();
    }

    /**
     * Resolve a chosen doctor into the columns a medical record stores.
     *
     * Returns [] when nothing was chosen, or when the id is not an active
     * doctor of this tenant — so a forged or stale id degrades to "no internal
     * doctor was picked" rather than writing a half-filled identity.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(?int $doctorUserId, int $tenantId, ?string $module = null): array
    {
        if (! $doctorUserId) {
            return [];
        }

        $profile = MedicalDoctorProfile::forTenant($tenantId)
            ->where('user_id', $doctorUserId)
            ->where('is_active', true)
            ->with('user:id,name,status')
            ->first();

        if (! $profile || ($profile->user?->status ?? null) !== 'active') {
            return [];
        }

        if ($module !== null && ! $profile->servesModule($module)) {
            return [];
        }

        return [
            'doctor_user_id'       => (int) $profile->user_id,
            'doctor_license_no'    => $profile->license_no,
            'doctor_council'       => $profile->council,
            'doctor_qualification' => $profile->qualification,
            'examiner_name'        => $profile->user?->name,
            'clinic_name'          => $profile->clinic_name,
            // Choosing from the in-house directory IS the statement that this
            // was an internal examination.
            'exam_type'            => 'internal',
        ];
    }

    /**
     * Fold a chosen doctor into a validated payload, on the way to the service.
     *
     * The snapshot WINS over whatever the client sent for those fields. That is
     * the point: if someone picks Dr Rao and also types a different licence
     * number, the number on the record is the one in Dr Rao's profile. A
     * certificate is a legal document and its licence number is not a free-text
     * field once a real doctor has been named.
     *
     * The typed name still stands when nobody was picked, which is how an
     * outside doctor — or one not yet in the directory — keeps working exactly
     * as before.
     *
     * @param  array<string, mixed>  $data  validated request data
     * @return array<string, mixed>
     */
    public static function applyTo(array $data, int $tenantId, ?string $module = null): array
    {
        $snapshot = self::snapshot(
            isset($data['doctor_user_id']) ? (int) $data['doctor_user_id'] : null,
            $tenantId,
            $module,
        );

        if (! $snapshot) {
            // Nothing chosen, or an id that no longer resolves. Drop the key so
            // a stale id is never written as a dangling reference.
            unset($data['doctor_user_id']);

            return $data;
        }

        return [...$data, ...$snapshot];
    }
}
