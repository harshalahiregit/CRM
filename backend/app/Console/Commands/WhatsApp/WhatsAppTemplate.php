<?php

namespace App\Console\Commands\WhatsApp;

use App\Services\WhatsApp\MediaUploader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Creates the one template every CRM notification goes out as.
 *
 * Doing this by hand in Business Manager is where these integrations stall:
 * the rules are unwritten and a rejection is a day lost. Two of them bite every
 * first attempt — a body may not START or END with a variable, and a template
 * whose body is only variables is refused as having no content. The body below
 * is shaped around both.
 *
 * Submits for review; it does not send anything. Meta usually answers in
 * minutes. Check with `php artisan whatsapp:doctor`.
 */
class WhatsAppTemplate extends Command
{
    protected $signature = 'whatsapp:template
                            {--name= : template name (default: the configured one)}
                            {--language=en}
                            {--logo= : path to a PNG/JPG to use as an image header}
                            {--dry-run : print what would be submitted and stop}';

    protected $description = 'Create the notification message template on the WhatsApp Business account';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: config('whatsapp.cloud.notification_template'));
        $language = (string) $this->option('language');
        $waba = (string) config('whatsapp.cloud.waba_id');
        $token = (string) config('whatsapp.cloud.token');

        if ($waba === '' || $token === '') {
            $this->error('WHATSAPP_CLOUD_WABA_ID and WHATSAPP_CLOUD_TOKEN must be set.');

            return self::FAILURE;
        }

        $components = [];

        // An image header is what puts the company's mark on the message
        // itself. Without one a notification is a plain grey bubble and the
        // only branding is the sender avatar, which is easy to miss in a busy
        // chat list. The logo is uploaded once, here, and baked into the
        // template as an example; each SEND then supplies the actual image.
        if ($logo = $this->option('logo')) {
            $handle = $this->uploadLogo($logo);
            if ($handle === null) {
                return self::FAILURE;
            }
            $components[] = [
                'type'    => 'HEADER',
                'format'  => 'IMAGE',
                'example' => ['header_handle' => [$handle]],
            ];
        }

        $payload = [
            'name'       => $name,
            'language'   => $language,
            'category'   => 'UTILITY',
            'components' => array_merge($components, [
                [
                    'type' => 'BODY',
                    // Wording matters as much as the requested category, and
                    // getting it wrong is silent. Asking for UTILITY is only a
                    // request: Meta classifies from the text, and the first
                    // version of this body — "Update from Sangoe: {{1}} ...
                    // Open the Sangoe app for details." — was approved as
                    // MARKETING. Marketing templates are subject to per-person
                    // frequency caps and opt-out, and once a recipient is over
                    // that line Meta ACCEPTS the send, returns a real message
                    // id, and never delivers it. That is indistinguishable from
                    // a working integration until somebody checks a handset.
                    //
                    // So the body now names the thing it is about: a request
                    // the person themselves filed in this workspace. Static
                    // text also wraps every variable — Meta rejects a body that
                    // opens or closes on one, or that runs two together.
                    'text' => "Your Sangoe HR request has been updated.\n\nUpdate: {{1}}\n\nDetails: {{2}}\n\nSign in to the Sangoe app to see the full record.",
                    'example' => [
                        'body_text' => [[
                            'Leave approved',
                            'Your leave from 10 Sep to 12 Sep has been approved by Priya Sharma.',
                        ]],
                    ],
                ],
                ['type' => 'FOOTER', 'text' => 'Sangoe CRM'],
            ]),
        ];

        if ($this->option('dry-run')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $res = Http::withToken($token)->asJson()
            ->post("https://graph.facebook.com/v21.0/{$waba}/message_templates", $payload);

        if ($res->successful() && ! $res->json('error')) {
            $this->info("Submitted '{$name}' ({$language}) for review.");
            $this->line('  id     : '.$res->json('id'));
            $this->line('  status : '.$res->json('status'));
            $this->line('Check approval with: php artisan whatsapp:doctor');

            return self::SUCCESS;
        }

        $err = $res->json('error') ?? [];
        $this->error('Meta refused the template: '.($err['message'] ?? 'HTTP '.$res->status()));
        if (! empty($err['error_user_msg'])) {
            $this->line('  '.$err['error_user_msg']);
        }

        return self::FAILURE;
    }

    private function uploadLogo(string $path): ?string
    {
        $appId = (string) config('whatsapp.cloud.app_id');
        if ($appId === '') {
            $this->error('WHATSAPP_CLOUD_APP_ID must be set to upload a header image.');

            return null;
        }

        $this->line('Uploading '.basename($path).' …');

        $res = (new MediaUploader(
            (string) config('whatsapp.cloud.token'),
            $appId,
            (string) config('whatsapp.cloud.api_version', 'v21.0'),
        ))->uploadForTemplate($path);

        if (! $res['ok']) {
            $this->error('Upload failed: '.$res['error']);

            return null;
        }

        $this->line('  handle acquired.');

        return $res['handle'];
    }
}
