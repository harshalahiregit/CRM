<?php

namespace App\Support\Email;

/**
 * The catalogue of shipped ("system") email templates and their categories.
 *
 * This is the ONLY place a template is declared — the service, API and UI all read
 * from here, so adding one is a single entry and adding a CATEGORY is a single
 * string. Modules may also register templates at runtime via register() from a
 * service provider, without touching this file or the engine.
 *
 * Subjects/bodies below intentionally mirror the mail the app already sends, so
 * templating a flow later is a like-for-like swap rather than a rewrite.
 */
final class EmailTemplateRegistry
{
    /** Runtime-registered templates (module service providers may add to these). */
    private static array $extra = [];

    /** Categories the UI groups by. Adding one needs no engine change. */
    public const CATEGORIES = [
        'authentication' => 'Authentication',
        'users'          => 'Users',
        'hr'             => 'HR',
        'recruitment'    => 'Recruitment',
        'employees'      => 'Employees',
        'payroll'        => 'Payroll',
        'attendance'     => 'Attendance',
        'leave'          => 'Leave',
        'purchase'       => 'Purchase',
        'sales'          => 'Sales',
        'finance'        => 'Finance',
        'inventory'      => 'Inventory',
        'projects'       => 'Projects',
        'helpdesk'       => 'Helpdesk',
        'tpv'            => 'TPV',
        'vendor'         => 'Vendor',
        'customer'       => 'Customer',
        'meetings'       => 'Meetings',
        'notifications'  => 'Notifications',
        'system'         => 'System',
    ];

    /** A tiny, email-client-safe wrapper so every shipped body looks consistent. */
    private static function wrap(string $inner): string
    {
        return '<p>'.$inner.'</p>'
            .'<p>Regards,<br>{{company.name}}</p>';
    }

