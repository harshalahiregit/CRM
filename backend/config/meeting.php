<?php

/**
 * Online-meeting integration configuration.
 *
 * Set MEETING_PROVIDER in .env to enable a live provider.
 * Leave it as "stub" (the default) during local development — the stub returns
 * a placeholder link so the rest of the UI can be tested without API keys.
 *
 * Supported providers: google_meet | zoom | teams | stub
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Active provider
    |--------------------------------------------------------------------------
    | This value is read by MeetingProviderFactory to select which driver to
    | instantiate. Falls back to "stub" when the env variable is absent.
    */
    'provider' => env('MEETING_PROVIDER', 'stub'),

    /*
    |--------------------------------------------------------------------------
    | Jitsi
    |--------------------------------------------------------------------------
    | The server Jitsi rooms are created on, and which the in-app meeting room
    | embeds. It defaults to the free public instance, which needs no account
    | and no keys — but on that instance the person who STARTS a meeting has to
    | sign in once with Google/GitHub/Facebook, and 8x8 offer no uptime promise
    | or branding control there.
    |
    | Point JITSI_DOMAIN at your own Jitsi server (e.g. meet.yourcompany.com)
    | and both of those go away: no sign-in, your branding, your uptime. The
    | room screen reads the host out of each meeting's stored link, so existing
    | meetings keep working and only new links move to the new server.
    */
    'jitsi' => [
        'domain' => env('JITSI_DOMAIN', 'meet.jit.si'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Meet (via Google Calendar API + service account)
    |--------------------------------------------------------------------------
    */
    'google_meet' => [
        // Absolute path to the downloaded service-account JSON key file.
        'credentials_path' => env('GOOGLE_MEET_CREDENTIALS_JSON'),
        // Calendar ID in which events/meetings are created (usually primary)
        'calendar_id'      => env('GOOGLE_MEET_CALENDAR_ID', 'primary'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zoom (Server-to-Server OAuth — replaces legacy JWT)
    |--------------------------------------------------------------------------
    */
    'zoom' => [
        'account_id'    => env('ZOOM_ACCOUNT_ID'),
        'client_id'     => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft Teams (Microsoft Graph API)
    |--------------------------------------------------------------------------
    */
    'teams' => [
        'tenant_id'     => env('TEAMS_TENANT_ID'),
        'client_id'     => env('TEAMS_CLIENT_ID'),
        'client_secret' => env('TEAMS_CLIENT_SECRET'),
    ],

];
