<?php

use App\Services\Hr\Publishing\CareerPortalChannel;
use App\Services\Hr\Publishing\IndeedChannel;
use App\Services\Hr\Publishing\LinkedInChannel;
use App\Services\Hr\Publishing\NaukriChannel;
use App\Services\Hr\Publishing\TrulyTalentsChannel;

/*
 | Every REST board shares the same option shape, so the defaults live in one
 | place. A board overrides only what it actually does differently.
 */
$board = fn (string $prefix, array $overrides = []) => array_merge([
    'base_url'            => env($prefix.'_BASE_URL'),
    'api_key'             => env($prefix.'_API_KEY'),
    'publish_path'        => env($prefix.'_PUBLISH_PATH', 'jobs'),
    'unpublish_path'      => env($prefix.'_UNPUBLISH_PATH', 'jobs'),
    'timeout'             => (int) env($prefix.'_TIMEOUT', 20),
    'retries'             => (int) env($prefix.'_RETRIES', 2),
    'response_ref_key'    => env($prefix.'_REF_KEY', 'id'),
    'response_url_key'    => env($prefix.'_URL_KEY', 'url'),
    'response_status_key' => env($prefix.'_STATUS_KEY', 'status'),
], $overrides);

return [
    /*
     | Base URL of the public Career Portal SPA. Used to build the public job URL
     | when a posting is published to the "careers" channel.
     */
    'career_portal_url' => env('CAREER_PORTAL_URL', 'http://localhost:5173/careers'),

    /*
     | Base URL of the public candidate Onboarding portal SPA. The token is
     | appended: {onboarding_portal_url}/{access_token}.
     */
    'onboarding_portal_url' => env('ONBOARDING_PORTAL_URL', 'http://localhost:5173/onboarding'),

    /*
     | Base URL of the public candidate Offer Letter portal SPA. The token is
     | appended: {offer_portal_url}/{access_token}.
     */
    'offer_portal_url' => env('OFFER_PORTAL_URL', 'http://localhost:5173/offer'),

    /*
     | Job distribution channels. `class` implements App\Services\Hr\Publishing\JobChannel.
     | Channels with a null class are recognised but "not yet integrated" — adding a
     | real integration (LinkedIn, Naukri, Indeed, TrulyTalents) is just: write the
     | class, drop it in here. No other code changes required.
     */
    'channels' => [
        'careers'      => ['label' => 'Career Portal', 'class' => CareerPortalChannel::class],
        'linkedin'     => ['label' => 'LinkedIn',      'class' => LinkedInChannel::class],
        'naukri'       => ['label' => 'Naukri',        'class' => NaukriChannel::class],
        'indeed'       => ['label' => 'Indeed',        'class' => IndeedChannel::class],
        'trulytalents' => ['label' => 'TrulyTalents',  'class' => TrulyTalentsChannel::class],
    ],

    /*
     | Per-board settings (#12, #13). Left blank a board reports itself as
     | unconfigured and a publish attempt is recorded as `failed` with that reason
     | — never a silent success against a board the job never reached.
     |
     | The response keys are dot-paths into the JSON the board returns, so a change
     | in their payload shape is a config edit, not a code change. `field_map`
     | renames outgoing payload keys; `status_map` teaches the board's own status
     | vocabulary. Both mean a board quirk never becomes a subclass override.
     */
    'trulytalents' => $board('TRULYTALENTS'),

    'linkedin' => $board('LINKEDIN', [
        'publish_path'   => env('LINKEDIN_PUBLISH_PATH', 'jobPostings'),
        'unpublish_path' => env('LINKEDIN_UNPUBLISH_PATH', 'jobPostings'),
        'field_map' => [
            'title'           => 'jobTitle',
            'description'     => 'jobDescription',
            'location'        => 'jobLocation',
            'employment_type' => 'employmentStatus',
            'external_id'     => 'externalJobPostingId',
        ],
        'status_map' => [
            'published' => ['LISTED', 'OPEN'],
            'removed'   => ['CLOSED', 'DRAFT_CLOSED'],
            'expired'   => ['EXPIRED'],
        ],
    ]),

    'naukri' => $board('NAUKRI', [
        'publish_path'   => env('NAUKRI_PUBLISH_PATH', 'job/post'),
        'unpublish_path' => env('NAUKRI_UNPUBLISH_PATH', 'job'),
        'response_ref_key' => env('NAUKRI_REF_KEY', 'data.jobId'),
        'response_url_key' => env('NAUKRI_URL_KEY', 'data.jobUrl'),
        'field_map' => [
            'title'      => 'jobTitle',
            'experience' => 'experienceRange',
            'openings'   => 'vacancies',
        ],
        'status_map' => [
            'published' => ['ACTIVE'],
            'removed'   => ['INACTIVE', 'DELETED'],
            'expired'   => ['EXPIRED'],
        ],
    ]),

    'indeed' => $board('INDEED', [
        'publish_path'   => env('INDEED_PUBLISH_PATH', 'jobs'),
        'unpublish_path' => env('INDEED_UNPUBLISH_PATH', 'jobs'),
        'field_map' => [
            'title'       => 'jobtitle',
            'description' => 'jobdescription',
            'location'    => 'joblocation',
        ],
        'status_map' => [
            'published' => ['ACTIVE', 'SPONSORED'],
            'removed'   => ['CLOSED', 'PAUSED'],
            'expired'   => ['EXPIRED'],
        ],
    ]),
];
