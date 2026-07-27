<?php

namespace App\Services\Notifications;

/**
 * Template renderer (Central Notification Engine). Replaces {{placeholder}} tokens
 * with values from the dispatch context (employee, department, designation,
 * manager, remaining_days, date, module, company, …). Unknown tokens resolve to an
 * empty string so a missing value never leaks "{{x}}" into a notification.
 */
class TemplateRenderer
{
    public function render(?string $text, array $context): string
    {
        if (! $text) {
            return '';
        }

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($context) {
            $key = $m[1];
            $val = $context[$key] ?? '';

            return is_scalar($val) ? (string) $val : '';
        }, $text);
    }

    /** Render subject + body together. */
    public function renderTemplate(?string $subject, ?string $body, array $context): array
    {
        return [
            'subject' => $this->render($subject, $context),
            'body'    => $this->render($body, $context),
        ];
    }
}
