<?php

return [
    /*
     * Base URL of the front-end SPA, used to build the "Open task" deep links in
     * outbound email. Same reasoning as config/helpdesk.php and config/inventory.php:
     * the Laravel API and the React SPA run on different origins in dev (API on
     * :8000, SPA on :5173), so a link built from app.url would send the reader to
     * the API. Defaults to app.url so a single-origin production deploy needs no
     * extra config.
     */
    'app_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),
];