    /**
     * key => [category, name, subject, body_html, description]
     * body_text is derived from body_html when a template does not define one.
     */
    public static function templates(): array
    {
        $t = [
            /* ── Authentication ─────────────────────────────────────────── */
            'auth.welcome' => ['authentication', 'Welcome / Account Created',
                'Welcome to {{company.name}}',
                self::wrap('Hi {{user.name}},<br><br>Your account has been created. You can sign in here: <a href="{{url.login}}">{{url.login}}</a>'),
                'Sent when a user account is created.'],
            'auth.password_reset' => ['authentication', 'Password Reset',
                'Reset your {{company.name}} password',
                self::wrap('Hi {{user.name}},<br><br>We received a request to reset your password. Use the link below — it expires shortly.<br><br><a href="{{url.reset}}">Reset password</a><br><br>If you did not request this, you can ignore this email.'),
                'Password reset link.'],
            'auth.password_changed' => ['authentication', 'Password Changed',
                'Your password was changed',
                self::wrap('Hi {{user.name}},<br><br>Your password was changed on {{date.now}}. If this was not you, contact {{company.email}} immediately.'),
                'Confirmation that a password changed.'],
            'auth.email_verification' => ['authentication', 'Email Verification',
                'Verify your email address',
                self::wrap('Hi {{user.name}},<br><br>Please confirm your email address:<br><br><a href="{{url.verify}}">Verify email</a>'),
                'Email-address verification link.'],

            /* ── Users ──────────────────────────────────────────────────── */
            'users.invitation' => ['users', 'User Invitation',
                'You have been invited to {{company.name}}',
                self::wrap('Hi {{user.name}},<br><br>You have been invited to join {{company.name}} on our CRM. Get started here: <a href="{{url.login}}">{{url.login}}</a>'),
                'Invite an internal user to the workspace.'],

            /* ── Recruitment ────────────────────────────────────────────── */
            'recruitment.application_received' => ['recruitment', 'Application Received',
                'Application Received - {{candidate.position}}',
                self::wrap('Dear {{candidate.name}},<br><br>Thank you for applying for {{candidate.position}}. Our team is reviewing your application and will be in touch.'),
                'Acknowledges a candidate application.'],
            'recruitment.application_status' => ['recruitment', 'Application Status Update',
                'Application Update - {{candidate.stage}}',
                self::wrap('Dear {{candidate.name}},<br><br>Your application for {{candidate.position}} has moved to <strong>{{candidate.stage}}</strong>.'),
                'Notifies a candidate of a stage change.'],
            'recruitment.interview_candidate' => ['recruitment', 'Interview Scheduled (Candidate)',
                'Interview Scheduled - {{candidate.position}}',
                self::wrap('Dear {{candidate.name}},<br><br>Your interview is scheduled for {{date.now}}. We look forward to speaking with you.'),
                'Interview invitation sent to the candidate.'],
            'recruitment.interview_interviewer' => ['recruitment', 'Interview Scheduled (Interviewer)',
                'New Interview Scheduled - {{candidate.name}}',
                self::wrap('Hi {{user.name}},<br><br>An interview with {{candidate.name}} for {{candidate.position}} has been scheduled.'),
                'Interview notification sent to the interviewer.'],
            'recruitment.offer_letter' => ['recruitment', 'Offer Letter',
                'Job Offer - {{candidate.position}}',
                self::wrap('Dear {{candidate.name}},<br><br>We are delighted to offer you the role of {{candidate.position}} at {{company.name}}.<br><br>Review and respond here: <a href="{{url.portal}}">{{url.portal}}</a>'),
                'Sends the offer to a candidate.'],
            'recruitment.onboarding_welcome' => ['recruitment', 'Onboarding Welcome',
                'Congratulations! You have been selected at {{company.name}}',
                self::wrap('Dear {{candidate.name}},<br><br>Welcome aboard! Please complete your onboarding here: <a href="{{url.portal}}">{{url.portal}}</a>'),
                'Welcome mail once a candidate is hired.'],

            /* ── Employees / HR ─────────────────────────────────────────── */
            'employees.welcome' => ['employees', 'Employee Welcome',
                'Welcome to the team, {{employee.name}}',
                self::wrap('Hi {{employee.name}},<br><br>Welcome to {{company.name}}. Your employee code is <strong>{{employee.code}}</strong>.'),
                'Sent when an employee record is created.'],
            'hr.document_request' => ['hr', 'Document Request',
                'Documents required - {{company.name}}',
                self::wrap('Hi {{employee.name}},<br><br>Please upload the outstanding documents for your record at your earliest convenience.'),
                'Requests outstanding employee documents.'],

            /* ── Leave / Attendance / Payroll ───────────────────────────── */
            'leave.applied' => ['leave', 'Leave Applied',
                'Leave request from {{employee.name}}',
                self::wrap('{{employee.name}} has applied for leave. Please review the request in the HR module.'),
                'Notifies an approver of a new leave request.'],
            'leave.decision' => ['leave', 'Leave Decision',
                'Your leave request has been {{status}}',
                self::wrap('Hi {{employee.name}},<br><br>Your leave request has been <strong>{{status}}</strong>.'),
                'Informs an employee of the approval decision.'],
            'attendance.summary' => ['attendance', 'Attendance Summary',
                'Attendance summary - {{date.today}}',
                self::wrap('Hi {{employee.name}},<br><br>Here is your attendance summary for {{date.today}}.'),
                'Periodic attendance summary.'],
            'payroll.payslip' => ['payroll', 'Payslip Published',
                'Your payslip for {{period}} is ready',
                self::wrap('Hi {{employee.name}},<br><br>Your payslip <strong>{{document.number}}</strong> for {{period}} is now available. Net pay: {{amount}}.'),
                'Notifies an employee that a payslip is available.'],

            /* ── Purchase / Vendor / TPV ────────────────────────────────── */
            'purchase.order_issued' => ['purchase', 'Purchase Order Issued',
                'Purchase Order {{document.number}}',
                self::wrap('Dear {{vendor.name}},<br><br>Please find Purchase Order <strong>{{document.number}}</strong> dated {{date.today}} for a total of {{amount}}.'),
                'Sends a PO to a vendor.'],
            'purchase.rfq_invitation' => ['purchase', 'RFQ Invitation',
                'Request for Quotation {{document.number}}',
                self::wrap('Dear {{vendor.name}},<br><br>You are invited to quote against RFQ <strong>{{document.number}}</strong>. Submit your quotation via the portal: <a href="{{url.portal}}">{{url.portal}}</a>'),
                'Invites a vendor to quote.'],
            'vendor.portal_invitation' => ['vendor', 'Vendor Portal Invitation',
                'Your {{company.name}} vendor portal access',
                self::wrap('Dear {{vendor.name}},<br><br>Your vendor portal account is ready. Sign in here: <a href="{{url.portal}}">{{url.portal}}</a>'),
                'Vendor portal credentials / access link.'],
            'tpv.onboarding_invitation' => ['tpv', 'TPV Onboarding Invitation',
                'Complete your onboarding - {{company.name}}',
                self::wrap('Dear {{vendor.name}},<br><br>Please complete your third-party vendor onboarding: <a href="{{url.portal}}">{{url.portal}}</a>'),
                'Invites a TPV to complete onboarding.'],
            'tpv.approved' => ['tpv', 'TPV Approved',
                'Your registration is approved - {{document.number}}',
                self::wrap('Dear {{vendor.name}},<br><br>Your registration has been approved. Your registration number is <strong>{{document.number}}</strong>.'),
                'Confirms TPV approval and registration number.'],

            /* ── Sales / Finance / Customer ─────────────────────────────── */
            'sales.proposal' => ['sales', 'Proposal Sent',
                'Proposal {{document.number}} from {{company.name}}',
                self::wrap('Dear {{customer.name}},<br><br>Please find our proposal <strong>{{document.number}}</strong>. You can review and accept it online: <a href="{{url.portal}}">{{url.portal}}</a>'),
                'Sends a proposal to a customer.'],
            'sales.contract' => ['sales', 'Contract Sent',
                'Contract {{document.number}} for signature',
                self::wrap('Dear {{customer.name}},<br><br>Please review and sign contract <strong>{{document.number}}</strong>: <a href="{{url.portal}}">{{url.portal}}</a>'),
                'Sends a contract for e-signature.'],
            'finance.invoice' => ['finance', 'Invoice Sent',
                'Invoice {{document.number}} from {{company.name}}',
                self::wrap('Dear {{customer.name}},<br><br>Invoice <strong>{{document.number}}</strong> dated {{date.today}} for {{amount}} is attached. Payment is due by {{due_date}}.'),
                'Sends an invoice to a customer.'],
            'finance.payment_received' => ['finance', 'Payment Received',
                'Payment received - thank you',
                self::wrap('Dear {{customer.name}},<br><br>We have received your payment of {{amount}}. Thank you.'),
                'Payment acknowledgement / receipt.'],
            'finance.payment_reminder' => ['finance', 'Payment Reminder',
                'Reminder: Invoice {{document.number}} is due',
                self::wrap('Dear {{customer.name}},<br><br>This is a friendly reminder that invoice <strong>{{document.number}}</strong> for {{amount}} is due on {{due_date}}.'),
                'Overdue / upcoming payment reminder.'],
            'customer.welcome' => ['customer', 'Customer Welcome',
                'Welcome to {{company.name}}',
                self::wrap('Dear {{customer.name}},<br><br>Thank you for choosing {{company.name}}. We are glad to have you with us.'),
                'Welcomes a new customer.'],

            /* ── Inventory / Projects / Helpdesk / Meetings ─────────────── */
            'inventory.stock_alert' => ['inventory', 'Stock Alert',
                '[Low stock] {{subject}}',
                self::wrap('The following items have fallen below their reorder level and need attention.'),
                'Low / out-of-stock alert.'],
            'projects.task_assigned' => ['projects', 'Task Assigned',
                '[Task] {{user.name}} assigned you: {{subject}}',
                self::wrap('Hi,<br><br>A task has been assigned to you: <strong>{{subject}}</strong>.'),
                'Notifies an assignee of a new task.'],
            'helpdesk.ticket_received' => ['helpdesk', 'Ticket Received',
                '[Ticket #{{ticket.id}}] {{ticket.subject}} — we have received your request',
                self::wrap('Hi {{customer.name}},<br><br>We have received your request and our team will respond shortly.'),
                'Acknowledges a new support ticket.'],
            'helpdesk.ticket_reply' => ['helpdesk', 'Ticket Reply',
                '[Ticket #{{ticket.id}}] {{ticket.subject}}',
                self::wrap('Hi {{customer.name}},<br><br>There is a new reply on your ticket.'),
                'Agent reply on a ticket.'],
            'meetings.invitation' => ['meetings', 'Meeting Invitation',
                'Meeting invitation - {{subject}}',
                self::wrap('Hi,<br><br>You are invited to a meeting on {{date.now}}.'),
                'Generic meeting/kickoff invitation.'],

            /* ── Notifications / System ─────────────────────────────────── */
            'notifications.generic' => ['notifications', 'Generic Notification',
                '{{subject}}',
                self::wrap('{{body}}'),
                'Fallback wrapper for ad-hoc notifications.'],
            'system.test' => ['system', 'SMTP Test Email',
                '{{company.name}} — test email',
                self::wrap('This is a test email from {{company.name}}. If you received it, your SMTP settings are working.'),
                'Sent by Settings → Email/SMTP → Send Test.'],
        ];

        return $t;
    }

