<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Appointment;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Log;

/**
 * Appointments against a lead (or any subject, later).
 *
 * Tenant-scoped like the rest of Sales: reads go through forTenant(), and every
 * write asserts ownership before touching the record.
 */
class AppointmentService
{
    public function listForSubject(int $tenantId, string $subjectType, int $subjectId)
    {
        return Appointment::forTenant($tenantId)
            ->forSubject($subjectType, $subjectId)
            ->with('assignee:id,name', 'creator:id,name')
            // Soonest first among upcoming, then past most-recent-first: the next
            // thing you have to do should be at the top.
            ->orderByRaw('CASE WHEN status = \'scheduled\' THEN 0 ELSE 1 END')
            ->orderBy('starts_at')
            ->get();
    }

    /** Everything coming up across the workspace — feeds a dashboard/agenda view. */
    public function upcoming(int $tenantId, int $days = 14)
    {
        return Appointment::forTenant($tenantId)
            ->upcoming()
            ->where('starts_at', '<=', now()->addDays($days))
            ->with('assignee:id,name')
            ->orderBy('starts_at')
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): Appointment
    {
        $data = $this->clean($data);
        $this->assertTimes($data);

        $appointment = Appointment::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
        ]);

        Log::channel('sales')->info('Appointment created', [
            'appointment_id' => $appointment->id, 'tenant_id' => $tenantId,
        ]);

        return $appointment->load('assignee:id,name', 'creator:id,name');
    }

    public function update(Appointment $appointment, array $data, int $tenantId): Appointment
    {
        $this->assertTenant($appointment, $tenantId);

        $data = $this->clean($data);
        // Validate against the merged result, so sending only ends_at is still
        // checked against the stored starts_at.
        $this->assertTimes([
            'starts_at' => $data['starts_at'] ?? $appointment->starts_at,
            'ends_at'   => array_key_exists('ends_at', $data) ? $data['ends_at'] : $appointment->ends_at,
        ]);

        // The subject is what an appointment belongs to; moving it between records
        // is not an edit.
        unset($data['subject_type'], $data['subject_id']);

        $appointment->update($data);

        return $appointment->fresh()->load('assignee:id,name', 'creator:id,name');
    }

    /**
     * Close out an appointment.
     *
     * An outcome is required when completing — an appointment marked done with no
     * record of what happened is the thing that makes these logs useless.
     */
    public function complete(Appointment $appointment, array $data, int $tenantId): Appointment
    {
        $this->assertTenant($appointment, $tenantId);

        $outcome = trim((string) ($data['outcome'] ?? ''));
        if ($outcome === '') {
            throw new BusinessException('Add a short outcome so the appointment history is useful.');
        }

        $appointment->update([
            'status'  => $data['status'] ?? 'completed',
            'outcome' => HtmlSanitizer::clean($outcome),
        ]);

        return $appointment->fresh()->load('assignee:id,name', 'creator:id,name');
    }

    public function delete(Appointment $appointment, int $tenantId): void
    {
        $this->assertTenant($appointment, $tenantId);
        $appointment->delete();
    }

    private function clean(array $data): array
    {
        $data = HtmlSanitizer::cleanFields($data, ['description', 'outcome']);

        // A meeting URL only means anything for a video appointment, and letting a
        // stale one linger after switching to in-person is misleading.
        if (($data['mode'] ?? null) !== null && $data['mode'] !== 'video') {
            $data['meeting_url'] = null;
        }

        return $data;
    }

    /** An appointment that ends before it starts is a data-entry slip, not a range. */
    private function assertTimes(array $data): void
    {
        $start = $data['starts_at'] ?? null;
        $end   = $data['ends_at'] ?? null;

        if ($start && $end && strtotime((string) $end) < strtotime((string) $start)) {
            throw new BusinessException('The end time cannot be before the start time.');
        }
    }

    private function assertTenant(Appointment $appointment, int $tenantId): void
    {
        if ((int) $appointment->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
