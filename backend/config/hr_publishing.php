<?php

use App\Services\Hr\Publishing\CareerPortalChannel;

return [
    /*
     | Base URL of the public Career Portal SPA. Used to build the public job URL
     | when a posting is published to the "careers" channel.
     */
    'career_portal_url' => env('CAREER_PORTAL_URL', 'http://localhost:5173/careers'),

    /*
     | Job distribution channels. `class` implements App\Services\Hr\Publishing\JobChannel.
     | Channels with a null class are recognised but "not yet integrated" — adding a
     | real integration (LinkedIn, Naukri, Indeed, TrulyTalents) is just: write the
     | class, drop it in here. No other code changes required.
     */
    'channels' => [
        'careers'      => ['label' => 'Career Portal', 'class' => CareerPortalChannel::class],
        'linkedin'     => ['label' => 'LinkedIn',      'class' => null],
        'naukri'       => ['label' => 'Naukri',        'class' => null],
        'indeed'       => ['label' => 'Indeed',        'class' => null],
        'trulytalents' => ['label' => 'TrulyTalents',  'class' => null],
    ],
];