    /** Register (or override) a template at runtime. */
    public static function register(string $key, array $definition): void
    {
        self::$extra[$key] = $definition;
    }

    /** Drop runtime registrations — static state, so tests must reset it. */
    public static function flush(): void
    {
        self::$extra = [];
    }

    /** All shipped templates, normalised. */
    public static function all(): array
    {
        $out = [];
        foreach (array_merge(self::templates(), self::$extra) as $key => $d) {
            $out[$key] = self::normalise($key, $d);
        }

        return $out;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    private static function normalise(string $key, array $d): array
    {
        $html = $d[3] ?? $d['body_html'] ?? '';

        return [
            'key'         => $key,
            'category'    => $d[0] ?? $d['category'] ?? 'system',
            'name'        => $d[1] ?? $d['name'] ?? $key,
            'subject'     => $d[2] ?? $d['subject'] ?? '',
            'body_html'   => $html,
            // Index 4 is the DESCRIPTION in the positional form; the plain-text
            // alternative is derived unless a definition supplies one by name.
            'body_text'   => $d['body_text'] ?? self::toPlainText($html),
            'description' => $d[4] ?? $d['description'] ?? '',
            'enabled'     => true,
            'is_system'   => true,
        ];
    }

    /**
     * A readable plain-text alternative derived from the HTML body.
     *
     * Links keep their URL — strip_tags alone would leave "Reset password" with no
     * address at all, which makes a plain-text password-reset mail useless. Block
     * elements become newlines so lists and headings do not run together.
     */
    public static function toPlainText(string $html): string
    {
        $text = (string) $html;

        // "text (https://…)", collapsing when the label already IS the URL.
        $text = (string) preg_replace_callback(
            '~<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>~is',
            function (array $m) {
                $href = trim(strip_tags($m[2]));
                $label = trim(strip_tags($m[3]));

                if ($label === '' || $label === $href) {
                    return $href;
                }

                return $href === '' ? $label : $label.' ('.$href.')';
            },
            $text,
        );

        $text = (string) preg_replace('~<br\s*/?>~i', "\n", $text);
        $text = (string) preg_replace('~</(p|li|h[1-6]|div|tr)>~i', "\n", $text);
        $text = (string) preg_replace('~</(td|th)>~i', "\t", $text);

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse the runs of blank lines the rewrites above can produce.
        return trim((string) preg_replace("~\n{3,}~", "\n\n", $text));
    }
}
