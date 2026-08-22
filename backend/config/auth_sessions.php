<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enterprise Session Management
    |--------------------------------------------------------------------------
    |
    | concurrency = 'single' → one active session per user; a new login revokes
    |                          every prior token.
    | concurrency = 'multi'  → concurrent sessions allowed. `max_devices` caps
    |                          them and evicts the oldest beyond the cap;
    |                          0 means unlimited.
    |
    | Default is multi/unlimited. 'single' meant signing in on a phone silently
    | signed you out on the desktop, and two browsers open at once fought each
    | other — each login killing the other's token, which reads as being logged
    | out every minute or so rather than as a policy.
    |
    | Tighten it per-environment when a deployment genuinely needs one session
    | per user, e.g. AUTH_SESSION_CONCURRENCY=multi with AUTH_SESSION_MAX_DEVICES=3.
    |
    */
    'concurrency'      => env('AUTH_SESSION_CONCURRENCY', 'multi'),
    'max_devices'      => (int) env('AUTH_SESSION_MAX_DEVICES', 0),

    // Idle timeout (minutes) for non-"remember me" sessions. 0 disables it.
    'idle_minutes'     => (int) env('AUTH_SESSION_IDLE_MINUTES', 30),

    // Token lifetimes (days).
    'token_days'       => (int) env('AUTH_TOKEN_DAYS', 30),
    'remember_me_days' => (int) env('AUTH_REMEMBER_DAYS', 30),

];
