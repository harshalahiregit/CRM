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
    | Supported: 'cloud' (Meta WhatsApp Cloud API) and 'twilio'.
    |
    | These are FALLBACKS. A tenant with its own row in tenant_whatsapp_settings
    | sends from its own number and token; everyone else falls back to here.
    |
    */

    'provider' => env('WHATSAPP_PROVIDER', 'cloud'),

    /*
    |--------------------------------------------------------------------------
    | Meta WhatsApp Cloud API
    |--------------------------------------------------------------------------
    |
    | The token MUST be a System User token from Business Settings. A token
    | copied out of the Graph API Explorer is a USER token and expires in about
    | an hour, which looks exactly like the integration breaking by itself.
    |
    */

    'cloud' => [
        'token'           => env('WHATSAPP_CLOUD_TOKEN'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
        'waba_id'         => env('WHATSAPP_CLOUD_WABA_ID'),
        // Meta's resumable-upload endpoint hangs off the APP, not the phone
        // number or the WABA — an image header cannot be created without it.
        'app_id'          => env('WHATSAPP_CLOUD_APP_ID'),
        'api_version'     => env('WHATSAPP_CLOUD_API_VERSION', 'v21.0'),

        /*
         | The approved template every business-initiated notification goes out
         | as. WhatsApp only carries free-form text inside the 24-hour window
         | that opens when the person messages the business, and nothing a CRM
         | sends is inside it, so this template is load-bearing rather than
         | decorative. Create it with `php artisan whatsapp:template`.
         |
         | Body:   Update from Sangoe: {{1}}
         |
         |         {{2}}
         |
         |         Open the Sangoe app for details.
         */
        /*
         | 'sangoe_hr_update', not 'sangoe_notification'.
         |
         | Both are APPROVED, but Meta categorised the older one as MARKETING —
         | it assigns the category from the wording, whatever the submission
         | asks for. Marketing templates carry per-recipient frequency caps and
         | an opt-out, and once a person is past that line Graph ACCEPTS the
         | send, returns a real message id, and delivers nothing. Measured on
         | 2026-09-07: the UTILITY template arrived on the handset and the
         | MARKETING one, sent seconds later to the same number, did not.
         | Notifications are transactional, so they belong in UTILITY.
         */
        'notification_template'          => env('WHATSAPP_CLOUD_TEMPLATE', 'sangoe_hr_update'),
        'notification_template_language' => env('WHATSAPP_CLOUD_TEMPLATE_LANG', 'en'),

        /*
         | The same template with the company logo across the top. Used only
         | when Settings > General > Branding holds a PUBLICLY reachable https
         | logo — Meta fetches that URL itself at send time, so a link only this
         | server can reach yields a message with a broken image and no error
         | anybody would see. Without one we send the plain template, which is a
         | worse-looking message rather than a failed one.
         |
         | Create it with: php artisan whatsapp:template --name=… --logo=…
         */
        'notification_template_with_logo' => env('WHATSAPP_CLOUD_TEMPLATE_LOGO', 'sangoe_notification_logo'),

        /*
         | Templates for particular notifications, keyed "Module Event".
         |
         | A template's parameter count has to match EXACTLY or Meta rejects the
         | send, so each entry says which values fill it. Tokens: 'name' (the
         | recipient's), 'title', 'body'. Anything not listed here falls back to
         | the generic template above with [title, body].
         |
         | sangoe_clockout_reminder was approved for the old WorkDo system and
         | takes one parameter: "Hi {{1}}, you have been clocked in for 10 hours."
         */
        'templates' => [
            'Attendance Clock-out reminder' => [
                'name'     => env('WHATSAPP_CLOUD_CLOCKOUT_TEMPLATE', 'sangoe_clockout_reminder'),
                'language' => 'en',
                'params'   => ['name'],
            ],
        ],
    ],

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
