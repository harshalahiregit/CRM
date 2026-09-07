<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;

/**
 * Meta's WhatsApp Cloud API, and nothing else -- no database, no logging table,
 * no tenant. One place that knows the wire format, so the rest of the app never
 * has to think about Graph.
 *
 * TWO THINGS ABOUT THIS API THAT ARE NOT OPTIONAL:
 *
 * 1. Free-form text only reaches somebody inside the 24-hour window that opens
 *    when THEY message the business. Outside it Meta rejects the send (error
 *    131047) no matter how well-formed. Anything the business initiates -- a
 *    leave approval, a clock-out nudge -- has to be an approved TEMPLATE.
 *    So sendTemplate() is the real method here and sendText() is for replies.
 *
 * 2. A send is not a delivery. Meta returns 200 with a message id the moment it
 *    accepts the request; whether it reached the handset arrives later on the
 *    webhook. Treating the 200 as "delivered" is how a notification system
 *    reports 100% success while nobody is getting anything.
 */
class CloudApiClient
{
    public function __construct(
        private string $token,
        private string $phoneNumberId,
        private string $apiVersion = 'v21.0',
    ) {
    }

    /**
     * An approved template. The only thing that works outside the 24h window.
     *
     * $headerImage is a PUBLIC https url. Meta fetches it at send time, so a
     * link only this server can reach produces a message with a broken header
     * rather than an error anyone would notice.
     */
    public function sendTemplate(
        string $to,
        string $template,
        string $language,
        array $bodyParams = [],
        ?string $headerImage = null,
    ): CloudApiResult {
        $components = [];

        if ($headerImage !== null && $headerImage !== '') {
            $components[] = [
                'type'       => 'header',
                'parameters' => [['type' => 'image', 'image' => ['link' => $headerImage]]],
            ];
        }

        if ($bodyParams !== []) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn ($p) => ['type' => 'text', 'text' => (string) $p],
                    array_values($bodyParams)
                ),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => self::normalise($to),
            'type'              => 'template',
            'template'          => [
                'name'     => $template,
                'language' => ['code' => $language],
            ],
        ];

        if ($components !== []) {
            $payload['template']['components'] = $components;
        }

        return $this->post($payload);
    }

    /** Free-form text. Only lands inside the 24-hour customer service window. */
    public function sendText(string $to, string $body): CloudApiResult
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'to'                => self::normalise($to),
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => $body],
        ]);
    }

    /** Who this token and phone number actually are -- used by the Test button. */
    public function identity(): CloudApiResult
    {
        try {
            return $this->fetchIdentity();
        } catch (\Throwable $e) {
            return CloudApiResult::unreachable($e->getMessage());
        }
    }

    private function fetchIdentity(): CloudApiResult
    {
        $res = Http::withToken($this->token)
            ->timeout(15)
            ->get($this->endpoint(), [
                // status/name_status/code_verification_status are the three that
                // silently stop a CONNECTED-looking number from sending.
                'fields' => 'verified_name,display_phone_number,quality_rating,platform_type,'
                           .'status,name_status,code_verification_status,account_mode',
            ]);

        return CloudApiResult::from($res);
    }

    private function post(array $payload): CloudApiResult
    {
        // A connection failure THROWS rather than returning a response — DNS
        // down, no route, timeout. Uncaught, it escaped past the caller's
        // logging, so the WhatsApp log row stayed at 'queued' for ever and a
        // send that had definitively failed looked like one still in flight.
        try {
            $res = Http::withToken($this->token)
                ->timeout(20)
                ->asJson()
                ->post($this->endpoint().'/messages', $payload);
        } catch (\Throwable $e) {
            return CloudApiResult::unreachable($e->getMessage());
        }

        return CloudApiResult::from($res);
    }

    private function endpoint(): string
    {
        return "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}";
    }

    /**
     * Meta wants digits only, in full international form, with no '+' and no
     * 'whatsapp:' prefix (that one is Twilio's). A ten-digit Indian mobile is
     * the overwhelmingly common case here and is assumed to be +91 -- a number
     * sent without a country code is silently delivered to whoever holds it in
     * whichever country Meta guesses, so guessing once, explicitly, beats
     * guessing implicitly.
     */
    public static function normalise(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 10) {
            return '91'.$digits;
        }

        // 011 91 98... and 00 91 98... are both written by people.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }
}
