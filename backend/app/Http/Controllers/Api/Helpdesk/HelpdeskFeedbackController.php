<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Services\Helpdesk\HelpdeskService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HelpdeskFeedbackController extends Controller
{
    public function __construct(private HelpdeskService $helpdesk)
    {
    }

    /**
     * One-click feedback capture from the closure email.
     *
     * Public route protected by the 'signed' middleware — the signature on the
     * emailed URL is the authorization, so no login is required. Saves the star
     * rating and returns a self-contained "Thank you" HTML page (no redirects,
     * no multi-step flow).
     */
    public function submit(Request $request, int $ticket, int $rating): Response
    {
        $this->helpdesk->submitFeedbackOneClick($ticket, $rating);

        $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Thank you</title>
        </head>
        <body style="margin:0;font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;display:flex;min-height:100vh;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:16px;padding:40px;max-width:420px;text-align:center;box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                <div style="font-size:40px;color:#f59e0b;letter-spacing:4px;">{$stars}</div>
                <h1 style="margin:16px 0 8px;font-size:22px;color:#0f172a;">Thank you!</h1>
                <p style="margin:0;font-size:14px;color:#475569;line-height:1.6;">
                    Your {$rating}-star rating for ticket #{$ticket} has been recorded. We appreciate your feedback.
                </p>
            </div>
        </body>
        </html>
        HTML;

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    /**
     * One-click ticket reopen from the closure email.
     *
     * Same trust model as submit(): the 'signed' middleware validates the emailed
     * signature, so no login is required. Reopens the ticket, then shows a
     * confirmation page with a button to view it in the workspace.
     */
    public function reopen(Request $request, int $ticket): Response
    {
        $this->helpdesk->reopenOneClick($ticket);

        $appUrl = rtrim((string) config('helpdesk.app_url'), '/');
        $ticketUrl = "{$appUrl}/app/helpdesk/tickets/{$ticket}";

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Ticket reopened</title>
        </head>
        <body style="margin:0;font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;display:flex;min-height:100vh;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:16px;padding:40px;max-width:440px;text-align:center;box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                <div style="font-size:40px;">🔄</div>
                <h1 style="margin:16px 0 8px;font-size:22px;color:#0f172a;">Ticket #{$ticket} reopened</h1>
                <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.6;">
                    We've reopened your ticket and the support team has been notified. They'll pick it back up shortly.
                </p>
                <a href="{$ticketUrl}" style="display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;font-weight:bold;font-size:14px;padding:12px 24px;border-radius:10px;">View your ticket &rarr;</a>
            </div>
        </body>
        </html>
        HTML;

        return response($html, 200)->header('Content-Type', 'text/html');
    }
}
