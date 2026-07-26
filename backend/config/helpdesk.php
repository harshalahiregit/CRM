<?php

return [
    /*
     * Base URL of the front-end SPA, used to build the "Open ticket" deep links
     * in outbound email. This is NOT app.url: the Laravel API and the React SPA
     * run on different origins in dev (API on :8000, SPA on :5173), and email
     * links must point at the SPA. Defaults to app.url so production single-origin
     * setups keep working without extra config.
     */
    'app_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),

    /*
     * Address that receives customer email replies. Outbound helpdesk mail sets
     * its Reply-To to the plus-addressed form:
     *     support+{ticketId}-{token}@your-domain
     * so an inbound reply is routed back to the correct ticket thread.
     */
    'inbound_address' => env(
        'HELPDESK_INBOUND_ADDRESS',
        'support@'.(parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost')
    ),

    /*
     * Shared secret an inbound-email provider (Mailgun/SendGrid/Postmark routes,
     * or an IMAP poller) must present to POST a parsed reply into a ticket.
     * Set HELPDESK_INBOUND_SECRET in the environment for any real deployment.
     */
    'inbound_secret' => env('HELPDESK_INBOUND_SECRET', 'change-me-inbound-secret'),
];
