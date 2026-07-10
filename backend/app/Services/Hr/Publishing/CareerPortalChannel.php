<?php

namespace App\Services\Hr\Publishing;

use App\Models\Hr\HrJobPosting;

/**
 * The built-in Career Portal channel. Publishing just flips the on_career_portal
 * flag — the public portal reads live, on-portal jobs directly from the DB, so
 * there's no external API to call.
 */
class CareerPortalChannel implements JobChannel
{
    public function key(): string
    {
        return 'careers';
    }

    public function label(): string
    {
        return 'Career Portal';
    }

    public function publish(HrJobPosting $job): array
    {
        $job->forceFill([
            'on_career_portal'    => true,
            'career_published_at' => $job->career_published_at ?? now(),
        ])->save();

        $slug = $job->tenant?->slug;
        $base = rtrim(config('hr_publishing.career_portal_url'), '/');

        // Tenant-aware public URLs, generated automatically from the tenant slug.
        $homeUrl = $slug ? "{$base}/{$slug}" : null;
        $jobUrl  = $slug ? "{$homeUrl}/jobs/{$job->id}" : null;

        return [
            'external_ref' => 'careers-'.$job->id,
            'external_url' => $jobUrl,     // primary public link = the direct job URL
            'meta'         => [
                'portal_home_url' => $homeUrl,
                'job_url'         => $jobUrl,
            ],
        ];
    }

    public function unpublish(HrJobPosting $job): void
    {
        $job->forceFill(['on_career_portal' => false])->save();
    }
}
