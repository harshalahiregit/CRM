<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enterprise Session Management
    |--------------------------------------------------------------------------
    |
    | concurrency = 'single' → one active session per user (the existing
    |                          behaviour; a new login revokes prior tokens).
    | concurrency = 'multi'  → up to `max_devices` concurrent sessions; the
    |                          oldest is evicted beyond the cap.
    |
    */
    'concurrency'      => env('AUTH_SESSION_CONCURRENCY', 'single'),
    'max_devices'      => (int) env('AUTH_SESSION_MAX_DEVICES', 1),

    // Idle timeout (minutes) for non-"remember me" sessions. 0 disables it.
    'idle_minutes'     => (int) env('AUTH_SESSION_IDLE_MINUTES', 30),

    // Token lifetimes (days).
    'token_days'       => (int) env('AUTH_TOKEN_DAYS', 30),
    'remember_me_days' => (int) env('AUTH_REMEMBER_DAYS', 30),

];
