<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Integration Status
    |--------------------------------------------------------------------------
    |
    | This option controls whether WhatsApp notifications are enabled.
    | Set to false to disable all WhatsApp functionality.
    |
    */

    'enabled' => env('WHATSAPP_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Provider
    |--------------------------------------------------------------------------
    |
    | The provider used for sending WhatsApp messages.
    | Currently supported: 'twilio'
    |
    */

    'provider' => env('WHATSAPP_PROVIDER', 'twilio'),

    /*
    |--------------------------------------------------------------------------
    | Twilio Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Twilio WhatsApp integration.
    |
    */

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | WhatsApp messages are sent via queue for reliability.
    |
    */

    'queue' => env('WHATSAPP_QUEUE', 'default'),
    'retry' => env('WHATSAPP_RETRY_TIMES', 3),

    /*
    |--------------------------------------------------------------------------
    | Message Settings
    |--------------------------------------------------------------------------
    */

    'company_name' => env('APP_NAME', 'Laravel'),
    
    /*
    |--------------------------------------------------------------------------
    | Interview Reminder Settings
    |--------------------------------------------------------------------------
    */
    
    'reminders' => [
        'enabled' => env('WHATSAPP_REMINDERS_ENABLED', true),
        'hours_before' => env('WHATSAPP_REMINDER_HOURS', 24),
    ],

];
