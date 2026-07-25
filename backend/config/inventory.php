<?php

return [
    /*
     * Base URL of the front-end SPA, used to build the deep links in inventory
     * alert email. Same reasoning as the helpdesk config: the API and the React
     * SPA run on different origins in dev (API on :8000, SPA on :5173), so a
     * link built from app.url would send the reader to the API. Defaults to
     * app.url so a single-origin production deploy needs no extra config.
     */
    'app_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),

    /*
     * Couriers.
     *
     * The keys are deliberately blank. Shipping works without them — the person
     * types the tracking number off the courier's own slip and CarrierService
     * records it as a manual booking — and the tracking LINK still works,
     * because that is just a URL pattern and needs no account.
     *
     * To connect one for real: put its key in .env (e.g. DELHIVERY_API_KEY),
     * implement the driver call in CarrierService::callCarrier(), and nothing
     * above that class has to change.
     */
    'carriers' => [
        'delhivery' => [
            'label'     => 'Delhivery',
            'api_key'   => env('DELHIVERY_API_KEY'),
            'track_url' => 'https://www.delhivery.com/track/package/{number}',
        ],
        'bluedart' => [
            'label'     => 'Blue Dart',
            'api_key'   => env('BLUEDART_API_KEY'),
            'track_url' => 'https://www.bluedart.com/tracking?trackFor=0&trackNo={number}',
        ],
        'shiprocket' => [
            'label'     => 'Shiprocket',
            'api_key'   => env('SHIPROCKET_API_KEY'),
            'track_url' => 'https://shiprocket.co/tracking/{number}',
        ],
        'dtdc' => [
            'label'     => 'DTDC',
            'api_key'   => env('DTDC_API_KEY'),
            'track_url' => 'https://www.dtdc.in/tracking.asp?strCnno={number}',
        ],
        // Own van, customer collection, hand delivery — no tracking to speak of,
        // but the parcel still has to be recorded as gone.
        'own' => [
            'label'     => 'Own transport / collected',
            'api_key'   => null,
            'track_url' => null,
        ],
    ],
];
