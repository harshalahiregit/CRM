<?php

namespace App\Console\Commands\WhatsApp;

use App\Services\WhatsApp\TenantWhatsApp;
use Illuminate\Console\Command;

/**
 * Sends one real WhatsApp, so "is it wired up" is answered by a phone buzzing
 * rather than by reading code.
 *
 * Defaults to the hello_world template every Cloud API account starts with, so
 * this works before anything of ours has been approved.
 */
class WhatsAppTest extends Command
{
    protected $signature = 'whatsapp:test
                            {to : mobile number, with or without +91}
                            {--tenant= : send from this workspace\'s own number}
                            {--template=hello_world}
                            {--language=en_US}
                            {--text= : send free text instead (only works inside the 24h window)}';

    protected $description = 'Send a test WhatsApp message';

    public function handle(TenantWhatsApp $tenants): int
    {
        $tenantId = $this->option('tenant');
        $client = $tenantId ? $tenants->clientFor((int) $tenantId) : $tenants->platformClient();

        if (! $client) {
            $this->error('No WhatsApp credentials configured.');

            return self::FAILURE;
        }

        $to = (string) $this->argument('to');
        $text = $this->option('text');

        $result = $text
            ? $client->sendText($to, (string) $text)
            : $client->sendTemplate($to, (string) $this->option('template'), (string) $this->option('language'));

        if ($result->ok) {
            $this->info('Accepted by Meta. Message id: '.$result->messageId);
            // Accepted is not delivered. Say so, or a green tick here gets read
            // as proof the handset received it.
            $this->line('That means Meta took it, not that the handset has it — delivery arrives on the webhook.');

            return self::SUCCESS;
        }

        $this->error('Failed: '.$result->error);

        if ($result->isOutsideServiceWindow()) {
            $this->line('Free text only works within 24h of THEM messaging you. Use a template.');
        }
        if ($result->code === 131030) {
            $this->line('This number is not on the account\'s allowed-recipient list.');
            $this->line('An unverified account can only message numbers added under');
            $this->line('WhatsApp > API Setup > "To" — add it there first.');
        }

        return self::FAILURE;
    }
}
